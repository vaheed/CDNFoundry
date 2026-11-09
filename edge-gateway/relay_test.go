package main

import (
	"crypto/tls"
	"fmt"
	"io"
	"net"
	"testing"
	"time"
)

func tcpPair(t *testing.T) (*net.TCPConn, *net.TCPConn) {
	t.Helper()
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	accepted := make(chan *net.TCPConn, 1)
	go func() {
		connection, _ := listener.Accept()
		accepted <- connection.(*net.TCPConn)
	}()
	dialed, err := net.Dial("tcp", listener.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	return dialed.(*net.TCPConn), <-accepted
}

func TestSourceLimiterBoundsOneSourceAndCountsAnIPv6PrefixOnce(t *testing.T) {
	limiter := newSourceLimiter(2)
	a := sourceKey(&net.TCPAddr{IP: net.ParseIP("2001:db8:1:2::1")})
	b := sourceKey(&net.TCPAddr{IP: net.ParseIP("2001:db8:1:2:ffff::9")})
	other := sourceKey(&net.TCPAddr{IP: net.ParseIP("2001:db8:1:3::1")})
	if a != b || a == other {
		t.Fatalf("a /64 must share one key: %s %s %s", a, b, other)
	}
	if sourceKey(&net.TCPAddr{IP: net.ParseIP("::ffff:192.0.2.9")}) != "192.0.2.9" {
		t.Fatal("an IPv4-mapped address must count as IPv4")
	}
	if !limiter.acquire(a) || !limiter.acquire(b) || limiter.acquire(a) {
		t.Fatal("a third connection from the same /64 must be refused")
	}
	if !limiter.acquire(other) {
		t.Fatal("another prefix is unaffected")
	}
	limiter.release(a)
	if !limiter.acquire(a) {
		t.Fatal("a released slot must be reusable")
	}
	limiter.release(a)
	limiter.release(a)
	limiter.release(other)
	if len(limiter.counts) != 0 {
		t.Fatalf("released sources must not be retained: %v", limiter.counts)
	}
}

func TestRelayClosesAConnectionThatGoesSilent(t *testing.T) {
	clientOuter, clientInner := tcpPair(t)
	upstreamInner, upstreamOuter := tcpPair(t)
	defer clientOuter.Close()
	defer upstreamOuter.Close()
	done := make(chan struct{})
	go func() { relay(clientInner, upstreamInner, 200*time.Millisecond, 200*time.Millisecond); close(done) }()
	select {
	case <-done:
	case <-time.After(3 * time.Second):
		t.Fatal("a silent connection held its slot past the idle timeout")
	}
}

func TestRelayKeepsAnActiveConnectionAndForwardsBothDirections(t *testing.T) {
	clientOuter, clientInner := tcpPair(t)
	upstreamInner, upstreamOuter := tcpPair(t)
	defer clientOuter.Close()
	defer upstreamOuter.Close()
	go relay(clientInner, upstreamInner, 300*time.Millisecond, 300*time.Millisecond)
	buffer := make([]byte, 4)
	for round := 0; round < 4; round++ {
		time.Sleep(150 * time.Millisecond) // longer in total than the idle timeout, but never silent
		if _, err := clientOuter.Write([]byte("ping")); err != nil {
			t.Fatal(err)
		}
		if _, err := io.ReadFull(upstreamOuter, buffer); err != nil || string(buffer) != "ping" {
			t.Fatalf("round %d client to upstream: %v %q", round, err, buffer)
		}
		if _, err := upstreamOuter.Write([]byte("pong")); err != nil {
			t.Fatal(err)
		}
		if _, err := io.ReadFull(clientOuter, buffer); err != nil || string(buffer) != "pong" {
			t.Fatalf("round %d upstream to client: %v %q", round, err, buffer)
		}
	}
}

func TestRelayHalfCloseStillDeliversTheResponseThenFreesTheSlot(t *testing.T) {
	clientOuter, clientInner := tcpPair(t)
	upstreamInner, upstreamOuter := tcpPair(t)
	defer clientOuter.Close()
	defer upstreamOuter.Close()
	done := make(chan struct{})
	go func() { relay(clientInner, upstreamInner, 5*time.Second, 300*time.Millisecond); close(done) }()

	_, _ = clientOuter.Write([]byte("request"))
	_ = clientOuter.CloseWrite()
	request, err := io.ReadAll(io.LimitReader(upstreamOuter, 7))
	if err != nil || string(request) != "request" {
		t.Fatalf("request not forwarded: %v %q", err, request)
	}
	_, _ = upstreamOuter.Write([]byte("response"))
	response := make([]byte, 8)
	if _, err := io.ReadFull(clientOuter, response); err != nil || string(response) != "response" {
		t.Fatalf("the response after a client half-close was lost: %v %q", err, response)
	}
	// The upstream now goes quiet without closing: the half-close limit, not the long idle
	// timeout, must free the slot.
	select {
	case <-done:
	case <-time.After(3 * time.Second):
		t.Fatal("a half-closed connection pinned its slot")
	}
}

func TestOneSourceCannotTakeEverySlot(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	g := &gateway{slots: make(chan struct{}, 8), perSource: newSourceLimiter(2)}
	go g.accept("127.0.0.1:80", listener)

	var held []net.Conn
	for i := 0; i < 2; i++ {
		connection, err := net.Dial("tcp", listener.Addr().String())
		if err != nil {
			t.Fatal(err)
		}
		held = append(held, connection)
	}
	defer func() {
		for _, connection := range held {
			connection.Close()
		}
	}()
	time.Sleep(100 * time.Millisecond)

	third, err := net.Dial("tcp", listener.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	defer third.Close()
	_ = third.SetReadDeadline(time.Now().Add(2 * time.Second))
	if _, err := third.Read(make([]byte, 1)); err != io.EOF {
		t.Fatalf("the third connection from one address must be closed at once, got %v", err)
	}
	if g.rejected.Load() == 0 {
		t.Fatal("the refusal must be counted")
	}
	if len(g.slots) != 2 {
		t.Fatalf("a refused connection must not keep a global slot: %d held", len(g.slots))
	}
}

func TestHandshakeLimiterCountsPerClientAndHostInOneSecondWindows(t *testing.T) {
	limiter := newHandshakeLimiter()
	now := time.Unix(1000, 0)
	for i := 0; i < 5; i++ {
		if !limiter.allow("198.51.100.7|a.test", 5, now) {
			t.Fatalf("handshake %d is within the budget", i+1)
		}
	}
	if limiter.allow("198.51.100.7|a.test", 5, now) {
		t.Fatal("the sixth handshake in one second exceeds the budget")
	}
	if !limiter.allow("198.51.100.8|a.test", 5, now) || !limiter.allow("198.51.100.7|b.test", 5, now) {
		t.Fatal("another client or another host has its own budget")
	}
	if !limiter.allow("198.51.100.7|a.test", 5, now.Add(time.Second)) {
		t.Fatal("the budget resets with the next second")
	}
}

func TestGatewayRefusesOneClientsTLSFloodButNotAnotherClient(t *testing.T) {
	upstream, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer upstream.Close()
	go func() {
		for {
			connection, err := upstream.Accept()
			if err != nil {
				return
			}
			go func() { _, _ = io.Copy(io.Discard, connection); connection.Close() }()
		}
	}()
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	fixed := time.Unix(5000, 0)
	g := &gateway{handshakes: newHandshakeLimiter(), clock: func() time.Time { return fixed }}
	g.table.Store(&routingTable{
		routes:     map[string]string{"https|127.0.0.1|route.test": upstream.Addr().String()},
		tlsBudgets: map[string]int{"route.test": 3},
	})
	go g.accept("127.0.0.1:443", listener)

	hello := func(source string) {
		dialer := net.Dialer{LocalAddr: &net.TCPAddr{IP: net.ParseIP(source)}, Timeout: 2 * time.Second}
		connection, err := dialer.Dial("tcp", listener.Addr().String())
		if err != nil {
			t.Fatal(err)
		}
		defer connection.Close()
		_ = connection.SetDeadline(time.Now().Add(100 * time.Millisecond))
		_ = tls.Client(connection, &tls.Config{ServerName: "route.test", MinVersion: tls.VersionTLS12}).Handshake()
	}
	for i := 0; i < 8; i++ {
		hello("127.0.0.1")
	}
	waitFor(t, func() bool { return g.accepted.Load() == 3 && g.rejected.Load() == 5 }, func() string {
		return fmt.Sprintf("accepted=%d rejected=%d errors=%d", g.accepted.Load(), g.rejected.Load(), g.errors.Load())
	})
	hello("127.0.0.2")
	waitFor(t, func() bool { return g.accepted.Load() == 4 }, func() string { return fmt.Sprintf("accepted=%d", g.accepted.Load()) })
}

func waitFor(t *testing.T, condition func() bool, state func() string) {
	t.Helper()
	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if condition() {
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("condition not reached in time: " + state())
}

func TestGatewayAnswersAnUnroutedPlainHTTPHostWith421ButClosesAnUnroutedTLSName(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	g := &gateway{}
	g.table.Store(&routingTable{routes: map[string]string{}})
	go g.accept("127.0.0.1:80", listener)

	connection, err := net.DialTimeout("tcp", listener.Addr().String(), 2*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	defer connection.Close()
	_ = connection.SetDeadline(time.Now().Add(2 * time.Second))
	if _, err := connection.Write([]byte("GET / HTTP/1.1\r\nHost: unrouted.test\r\n\r\n")); err != nil {
		t.Fatal(err)
	}
	response, _ := io.ReadAll(connection)
	if string(response) != misdirectedResponse {
		t.Fatalf("an unrouted plain-HTTP host must get the fixed 421 answer, got %q", response)
	}
	waitFor(t, func() bool { return g.rejected.Load() == 1 }, func() string { return fmt.Sprintf("rejected=%d", g.rejected.Load()) })

	tlsListener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer tlsListener.Close()
	go g.accept("127.0.0.1:443", tlsListener)
	secure, err := net.DialTimeout("tcp", tlsListener.Addr().String(), 2*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	defer secure.Close()
	_ = secure.SetDeadline(time.Now().Add(500 * time.Millisecond))
	_ = tls.Client(secure, &tls.Config{ServerName: "unrouted.test", MinVersion: tls.VersionTLS12}).Handshake()
	waitFor(t, func() bool { return g.rejected.Load() == 2 }, func() string { return fmt.Sprintf("rejected=%d", g.rejected.Load()) })
}
