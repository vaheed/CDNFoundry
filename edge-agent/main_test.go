package main

import (
	"bytes"
	"compress/gzip"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
)

func TestVerifyAndCompatibility(t *testing.T) {
	public, private, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	payload := []byte(`{"domain":"example.test","revision":2}`)
	sum := sha256.Sum256(payload)
	checksum := hex.EncodeToString(sum[:])
	signature := ed25519.Sign(private, []byte(checksum))
	got, err := verify(base64.StdEncoding.EncodeToString(payload), checksum, hex.EncodeToString(signature), hex.EncodeToString(public))
	if err != nil || string(got) != string(payload) {
		t.Fatalf("verification failed: %v", err)
	}
	if _, err := verify(base64.StdEncoding.EncodeToString(append(payload, 'x')), checksum, hex.EncodeToString(signature), hex.EncodeToString(public)); err == nil {
		t.Fatal("tampered payload accepted")
	}
	if !compatible("1.0.0", "1.99.99") || compatible("1.1.0", "1.99.99") {
		t.Fatal("compatibility bounds are incorrect")
	}
}

func TestAcknowledgementBufferRetriesAfterRecovery(t *testing.T) {
	failing := true
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if failing {
			http.Error(w, "offline", http.StatusServiceUnavailable)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data":{"accepted":true}}`))
	}))
	defer server.Close()
	c := &client{base: server.URL, dir: t.TempDir(), http: server.Client()}
	c.queueAck(ack{Sequence: 7})
	if err := c.flushAcks(); err == nil {
		t.Fatal("offline acknowledgement unexpectedly succeeded")
	}
	if _, err := os.Stat(filepath.Join(c.dir, "acks.json")); err != nil {
		t.Fatal("acknowledgement was not persisted")
	}
	failing = false
	if err := c.flushAcks(); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(c.dir, "acks.json")); !errors.Is(err, os.ErrNotExist) {
		t.Fatal("acknowledgement buffer was not cleared")
	}
}

func TestActivationPreservesPreviousAndRestartState(t *testing.T) {
	dir := t.TempDir()
	c := &client{dir: dir, runtimeDir: filepath.Join(dir, "runtime")}
	first := state{Sequence: 4, Domains: map[string]json.RawMessage{"1": runtimeDomain(4)}}
	second := state{Sequence: 5, Domains: map[string]json.RawMessage{"1": runtimeDomain(5)}}
	if err := c.activate(first); err != nil {
		t.Fatal(err)
	}
	if err := c.activate(second); err != nil {
		t.Fatal(err)
	}
	active, err := loadState(filepath.Join(dir, "active", "state.json"))
	if err != nil || active.Sequence != 5 {
		t.Fatalf("active state not restart-safe: %v", err)
	}
	previous, err := loadState(filepath.Join(dir, "previous", "state.json"))
	if err != nil || previous.Sequence != 4 {
		t.Fatalf("previous state not preserved: %v", err)
	}
	if err := c.activate(state{Sequence: 6}); err == nil {
		t.Fatal("invalid candidate activated")
	}
	active, _ = loadState(filepath.Join(dir, "active", "state.json"))
	if active.Sequence != 5 {
		t.Fatal("invalid candidate replaced active state")
	}
	var poolRuntime struct {
		Hosts map[string]any `json:"hosts"`
	}
	poolBytes, err := os.ReadFile(filepath.Join(dir, "runtime", "shared-default.json"))
	if err != nil || json.Unmarshal(poolBytes, &poolRuntime) != nil || poolRuntime.Hosts["www.example.test"] == nil {
		t.Fatal("placement-aware pool runtime was not published")
	}
}

func TestFreshFullSnapshotThenIncrementalArtifact(t *testing.T) {
	public, private, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	publicHex := hex.EncodeToString(public)
	fullPayload, fullChecksum, fullSignature := signedGzipJSON(t, private, map[string]any{
		"schema_version": 1, "minimum_agent_version": "1.0.0", "maximum_agent_version": "1.99.0",
		"artifacts": []map[string]any{{"sequence": 4, "domain_id": 1, "kind": "domain", "payload": json.RawMessage(runtimeDomain(4))}},
	})
	incrementalPayload := []byte(runtimeDomain(5))
	incrementalChecksum := sha256.Sum256(incrementalPayload)
	incrementalChecksumHex := hex.EncodeToString(incrementalChecksum[:])
	incrementalSignature := hex.EncodeToString(ed25519.Sign(private, []byte(incrementalChecksumHex)))
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch {
		case r.URL.Path == "/edge/v1/config/full":
			_ = json.NewEncoder(w).Encode(map[string]any{"encoded_snapshot": fullPayload, "checksum": fullChecksum, "signature": fullSignature, "signing_public_key": publicHex})
		case r.URL.Path == "/edge/v1/config/manifest":
			_ = json.NewEncoder(w).Encode(map[string]any{"data": []map[string]any{{
				"sequence": 5, "kind": "domain", "domain_id": 1, "checksum": incrementalChecksumHex,
				"signature": incrementalSignature, "schema_version": 1,
				"minimum_agent_version": "1.0.0", "maximum_agent_version": "1.99.0",
			}}})
		case strings.HasPrefix(r.URL.Path, "/edge/v1/config/artifacts/"):
			_ = json.NewEncoder(w).Encode(map[string]any{"encoded_payload": base64.StdEncoding.EncodeToString(incrementalPayload)})
		default:
			_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"accepted": true}})
		}
	}))
	defer server.Close()
	dir := t.TempDir()
	c := &client{base: server.URL, dir: dir, runtimeDir: filepath.Join(dir, "runtime"), http: server.Client(), id: identity{PublicKey: publicHex}}
	if err := c.full(); err != nil {
		t.Fatal(err)
	}
	active, err := loadState(filepath.Join(dir, "active", "state.json"))
	if err != nil || active.Sequence != 4 || len(active.Domains) != 1 {
		t.Fatalf("fresh full snapshot was not activated: %#v, %v", active, err)
	}
	if err := c.sync(); err != nil {
		t.Fatal(err)
	}
	active, err = loadState(filepath.Join(dir, "active", "state.json"))
	if err != nil || active.Sequence != 5 || !bytes.Contains(active.Domains["1"], []byte(`"revision":5`)) {
		t.Fatalf("incremental artifact was not activated: %#v, %v", active, err)
	}
}

func TestOriginTaskUsesApprovedAddressAndCanonicalHost(t *testing.T) {
	listener, err := net.Listen("tcp4", "0.0.0.0:0")
	if err != nil {
		t.Fatal(err)
	}
	server := &httptest.Server{Listener: listener, Config: &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Host != "origin.example" {
			t.Errorf("unexpected origin host: %s", r.Host)
		}
		w.WriteHeader(http.StatusNoContent)
	})}}
	server.Start()
	defer server.Close()
	_, port, err := net.SplitHostPort(listener.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	host := privateInterfaceAddress(t)
	portNumber, _ := strconv.Atoi(port)
	task := edgeTask{}
	task.Payload.Addresses = []string{host}
	task.Payload.Allowlist = []string{host + "/32"}
	task.Payload.Origin.Host = "ignored.example"
	task.Payload.Origin.Scheme = "http"
	task.Payload.Origin.HostHeader = "origin.example"
	task.Payload.Origin.Port = portNumber
	task.Payload.Origin.ConnectTimeoutMS = 1000
	task.Payload.Origin.ResponseTimeoutMS = 1000
	result := runOriginTest(task)
	if result["status"] != "healthy" || result["resolved_address"] != host {
		t.Fatalf("origin task failed: %#v", result)
	}
}

func TestOriginTaskNeverAllowsLoopbackThroughPrivateAllowlist(t *testing.T) {
	task := edgeTask{}
	task.Payload.Addresses = []string{"127.0.0.1", "::ffff:127.0.0.1"}
	task.Payload.Allowlist = []string{"127.0.0.0/8", "::/0"}
	task.Payload.Origin.Scheme = "http"
	task.Payload.Origin.HostHeader = "origin.example"
	task.Payload.Origin.Port = 80
	result := runOriginTest(task)
	if result["failure_reason"] != "blocked_destination" {
		t.Fatalf("loopback allowlist bypassed destination safety: %#v", result)
	}
}

func TestOriginTaskAppliesPostgresqlBackedBlockedNetworks(t *testing.T) {
	task := edgeTask{}
	task.Payload.Addresses = []string{"203.0.113.10"}
	task.Payload.BlockedNetworks = []string{"203.0.113.0/24"}
	task.Payload.Origin.Scheme = "http"
	task.Payload.Origin.HostHeader = "origin.example"
	task.Payload.Origin.Port = 80
	result := runOriginTest(task)
	if result["failure_reason"] != "blocked_destination" {
		t.Fatalf("configured blocked network was ignored: %#v", result)
	}
}

func privateInterfaceAddress(t *testing.T) string {
	t.Helper()
	addresses, err := net.InterfaceAddrs()
	if err != nil {
		t.Fatal(err)
	}
	for _, address := range addresses {
		ip, _, err := net.ParseCIDR(address.String())
		if err == nil && ip.To4() != nil && !ip.IsLoopback() && ip.IsPrivate() {
			return ip.String()
		}
	}
	t.Fatal("no private non-loopback IPv4 interface is available")
	return ""
}

func TestPassiveFailuresAreBoundedAndAuthenticated(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Edge-Status-Token") != "status-secret" {
			http.NotFound(w, r)
			return
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"data": []map[string]any{{
			"domain": "example.test", "hostname": "www.example.test", "failure_count": 2,
			"last_status": 502, "last_failed_at": 123,
		}}, "cell": map[string]any{"name": "shared-default", "status": "ready", "capacity": map[string]any{}}})
	}))
	defer server.Close()
	c := &client{http: server.Client(), statusToken: "status-secret", statusURLs: []string{server.URL}}
	cells, failures := c.runtimeStatus()
	if len(failures) != 1 || failures[0]["hostname"] != "www.example.test" {
		t.Fatalf("passive failures were not collected: %#v", failures)
	}
	if len(cells) != 1 || cells[0]["name"] != "shared-default" {
		t.Fatalf("cell status was not collected: %#v", cells)
	}
}

func TestCellControlTaskUsesAuthenticatedBoundedSupervisorEndpoint(t *testing.T) {
	controlCalls := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Edge-Status-Token") != "status-secret" {
			http.NotFound(w, r)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Path == "/passive-failures" {
			_ = json.NewEncoder(w).Encode(map[string]any{"data": []any{}, "cell": map[string]any{"name": "quarantine-default", "status": "ready", "capacity": map[string]any{}}})
			return
		}
		if r.URL.Path != "/control" || r.Method != http.MethodPost {
			http.NotFound(w, r)
			return
		}
		controlCalls++
		var command map[string]string
		_ = json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&command)
		if command["action"] != "drain" || command["task_id"] != "task-1" {
			http.Error(w, "invalid command", http.StatusBadRequest)
			return
		}
		_, _ = w.Write([]byte(`{"data":{"accepted":true}}`))
	}))
	defer server.Close()
	c := &client{dir: t.TempDir(), http: server.Client(), statusToken: "status-secret", statusURLs: []string{server.URL + "/passive-failures"}}
	var task edgeTask
	task.ID = "task-1"
	task.Type = "cell_drain"
	task.Payload.CellName = "quarantine-default"
	result, status := c.runCellTask(task)
	if status != "succeeded" || result["status"] != "completed" || controlCalls != 1 {
		t.Fatalf("cell control task failed: status=%s result=%#v calls=%d", status, result, controlCalls)
	}
	controls, err := c.loadCellControls()
	if err != nil || !controls["quarantine-default"] {
		t.Fatalf("desired drain state was not persisted: controls=%#v error=%v", controls, err)
	}
}

func signedGzipJSON(t *testing.T, private ed25519.PrivateKey, value any) (string, string, string) {
	t.Helper()
	payload, err := json.Marshal(value)
	if err != nil {
		t.Fatal(err)
	}
	var compressed bytes.Buffer
	writer := gzip.NewWriter(&compressed)
	if _, err = writer.Write(payload); err != nil {
		t.Fatal(err)
	}
	if err = writer.Close(); err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256(compressed.Bytes())
	checksum := hex.EncodeToString(sum[:])
	return base64.StdEncoding.EncodeToString(compressed.Bytes()), checksum, hex.EncodeToString(ed25519.Sign(private, []byte(checksum)))
}

func signedJSON(t *testing.T, private ed25519.PrivateKey, value any) (string, string, string) {
	t.Helper()
	payload, err := json.Marshal(value)
	if err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256(payload)
	checksum := hex.EncodeToString(sum[:])
	return base64.StdEncoding.EncodeToString(payload), checksum, hex.EncodeToString(ed25519.Sign(private, []byte(checksum)))
}

func runtimeDomain(revision int) json.RawMessage {
	return json.RawMessage(`{"domain":"example.test","revision":` + strconv.Itoa(revision) + `,"pools":["shared-default"],"settings":{"enabled":true},"hostnames":[{"hostname":"www.example.test","origin":{"host":"origin.example"}}]}`)
}
