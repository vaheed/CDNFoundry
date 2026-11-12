package main

import (
	"crypto/tls"
	"encoding/binary"
	"io"
	"net"
	"testing"
	"time"
)

// capturedClientHello returns the single TLS record a real client sends first.
func capturedClientHello(t *testing.T, serverName string) []byte {
	t.Helper()
	server, client := net.Pipe()
	result := make(chan []byte, 1)
	go func() {
		header := make([]byte, 5)
		_, _ = io.ReadFull(server, header)
		body := make([]byte, binary.BigEndian.Uint16(header[3:5]))
		_, _ = io.ReadFull(server, body)
		result <- append(header, body...)
		_ = server.Close()
	}()
	_ = tls.Client(client, &tls.Config{ServerName: serverName, MinVersion: tls.VersionTLS12}).Handshake()
	return <-result
}

// reframe splits the handshake message of one record into records of at most size bytes.
func reframe(record []byte, size int) []byte {
	body := record[5:]
	var out []byte
	for len(body) > 0 {
		n := min(size, len(body))
		out = append(out, 22, record[1], record[2], byte(n>>8), byte(n))
		out = append(out, body[:n]...)
		body = body[n:]
	}
	return out
}

func readHelloFrom(t *testing.T, input []byte) (string, []byte, error) {
	t.Helper()
	server, client := net.Pipe()
	defer server.Close()
	go func() { _, _ = client.Write(input); _ = client.Close() }()
	_ = server.SetDeadline(time.Now().Add(2 * time.Second))
	return readClientHello(server)
}

func TestClientHelloSplitAcrossTLSRecordsIsReassembled(t *testing.T) {
	record := capturedClientHello(t, "Split.Example.Test")
	for _, size := range []int{1, 7, 64, 200, len(record)} {
		framed := reframe(record, size)
		name, prefix, err := readHelloFrom(t, framed)
		if err != nil || name != "split.example.test" {
			t.Fatalf("record size %d: %q, %v", size, name, err)
		}
		if string(prefix) != string(framed) {
			t.Fatalf("record size %d: the prefix must replay every byte read", size)
		}
	}
}

func TestClientHelloLargerThanOneRecordIsAccepted(t *testing.T) {
	record := capturedClientHello(t, "large.example.test")
	body := append([]byte(nil), record[5:]...)
	// Pad with an ignorable extension the way a hybrid post-quantum key share enlarges a hello.
	pad := make([]byte, 20000)
	body = appendExtension(body, 0x4a4a, pad)
	framed := reframe(append([]byte{22, 3, 1, byte(len(body) >> 8), byte(len(body))}, body...), 16<<10)
	name, _, err := readHelloFrom(t, framed)
	if err != nil || name != "large.example.test" {
		t.Fatalf("a 20 KB hello in two records must be accepted: %q, %v", name, err)
	}
}

// extensionLengthOffset returns the slice holding the two-byte extensions length of a ClientHello body.
func extensionLengthOffset(handshake []byte) []byte {
	p := 4 + 2 + 32
	p += 1 + int(handshake[p])
	p += 2 + int(binary.BigEndian.Uint16(handshake[p:p+2]))
	p += 1 + int(handshake[p])
	return handshake[p : p+2]
}

func appendExtension(handshake []byte, kind uint16, data []byte) []byte {
	lengthField := extensionLengthOffset(handshake)
	binary.BigEndian.PutUint16(lengthField, binary.BigEndian.Uint16(lengthField)+uint16(4+len(data)))
	handshake = append(handshake, byte(kind>>8), byte(kind), byte(len(data)>>8), byte(len(data)))
	handshake = append(handshake, data...)
	total := len(handshake) - 4
	handshake[1], handshake[2], handshake[3] = byte(total>>16), byte(total>>8), byte(total)
	return handshake
}

func TestClientHelloBoundsAreEnforced(t *testing.T) {
	record := capturedClientHello(t, "bounds.example.test")
	oversizedRecord := append([]byte{22, 3, 1, byte(17000 >> 8), byte(17000 & 0xff)}, make([]byte, 17000)...)
	if _, _, err := readHelloFrom(t, oversizedRecord); err == nil {
		t.Fatal("a record above the TLS maximum must be refused")
	}
	declaredHuge := append([]byte(nil), record...)
	declaredHuge[6], declaredHuge[7], declaredHuge[8] = 0x02, 0x00, 0x00 // a 128 KiB handshake message
	if _, _, err := readHelloFrom(t, declaredHuge); err == nil {
		t.Fatal("a ClientHello above 64 KiB in total must be refused")
	}
	notHello := append([]byte(nil), record...)
	notHello[5] = 2
	if _, _, err := readHelloFrom(t, notHello); err == nil {
		t.Fatal("a handshake that is not a ClientHello must be refused")
	}
	if _, _, err := readHelloFrom(t, record[:len(record)/2]); err == nil {
		t.Fatal("a truncated ClientHello must be refused")
	}
}

func TestEachRejectionPathCountsInItsOwnSeries(t *testing.T) {
	counts := func(g *gateway) map[string]uint64 {
		result := map[string]uint64{}
		for reason, name := range rejectionReasonNames {
			if value := g.rejectedByReason[reason].Load(); value > 0 {
				result[name] = value
			}
		}
		return result
	}
	expect := func(g *gateway, name string) {
		t.Helper()
		got := counts(g)
		if len(got) != 1 || got[name] != 1 || g.rejected.Load() != 1 {
			t.Fatalf("expected exactly one %q rejection, got %v (total %d)", name, got, g.rejected.Load())
		}
	}
	listen := func(t *testing.T) net.Listener {
		listener, err := net.Listen("tcp", "127.0.0.1:0")
		if err != nil {
			t.Fatal(err)
		}
		t.Cleanup(func() { listener.Close() })
		return listener
	}
	send := func(t *testing.T, listener net.Listener, payload string) {
		connection, err := net.DialTimeout("tcp", listener.Addr().String(), 2*time.Second)
		if err != nil {
			t.Fatal(err)
		}
		defer connection.Close()
		_ = connection.SetDeadline(time.Now().Add(2 * time.Second))
		_, _ = connection.Write([]byte(payload))
		_, _ = io.ReadAll(connection)
	}
	settle := func(g *gateway) {
		waitFor(t, func() bool { return g.rejected.Load() >= 1 }, func() string { return "no rejection counted" })
		time.Sleep(50 * time.Millisecond)
	}

	t.Run("no free slot", func(t *testing.T) {
		g := &gateway{slots: make(chan struct{})}
		listener := listen(t)
		go g.accept("127.0.0.1:80", listener)
		send(t, listener, "")
		settle(g)
		expect(g, "no_slot")
	})
	t.Run("per-source limit", func(t *testing.T) {
		g := &gateway{perSource: newSourceLimiter(1)}
		g.perSource.acquire("127.0.0.1")
		listener := listen(t)
		go g.accept("127.0.0.1:80", listener)
		send(t, listener, "")
		settle(g)
		expect(g, "per_source_limit")
	})
	t.Run("invalid hello", func(t *testing.T) {
		g := &gateway{}
		listener := listen(t)
		go g.accept("127.0.0.1:443", listener)
		send(t, listener, "this is not TLS")
		settle(g)
		expect(g, "invalid_hello")
	})
	t.Run("unknown route", func(t *testing.T) {
		g := &gateway{}
		g.table.Store(&routingTable{routes: map[string]string{}})
		listener := listen(t)
		go g.accept("127.0.0.1:80", listener)
		send(t, listener, "GET / HTTP/1.1\r\nHost: nowhere.test\r\n\r\n")
		settle(g)
		expect(g, "unknown_route")
	})
	t.Run("handshake budget", func(t *testing.T) {
		upstream := listen(t)
		go func() {
			for {
				connection, err := upstream.Accept()
				if err != nil {
					return
				}
				go func() { _, _ = io.Copy(io.Discard, connection); connection.Close() }()
			}
		}()
		g := &gateway{handshakes: newHandshakeLimiter(), clock: func() time.Time { return time.Unix(9000, 0) }}
		g.table.Store(&routingTable{routes: map[string]string{"https|127.0.0.1|budget.test": upstream.Addr().String()}, tlsBudgets: map[string]int{"budget.test": 1}})
		listener := listen(t)
		go g.accept("127.0.0.1:443", listener)
		hello := string(capturedClientHello(t, "budget.test"))
		send(t, listener, hello)
		waitFor(t, func() bool { return g.accepted.Load() == 1 }, func() string { return "first hello not accepted" })
		send(t, listener, hello)
		settle(g)
		expect(g, "handshake_budget")
	})
	t.Run("gateway unavailable", func(t *testing.T) {
		g := &gateway{}
		listener := listen(t)
		go g.accept("127.0.0.1:80", listener)
		send(t, listener, "GET / HTTP/1.1\r\nHost: nowhere.test\r\n\r\n")
		settle(g)
		expect(g, "gateway_unavailable")
	})
}
