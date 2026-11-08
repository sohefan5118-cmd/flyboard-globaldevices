package devices

import (
	"context"
	"os"
	"sync"
	"testing"
	"time"

	"github.com/redis/go-redis/v9"
)

type mem struct {
	sync.Mutex
	d map[int64]map[string]Device
}

func (m *mem) Admit(_ context.Context, u int64, l int, _ time.Duration, n, ip string, now time.Time) (bool, error) {
	m.Lock()
	defer m.Unlock()
	if m.d == nil {
		m.d = map[int64]map[string]Device{}
	}
	if m.d[u] == nil {
		m.d[u] = map[string]Device{}
	}
	f := field(n, ip)
	if _, ok := m.d[u][f]; !ok && l > 0 && len(m.d[u]) >= l {
		return false, nil
	}
	m.d[u][f] = Device{Field: f, NodeID: n, IP: ip, SeenAt: now}
	return true, nil
}
func (m *mem) Snapshot(_ context.Context, u int64, w time.Duration, now time.Time) (Snapshot, error) {
	m.Lock()
	defer m.Unlock()
	s := Snapshot{UserID: u}
	for _, d := range m.d[u] {
		if now.Sub(d.SeenAt) < w {
			d.LeaseUntil = d.SeenAt.Add(w)
			s.Devices = append(s.Devices, d)
		}
	}
	s.OnlineCount = len(s.Devices)
	return s, nil
}
func (m *mem) Clear(_ context.Context, u int64) error                           { delete(m.d, u); return nil }
func (m *mem) Prune(context.Context, time.Duration, time.Time) ([]int64, error) { return nil, nil }
func TestCrossNodeAtomic(t *testing.T) {
	m := &mem{}
	s := Service{Store: m, Window: time.Minute}
	var wg sync.WaitGroup
	ok := 0
	var mu sync.Mutex
	for _, n := range []string{"a", "b"} {
		wg.Add(1)
		go func(n string) {
			defer wg.Done()
			x, _ := s.Report(context.Background(), Report{UserID: 1, Limit: 1, NodeID: n, IPs: []string{"192.0.2." + map[string]string{"a": "1", "b": "2"}[n]}})
			if x.OnlineCount == 1 {
				mu.Lock()
				ok++
				mu.Unlock()
			}
		}(n)
	}
	wg.Wait()
	snap, _ := m.Snapshot(context.Background(), 1, time.Minute, time.Now())
	if snap.OnlineCount != 1 || ok < 1 {
		t.Fatalf("%+v", snap)
	}
}
func TestOnlyPositivePresenceRenewsLease(t *testing.T) {
	m := &mem{}
	now := time.Now()
	s := Service{Store: m, Window: 5 * time.Minute, Now: func() time.Time { return now }}
	s.Report(context.Background(), Report{UserID: 1, Limit: 1, NodeID: "n", IPs: []string{"192.0.2.1"}, Full: true})
	now = now.Add(2 * time.Minute)
	s.Report(context.Background(), Report{UserID: 1, NodeID: "n", Full: true})
	x, _ := s.Report(context.Background(), Report{UserID: 1, NodeID: "n", Kind: "traffic"})
	if x.OnlineCount != 1 {
		t.Fatal("empty snapshot or traffic removed a live lease")
	}
	now = now.Add(2 * time.Minute)
	s.Report(context.Background(), Report{UserID: 1, NodeID: "n", Full: true})
	x, _ = m.Snapshot(context.Background(), 1, 5*time.Minute, now)
	if x.OnlineCount != 1 {
		t.Fatal("repeated empty snapshots released before the lease window")
	}
	now = now.Add(61 * time.Second)
	x, _ = m.Snapshot(context.Background(), 1, 5*time.Minute, now)
	if x.OnlineCount != 0 {
		t.Fatal("lease did not expire after positive presence stopped")
	}
}

func TestPositivePresenceRefreshesLease(t *testing.T) {
	m := &mem{}
	now := time.Now()
	s := Service{Store: m, Window: 5 * time.Minute, Now: func() time.Time { return now }}
	s.Report(context.Background(), Report{UserID: 1, Limit: 1, NodeID: "n", IPs: []string{"192.0.2.1"}, Full: true})
	now = now.Add(4 * time.Minute)
	s.Report(context.Background(), Report{UserID: 1, Limit: 1, NodeID: "n", IPs: []string{"192.0.2.1"}, Full: true})
	now = now.Add(4 * time.Minute)
	x, _ := m.Snapshot(context.Background(), 1, 5*time.Minute, now)
	if x.OnlineCount != 1 {
		t.Fatal("positive presence did not renew the lease")
	}
}

func TestWindowSourceIsBoundedAndLive(t *testing.T) {
	m := &mem{}
	now := time.Now()
	window := 2 * time.Minute
	s := Service{Store: m, WindowSource: func(context.Context) time.Duration { return window }, Now: func() time.Time { return now }}
	s.Report(context.Background(), Report{UserID: 1, Limit: 1, NodeID: "n", IPs: []string{"192.0.2.1"}})
	now = now.Add(90 * time.Second)
	window = time.Minute
	x, _ := s.Report(context.Background(), Report{UserID: 1, Kind: "traffic"})
	if x.OnlineCount != 0 {
		t.Fatal("live window change was not applied")
	}
	if ClampWindow(0) != time.Minute || ClampWindow(10*time.Minute) != 5*time.Minute {
		t.Fatal("window bounds are not 1 through 5 minutes")
	}
}

func TestValkeySnapshotDoesNotDeleteExpiredLease(t *testing.T) {
	raw := os.Getenv("TEST_VALKEY_URL")
	if raw == "" {
		t.Skip("TEST_VALKEY_URL is not set")
	}
	opt, err := redis.ParseURL(raw)
	if err != nil {
		t.Fatal(err)
	}
	rdb := redis.NewClient(opt)
	t.Cleanup(func() { rdb.Close() })
	ctx := context.Background()
	if err = rdb.FlushDB(ctx).Err(); err != nil {
		t.Fatal(err)
	}

	now := time.Now().Truncate(time.Millisecond)
	v := Valkey{R: rdb}
	if _, err = v.Admit(ctx, 1, 1, time.Minute, "1", "192.0.2.1", now.Add(-2*time.Minute)); err != nil {
		t.Fatal(err)
	}
	snap, err := v.Snapshot(ctx, 1, time.Minute, now)
	if err != nil || snap.OnlineCount != 0 {
		t.Fatalf("expired snapshot=%+v err=%v", snap, err)
	}
	if got, err := rdb.ZCard(ctx, key(1)).Result(); err != nil || got != 1 {
		t.Fatalf("snapshot deleted lease: zcard=%d err=%v", got, err)
	}
}

func TestValkeyLeaseLifecycle(t *testing.T) {
	raw := os.Getenv("TEST_VALKEY_URL")
	if raw == "" {
		t.Skip("TEST_VALKEY_URL is not set")
	}
	opt, err := redis.ParseURL(raw)
	if err != nil {
		t.Fatal(err)
	}
	rdb := redis.NewClient(opt)
	t.Cleanup(func() { rdb.Close() })
	ctx := context.Background()
	if err = rdb.FlushDB(ctx).Err(); err != nil {
		t.Fatal(err)
	}

	now := time.Now().Truncate(time.Millisecond)
	s := Service{Store: Valkey{R: rdb}, Window: 5 * time.Minute, Now: func() time.Time { return now }}
	first, err := s.Report(ctx, Report{UserID: 1, Limit: 1, NodeID: "1", IPs: []string{"192.0.2.1"}, Full: true})
	if err != nil || first.OnlineCount != 1 {
		t.Fatalf("first report: snapshot=%+v err=%v", first, err)
	}
	second, err := s.Report(ctx, Report{UserID: 1, Limit: 1, NodeID: "2", IPs: []string{"192.0.2.2"}, Full: true})
	if err != nil || second.OnlineCount != 1 || second.Devices[0].IP != "192.0.2.1" {
		t.Fatalf("second device replaced first: snapshot=%+v err=%v", second, err)
	}

	now = now.Add(4 * time.Minute)
	if _, err = s.Report(ctx, Report{UserID: 1, Limit: 1, NodeID: "1", IPs: []string{"192.0.2.1"}, Full: true}); err != nil {
		t.Fatal(err)
	}
	now = now.Add(2 * time.Minute)
	if _, err = s.Report(ctx, Report{UserID: 1, NodeID: "1", Full: true}); err != nil {
		t.Fatal(err)
	}
	current, err := s.Report(ctx, Report{UserID: 1, NodeID: "1", Kind: "traffic"})
	if err != nil || current.OnlineCount != 1 {
		t.Fatalf("empty/traffic report shortened lease: snapshot=%+v err=%v", current, err)
	}

	now = now.Add(3*time.Minute + time.Second)
	expired, err := s.Store.Snapshot(ctx, 1, 5*time.Minute, now)
	if err != nil || expired.OnlineCount != 0 {
		t.Fatalf("stale lease retained: snapshot=%+v err=%v", expired, err)
	}
}

func TestMemorySnapshotPreservesExpiredEntryForPrune(t *testing.T) {
	m := &mem{d: map[int64]map[string]Device{1: {"1|192.0.2.1": {Field: "1|192.0.2.1", SeenAt: time.Now().Add(-2 * time.Minute)}}}}
	snap, err := m.Snapshot(context.Background(), 1, time.Minute, time.Now())
	if err != nil || snap.OnlineCount != 0 {
		t.Fatalf("snapshot=%+v err=%v", snap, err)
	}
	if len(m.d[1]) != 1 {
		t.Fatal("read-only snapshot deleted lease before prune")
	}
}
