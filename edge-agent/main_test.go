package main

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
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
	fullPayload, fullChecksum, fullSignature := signedJSON(t, private, map[string]any{
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
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Host != "origin.example" {
			t.Errorf("unexpected origin host: %s", r.Host)
		}
		w.WriteHeader(http.StatusNoContent)
	}))
	defer server.Close()
	address := strings.TrimPrefix(server.URL, "http://")
	host, port, err := net.SplitHostPort(address)
	if err != nil {
		t.Fatal(err)
	}
	portNumber, _ := strconv.Atoi(port)
	task := edgeTask{}
	task.Payload.Addresses = []string{host}
	task.Payload.Allowlist = []string{"127.0.0.0/8"}
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

func TestPassiveFailuresAreBoundedAndAuthenticated(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Edge-Status-Token") != "status-secret" {
			http.NotFound(w, r)
			return
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"data": []map[string]any{{
			"domain": "example.test", "hostname": "www.example.test", "failure_count": 2,
			"last_status": 502, "last_failed_at": 123,
		}}})
	}))
	defer server.Close()
	c := &client{http: server.Client(), statusToken: "status-secret", statusURLs: []string{server.URL}}
	failures := c.passiveFailures()
	if len(failures) != 1 || failures[0]["hostname"] != "www.example.test" {
		t.Fatalf("passive failures were not collected: %#v", failures)
	}
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
