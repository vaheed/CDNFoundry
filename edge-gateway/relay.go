package main

import (
	"errors"
	"io"
	"net"
	"sync"
	"sync/atomic"
	"time"
)

// sourceLimiter bounds the connections one source address may hold, so a single client cannot
// take every slot of the gateway. IPv6 sources count per /64, since a host controls a whole /64.
type sourceLimiter struct {
	mu     sync.Mutex
	limit  int
	counts map[string]int
}

func newSourceLimiter(limit int) *sourceLimiter {
	return &sourceLimiter{limit: limit, counts: map[string]int{}}
}

func sourceKey(address net.Addr) string {
	tcp, ok := address.(*net.TCPAddr)
	if !ok || tcp.IP == nil {
		return address.String()
	}
	if v4 := tcp.IP.To4(); v4 != nil {
		return v4.String()
	}
	return tcp.IP.Mask(net.CIDRMask(64, 128)).String() + "/64"
}

func (l *sourceLimiter) acquire(key string) bool {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.counts[key] >= l.limit {
		return false
	}
	l.counts[key]++
	return true
}

func (l *sourceLimiter) release(key string) {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.counts[key] <= 1 {
		delete(l.counts, key)
		return
	}
	l.counts[key]--
}

// relay copies both directions until each side is done. A direction that sees no byte for
// idle is closed, so a silent peer cannot hold a slot. When one side half-closes, the other
// direction keeps flowing but only while it makes progress within halfClose, so a client that
// stops reading cannot pin the slot after its peer has finished. Any other error ends both.
func relay(client, upstream net.Conn, idle, halfClose time.Duration) {
	var forward, backward atomic.Int64
	forward.Store(int64(idle))
	backward.Store(int64(idle))
	var once sync.Once
	abort := func() { once.Do(func() { _ = client.Close(); _ = upstream.Close() }) }
	var wait sync.WaitGroup
	wait.Add(2)
	pump := func(dst, src net.Conn, own, peer *atomic.Int64) {
		defer wait.Done()
		clean := copyWithIdle(dst, src, own)
		if !clean {
			abort()
			return
		}
		if tcp, ok := dst.(*net.TCPConn); ok {
			_ = tcp.CloseWrite()
		}
		peer.Store(int64(halfClose))
		// A read already waiting on the longer idle deadline must notice the shorter one.
		if peer == &forward {
			_ = client.SetReadDeadline(time.Now().Add(halfClose))
		} else {
			_ = upstream.SetReadDeadline(time.Now().Add(halfClose))
		}
	}
	go pump(upstream, client, &forward, &backward)
	pump(client, upstream, &backward, &forward)
	wait.Wait()
}

// copyWithIdle copies src to dst and reports whether the source ended cleanly with EOF. The
// limit is read on every iteration so relay can shorten it after a half-close.
func copyWithIdle(dst, src net.Conn, limit *atomic.Int64) bool {
	buffer := make([]byte, 32<<10)
	for {
		_ = src.SetReadDeadline(time.Now().Add(time.Duration(limit.Load())))
		n, err := src.Read(buffer)
		if n > 0 {
			_ = dst.SetWriteDeadline(time.Now().Add(time.Duration(limit.Load())))
			if _, writeErr := dst.Write(buffer[:n]); writeErr != nil {
				return false
			}
		}
		if err != nil {
			return errors.Is(err, io.EOF)
		}
	}
}

const defaultTLSHandshakeBudget = 50

// maxHandshakeKeys bounds the memory one second of distinct (client, host) pairs can use. Past it,
// new pairs are not counted; the connection limits still apply.
const maxHandshakeKeys = 262144

// handshakeLimiter counts new TLS connections per client prefix and host in one-second windows, so
// one client cannot spend the budget other clients of the same domain need.
type handshakeLimiter struct {
	mu     sync.Mutex
	second int64
	counts map[string]int
}

func newHandshakeLimiter() *handshakeLimiter {
	return &handshakeLimiter{counts: map[string]int{}}
}

func (l *handshakeLimiter) allow(key string, budget int, now time.Time) bool {
	l.mu.Lock()
	defer l.mu.Unlock()
	if second := now.Unix(); second != l.second {
		l.second = second
		clear(l.counts)
	}
	if _, seen := l.counts[key]; !seen && len(l.counts) >= maxHandshakeKeys {
		return true
	}
	l.counts[key]++
	return l.counts[key] <= budget
}
