// Package devices implements the clean-room global device lease domain.
package devices

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"sort"
	"strings"
	"time"

	"github.com/redis/go-redis/v9"
)

var ErrLimit = errors.New("device limit exceeded")

type Clock func() time.Time
type Device struct {
	Field      string    `json:"field"`
	NodeID     string    `json:"node_id"`
	IP         string    `json:"ip"`
	SeenAt     time.Time `json:"seen_at"`
	LeaseUntil time.Time `json:"lease_until"`
}
type Snapshot struct {
	UserID      int64    `json:"user_id"`
	Devices     []Device `json:"devices"`
	OnlineCount int      `json:"online_count"`
}
type Report struct {
	UserID int64    `json:"user_id"`
	Limit  int      `json:"device_limit"`
	NodeID string   `json:"node_id"`
	IPs    []string `json:"ips"`
	Full   bool     `json:"full"`
	Kind   string   `json:"kind"`
}
type Store interface {
	Admit(context.Context, int64, int, time.Duration, string, string, time.Time) (bool, error)
	Snapshot(context.Context, int64, time.Duration, time.Time) (Snapshot, error)
	Clear(context.Context, int64) error
	Prune(context.Context, time.Duration, time.Time) ([]int64, error)
}
type Service struct {
	Store        Store
	Window       time.Duration
	WindowSource func(context.Context) time.Duration
	Pending      func(context.Context, int64) error
	Now          Clock
}

func ClampWindow(window time.Duration) time.Duration {
	if window < time.Minute {
		return time.Minute
	}
	if window > 5*time.Minute {
		return 5 * time.Minute
	}
	return window
}

func ConfiguredWindow(ctx context.Context, db *sql.DB, fallback time.Duration) time.Duration {
	var raw []byte
	if err := db.QueryRowContext(ctx, `select value from settings where key='device_window_minutes'`).Scan(&raw); err != nil {
		return ClampWindow(fallback)
	}
	var minutes int
	if err := json.Unmarshal(raw, &minutes); err != nil || minutes < 1 || minutes > 5 {
		return ClampWindow(fallback)
	}
	return time.Duration(minutes) * time.Minute
}

func (s Service) WindowFor(ctx context.Context) time.Duration {
	if s.WindowSource != nil {
		return ClampWindow(s.WindowSource(ctx))
	}
	return ClampWindow(s.Window)
}
func (s Service) Report(ctx context.Context, r Report) (Snapshot, error) {
	now := time.Now()
	if s.Now != nil {
		now = s.Now()
	}
	window := s.WindowFor(ctx)
	if r.Kind == "traffic" {
		return s.Store.Snapshot(ctx, r.UserID, window, now)
	}
	for _, ip := range r.IPs {
		if net.ParseIP(ip) == nil {
			continue
		}
		ok, e := s.Store.Admit(ctx, r.UserID, r.Limit, window, r.NodeID, ip, now)
		if e != nil {
			return Snapshot{}, e
		}
		if !ok {
			return s.Store.Snapshot(ctx, r.UserID, window, now)
		}
	}
	out, e := s.Store.Snapshot(ctx, r.UserID, window, now)
	if e == nil && s.Pending != nil {
		_ = s.Pending(ctx, r.UserID)
	}
	return out, e
}
func (s Service) Clear(ctx context.Context, u int64) error {
	if e := s.Store.Clear(ctx, u); e != nil {
		return e
	}
	if s.Pending != nil {
		return s.Pending(ctx, u)
	}
	return nil
}

type Valkey struct{ R redis.UniversalClient }

var admitLua = redis.NewScript(`local k=KEYS[1]; redis.call('SADD',KEYS[2],ARGV[5]); local cutoff=tonumber(ARGV[1]); redis.call('ZREMRANGEBYSCORE',k,'-inf',cutoff); local f=ARGV[2]; local lim=tonumber(ARGV[3]); if redis.call('ZSCORE',k,f) then redis.call('ZADD',k,ARGV[4],f); return 1 end; if lim==0 or redis.call('ZCARD',k)<lim then redis.call('ZADD',k,ARGV[4],f); return 1 end; return 0`)

func key(u int64) string        { return fmt.Sprintf("flyboard:devices:%d", u) }
func field(n, ip string) string { return n + "|" + ip }
func (v Valkey) Admit(c context.Context, u int64, l int, w time.Duration, n, ip string, now time.Time) (bool, error) {
	x, e := admitLua.Run(c, v.R, []string{key(u), "flyboard:device_users"}, now.Add(-w).UnixMilli(), field(n, ip), l, now.UnixMilli(), u).Int()
	return x == 1, e
}
func (v Valkey) Snapshot(c context.Context, u int64, w time.Duration, now time.Time) (Snapshot, error) {
	z, e := v.R.ZRangeWithScores(c, key(u), 0, -1).Result()
	out := Snapshot{UserID: u}
	for _, x := range z {
		seen := time.UnixMilli(int64(x.Score))
		if seen.Before(now.Add(-w)) {
			continue
		}
		p := strings.SplitN(x.Member.(string), "|", 2)
		if len(p) == 2 {
			out.Devices = append(out.Devices, Device{Field: x.Member.(string), NodeID: p[0], IP: p[1], SeenAt: seen, LeaseUntil: seen.Add(w)})
		}
	}
	out.OnlineCount = len(out.Devices)
	return out, e
}
func (v Valkey) Remove(c context.Context, u int64, node, ip string) error {
	if ip == "" {
		return nil
	}
	if node != "" {
		return v.R.ZRem(c, key(u), field(node, ip)).Err()
	}
	members, e := v.R.ZRange(c, key(u), 0, -1).Result()
	if e != nil {
		return e
	}
	p := v.R.Pipeline()
	for _, f := range members {
		if strings.HasSuffix(f, "|"+ip) {
			p.ZRem(c, key(u), f)
		}
	}
	_, e = p.Exec(c)
	return e
}
func (v Valkey) Clear(c context.Context, u int64) error {
	p := v.R.Pipeline()
	p.Del(c, key(u))
	p.SRem(c, "flyboard:device_users", u)
	_, e := p.Exec(c)
	return e
}
func (v Valkey) Prune(c context.Context, w time.Duration, now time.Time) ([]int64, error) {
	ids, e := v.R.SMembers(c, "flyboard:device_users").Result()
	if e != nil {
		return nil, e
	}
	changed := []int64{}
	for _, raw := range ids {
		var u int64
		if _, e = fmt.Sscan(raw, &u); e != nil {
			continue
		}
		n, e := v.R.ZRemRangeByScore(c, key(u), "-inf", fmt.Sprint(now.Add(-w).UnixMilli())).Result()
		if e != nil {
			return nil, e
		}
		if n > 0 {
			changed = append(changed, u)
		}
		z, _ := v.R.ZCard(c, key(u)).Result()
		if z == 0 {
			_ = v.R.SRem(c, "flyboard:device_users", u).Err()
		}
	}
	return changed, nil
}
func Sort(s *Snapshot) {
	sort.Slice(s.Devices, func(i, j int) bool { return s.Devices[i].Field < s.Devices[j].Field })
}
