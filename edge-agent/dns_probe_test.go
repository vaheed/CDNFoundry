package main

import (
	"encoding/binary"
	"net"
	"strings"
	"testing"
)

// serveSOA answers every UDP query on [::1] with a canned response built from the query.
func serveSOA(t *testing.T, flags byte, rcode byte, answers uint16) string {
	t.Helper()
	listener, err := net.ListenPacket("udp6", "[::1]:0")
	if err != nil {
		t.Skipf("IPv6 loopback unavailable: %v", err)
	}
	t.Cleanup(func() { listener.Close() })
	go func() {
		buffer := make([]byte, 512)
		for {
			n, peer, err := listener.ReadFrom(buffer)
			if err != nil {
				return
			}
			response := append([]byte{}, buffer[:n]...)
			response[2] = 0x80 | flags
			response[3] = rcode
			binary.BigEndian.PutUint16(response[6:], answers)
			_, _ = listener.WriteTo(response, peer)
		}
	}()
	return listener.LocalAddr().String()
}

func TestProbeDNSAcceptsOnlyAnAuthoritativeAnswer(t *testing.T) {
	cases := map[string]struct {
		flags, rcode byte
		answers      uint16
		want         string
	}{
		"authoritative answer":  {0x04, 0, 1, ""},
		"not authoritative":     {0x00, 0, 1, "not_authoritative"},
		"refused":               {0x04, 5, 0, "rcode_not_noerror"},
		"authoritative, no SOA": {0x04, 0, 0, "no_soa_answer"},
	}
	for name, test := range cases {
		endpoint := serveSOA(t, test.flags, test.rcode, test.answers)
		err := probeDNS(endpoint, "ns.example.test")
		if (err == nil) != (test.want == "") || (err != nil && !strings.Contains(err.Error(), test.want)) {
			t.Errorf("%s: got %v, want %q", name, err, test.want)
		}
	}
}

func TestProbeDNSFailsWhenNothingAnswers(t *testing.T) {
	listener, err := net.ListenPacket("udp6", "[::1]:0")
	if err != nil {
		t.Skip("IPv6 loopback unavailable")
	}
	endpoint := listener.LocalAddr().String()
	listener.Close()
	if err := probeDNS(endpoint, "ns.example.test"); err == nil {
		t.Fatal("a closed port must not count as an answering nameserver")
	}
}

func TestDNSProbeRefusesNonPublicOrNonIPv6Addresses(t *testing.T) {
	for _, address := range []string{"127.0.0.1", "::1", "fe80::1", "2002:808:808::1", "8.8.8.8", "not-an-address"} {
		var task edgeTask
		task.Payload.ProbeAddress = address
		task.Payload.ProbeZone = "example.test"
		if result, status := runDNSProbe(task); status != "failed" || result["failure_reason"] != "invalid_probe_address" {
			t.Errorf("%s must be refused, got %v %s", address, result, status)
		}
	}
}
