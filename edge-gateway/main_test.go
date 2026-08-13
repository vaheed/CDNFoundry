package main

import (
	"crypto/tls"
	"encoding/binary"
	"encoding/json"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestLoadInitialWaitsForTheFirstCandidate(t *testing.T) {
	stateDir := t.TempDir()
	g := &gateway{
		configPath: filepath.Join(stateDir, "missing-candidate.json"),
		stateDir:   stateDir,
		listeners:  map[string]net.Listener{},
	}

	if err := g.loadInitial(); err != nil {
		t.Fatal(err)
	}
	table := g.table.Load()
	if table == nil || table.revision != 0 || table.generationID != "bootstrap-unconfigured" || len(table.routes) != 0 {
		t.Fatalf("unexpected bootstrap routing table: %#v", table)
	}
	if g.ready.Load() {
		t.Fatal("gateway became ready before its first valid candidate")
	}
	if _, err := os.Stat(filepath.Join(stateDir, "last-valid.json")); !os.IsNotExist(err) {
		t.Fatalf("bootstrap wait unexpectedly created last-valid state: %v", err)
	}
}

func TestLoadInitialFallsBackFromAnInvalidCandidate(t *testing.T) {
	stateDir := t.TempDir()
	configPath := filepath.Join(stateDir, "candidate.json")
	if err := os.WriteFile(configPath, []byte(`{"schema_version":1,"listeners":["192.0.2.10:80"]}`), 0600); err != nil {
		t.Fatal(err)
	}
	lastValid, err := json.Marshal(config{
		SchemaVersion: 1, Revision: 4, GenerationID: "00000000000000000004-test",
		Listeners: []string{"192.0.2.10:80"},
		Routes:    []route{{Address: "192.0.2.10", Hostname: "www.example.test", HTTP: "cell-a:8081"}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(stateDir, "last-valid.json"), lastValid, 0600); err != nil {
		t.Fatal(err)
	}
	g := &gateway{
		configPath: configPath,
		stateDir:   stateDir,
		listeners:  map[string]net.Listener{},
		listen: func(_, _ string) (net.Listener, error) {
			return net.Listen("tcp4", "127.0.0.1:0")
		},
	}
	defer g.close()

	if err := g.loadInitial(); err != nil {
		t.Fatal(err)
	}
	if table := g.table.Load(); table == nil || table.revision != 4 || !g.ready.Load() {
		t.Fatalf("last-valid routing table was not restored: %#v", table)
	}
}

func TestValidateAcceptsBoundedDualStackRoutes(t *testing.T) {
	candidate := config{
		SchemaVersion: 1,
		Revision:      7,
		Listeners:     []string{"192.0.2.10:80", "[2001:db8::10]:443"},
		Routes: []route{{
			Address: "192.0.2.10", Hostname: "www.example.test",
			HTTP: "cell-a:8081", HTTPS: "cell-a:8444",
		}},
	}
	table, err := validate(candidate)
	if err != nil {
		t.Fatal(err)
	}
	if table.revision != 7 || table.routes["http|192.0.2.10|www.example.test"] != "cell-a:8081" {
		t.Fatalf("unexpected routing table: %#v", table)
	}
}

func TestValidateAcceptsAnEmptyFailClosedCandidate(t *testing.T) {
	table, err := validate(config{SchemaVersion: 1, Revision: 0})
	if err != nil {
		t.Fatal(err)
	}
	if table.revision != 0 || len(table.routes) != 0 || table.generationID != "legacy-00000000000000000000" {
		t.Fatalf("unexpected empty routing table: %#v", table)
	}
}

func TestValidateRejectsDuplicateAndInvalidCandidates(t *testing.T) {
	base := config{
		SchemaVersion: 1, Revision: 1, Listeners: []string{"192.0.2.10:80"},
		Routes: []route{{Address: "192.0.2.10", Hostname: "www.example.test", HTTP: "cell-a:8081"}},
	}
	tests := []config{
		{SchemaVersion: 2, Revision: 1, Listeners: base.Listeners, Routes: base.Routes},
		{SchemaVersion: 1, Revision: 1, Listeners: base.Listeners},
		{SchemaVersion: 1, Revision: 1, Routes: base.Routes},
		{SchemaVersion: 1, Revision: 1, Listeners: []string{"0.0.0.0:8080"}, Routes: base.Routes},
		{SchemaVersion: 1, Revision: 1, Listeners: base.Listeners, Routes: append(base.Routes, base.Routes[0])},
		{SchemaVersion: 1, Revision: 1, Listeners: base.Listeners, Routes: []route{{Address: "192.0.2.10", Hostname: "bad_name", HTTP: "cell-a:8081"}}},
	}
	for index, candidate := range tests {
		if _, err := validate(candidate); err == nil {
			t.Fatalf("candidate %d unexpectedly passed", index)
		}
	}
}

func TestReadHTTPRequiresOneCanonicalHost(t *testing.T) {
	for _, request := range []string{
		"GET / HTTP/1.1\r\n\r\n",
		"GET / HTTP/1.1\r\nHost: good.example\r\nHost: evil.example\r\n\r\n",
		"GET / HTTP/1.1\r\nHost: bad_name\r\n\r\n",
	} {
		server, client := net.Pipe()
		go func(value string) { _, _ = client.Write([]byte(value)); _ = client.Close() }(request)
		if _, _, err := readHTTP(server); err == nil {
			t.Fatalf("invalid request passed: %q", request)
		}
		_ = server.Close()
	}
	server, client := net.Pipe()
	go func() {
		_, _ = client.Write([]byte("GET / HTTP/1.1\r\nHost: WWW.Example.Test:80\r\n\r\n"))
		_ = client.Close()
	}()
	host, prefix, err := readHTTP(server)
	if err != nil || host != "www.example.test" || !strings.Contains(string(prefix), "Host: WWW.Example.Test:80") {
		t.Fatalf("valid request failed: host=%q err=%v", host, err)
	}
}

func TestClientHelloSNI(t *testing.T) {
	server, client := net.Pipe()
	result := make(chan []byte, 1)
	go func() {
		header := make([]byte, 5)
		_, _ = server.Read(header)
		size := int(binary.BigEndian.Uint16(header[3:5]))
		body := make([]byte, size)
		offset := 0
		for offset < size {
			n, _ := server.Read(body[offset:])
			offset += n
		}
		result <- body
		_ = server.Close()
	}()
	tlsClient := tls.Client(client, &tls.Config{ServerName: "Route.Example.Test", MinVersion: tls.VersionTLS12})
	_ = tlsClient.Handshake()
	body := <-result
	name, err := clientHelloSNI(body)
	if err != nil || name != "route.example.test" {
		t.Fatalf("unexpected SNI: %q, %v", name, err)
	}
}

func TestProxyProtocolPreservesIPv4AndIPv6Identity(t *testing.T) {
	for _, item := range []struct {
		source, destination string
		family              byte
		length              int
	}{
		{"198.51.100.2:1234", "192.0.2.10:80", 0x11, 28},
		{"[2001:db8::2]:1234", "[2001:db8::10]:443", 0x21, 52},
	} {
		source, _ := net.ResolveTCPAddr("tcp", item.source)
		destination, _ := net.ResolveTCPAddr("tcp", item.destination)
		header := proxyProtocolHeader(source, destination)
		if len(header) != item.length || header[13] != item.family {
			t.Fatalf("unexpected PROXY protocol header for %s: %x", item.source, header)
		}
	}
}
