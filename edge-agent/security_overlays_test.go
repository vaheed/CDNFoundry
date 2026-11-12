package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"
)

type overlayCell struct {
	mu       sync.Mutex
	commands []map[string]any
	restored bool
}

func (cell *overlayCell) handler() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Edge-Status-Token") != "status-secret" {
			http.NotFound(w, r)
			return
		}
		cell.mu.Lock()
		defer cell.mu.Unlock()
		if r.URL.Path == "/passive-failures" {
			_ = json.NewEncoder(w).Encode(map[string]any{"data": []any{}, "security": []any{}, "cell": map[string]any{
				"name": "cell-01", "status": "ready", "purges_restored": true, "overlays_restored": cell.restored, "capacity": map[string]any{},
			}})
			return
		}
		var command map[string]any
		_ = json.NewDecoder(io.LimitReader(r.Body, 128<<10)).Decode(&command)
		cell.commands = append(cell.commands, command)
		if command["action"] == "security_overlays_restored" {
			cell.restored = true
		}
		_, _ = w.Write([]byte(`{"data":{"accepted":true}}`))
	})
}

func overlayTask(id string, domain int64, sequence uint64, state string, limits map[string]int64) edgeTask {
	var task edgeTask
	task.ID, task.Type = id, "security_overlay"
	task.Payload.DomainID, task.Payload.OverlaySequence, task.Payload.OverlayState, task.Payload.OverlayLimits = domain, sequence, state, limits
	return task
}

func TestSecurityOverlayIsStoredAndPushedToEveryCell(t *testing.T) {
	cell := &overlayCell{}
	server := httptest.NewServer(cell.handler())
	defer server.Close()
	c := &client{http: server.Client(), statusToken: "status-secret", statusURLs: []string{server.URL + "/passive-failures"}, dir: t.TempDir()}

	result, status := c.runSecurityOverlay(overlayTask("t1", 42, 3, "restricted", map[string]int64{"requests_per_second": 20}))
	if status != "succeeded" || result["status"] != "completed" {
		t.Fatalf("overlay not applied: %s %#v", status, result)
	}
	if len(cell.commands) != 1 || cell.commands[0]["action"] != "security_overlay" || cell.commands[0]["domain_id"] != float64(42) || cell.commands[0]["sequence"] != float64(3) {
		t.Fatalf("unexpected cell command: %#v", cell.commands)
	}
	stored, _ := c.loadOverlays()
	if stored[42].State != "restricted" || stored[42].Limits["requests_per_second"] != 20 {
		t.Fatalf("overlay not persisted: %#v", stored)
	}
}

func TestAnObsoleteOverlayNeverReplacesANewerOne(t *testing.T) {
	c := &client{dir: t.TempDir()}
	if changed, err := c.persistOverlay(securityOverlay{DomainID: 7, Sequence: 5, State: "restricted"}); err != nil || !changed {
		t.Fatalf("first overlay: %v %v", changed, err)
	}
	if changed, err := c.persistOverlay(securityOverlay{DomainID: 7, Sequence: 4, State: "suspected"}); err != nil || changed {
		t.Fatalf("an older sequence must be ignored: %v %v", changed, err)
	}
	if stored, _ := c.loadOverlays(); stored[7].State != "restricted" {
		t.Fatalf("older overlay won: %#v", stored)
	}
	if changed, _ := c.persistOverlay(securityOverlay{DomainID: 7, Sequence: 6, State: "normal"}); !changed {
		t.Fatal("normal must remove the overlay")
	}
	if stored, _ := c.loadOverlays(); len(stored) != 0 {
		t.Fatalf("normal left an overlay behind: %#v", stored)
	}
}

func TestOverlayStorageIsBoundedAndInvalidOverlaysAreRefused(t *testing.T) {
	c := &client{dir: t.TempDir()}
	for i := uint64(1); i <= maximumSecurityOverlays; i++ {
		if _, err := c.persistOverlay(securityOverlay{DomainID: i, Sequence: 1, State: "suspected"}); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := c.persistOverlay(securityOverlay{DomainID: 9999, Sequence: 1, State: "suspected"}); err != errOverlayCapacity {
		t.Fatalf("a new domain beyond the bound must be refused, got %v", err)
	}
	if _, err := c.persistOverlay(securityOverlay{DomainID: 1, Sequence: 2, State: "restricted"}); err != nil {
		t.Fatalf("an existing domain can still change: %v", err)
	}
	for _, bad := range []securityOverlay{
		{DomainID: 0, Sequence: 1, State: "suspected"}, {DomainID: 1, Sequence: 0, State: "suspected"},
		{DomainID: 1, Sequence: 1, State: "banished"}, {DomainID: 1, Sequence: 1, State: "restricted", Limits: map[string]int64{"x": -1}},
	} {
		if validOverlay(bad) {
			t.Fatalf("invalid overlay accepted: %#v", bad)
		}
	}
	_, status := (&client{dir: t.TempDir()}).runSecurityOverlay(overlayTask("bad", 1, 1, "banished", nil))
	if status != "failed" {
		t.Fatal("the task with an invalid state must fail")
	}
}

func TestARestartedCellGetsTheStoredOverlaysBack(t *testing.T) {
	cell := &overlayCell{}
	server := httptest.NewServer(cell.handler())
	defer server.Close()
	c := &client{http: server.Client(), statusToken: "status-secret", statusURLs: []string{server.URL + "/passive-failures"}, dir: t.TempDir()}
	_, _ = c.persistOverlay(securityOverlay{DomainID: 11, Sequence: 2, State: "suspected", Limits: map[string]int64{"requests_per_second": 50}})
	_, _ = c.persistOverlay(securityOverlay{DomainID: 12, Sequence: 9, State: "restricted"})

	c.runtimeStatus()

	var replays, restored int
	for _, command := range cell.commands {
		switch command["action"] {
		case "security_overlay":
			replays++
		case "security_overlays_restored":
			restored++
		}
	}
	if replays != 2 || restored != 1 {
		t.Fatalf("expected 2 replayed overlays and one restored marker, got %d and %d: %#v", replays, restored, cell.commands)
	}
	cell.commands = nil
	c.runtimeStatus()
	if len(cell.commands) != 0 {
		t.Fatalf("a restored cell must not be sent anything again: %#v", cell.commands)
	}
}
