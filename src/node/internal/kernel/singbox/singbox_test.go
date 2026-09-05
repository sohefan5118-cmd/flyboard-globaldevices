package singbox

import (
	"context"
	"errors"
	"net"
	"net/netip"
	"testing"
	"time"

	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/model"
	"github.com/sagernet/sing-box/adapter"
	singM "github.com/sagernet/sing/common/metadata"
	"golang.org/x/time/rate"
)

func TestSingBoxCapabilities(t *testing.T) {
	s := New(config.KernelConfig{Type: "sing-box"})
	caps := s.Capabilities()
	if !caps.PerUserSpeedLimit || !caps.DeviceLimit || !caps.AliveIPTracking || !caps.ForceCloseUser {
		t.Fatalf("unexpected sing-box capabilities: %+v", caps)
	}
	if caps.BuiltInTrafficStats || caps.ForceCloseConnection {
		t.Fatalf("unexpected sing-box capabilities: %+v", caps)
	}
	protocols := s.Protocols()
	found := false
	for _, protocol := range protocols {
		if protocol == "hysteria" {
			found = true
			break
		}
	}
	if !found {
		t.Fatalf("Protocols() missing hysteria: %v", protocols)
	}
}

type testConn struct {
	closed bool
	reads  [][]byte
	writes [][]byte
}

func (c *testConn) Read(b []byte) (int, error) {
	if len(c.reads) == 0 {
		return 0, errors.New("eof")
	}
	chunk := c.reads[0]
	c.reads = c.reads[1:]
	n := copy(b, chunk)
	return n, nil
}

func (c *testConn) Write(b []byte) (int, error) {
	cp := append([]byte(nil), b...)
	c.writes = append(c.writes, cp)
	return len(b), nil
}

func (c *testConn) Close() error                     { c.closed = true; return nil }
func (c *testConn) LocalAddr() net.Addr              { return &net.TCPAddr{} }
func (c *testConn) RemoteAddr() net.Addr             { return &net.TCPAddr{} }
func (c *testConn) SetDeadline(time.Time) error      { return nil }
func (c *testConn) SetReadDeadline(time.Time) error  { return nil }
func (c *testConn) SetWriteDeadline(time.Time) error { return nil }

func testInboundContext(uuid, ip string) adapter.InboundContext {
	return adapter.InboundContext{
		User:   uuid,
		Source: singM.Socksaddr{Addr: netip.MustParseAddr(ip)},
	}
}

func TestConnTrackerRoutedConnectionTracksTrafficAndAliveDevices(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"uuid-1": 1})
	base := &testConn{reads: [][]byte{[]byte("hello")}}

	wrapped := tracker.RoutedConnection(context.Background(), base, testInboundContext("uuid-1", "1.1.1.1"), nil, nil)

	buf := make([]byte, 16)
	n, err := wrapped.Read(buf)
	if err != nil {
		t.Fatalf("Read() error = %v", err)
	}
	if n != 5 {
		t.Fatalf("Read() bytes = %d, want 5", n)
	}
	if _, err := wrapped.Write([]byte("bye")); err != nil {
		t.Fatalf("Write() error = %v", err)
	}

	traffic, aliveDevices, connCount := tracker.GetUserTraffic()
	if got := traffic[1]; got != [2]int64{5, 3} {
		t.Fatalf("traffic[1] = %v, want [5 3]", got)
	}
	if !aliveDevices[1]["1.1.1.1"] {
		t.Fatal("expected alive devices to include source IP device identifier")
	}
	if connCount != 1 {
		t.Fatalf("connCount = %d, want 1", connCount)
	}

	if err := wrapped.Close(); err != nil {
		t.Fatalf("Close() error = %v", err)
	}
	_, aliveDevices, connCount = tracker.GetUserTraffic()
	if aliveDevices[1] != nil {
		t.Fatalf("aliveDevices after close = %v, want nil", aliveDevices[1])
	}
	if connCount != 0 {
		t.Fatalf("connCount after close = %d, want 0", connCount)
	}
}

func TestConnTrackerRoutedConnectionRejectsWhenDeviceLimitExceeded(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"uuid-1": 1, "uuid-2": 1})
	tracker.SetDeviceLimitFunc(func(uuid string) (int, bool) {
		if uuid != "uuid-1" && uuid != "uuid-2" {
			return 0, false
		}
		return 1, true
	})

	first := &testConn{}
	wrapped1 := tracker.RoutedConnection(context.Background(), first, testInboundContext("uuid-1", "1.1.1.1"), nil, nil)
	if wrapped1 == first {
		t.Fatal("expected first credential connection to be wrapped")
	}

	// Same account credential behind a different source IP is a different device
	// and must be rejected when device_limit=1. This is the strict global
	// behavior required by the panel: one account may not use two devices.
	sameCredentialDifferentIP := &testConn{}
	wrappedSame := tracker.RoutedConnection(context.Background(), sameCredentialDifferentIP, testInboundContext("uuid-1", "2.2.2.2"), nil, nil)
	if wrappedSame != sameCredentialDifferentIP {
		t.Fatal("expected same credential from a second IP/device to be rejected and returned unwrapped")
	}
	if !sameCredentialDifferentIP.closed {
		t.Fatal("expected rejected second IP/device connection to be closed")
	}

	// A different credential sharing the already-allowed source IP is the same
	// device identifier for limit purposes and should not consume another slot.
	secondCredentialSameIP := &testConn{}
	wrapped2 := tracker.RoutedConnection(context.Background(), secondCredentialSameIP, testInboundContext("uuid-2", "1.1.1.1"), nil, nil)
	if wrapped2 == secondCredentialSameIP {
		t.Fatal("expected second credential on the same IP/device to be accepted and wrapped")
	}
}

func TestConnTrackerCheckDeviceGateAllowsSecondDeviceWhenFreshGlobalBelowLimit(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"uuid-1": 1})
	us := tracker.users[1]
	us.addConn("1.1.1.1")
	tracker.UpdateGlobalDevices(map[int][]string{1: {"1.1.1.1"}})

	if tracker.checkDeviceGate(us, 1, "2.2.2.2", 2) {
		t.Fatal("expected second device/IP to be accepted while global whitelist is below limit")
	}
	us.addConn("2.2.2.2")
	if !tracker.checkDeviceGate(us, 1, "3.3.3.3", 2) {
		t.Fatal("expected third device/IP to be rejected once merged global+local devices reach limit")
	}
	if tracker.checkDeviceGate(us, 1, "1.1.1.1", 2) {
		t.Fatal("expected globally allowed device/IP to be accepted")
	}
}

func TestConnTrackerFreshGlobalWhitelistOverridesLocalState(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"uuid-1": 1})
	tracker.SetDeviceLimitFunc(func(uuid string) (int, bool) {
		return 1, uuid == "uuid-1"
	})
	us := tracker.users[1]
	us.addConn("1.1.1.1")
	tracker.UpdateGlobalDevices(map[int][]string{1: {"1.1.1.1"}})

	if !tracker.checkDeviceGate(us, 1, "2.2.2.2", 1) {
		t.Fatal("expected unknown device/IP to be rejected by fresh global whitelist")
	}
	if tracker.checkDeviceGate(us, 1, "1.1.1.1", 1) {
		t.Fatal("expected globally allowed device/IP to be accepted")
	}
}

func TestConnTrackerFreshEmptyGlobalWhitelistAllowsBootstrapOnly(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"uuid-1": 1})
	us := tracker.users[1]
	tracker.UpdateGlobalDevices(map[int][]string{1: {}})

	if tracker.checkDeviceGate(us, 1, "uuid-1", 1) {
		t.Fatal("expected first credential to be admitted for bootstrap reporting")
	}
	us.addConn("1.1.1.1")
	if !tracker.checkDeviceGate(us, 1, "uuid-2", 1) {
		t.Fatal("expected second credential to be rejected by fresh empty whitelist")
	}
}

func TestConnTrackerCloseByIDClosesTrackedConnection(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"uuid-1": 1})
	base := &testConn{}
	wrapped := tracker.RoutedConnection(context.Background(), base, testInboundContext("uuid-1", "1.1.1.1"), nil, nil)
	tracked, ok := wrapped.(*trackedConn)
	if !ok {
		t.Fatalf("wrapped type = %T, want *trackedConn", wrapped)
	}
	if !tracker.CloseByID(tracked.connID) {
		t.Fatal("CloseByID() = false, want true")
	}
	if !base.closed {
		t.Fatal("expected underlying connection to be closed")
	}
}

func TestConnTrackerCloseByUUIDClosesUserConnections(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"uuid-1": 1, "uuid-2": 2})

	base1 := &testConn{}
	base2 := &testConn{}
	baseOther := &testConn{}

	tracker.RoutedConnection(context.Background(), base1, testInboundContext("uuid-1", "1.1.1.1"), nil, nil)
	tracker.RoutedConnection(context.Background(), base2, testInboundContext("uuid-1", "1.1.1.2"), nil, nil)
	tracker.RoutedConnection(context.Background(), baseOther, testInboundContext("uuid-2", "2.2.2.2"), nil, nil)

	if n := tracker.CloseByUUID("uuid-1"); n != 2 {
		t.Fatalf("CloseByUUID() = %d, want 2", n)
	}
	if !base1.closed || !base2.closed {
		t.Fatal("expected uuid-1 connections to be closed")
	}
	if baseOther.closed {
		t.Fatal("did not expect other user connection to be closed")
	}
}

func TestSingBoxCloseRemovedUsersClosesAllAuthUUIDs(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"uuid-1": 1, "alias-1": 1})

	basePrimary := &testConn{}
	baseAlias := &testConn{}
	tracker.RoutedConnection(context.Background(), basePrimary, testInboundContext("uuid-1", "1.1.1.1"), nil, nil)
	tracker.RoutedConnection(context.Background(), baseAlias, testInboundContext("alias-1", "1.1.1.2"), nil, nil)

	s := &SingBox{connTracker: tracker}
	s.closeRemovedUsersLocked([]model.UserSpec{{ID: 1, UUID: "uuid-1", AuthAliases: []string{"alias-1"}}})

	if !basePrimary.closed || !baseAlias.closed {
		t.Fatal("expected all removed-user auth connections to be closed")
	}
}

func TestConnTrackerRateLimitHonorsContextCancellation(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"uuid-1": 1})
	tracker.SetSpeedLimitFunc(func(uuid string) *rate.Limiter {
		if uuid != "uuid-1" {
			return nil
		}
		return rate.NewLimiter(rate.Limit(1), 1)
	})

	readCtx, cancelRead := context.WithCancel(context.Background())
	readBase := &testConn{reads: [][]byte{[]byte("a"), []byte("a")}}
	readTracked := tracker.RoutedConnection(readCtx, readBase, testInboundContext("uuid-1", "1.1.1.1"), nil, nil).(*trackedConn)
	buf := make([]byte, 8)
	if n, err := readTracked.Read(buf); err != nil || n != 1 {
		t.Fatalf("first Read() = (%d, %v), want (1, nil)", n, err)
	}
	cancelRead()
	if n, err := readTracked.Read(buf); !errors.Is(err, context.Canceled) || n != 1 {
		t.Fatalf("second Read() = (%d, %v), want (1, context canceled)", n, err)
	}

	writeCtx, cancelWrite := context.WithCancel(context.Background())
	writeBase := &testConn{}
	writeTracked := tracker.RoutedConnection(writeCtx, writeBase, testInboundContext("uuid-1", "1.1.1.2"), nil, nil).(*trackedConn)
	if n, err := writeTracked.Write([]byte("a")); err != nil || n != 1 {
		t.Fatalf("first Write() = (%d, %v), want (1, nil)", n, err)
	}
	cancelWrite()
	if n, err := writeTracked.Write([]byte("a")); !errors.Is(err, context.Canceled) || n != 0 {
		t.Fatalf("second Write() = (%d, %v), want (0, context canceled)", n, err)
	}
}
