package main

import (
	"crypto/tls"
	"crypto/x509"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"strconv"
	"strings"
	"sync/atomic"
	"testing"
)

type controlNodeFake struct {
	server   *httptest.Server
	requests atomic.Int64
}

// twoControlNodes starts two TLS servers on the same port, on 127.0.0.1 and 127.0.0.2, so one
// hostname and port reach two different "nodes" depending on the dialed address.
func twoControlNodes(t *testing.T, first, second http.HandlerFunc) (*controlNodeFake, *controlNodeFake, *client) {
	t.Helper()
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	port := listener.Addr().(*net.TCPAddr).Port
	other, err := net.Listen("tcp", net.JoinHostPort("127.0.0.2", strconv.Itoa(port)))
	if err != nil {
		t.Skip("127.0.0.2 is not available:", err)
	}
	nodes := make([]*controlNodeFake, 2)
	for i, spec := range []struct {
		listener net.Listener
		handler  http.HandlerFunc
	}{{listener, first}, {other, second}} {
		node := &controlNodeFake{}
		handler := spec.handler
		node.server = httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			node.requests.Add(1)
			w.Header().Set("Content-Type", "application/json")
			handler(w, r)
		}))
		node.server.Listener = spec.listener
		node.server.StartTLS()
		t.Cleanup(node.server.Close)
		nodes[i] = node
	}
	roots := x509.NewCertPool()
	roots.AddCert(nodes[0].server.Certificate())
	transport := &http.Transport{TLSClientConfig: &tls.Config{RootCAs: roots, MinVersion: tls.VersionTLS12}, DialContext: dialControl(&net.Dialer{Timeout: controlDialTimeout})}
	c := &client{base: "https://127.0.0.1:" + strconv.Itoa(port), dir: t.TempDir(), http: &http.Client{Transport: transport}}
	c.controlRoute.Addresses = []string{"127.0.0.1", "127.0.0.2"}
	c.controlLoaded = true
	return nodes[0], nodes[1], c
}

func active(epoch string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set(controlStateHeader, "active")
		w.Header().Set(controlEpochHeader, epoch)
		_, _ = w.Write([]byte(`{"ok":true}`))
	}
}

func standby(w http.ResponseWriter, r *http.Request) {
	w.Header().Set(controlStateHeader, controlStateStandby)
	http.Error(w, `{"code":"control_standby"}`, http.StatusServiceUnavailable)
}

func TestAgentMovesToTheNextAddressOnAStandbyAndRemembersTheOneThatWorked(t *testing.T) {
	first, second, c := twoControlNodes(t, standby, active("2"))
	var out map[string]any
	if err := c.request("GET", "/edge/v1/tasks", nil, &out, true); err != nil {
		t.Fatal(err)
	}
	if c.controlRoute.LastGood != "127.0.0.2" || first.requests.Load() != 1 || second.requests.Load() != 1 {
		t.Fatalf("last good %q, requests %d/%d", c.controlRoute.LastGood, first.requests.Load(), second.requests.Load())
	}
	// The next request starts at the address that worked, and a restart remembers it.
	if err := c.request("GET", "/edge/v1/tasks", nil, &out, true); err != nil || first.requests.Load() != 1 {
		t.Fatalf("the working address must be tried first: %v, standby requests %d", err, first.requests.Load())
	}
	restarted := &client{dir: c.dir}
	if candidates := restarted.controlCandidates(); candidates[0] != "127.0.0.2" {
		t.Fatalf("candidates after restart: %v", candidates)
	}
}

func TestAgentFailsOverOnServerErrorsAndRefusedConnectionsButNotOnClientErrors(t *testing.T) {
	_, second, c := twoControlNodes(t,
		func(w http.ResponseWriter, r *http.Request) { http.Error(w, "boom", http.StatusBadGateway) },
		func(w http.ResponseWriter, r *http.Request) {
			if r.URL.Path == "/refuse" {
				w.Header().Set(controlEpochHeader, "1")
				http.Error(w, `{"code":"nope"}`, http.StatusUnprocessableEntity)
				return
			}
			active("1")(w, r)
		})
	var out map[string]any
	if err := c.request("GET", "/edge/v1/tasks", nil, &out, true); err != nil {
		t.Fatalf("a 502 must move on: %v", err)
	}
	err := c.request("GET", "/refuse", nil, &out, true)
	var status *httpStatusError
	if err == nil || !asStatus(err, &status) || status.Code != 422 {
		t.Fatalf("a 422 is an answer, not an address failure: %v", err)
	}
	if second.requests.Load() != 2 {
		t.Fatalf("the refusal must not rotate: %d", second.requests.Load())
	}
	second.server.Close() // 127.0.0.2 now refuses connections, and 127.0.0.1 answers 502
	if err := c.request("GET", "/edge/v1/tasks", nil, &out, true); err == nil {
		t.Fatal("with every address failing the request must fail")
	}
}

func asStatus(err error, target **httpStatusError) bool {
	s, ok := err.(*httpStatusError)
	if ok {
		*target = s
	}
	return ok
}

func TestAResponseFromALowerEpochIsIgnored(t *testing.T) {
	stale, current, c := twoControlNodes(t, active("4"), active("6"))
	c.controlRoute.Epoch = 5
	var out map[string]any
	if err := c.request("GET", "/edge/v1/tasks", nil, &out, true); err != nil {
		t.Fatal(err)
	}
	if c.controlRoute.Epoch != 6 || c.controlRoute.LastGood != "127.0.0.2" || stale.requests.Load() != 1 || current.requests.Load() != 1 {
		t.Fatalf("epoch %d, last good %q", c.controlRoute.Epoch, c.controlRoute.LastGood)
	}
	// Only the old node is left: it must never be believed again.
	current.server.Close()
	if err := c.request("GET", "/edge/v1/tasks", nil, &out, true); err == nil {
		t.Fatal("a node with a lower epoch than one already accepted must be ignored")
	}
	// The highest accepted epoch is durable.
	stored, err := os.ReadFile(c.controlStatePath())
	if err != nil || !contains(string(stored), `"epoch":6`) {
		t.Fatalf("stored state: %s %v", stored, err)
	}
}

func contains(haystack, needle string) bool { return strings.Contains(haystack, needle) }

func TestControlAddressesAreValidated(t *testing.T) {
	if got, err := parseControlAddresses([]string{"203.0.113.10", "2001:db8::10"}); err != nil || len(got) != 2 {
		t.Fatalf("%v %v", got, err)
	}
	for _, bad := range [][]string{{"example.com"}, {"203.0.113.1", "203.0.113.1"}, {"203.0.113.1", "203.0.113.2", "203.0.113.3"}, {""}} {
		if _, err := parseControlAddresses(bad); err == nil {
			t.Fatalf("%v must be refused", bad)
		}
	}
}

func TestSignedAddressListReplacesTheSeedAndSurvivesATamperedReply(t *testing.T) {
	control := newFakeControl(t)
	mode := "ok"
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if mode == "tampered" {
			_, _ = w.Write([]byte(`{"encoded_payload":"e30=","checksum":"00","signature":"00","expires_at":1,"delivery_signature":"00"}`))
			return
		}
		serveSignedDocument(w, r, control.private, controlAddressesScope, `{"addresses":["203.0.113.10","203.0.113.11"]}`)
	}))
	defer server.Close()
	c := &client{base: server.URL, dir: t.TempDir(), http: server.Client(), id: identity{PublicKey: control.public}, seedAddresses: []string{"203.0.113.99"}}
	c.syncControlAddresses()
	if len(c.controlRoute.Addresses) != 2 || c.controlRoute.Addresses[0] != "203.0.113.10" || c.controlRoute.Checksum == "" {
		t.Fatalf("list not applied: %+v", c.controlRoute)
	}
	held := c.controlRoute
	c.controlRoute.Checksum = "stale"
	mode = "tampered"
	c.syncControlAddresses()
	if len(c.controlRoute.Addresses) != 2 || c.controlRoute.Addresses[0] != held.Addresses[0] {
		t.Fatalf("a tampered document must keep the held list: %+v", c.controlRoute)
	}
}

// The agent dials an IPv6 control address under the same hostname, and fails over from one family to the other.
func TestAgentDialsIPv6ControlAddressesUnderTheHostname(t *testing.T) {
	listener, err := net.Listen("tcp6", "[::1]:0")
	if err != nil {
		t.Skip("IPv6 loopback is not available:", err)
	}
	server := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set(controlEpochHeader, "4")
		_, _ = w.Write([]byte(`{"ok":true}`))
	}))
	server.Listener = listener
	server.StartTLS()
	defer server.Close()
	roots := x509.NewCertPool()
	roots.AddCert(server.Certificate())
	transport := &http.Transport{TLSClientConfig: &tls.Config{RootCAs: roots, MinVersion: tls.VersionTLS12}, DialContext: dialControl(&net.Dialer{Timeout: controlDialTimeout})}
	port := strconv.Itoa(listener.Addr().(*net.TCPAddr).Port)
	// The hostname resolves nowhere useful; only the held addresses can reach the server. The first is a dead IPv4 address.
	c := &client{base: "https://example.com:" + port, dir: t.TempDir(), http: &http.Client{Transport: transport}}
	c.controlRoute.Addresses = []string{"127.0.0.2", "::1"}
	c.controlLoaded = true

	var out map[string]any
	if err := c.request("GET", "/edge/v1/tasks", nil, &out, true); err != nil {
		t.Fatal(err)
	}
	if c.controlRoute.LastGood != "::1" || c.controlRoute.Epoch != 4 {
		t.Fatalf("last good %q epoch %d", c.controlRoute.LastGood, c.controlRoute.Epoch)
	}
}
