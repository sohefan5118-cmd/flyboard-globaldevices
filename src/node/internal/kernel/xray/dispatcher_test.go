package xray

import (
	"sync/atomic"
	"testing"

	"github.com/cedar2025/xboard-node/internal/model"
	"github.com/xtls/xray-core/common/buf"
	"github.com/xtls/xray-core/transport"
)

func newTestDispatcher() *LimitDispatcher {
	return &LimitDispatcher{
		limitedIPs:   make(map[string]map[string]int),
		trackedConns: make(map[string]xrayConnRef),
	}
}

type nopReader struct{}

func (nopReader) ReadMultiBuffer() (buf.MultiBuffer, error) { return nil, nil }

func TestLimitDispatcher_DeviceLimitCheck(t *testing.T) {
	ld := newTestDispatcher()

	users := []model.UserSpec{
		{ID: 1, UUID: "uuid-1", DeviceLimit: 2, SpeedLimit: 0},
		{ID: 2, UUID: "uuid-2", DeviceLimit: 0, SpeedLimit: 10},
	}

	emailToUID := make(map[string]int)
	deviceLimits := make(map[string]int)
	for _, u := range users {
		email := userEmail(u.ID)
		emailToUID[email] = u.ID
		if u.DeviceLimit > 0 {
			deviceLimits[email] = u.DeviceLimit
		}
	}
	ld.UpdateLimits(emailToUID, deviceLimits, nil)

	email1 := userEmail(1)

	// First credential should be allowed
	if ld.checkDeviceLimit(email1, "device-1", true) {
		t.Error("first credential should be allowed")
	}

	// Second credential should be allowed (limit=2)
	if ld.checkDeviceLimit(email1, "device-2", true) {
		t.Error("second credential should be allowed")
	}

	// Third unique credential should be rejected
	if !ld.checkDeviceLimit(email1, "device-3", true) {
		t.Error("third credential should be rejected (limit=2)")
	}

	// Same credential as first should be allowed (already connected)
	if ld.checkDeviceLimit(email1, "device-1", true) {
		t.Error("same credential should always be allowed")
	}

	// User 2 has no device limit — should always be allowed
	email2 := userEmail(2)
	for i := 0; i < 10; i++ {
		device := "device-" + string(rune('0'+i))
		if ld.checkDeviceLimit(email2, device, true) {
			t.Errorf("user with no device limit should always be allowed (device=%s)", device)
		}
	}
}

func TestLimitDispatcher_GlobalDevicesAuthoritative(t *testing.T) {
	ld := newTestDispatcher()

	email := userEmail(1)
	ld.UpdateLimits(map[string]int{email: 1}, map[string]int{email: 1}, nil)
	ld.UpdateGlobalDevices(map[int][]string{1: {"device-1"}})

	// The globally selected credential is allowed, even if this node did not admit it
	// before receiving the panel snapshot.
	if ld.checkDeviceLimit(email, "device-1", true) {
		t.Fatal("globally allowed credential should be accepted")
	}

	// A different credential must not consume a fresh local slot on a newly added node.
	if !ld.checkDeviceLimit(email, "device-2", true) {
		t.Fatal("credential outside fresh global allowlist should be rejected")
	}
}

func TestLimitDispatcher_GlobalDevicesEmptyBootstrapAllowsFirstLocal(t *testing.T) {
	ld := newTestDispatcher()

	email := userEmail(1)
	ld.UpdateLimits(map[string]int{email: 1}, map[string]int{email: 1}, nil)
	ld.UpdateGlobalDevices(map[int][]string{1: {}})

	if ld.checkDeviceLimit(email, "device-1", true) {
		t.Fatal("empty fresh global set should allow first local credential for reporting")
	}
	if !ld.checkDeviceLimit(email, "device-2", true) {
		t.Fatal("second local credential outside empty global set should be rejected")
	}
}

func TestLimitDispatcher_DelConn(t *testing.T) {
	ld := newTestDispatcher()

	email := userEmail(1)
	deviceLimits := map[string]int{email: 2}
	ld.UpdateLimits(map[string]int{email: 1}, deviceLimits, nil)

	// Add 2 credentials
	ld.checkDeviceLimit(email, "device-1", true)
	ld.checkDeviceLimit(email, "device-2", true)

	// Third should be rejected
	if !ld.checkDeviceLimit(email, "device-3", true) {
		t.Error("third credential should be rejected")
	}

	// Remove first credential
	ld.delConn(email, "device-1")

	// Now third credential should be allowed
	if ld.checkDeviceLimit(email, "device-3", true) {
		t.Error("after deleting one credential, new credential should be allowed")
	}
}

func TestLimitDispatcher_GetConnectionState(t *testing.T) {
	ld := newTestDispatcher()

	email1 := userEmail(1)
	email2 := userEmail(2)
	ld.UpdateLimits(map[string]int{email1: 1, email2: 2}, nil, nil)

	ic1 := &deviceMapCounter{}
	r1 := &atomic.Int64{}
	r1.Store(1)
	ic1.ips.Store("device-1", r1)
	r2 := &atomic.Int64{}
	r2.Store(1)
	ic1.ips.Store("device-2", r2)
	ld.unlimitedIPs.Store(email1, ic1)

	ic2 := &deviceMapCounter{}
	r3 := &atomic.Int64{}
	r3.Store(1)
	ic2.ips.Store("device-3", r3)
	ld.unlimitedIPs.Store(email2, ic2)

	ld.connCount.Store(5)

	aliveDevices, connCount := ld.GetConnectionState()

	if connCount != 5 {
		t.Errorf("expected connCount=5, got %d", connCount)
	}
	if len(aliveDevices[1]) != 2 {
		t.Errorf("user 1 credentials: got %d, want 2", len(aliveDevices[1]))
	}
	if len(aliveDevices[2]) != 1 {
		t.Errorf("user 2 credentials: got %d, want 1", len(aliveDevices[2]))
	}
}

func TestLimitDispatcher_ResetConns(t *testing.T) {
	ld := newTestDispatcher()

	ld.mu.Lock()
	ld.limitedIPs["user@1"] = map[string]int{"device-1": 1}
	ld.mu.Unlock()
	ld.connCount.Store(3)

	ld.ResetConns()

	ld.mu.RLock()
	deviceMapCount := len(ld.limitedIPs)
	ld.mu.RUnlock()
	if deviceMapCount != 0 {
		t.Error("limited credential map should be empty after reset")
	}

	if ld.connCount.Load() != 0 {
		t.Error("connCount should be 0 after reset")
	}
}

func TestLimitDispatcher_UnlimitedUserFastPath(t *testing.T) {
	ld := newTestDispatcher()

	email := userEmail(1)
	// No device limit set for this user
	ld.UpdateLimits(map[string]int{email: 1}, nil, nil)

	// Should use fast path (sync.Map), no lock needed
	for i := 0; i < 100; i++ {
		device := "device-" + string(rune('0'+i%10))
		if ld.checkDeviceLimit(email, device, true) {
			t.Errorf("unlimited user should always be allowed (device=%s)", device)
		}
	}

	// Verify credentials are tracked in unlimitedIPs
	v, ok := ld.unlimitedIPs.Load(email)
	if !ok {
		t.Error("unlimited user should have entry in unlimitedIPs")
	}
	ic := v.(*deviceMapCounter)
	devices := ic.aliveDevices()
	if len(devices) == 0 {
		t.Error("should have tracked some credentials")
	}
}

func TestLimitDispatcher_TrackLinkPreservesReader(t *testing.T) {
	ld := newTestDispatcher()
	email := userEmail(1)
	ld.UpdateLimits(map[string]int{email: 1}, map[string]int{email: 1}, nil)

	origReader := nopReader{}
	origWriter := &closeTrackingWriter{Writer: buf.Discard, onClose: func() {}}
	link := &transport.Link{Reader: origReader, Writer: origWriter}

	ld.trackLink(link, email, "device-1", true)

	if link.Reader != origReader {
		t.Fatal("trackLink must not replace link.Reader")
	}
	if link.Writer == origWriter {
		t.Fatal("trackLink should wrap link.Writer for lifecycle callbacks")
	}
}

func TestLimitDispatcher_CloseTrackingWriterReleasesConn(t *testing.T) {
	ld := newTestDispatcher()
	email := userEmail(1)
	ld.UpdateLimits(map[string]int{email: 1}, map[string]int{email: 1}, nil)
	if ld.checkDeviceLimit(email, "device-1", true) {
		t.Fatal("first connection should be allowed")
	}

	link := &transport.Link{Reader: nopReader{}, Writer: buf.Discard}
	ld.trackLink(link, email, "device-1", true)

	if got := ld.connCount.Load(); got != 1 {
		t.Fatalf("expected connCount=1 after tracking, got %d", got)
	}
	cw, ok := link.Writer.(*closeTrackingWriter)
	if !ok {
		t.Fatal("expected closeTrackingWriter wrapper")
	}
	if err := cw.Close(); err != nil {
		t.Fatalf("closeTrackingWriter.Close() error = %v", err)
	}
	if got := ld.connCount.Load(); got != 0 {
		t.Fatalf("expected connCount=0 after close, got %d", got)
	}
	if ld.checkDeviceLimit(email, "device-2", true) {
		t.Fatal("device slot should be released after writer close")
	}
}
