package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
)

func policyServer(t *testing.T, control *fakeControl, behave func(w http.ResponseWriter, r *http.Request) bool) *httptest.Server {
	return httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if behave != nil && behave(w, r) {
			return
		}
		serveOriginPolicy(w, r, control.private, testOriginPolicy)
	}))
}

func TestApplyOriginPolicyReachesEveryOriginAndItsBackup(t *testing.T) {
	runtime := map[string]any{"hosts": map[string]any{
		"a.test": map[string]any{"origin": map[string]any{"host": "origin.example", "backup": map[string]any{"host": "backup.example"}}},
		"b.test": map[string]any{"origin": map[string]any{"host": "other.example"}},
	}}
	applyOriginPolicy(runtime, originPolicy{BlockedAddresses: []string{"192.0.2.55"}})

	a := runtime["hosts"].(map[string]any)["a.test"].(map[string]any)["origin"].(map[string]any)
	backup := a["backup"].(map[string]any)
	for _, origin := range []map[string]any{a, backup, runtime["hosts"].(map[string]any)["b.test"].(map[string]any)["origin"].(map[string]any)} {
		if got := origin["blocked_addresses"].([]string); len(got) != 1 || got[0] != "192.0.2.55" {
			t.Fatalf("blocked addresses missing: %v", origin)
		}
		if _, ok := origin["private_allowlist"].([]string); !ok {
			t.Fatalf("an empty list must be sent as an empty array, not null: %v", origin)
		}
	}
}

func TestOriginPolicyIsVerifiedStoredAndFetchedOnlyWhenItChanges(t *testing.T) {
	control := newFakeControl(t)
	requests := 0
	server := policyServer(t, control, func(w http.ResponseWriter, r *http.Request) bool { requests++; return false })
	defer server.Close()
	dir := t.TempDir()
	c := &client{base: server.URL, dir: dir, http: server.Client(), id: identity{PublicKey: control.public}}

	changed, err := c.syncOriginPolicy()
	if err != nil || !changed || c.originPolicy.BlockedAddresses[0] != "192.0.2.55" {
		t.Fatalf("first fetch: changed=%v err=%v policy=%+v", changed, err, c.originPolicy)
	}
	if changed, err = c.syncOriginPolicy(); err != nil || changed {
		t.Fatalf("an unchanged policy must report no change: %v %v", changed, err)
	}
	// A restarted agent starts from the stored, verified policy.
	restarted := &client{base: server.URL, dir: dir, http: server.Client(), id: identity{PublicKey: control.public}}
	if changed, err = restarted.syncOriginPolicy(); err != nil || changed || restarted.originPolicyChecksum != c.originPolicyChecksum {
		t.Fatalf("restart: changed=%v err=%v", changed, err)
	}
}

func TestWithoutAnyPolicyTheAgentRefusesToActivate(t *testing.T) {
	control := newFakeControl(t)
	server := policyServer(t, control, func(w http.ResponseWriter, r *http.Request) bool {
		http.Error(w, "down", http.StatusServiceUnavailable)
		return true
	})
	defer server.Close()
	c := &client{base: server.URL, dir: t.TempDir(), http: server.Client(), id: identity{PublicKey: control.public}}

	if err := c.sync(); err == nil {
		t.Fatal("an agent with no origin policy must not activate runtime files")
	}
	if _, err := os.Stat(filepath.Join(c.dir, "origin-policy.json")); err == nil {
		t.Fatal("nothing may be stored when nothing was verified")
	}
}

func TestHeldPolicySurvivesAControlOutageAndATamperedReply(t *testing.T) {
	control := newFakeControl(t)
	mode := "ok"
	server := policyServer(t, control, func(w http.ResponseWriter, r *http.Request) bool {
		switch mode {
		case "down":
			http.Error(w, "down", http.StatusServiceUnavailable)
			return true
		case "tampered":
			_ = json.NewEncoder(w).Encode(map[string]any{"encoded_payload": "e30=", "checksum": "00", "signature": "00", "expires_at": 1, "delivery_signature": "00"})
			return true
		}
		return false
	})
	defer server.Close()
	c := &client{base: server.URL, dir: t.TempDir(), http: server.Client(), id: identity{PublicKey: control.public}}
	if _, err := c.syncOriginPolicy(); err != nil {
		t.Fatal(err)
	}
	held := c.originPolicyChecksum
	c.originPolicyChecksum = "stale" // force a refetch on the next call

	for _, mode = range []string{"down", "tampered"} {
		if changed, err := c.syncOriginPolicy(); err != nil || changed {
			t.Fatalf("%s: a held policy must keep serving, got changed=%v err=%v", mode, changed, err)
		}
		if len(c.originPolicy.BlockedAddresses) != 1 {
			t.Fatalf("%s: the held policy was lost", mode)
		}
	}
	if held == "" {
		t.Fatal("the first fetch must have stored a checksum")
	}
}

func TestGatewayRoutesCarryEachHostsHandshakeBudget(t *testing.T) {
	pools := map[string]map[string]any{"shared": {"hosts": map[string]any{
		"strict.test": map[string]any{"security": map[string]any{"limits": map[string]any{"tls_handshakes_per_second": float64(5)}}},
		"plain.test":  map[string]any{},
		"absurd.test": map[string]any{"security": map[string]any{"limits": map[string]any{"tls_handshakes_per_second": float64(1e9)}}},
	}}}
	gateway, err := compileGateway(1, pools, `[{"address":"192.0.2.1","pool":"shared","http":"127.0.0.1:8081","https":"127.0.0.1:8444"}]`)
	if err != nil {
		t.Fatal(err)
	}
	budgets := map[string]int{}
	for _, route := range gateway["routes"].([]map[string]any) {
		budgets[route["hostname"].(string)] = route["tls_handshakes_per_second"].(int)
	}
	if budgets["strict.test"] != 5 || budgets["plain.test"] != defaultTLSHandshakeBudget || budgets["absurd.test"] != defaultTLSHandshakeBudget {
		t.Fatalf("unexpected budgets %v", budgets)
	}
}
