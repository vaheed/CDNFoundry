package main

import (
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"strconv"
	"testing"
)

func TestProbeListenerNeedsBothListeners(t *testing.T) {
	open := func() (net.Listener, int) {
		listener, err := net.Listen("tcp", "127.0.0.1:0")
		if err != nil {
			t.Fatal(err)
		}
		go func() {
			for {
				connection, err := listener.Accept()
				if err != nil {
					return
				}
				_ = connection.Close()
			}
		}()
		return listener, listener.Addr().(*net.TCPAddr).Port
	}
	first, firstPort := open()
	defer first.Close()
	second, secondPort := open()
	if ok, _ := probeListener("127.0.0.1", firstPort, secondPort); !ok {
		t.Fatal("two live listeners must pass")
	}
	_ = second.Close()
	if ok, detail := probeListener("127.0.0.1", firstPort, secondPort); ok || detail == "" {
		t.Fatalf("a closed TLS listener must fail with a reason, got ok=%v detail=%q", ok, detail)
	}
}

func TestProbeRoundReportsEveryTarget(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	go func() {
		for {
			connection, err := listener.Accept()
			if err != nil {
				return
			}
			_ = connection.Close()
		}
	}()
	port := listener.Addr().(*net.TCPAddr).Port
	var reported []probeResult
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.Method == http.MethodGet {
			_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{
				"targets": []probeTarget{{EdgeID: "alive", Address: "127.0.0.1"}, {EdgeID: "dead", Address: "127.0.0.2"}},
				"ports":   map[string]int{"http": port, "tls": port},
			}})
			return
		}
		var body struct {
			Results []probeResult `json:"results"`
		}
		_ = json.NewDecoder(r.Body).Decode(&body)
		reported = body.Results
		_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"accepted": len(body.Results)}})
	}))
	defer server.Close()
	c := &client{base: server.URL, dir: t.TempDir(), http: server.Client()}

	if err := c.runProbes(); err != nil {
		t.Fatal(err)
	}

	if len(reported) != 2 || !reported[0].OK || reported[1].OK {
		t.Fatalf("unexpected probe report %v (port %s)", reported, strconv.Itoa(port))
	}
}
