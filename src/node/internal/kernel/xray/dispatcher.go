package xray

import (
	"context"
	"errors"
	"reflect"
	"sync"
	"sync/atomic"
	"time"
	_ "unsafe"

	xrayDispatcher "github.com/xtls/xray-core/app/dispatcher"
	"github.com/xtls/xray-core/common"
	"github.com/xtls/xray-core/common/buf"
	"github.com/xtls/xray-core/common/net"
	"github.com/xtls/xray-core/common/session"
	"github.com/xtls/xray-core/features/routing"
	"github.com/xtls/xray-core/transport"

	"github.com/cedar2025/xboard-node/internal/nlog"
)

// Access xray's internal config creator registry so we can replace the
// default dispatcher factory with ours. This runs AFTER xray's init()
// functions because our package imports xray (dependency order guarantee).
//
//go:linkname typeCreatorRegistry github.com/xtls/xray-core/common.typeCreatorRegistry
var typeCreatorRegistry map[reflect.Type]common.ConfigCreator

var origDispatcherFactory common.ConfigCreator

// globalLimitDispatcher is set when the factory creates a LimitDispatcher.
// The Xray kernel reads it to configure limits and get connections.
var globalLimitDispatcher atomic.Pointer[LimitDispatcher]

func init() {
	configType := reflect.TypeOf((*xrayDispatcher.Config)(nil))
	origDispatcherFactory = typeCreatorRegistry[configType]
	typeCreatorRegistry[configType] = limitDispatcherFactory
}

func limitDispatcherFactory(ctx context.Context, config interface{}) (interface{}, error) {
	orig, err := origDispatcherFactory(ctx, config)
	if err != nil {
		return nil, err
	}
	inner, ok := orig.(routing.Dispatcher)
	if !ok {
		return orig, nil
	}
	ld := &LimitDispatcher{
		inner:        orig,
		innerDisp:    inner,
		limitedIPs:   make(map[string]map[string]int),
		trackedConns: make(map[string]xrayConnRef),
	}
	globalLimitDispatcher.Store(ld)
	nlog.Core().Debug("xray: limit dispatcher installed")
	return ld, nil
}

// LimitDispatcher wraps xray's DefaultDispatcher to enforce per-user
// admission checks before a request is dispatched into xray-core.
//
// It intentionally does NOT mutate transport.Link.Reader/Writer. Xray's
// mux/XUDP close path requires the original concrete *pipe.Reader to remain
// intact, so the dispatcher is limited to gate-keeping and safe connection
// lifecycle bookkeeping.
type LimitDispatcher struct {
	inner     interface{}        // original DefaultDispatcher (Feature + Dispatcher)
	innerDisp routing.Dispatcher // same object, typed as Dispatcher

	// limitedUsers: users with device limit > 0, protected by mu.
	// Needs deterministic credential ordering for admission decisions.
	mu             sync.RWMutex
	limitedIPs     map[string]map[string]int // email → device credential → refcount
	deviceLimits   map[string]int            // email → max devices
	emailToUID     map[string]int            // email → panel user ID
	emailToDevice  map[string]string         // email → auth credential/device ID
	trackedConnSeq atomic.Int64
	trackedConns   map[string]xrayConnRef // connID → close metadata for global whitelist pruning

	// Global device state pushed by the panel. When fresh and present for a
	// user, this is authoritative across every node: a new auth credential must be
	// in the panel-selected allowlist, not merely fit inside this node's local set.
	globalDevices    map[int]map[string]bool // userID → auth credential → allowed
	globalLastUpdate time.Time

	// unlimitedIPs: users without device limit — sync.Map for lock-free access.
	// Each entry is *deviceMapCounter{ips sync.Map}.
	unlimitedIPs sync.Map // email → *deviceMapCounter

	connCount atomic.Int64 // total active connections tracked by dispatcher
}

type xrayConnRef struct {
	uid      int
	email    string
	deviceID string
	close    func()
}

// deviceMapCounter tracks device credentials for unlimited users without any lock.
type deviceMapCounter struct {
	ips sync.Map // device credential → *atomic.Int64 (refcount)
}

// aliveDevices returns a snapshot of distinct alive device credentials.
func (ic *deviceMapCounter) aliveDevices() map[string]bool {
	result := make(map[string]bool)
	ic.ips.Range(func(key, _ interface{}) bool {
		if rv, ok := ic.ips.Load(key); ok && rv.(*atomic.Int64).Load() > 0 {
			result[key.(string)] = true
		}
		return true
	})
	return result
}

// ─── routing.Dispatcher ──────────────────────────────────────────────────────

func (d *LimitDispatcher) Dispatch(ctx context.Context, dest net.Destination) (*transport.Link, error) {
	email, deviceID, isTCP, err := d.identifyAndCheck(ctx, dest)
	if err != nil {
		return nil, err
	}

	link, err := d.innerDisp.Dispatch(ctx, dest)
	if err != nil {
		if email != "" && isTCP {
			d.delConn(email, deviceID)
		}
		return nil, err
	}

	if email != "" {
		d.trackLink(link, email, deviceID, isTCP)
	}
	return link, nil
}

func (d *LimitDispatcher) DispatchLink(ctx context.Context, dest net.Destination, link *transport.Link) error {
	email, deviceID, isTCP, err := d.identifyAndCheck(ctx, dest)
	if err != nil {
		return err
	}

	var cleanup func()
	if email != "" {
		cleanup = d.trackLink(link, email, deviceID, isTCP)
	}
	err = d.innerDisp.DispatchLink(ctx, dest, link)
	if err != nil && cleanup != nil {
		cleanup()
	}
	return err
}

// identifyAndCheck extracts user identity from the session context, enforces
// device limits, and returns the user's email, authenticated credential/device
// ID, and TCP flag.
// Returns a non-nil error only when the connection should be rejected.
func (d *LimitDispatcher) identifyAndCheck(ctx context.Context, dest net.Destination) (email, deviceID string, isTCP bool, err error) {
	si := session.InboundFromContext(ctx)
	if si == nil || si.User == nil || len(si.User.Email) == 0 {
		return "", "", false, nil
	}
	email = si.User.Email
	isTCP = dest.Network == net.Network_TCP

	// Strict global device limiting must distinguish different client devices
	// using the same account credential. Use the client source IP as the device
	// identifier; this matches the panel's reported device snapshot.
	deviceID = si.Source.Address.IP().String()
	if deviceID == "" {
		deviceID = email
	}

	if d.checkDeviceLimit(email, deviceID, isTCP) {
		nlog.Core().Debug("xray: device limit exceeded", "email", email, "device", deviceID, "ip", si.Source.Address.IP().String())
		return "", "", false, errors.New("device limit exceeded for " + email)
	}
	return email, deviceID, isTCP, nil
}

// trackLink records connection lifecycle without mutating xray-core owned
// transport primitives. This keeps mux/XUDP compatible while still allowing
// the dispatcher to release device-limit state when the link closes.
func (d *LimitDispatcher) trackLink(link *transport.Link, email, deviceID string, isTCP bool) func() {
	d.connCount.Add(1)
	connID := "xray-" + formatInt36(d.trackedConnSeq.Add(1))
	uid := 0
	d.mu.RLock()
	uid = d.emailToUID[email]
	d.mu.RUnlock()

	onClose := func() {
		if isTCP {
			d.delConn(email, deviceID)
		}
		d.mu.Lock()
		delete(d.trackedConns, connID)
		d.mu.Unlock()
		d.connCount.Add(-1)
	}

	cw := &closeTrackingWriter{
		Writer:  link.Writer,
		onClose: onClose,
	}

	d.mu.Lock()
	if d.trackedConns == nil {
		d.trackedConns = make(map[string]xrayConnRef)
	}
	d.trackedConns[connID] = xrayConnRef{uid: uid, email: email, deviceID: deviceID, close: func() { _ = cw.Close() }}
	d.mu.Unlock()

	link.Writer = cw
	return func() { _ = cw.Close() }
}

// ─── features.Feature (delegated) ───────────────────────────────────────────

func (d *LimitDispatcher) Type() interface{} { return routing.DispatcherType() }

func (d *LimitDispatcher) Start() error {
	if s, ok := d.inner.(interface{ Start() error }); ok {
		return s.Start()
	}
	return nil
}

func (d *LimitDispatcher) Close() error {
	if c, ok := d.inner.(interface{ Close() error }); ok {
		return c.Close()
	}
	return nil
}

// ─── Limit management (called by Xray kernel) ──────────────────────────────

func (d *LimitDispatcher) UpdateLimits(emailToUID map[string]int, deviceLimits map[string]int, emailToDevice map[string]string) {
	toClose := make([]func(), 0)
	d.mu.Lock()
	d.emailToUID = emailToUID
	d.deviceLimits = deviceLimits
	d.emailToDevice = make(map[string]string, len(emailToDevice))
	for email, deviceID := range emailToDevice {
		d.emailToDevice[email] = deviceID
	}
	for _, ref := range d.trackedConns {
		if _, ok := emailToUID[ref.email]; !ok && ref.close != nil {
			toClose = append(toClose, ref.close)
		}
	}
	d.mu.Unlock()

	for _, closeConn := range toClose {
		closeConn()
	}
	if len(toClose) > 0 {
		nlog.Core().Info("xray: closed connections for removed users", "connections", len(toClose))
	}
}

// CloseUsers closes all dispatcher-tracked connections for the given xray user emails.
func (d *LimitDispatcher) CloseUsers(emails map[string]struct{}) int {
	if len(emails) == 0 {
		return 0
	}
	toClose := make([]func(), 0)
	d.mu.RLock()
	for _, ref := range d.trackedConns {
		if _, ok := emails[ref.email]; ok && ref.close != nil {
			toClose = append(toClose, ref.close)
		}
	}
	d.mu.RUnlock()

	for _, closeConn := range toClose {
		closeConn()
	}
	return len(toClose)
}

// UpdateGlobalDevices installs the panel-aggregated global device allowlist.
// xray previously enforced device_limit only from this node's local IP set,
// which let every newly added node admit one more device. Keeping this state in
// the dispatcher makes the limit global across all nodes.
func (d *LimitDispatcher) UpdateGlobalDevices(users map[int][]string) {
	d.mu.Lock()
	d.globalDevices = make(map[int]map[string]bool, len(users))
	for uid, devices := range users {
		m := make(map[string]bool, len(devices))
		for _, device := range devices {
			m[device] = true
		}
		d.globalDevices[uid] = m
	}
	d.globalLastUpdate = time.Now()
	toClose := make([]func(), 0)
	for _, ref := range d.trackedConns {
		allowed, tracked := d.globalDevices[ref.uid]
		if tracked && !allowed[ref.deviceID] && ref.close != nil {
			toClose = append(toClose, ref.close)
		}
	}
	d.mu.Unlock()

	for _, closeConn := range toClose {
		closeConn()
	}
	if len(toClose) > 0 {
		nlog.Core().Info("xray: closed connections outside global device whitelist", "connections", len(toClose))
	}

	nlog.Core().Debug("xray: global device state updated", "users", len(users))
}

// ClearGlobalDevices drops the panel allowlist, causing the dispatcher to fall
// back to local-only enforcement until REST/WS resync supplies a fresh snapshot.
func (d *LimitDispatcher) ClearGlobalDevices() {
	d.mu.Lock()
	d.globalDevices = nil
	d.globalLastUpdate = time.Time{}
	d.mu.Unlock()

	nlog.Core().Debug("xray: global device state cleared")
}

func (d *LimitDispatcher) ResetConns() {
	d.mu.Lock()
	d.limitedIPs = make(map[string]map[string]int)
	d.trackedConns = make(map[string]xrayConnRef)
	d.mu.Unlock()

	// Clear unlimited credential counters
	d.unlimitedIPs.Range(func(key, _ interface{}) bool {
		d.unlimitedIPs.Delete(key)
		return true
	})

	d.connCount.Store(0)
}

// GetConnectionState returns dispatcher-tracked alive device credentials and connection count.
// Traffic bytes are intentionally left to xray's built-in stats pipeline.
func (d *LimitDispatcher) GetConnectionState() (aliveIPs map[int]map[string]bool, connCount int) {
	d.mu.RLock()
	emailToUID := d.emailToUID
	limitedIPs := d.limitedIPs
	d.mu.RUnlock()

	aliveIPs = make(map[int]map[string]bool)

	// Collect device credentials from limited users (under RLock snapshot).
	for email, ipsMap := range limitedIPs {
		uid := emailToUID[email]
		if uid == 0 {
			continue
		}
		deviceSet := make(map[string]bool, len(ipsMap))
		for device := range ipsMap {
			deviceSet[device] = true
		}
		if len(deviceSet) > 0 {
			aliveIPs[uid] = deviceSet
		}
	}

	// Collect device credentials from unlimited users (lock-free).
	d.unlimitedIPs.Range(func(key, value interface{}) bool {
		email := key.(string)
		uid := emailToUID[email]
		if uid == 0 {
			return true
		}
		ic := value.(*deviceMapCounter)
		if ips := ic.aliveDevices(); len(ips) > 0 {
			// Merge with limited credentials if any.
			if existing, ok := aliveIPs[uid]; ok {
				for ip := range ips {
					existing[ip] = true
				}
			} else {
				aliveIPs[uid] = ips
			}
		}
		return true
	})

	connCount = int(d.connCount.Load())
	return
}

// ─── Internal helpers ───────────────────────────────────────────────────────

// checkDeviceLimit enforces per-user device limits.
// Fast path: unlimited users use lock-free sync.Map.
// Slow path: limited users use RWMutex with deterministic credential ordering.
func (d *LimitDispatcher) checkDeviceLimit(email, deviceID string, isTCP bool) bool {
	d.mu.RLock()
	limit, hasLimit := d.deviceLimits[email]
	uid := d.emailToUID[email]
	globalFresh := !d.globalLastUpdate.IsZero() && time.Since(d.globalLastUpdate) <= 60*time.Second
	globalDevices, hasGlobal := d.globalDevices[uid]
	d.mu.RUnlock()

	// Fast path: no device limit — use lock-free sync.Map.
	if !hasLimit || limit <= 0 {
		if isTCP {
			v, _ := d.unlimitedIPs.LoadOrStore(email, &deviceMapCounter{})
			ic := v.(*deviceMapCounter)

			// Increment device credential refcount atomically.
			rv, _ := ic.ips.LoadOrStore(deviceID, &atomic.Int64{})
			rv.(*atomic.Int64).Add(1)
		}
		return false
	}

	// Slow path: user has device limit — need deterministic ordering.
	d.mu.RLock()
	ips := d.limitedIPs[email]
	localCount := len(ips)
	if globalFresh && hasGlobal {
		// Authoritative global allowlist. If this credential already owns a global slot,
		// admit it even on a newly added node. Any other credential must be rejected once
		// the panel has selected at least one allowed device.
		if globalDevices[deviceID] {
			d.mu.RUnlock()
			if isTCP {
				d.mu.Lock()
				if d.limitedIPs[email] == nil {
					d.limitedIPs[email] = make(map[string]int)
				}
				d.limitedIPs[email][deviceID]++
				d.mu.Unlock()
			}
			return false
		}

		// Empty fresh set is the bootstrap state: allow the first local credential so
		// it can be reported to the panel. After any local/global slot exists, a
		// credential outside the global allowlist is over limit.
		if len(globalDevices) == 0 && localCount == 0 {
			d.mu.RUnlock()
			if isTCP {
				d.mu.Lock()
				if d.limitedIPs[email] == nil {
					d.limitedIPs[email] = make(map[string]int)
				}
				d.limitedIPs[email][deviceID]++
				d.mu.Unlock()
			}
			return false
		}

		d.mu.RUnlock()
		nlog.Core().Debug("xray: global device whitelist rejecting", "email", email, "device", deviceID, "allowedDevices", len(globalDevices), "limit", limit)
		return true
	}

	if ips != nil && ips[deviceID] > 0 {
		d.mu.RUnlock()
		if isTCP {
			d.mu.Lock()
			d.limitedIPs[email][deviceID]++
			d.mu.Unlock()
		}
		return false
	}

	if ips != nil && len(ips) < limit {
		d.mu.RUnlock()
		if isTCP {
			d.mu.Lock()
			if d.limitedIPs[email] == nil {
				d.limitedIPs[email] = make(map[string]int)
			}
			d.limitedIPs[email][deviceID]++
			d.mu.Unlock()
		}
		return false
	}
	d.mu.RUnlock()

	// Over limit — need write lock for deterministic check.
	d.mu.Lock()
	defer d.mu.Unlock()

	// Re-check under write lock.
	ips = d.limitedIPs[email]
	if ips == nil {
		ips = make(map[string]int)
		d.limitedIPs[email] = ips
	}

	if ips[deviceID] > 0 {
		if isTCP {
			ips[deviceID]++
		}
		return false
	}

	if len(ips) < limit {
		if isTCP {
			ips[deviceID]++
		}
		return false
	}

	// Over limit: keep already admitted credentials stable and reject any new credential.
	// Do not replace/kick by lexical ordering; that allowed an extra device to
	// slip in until the old connection drained, so limit=1 could show 2 online.
	return true
}

// delConn decrements the device credential refcount when a connection closes.
func (d *LimitDispatcher) delConn(email, deviceID string) {
	// Check if this is an unlimited user first (lock-free).
	if v, ok := d.unlimitedIPs.Load(email); ok {
		ic := v.(*deviceMapCounter)
		if rv, ok := ic.ips.Load(deviceID); ok {
			counter := rv.(*atomic.Int64)
			if counter.Add(-1) <= 0 {
				ic.ips.Delete(deviceID)
			}
		}
		return
	}

	// Limited user — use write lock.
	d.mu.Lock()
	defer d.mu.Unlock()
	if ips, ok := d.limitedIPs[email]; ok {
		ips[deviceID]--
		if ips[deviceID] <= 0 {
			delete(ips, deviceID)
		}
		if len(ips) == 0 {
			delete(d.limitedIPs, email)
		}
	}
}

type closeTrackingWriter struct {
	buf.Writer
	onClose func()
	closed  atomic.Bool
}

func (w *closeTrackingWriter) Close() error {
	if w.closed.CompareAndSwap(false, true) {
		w.onClose()
	}
	return common.Close(w.Writer)
}

func formatInt36(n int64) string {
	const digits = "0123456789abcdefghijklmnopqrstuvwxyz"
	if n == 0 {
		return "0"
	}
	var buf [13]byte
	i := len(buf)
	for n > 0 {
		i--
		buf[i] = digits[n%36]
		n /= 36
	}
	return string(buf[i:])
}

func (w *closeTrackingWriter) Interrupt() {
	if w.closed.CompareAndSwap(false, true) {
		w.onClose()
	}
	common.Interrupt(w.Writer)
}
