package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
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
	c := &client{base: server.URL, dir: t.TempDir(), http: server.Client(), id: identity{Token: "test"}}
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
	c := &client{dir: dir}
	first := state{Sequence: 4, Domains: map[string]json.RawMessage{"1": json.RawMessage(`{"revision":4}`)}}
	second := state{Sequence: 5, Domains: map[string]json.RawMessage{"1": json.RawMessage(`{"revision":5}`)}}
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
}
