package nodeagent

import (
	"flyboard/internal/devices"
	"path/filepath"
	"testing"
	"time"
)

func TestDurableRevisionAndRestart(t *testing.T) {
	p := filepath.Join(t.TempDir(), "state.json")
	d, e := OpenDurable(p, time.Minute)
	if e != nil {
		t.Fatal(e)
	}
	s := devices.Snapshot{UserID: 7, Devices: []devices.Device{{IP: "192.0.2.1"}}}
	if e = d.ApplySnapshot(s, 2, time.Hour); e != nil {
		t.Fatal(e)
	}
	if e = d.ApplySnapshot(devices.Snapshot{UserID: 7}, 1, time.Hour); e != nil {
		t.Fatal(e)
	}
	d2, e := OpenDurable(p, time.Minute)
	if e != nil {
		t.Fatal(e)
	}
	if !d2.Cache().Admit("7", "192.0.2.1", false).Allowed {
		t.Fatal("cache/revision not recovered")
	}
}

func TestDurableUsesAuthoritativeLeaseDeadline(t *testing.T) {
	p := filepath.Join(t.TempDir(), "state.json")
	d, err := OpenDurable(p, 5*time.Minute)
	if err != nil {
		t.Fatal(err)
	}
	until := time.Now().Add(30 * time.Second)
	s := devices.Snapshot{UserID: 9, Devices: []devices.Device{{IP: "192.0.2.9", LeaseUntil: until}}}
	if err = d.ApplySnapshot(s, 1, time.Hour); err != nil {
		t.Fatal(err)
	}
	if !d.Cache().Admit("9", "192.0.2.9", false).Allowed {
		t.Fatal("authoritative lease was not cached")
	}
	d2, err := OpenDurable(p, 5*time.Minute)
	if err != nil {
		t.Fatal(err)
	}
	if got := d2.state.Verified["9\x00192.0.2.9"]; got.Sub(until) > time.Millisecond || until.Sub(got) > time.Millisecond {
		t.Fatalf("persisted lease deadline=%s want=%s", got, until)
	}
}
