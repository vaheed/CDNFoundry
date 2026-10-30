package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestPersistedPurgesAreReplayedToACellThatReportsItWasNotRestored(t *testing.T) {
	var mu sync.Mutex
	var commands []map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var command map[string]any
		if json.NewDecoder(io.LimitReader(r.Body, 128<<10)).Decode(&command) != nil {
			http.Error(w, "bad", http.StatusBadRequest)
			return
		}
		mu.Lock()
		commands = append(commands, command)
		mu.Unlock()
		_, _ = w.Write([]byte(`{"data":{"accepted":true}}`))
	}))
	defer server.Close()
	c := &client{http: server.Client(), statusToken: "status-secret", dir: t.TempDir()}
	keys := make([]string, 230)
	for i := range keys {
		keys[i] = "https|example.test|/k" + string(rune('a'+i%26)) + strings.Repeat("x", i)
	}
	if err := c.persistPurge(persistedPurge{PurgeID: 7, Domain: "example.test", Type: "urls", Epoch: 1, Keys: keys, At: time.Now().Unix()}); err != nil {
		t.Fatal(err)
	}
	if err := c.persistPurge(persistedPurge{PurgeID: 7, Domain: "example.test", Type: "urls", Epoch: 1, Keys: keys, At: time.Now().Unix()}); err != nil {
		t.Fatalf("persisting the same purge twice must be idempotent: %v", err)
	}
	if err := c.persistPurge(persistedPurge{PurgeID: 9, Domain: "example.test", Type: "all", Epoch: 3, At: time.Now().Unix()}); err != nil {
		t.Fatal(err)
	}

	c.restorePurges(server.URL + "/passive-failures")

	// 230 keys in batches of 100, one full purge, and the final restored marker.
	if len(commands) != 3+1+1 {
		t.Fatalf("unexpected number of control commands: %d", len(commands))
	}
	for _, command := range commands[:3] {
		if command["action"] != "cache_purge" || command["purge_id"] != float64(7) || len(command["cache_keys"].([]any)) > purgeRestoreBatchSize {
			t.Fatalf("invalid replayed batch: %#v", command)
		}
	}
	if commands[3]["type"] != "all" || commands[3]["purge_id"] != float64(9) {
		t.Fatalf("the full purge was not replayed in order: %#v", commands[3])
	}
	if commands[4]["action"] != "cache_purges_restored" {
		t.Fatalf("the cell was not marked restored: %#v", commands[4])
	}
}

func TestPurgeStateIsBoundedAndExpires(t *testing.T) {
	c := &client{dir: t.TempDir()}
	old := time.Now().Add(-purgeRetention - time.Hour).Unix()
	if err := c.persistPurge(persistedPurge{PurgeID: 1, Domain: "a.example", Type: "urls", Keys: []string{"k"}, At: old}); err != nil {
		t.Fatal(err)
	}
	full := make([]string, maximumPurgeKeys)
	if err := c.persistPurge(persistedPurge{PurgeID: 2, Domain: "a.example", Type: "urls", Keys: full, At: time.Now().Unix()}); err != nil {
		t.Fatalf("expired purges must not count against the bound: %v", err)
	}
	records, _ := c.loadPurges()
	if len(records) != 1 || records[0].PurgeID != 2 {
		t.Fatalf("the expired purge was kept: %#v", records)
	}
	if err := c.persistPurge(persistedPurge{PurgeID: 3, Domain: "a.example", Type: "urls", Keys: []string{"one-more"}, At: time.Now().Unix()}); err != errPurgeCapacity {
		t.Fatalf("exceeding the bound must be refused, got %v", err)
	}
}
