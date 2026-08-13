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
	if !compatible("1.0.0", "1.99.99") || compatible("1.0.0", "1.0.99") {
		t.Fatal("compatibility bounds are incorrect")
	}
}

func TestVersionCommand(t *testing.T) {
	if version != "1.2.0" {
		t.Fatalf("unexpected release version %q", version)
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
	active, err := loadState(filepath.Join(dir, "runtime", "current", "state.json"))
	if err != nil || active.Sequence != 5 {
		t.Fatalf("active state not restart-safe: %v", err)
	}
	previous, err := loadState(filepath.Join(dir, "runtime", "previous", "state.json"))
	if err != nil || previous.Sequence != 4 {
		t.Fatalf("previous state not preserved: %v", err)
	}
	if err := c.activate(state{Sequence: 6}); err == nil {
		t.Fatal("invalid candidate activated")
	}
	active, _ = loadState(filepath.Join(dir, "runtime", "current", "state.json"))
	if active.Sequence != 5 {
		t.Fatal("invalid candidate replaced active state")
	}
	var poolRuntime struct {
		Hosts map[string]any `json:"hosts"`
	}
	poolBytes, err := os.ReadFile(filepath.Join(dir, "runtime", "current", "shared-default.json"))
	if err != nil || json.Unmarshal(poolBytes, &poolRuntime) != nil || poolRuntime.Hosts["www.example.test"] == nil {
		t.Fatal("placement-aware pool runtime was not published")
	}
	poolInfo, err := os.Stat(filepath.Join(dir, "runtime", "current", "shared-default.json"))
	if err != nil || poolInfo.Mode().Perm() != 0600 {
		t.Fatalf("runtime snapshot containing TLS keys must be mode 0600: %v", err)
	}
}

func TestGenerationFailureBoundariesNeverExposePartialCandidate(t *testing.T) {
	stages := []string{"after_files", "before_publish", "after_publish", "pointer_replace"}
	for _, stage := range stages {
		t.Run(stage, func(t *testing.T) {
			root := t.TempDir()
			first, err := publishGeneration(root, 1, generationFixture("one"))
			if err != nil {
				t.Fatal(err)
			}
			generationFault = func(actual string) error {
				if actual == stage {
					return errors.New("injected " + stage)
				}
				return nil
			}
			_, err = publishGeneration(root, 2, generationFixture("two"))
			generationFault = nil
			if err == nil {
				t.Fatal("failure injection did not interrupt publication")
			}
			active, recoverErr := recoverGeneration(root)
			if recoverErr != nil {
				t.Fatal(recoverErr)
			}
			if active.GenerationID != first.GenerationID || active.Revision != 1 {
				t.Fatalf("partial candidate became active: %#v", active)
			}
		})
	}
}

func TestGenerationVerificationRollbackRetentionAndIdempotency(t *testing.T) {
	root := t.TempDir()
	first, err := publishGeneration(root, 1, generationFixture("one"))
	if err != nil {
		t.Fatal(err)
	}
	second, err := publishGeneration(root, 2, generationFixture("two"))
	if err != nil {
		t.Fatal(err)
	}
	duplicate, err := publishGeneration(root, 2, generationFixture("two"))
	if err != nil || duplicate.GenerationID != second.GenerationID {
		t.Fatalf("duplicate activation was not idempotent: %#v %v", duplicate, err)
	}
	if _, err := publishGeneration(root, 1, generationFixture("old")); err == nil {
		t.Fatal("older revision activated")
	}
	rolledBack, err := rollbackGeneration(root)
	if err != nil || rolledBack.GenerationID != first.GenerationID {
		t.Fatalf("rollback failed: %#v %v", rolledBack, err)
	}
	if current, err := readGenerationPointer(root, "current"); err != nil || current.GenerationID != first.GenerationID {
		t.Fatal("rollback pointer is invalid")
	}

	currentPath := filepath.Join(root, "current", "active.json")
	if err := os.WriteFile(currentPath, []byte("corrupt"), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := readGenerationPointer(root, "current"); err == nil {
		t.Fatal("digest mismatch was accepted")
	}
	if recovered, err := recoverGeneration(root); err != nil || recovered.GenerationID != second.GenerationID {
		t.Fatalf("previous generation was not recovered: %#v %v", recovered, err)
	}
}

func TestGenerationRejectsMissingUnexpectedAndCorruptManifest(t *testing.T) {
	for _, mutation := range []string{"missing", "unexpected", "manifest"} {
		t.Run(mutation, func(t *testing.T) {
			root := t.TempDir()
			manifest, err := publishGeneration(root, 1, generationFixture("one"))
			if err != nil {
				t.Fatal(err)
			}
			dir := filepath.Join(root, "generations", manifest.GenerationID)
			switch mutation {
			case "missing":
				if err := os.Remove(filepath.Join(dir, "active.json")); err != nil {
					t.Fatal(err)
				}
			case "unexpected":
				if err := os.WriteFile(filepath.Join(dir, "extra.json"), []byte("{}"), 0600); err != nil {
					t.Fatal(err)
				}
			case "manifest":
				if err := os.WriteFile(filepath.Join(dir, "manifest.json"), []byte("{"), 0600); err != nil {
					t.Fatal(err)
				}
			}
			if _, err := verifyGeneration(dir); err == nil {
				t.Fatal("invalid generation verified")
			}
		})
	}
}

func generationFixture(value string) func(string, string) error {
	return func(dir, generationID string) error {
		if err := os.MkdirAll(filepath.Join(dir, "cells"), 0750); err != nil {
			return err
		}
		if err := atomicJSON(filepath.Join(dir, "state.json"), map[string]any{"sequence": 1}); err != nil {
			return err
		}
		if err := atomicJSON(filepath.Join(dir, "active.json"), map[string]any{"value": value, "generation_id": generationID}); err != nil {
			return err
		}
		return atomicJSON(filepath.Join(dir, "cells", "cell-01.json"), map[string]any{"value": value})
	}
}

func TestRuntimeAssignsSupplementalCertificatesPerHostname(t *testing.T) {
	domain := json.RawMessage(`{"domain":"example.test","revision":1,"pools":["shared-default"],"settings":{"enabled":true},"cache":{},"tls":{"mode":"managed","certificates":[{"id":"base","certificate_pem":"base-cert","private_key_pem":"base-key"},{"id":"deep","certificate_pem":"deep-cert","private_key_pem":"deep-key"}]},"hostnames":[{"hostname":"www.example.test","tls_certificate_id":"base","origin":{"host":"origin.example"}},{"hostname":"a.b.example.test","tls_certificate_id":"deep","origin":{"host":"origin.example"}}]}`)
	_, pools, err := compileRuntime(state{Sequence: 1, Domains: map[string]json.RawMessage{"1": domain}})
	if err != nil {
		t.Fatal(err)
	}
	pool := pools["shared-default"]
	hosts := pool["hosts"].(map[string]any)
	if hosts["www.example.test"].(map[string]any)["tls"].(map[string]any)["certificate_id"] != "base" {
		t.Fatal("base hostname did not receive the base certificate")
	}
	if hosts["a.b.example.test"].(map[string]any)["tls"].(map[string]any)["certificate_id"] != "deep" {
		t.Fatal("deep hostname did not receive its supplemental certificate")
	}
	if len(pool["certificates"].(map[string]any)) != 2 {
		t.Fatal("cell runtime did not deduplicate both required certificates")
	}
}

func TestRuntimeTargetsOnlySelectedCellsWithinOnePool(t *testing.T) {
	domain := json.RawMessage(`{"domain":"example.test","domain_id":7,"revision":2,"pools":["shared-default"],"cells":["cell-02"],"settings":{"enabled":true},"cache":{},"tls":{"mode":"disabled"},"hostnames":[{"hostname":"www.example.test","origin":{"host":"origin.example"}}]}`)
	_, runtimes, err := compileRuntime(state{Sequence: 9, Domains: map[string]json.RawMessage{"7": domain}})
	if err != nil {
		t.Fatal(err)
	}
	c := &client{runtimeDir: t.TempDir(), cellAssignments: map[string]string{"cell-01": "shared-default", "cell-02": "shared-default", "cell-03": "shared-default"}}
	if err := c.writeCellRuntimes(9, runtimes); err != nil {
		t.Fatal(err)
	}
	for _, cell := range []string{"cell-01", "cell-02", "cell-03"} {
		body, err := os.ReadFile(filepath.Join(c.runtimeDir, cell+".json"))
		if err != nil {
			t.Fatal(err)
		}
		var runtime struct {
			Hosts map[string]any `json:"hosts"`
		}
		if json.Unmarshal(body, &runtime) != nil {
			t.Fatalf("invalid runtime for %s", cell)
		}
		present := runtime.Hosts["www.example.test"] != nil
		if present != (cell == "cell-02") {
			t.Fatalf("domain targeting leaked to %s: %#v", cell, runtime.Hosts)
		}
	}
}

func TestCompileGatewayRoutesByAddressAndPool(t *testing.T) {
	pools := map[string]map[string]any{
		"shared-default": {
			"hosts": map[string]any{"b.example.test": map[string]any{}, "a.example.test": map[string]any{}},
		},
		"quarantine-default": {"hosts": map[string]any{"blocked.example.test": map[string]any{}}},
	}
	compiled, err := compileGateway(41, pools, `[
		{"address":"192.0.2.10","pool":"shared-default","http":"edge-a:8081","https":"edge-a:8444"},
		{"address":"2001:db8::10","pool":"shared-default","http":"edge-a:8081","https":"edge-a:8444"}
	]`)
	if err != nil {
		t.Fatal(err)
	}
	if compiled["revision"] != uint64(41) {
		t.Fatalf("unexpected gateway revision: %#v", compiled)
	}
	listeners := compiled["listeners"].([]string)
	if len(listeners) != 4 || listeners[0] != "192.0.2.10:443" {
		t.Fatalf("unexpected listeners: %#v", listeners)
	}
	routes := compiled["routes"].([]map[string]any)
	if len(routes) != 4 || routes[0]["hostname"] != "a.example.test" || routes[2]["address"] != "2001:db8::10" {
		t.Fatalf("unexpected routes: %#v", routes)
	}
}

func TestRewriteGatewayTargetsUsesBoundedDevelopmentEndpoints(t *testing.T) {
	bindings := []gatewayBinding{{
		Address: "192.0.2.10",
		Pool:    "shared-default",
		Cells: []struct {
			Name  string `json:"name"`
			HTTP  string `json:"http"`
			HTTPS string `json:"https"`
		}{
			{Name: "cell-01", HTTP: "127.0.0.1:18081", HTTPS: "127.0.0.1:18444"},
			{Name: "cell-02", HTTP: "127.0.0.1:18082", HTTPS: "127.0.0.1:18445"},
		},
	}}
	rewriteGatewayTargets(bindings, map[string]cellTarget{
		"cell-01": {HTTP: "edge-a:8081", HTTPS: "edge-a:8444"},
	})
	if bindings[0].Cells[0].HTTP != "edge-a:8081" || bindings[0].Cells[0].HTTPS != "edge-a:8444" {
		t.Fatalf("development target was not rewritten: %#v", bindings[0].Cells[0])
	}
	if bindings[0].Cells[1].HTTP != "127.0.0.1:18082" {
		t.Fatalf("unconfigured target was rewritten: %#v", bindings[0].Cells[1])
	}
}

func TestRewriteGatewayAddressesUsesBoundedDevelopmentListeners(t *testing.T) {
	bindings := []gatewayBinding{
		{Address: "192.0.2.10", Pool: "shared-default"},
		{Address: "2001:db8::10", Pool: "shared-default"},
		{Address: "192.0.2.11", Pool: "quarantine-default"},
	}
	rewritten := rewriteGatewayAddresses(bindings, []string{"172.28.10.10", "fd00:cd0f:10::10"})
	if len(rewritten) != 4 {
		t.Fatalf("expected two addresses for each pool, got %#v", rewritten)
	}
	if rewritten[0].Address != "172.28.10.10" || rewritten[1].Address != "fd00:cd0f:10::10" ||
		rewritten[2].Pool != "quarantine-default" {
		t.Fatalf("development listeners were not rewritten: %#v", rewritten)
	}
}

func TestRewriteGatewayAddressMapUsesExactLocalListeners(t *testing.T) {
	bindings := []gatewayBinding{
		{Address: "192.0.2.10", Pool: "shared-default"},
		{Address: "2001:db8::10", Pool: "shared-default"},
		{Address: "192.0.2.11", Pool: "quarantine-default"},
	}
	addresses, err := parseGatewayAddressMap(`{"192.0.2.10":"10.20.0.10","2001:db8::10":"fd00:20::10"}`)
	if err != nil {
		t.Fatal(err)
	}
	rewritten := rewriteGatewayAddressMap(bindings, addresses)
	if rewritten[0].Address != "10.20.0.10" || rewritten[1].Address != "fd00:20::10" || rewritten[2].Address != "192.0.2.11" {
		t.Fatalf("production listener map was not applied exactly: %#v", rewritten)
	}
	if bindings[0].Address != "192.0.2.10" {
		t.Fatalf("desired-state binding was mutated: %#v", bindings)
	}
}

func TestParseGatewayAddressMapRejectsUnsafeMappings(t *testing.T) {
	for _, raw := range []string{
		`[]`,
		`{"192.0.2.10":"0.0.0.0"}`,
		`{"192.0.2.10":"198.51.100.10"}`,
		`{"192.0.2.10":"fd00:20::10"}`,
		`{"192.0.2.10":"10.20.0.10","192.0.2.11":"10.20.0.10"}`,
	} {
		if _, err := parseGatewayAddressMap(raw); err == nil {
			t.Fatalf("unsafe listener map was accepted: %s", raw)
		}
	}
}

func TestParseGatewayAddressMapAcceptsDirectlyAssignedPublicAddress(t *testing.T) {
	addresses, err := parseGatewayAddressMap(`{"192.0.2.10":"192.0.2.10","2001:db8::10":"2001:db8::10"}`)
	if err != nil {
		t.Fatal(err)
	}
	if addresses["192.0.2.10"] != "192.0.2.10" || addresses["2001:db8::10"] != "2001:db8::10" {
		t.Fatalf("direct public listener mapping was not preserved: %#v", addresses)
	}
}

func TestStaticGatewayBindingsUseProductionAddressMap(t *testing.T) {
	raw := `[{"address":"192.0.2.10","pool":"shared-default"}]`
	rewritten, err := rewriteGatewayBindingsJSON(raw, map[string]string{"192.0.2.10": "10.20.0.10"}, true)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(rewritten, `"address":"10.20.0.10"`) {
		t.Fatalf("static production binding retained its advertised address: %s", rewritten)
	}
}

func TestGatewayAddressMapCoverageFailsClosed(t *testing.T) {
	bindings := []gatewayBinding{
		{Address: "192.0.2.10", Pool: "shared-default"},
		{Address: "192.0.2.11", Pool: "quarantine-default"},
	}
	addresses := map[string]string{"192.0.2.10": "10.20.0.10"}
	if err := validateGatewayAddressMapCoverage(bindings, addresses, true); err == nil {
		t.Fatal("production listener map accepted an unmapped advertised address")
	}
	if err := validateGatewayAddressMapCoverage(bindings, addresses, false); err != nil {
		t.Fatalf("development listener mapping was unexpectedly required: %v", err)
	}
}

func TestGatewayRevisionChangeRebuildsUnchangedBindings(t *testing.T) {
	c := &client{gatewayRevision: 41, derivedEnsured: true}
	c.updateGatewayRevision(42)
	if c.gatewayRevision != 42 || c.derivedEnsured {
		t.Fatalf("revision-only candidate did not require activation: %#v", c)
	}
	c.derivedEnsured = true
	c.updateGatewayRevision(42)
	if !c.derivedEnsured {
		t.Fatal("unchanged revision unnecessarily required activation")
	}
}

func TestCompileGatewayRejectsUnknownPoolDuplicateAndBounds(t *testing.T) {
	pools := map[string]map[string]any{"shared": {"hosts": map[string]any{"a.example.test": map[string]any{}}}}
	for _, raw := range []string{
		`[{"address":"192.0.2.10","pool":"missing","http":"cell:8081","https":"cell:8444"}]`,
		`[{"address":"192.0.2.10","pool":"shared","http":"cell:8081","https":"cell:8444"},{"address":"192.0.2.10","pool":"shared","http":"cell:8081","https":"cell:8444"}]`,
		`[{"address":"not-an-ip","pool":"shared","http":"cell:8081","https":"cell:8444"}]`,
	} {
		if _, err := compileGateway(1, pools, raw); err == nil {
			t.Fatalf("invalid gateway bindings passed: %s", raw)
		}
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
	active, err := loadState(filepath.Join(dir, "runtime", "current", "state.json"))
	if err != nil || active.Sequence != 4 || len(active.Domains) != 1 {
		t.Fatalf("fresh full snapshot was not activated: %#v, %v", active, err)
	}
	if err := c.sync(); err != nil {
		t.Fatal(err)
	}
	active, err = loadState(filepath.Join(dir, "runtime", "current", "state.json"))
	if err != nil || active.Sequence != 5 || !bytes.Contains(active.Domains["1"], []byte(`"revision":5`)) {
		t.Fatalf("incremental artifact was not activated: %#v, %v", active, err)
	}
}

func TestFreshEmptyFullSnapshotActivatesBootstrapGeneration(t *testing.T) {
	public, private, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	publicHex := hex.EncodeToString(public)
	fullPayload, fullChecksum, fullSignature := signedGzipJSON(t, private, map[string]any{
		"schema_version": 1, "minimum_agent_version": "1.0.0", "maximum_agent_version": "1.99.0",
		"artifacts": []map[string]any{},
	})
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/edge/v1/config/full":
			_ = json.NewEncoder(w).Encode(map[string]any{"encoded_snapshot": fullPayload, "checksum": fullChecksum, "signature": fullSignature, "signing_public_key": publicHex})
		default:
			_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"accepted": true}})
		}
	}))
	defer server.Close()

	dir := t.TempDir()
	runtimeDir := filepath.Join(dir, "runtime")
	c := &client{
		base: server.URL, dir: dir, runtimeDir: runtimeDir, http: server.Client(),
		id: identity{PublicKey: publicHex}, gatewayBindings: "[]",
		cellAssignments: map[string]string{"cell-01": ""},
	}
	if err := c.full(); err != nil {
		t.Fatal(err)
	}

	active, err := loadState(filepath.Join(runtimeDir, "current", "state.json"))
	if err != nil || active.Sequence != 0 || len(active.Domains) != 0 {
		t.Fatalf("empty bootstrap snapshot was not activated: %#v, %v", active, err)
	}
	manifest, err := readGenerationPointer(runtimeDir, "current")
	if err != nil || manifest.Revision != 0 {
		t.Fatalf("bootstrap generation was not published at revision zero: %#v, %v", manifest, err)
	}
	var cellRuntime map[string]any
	cellBody, err := os.ReadFile(filepath.Join(runtimeDir, "current", "cell-01.json"))
	if err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(cellBody, &cellRuntime); err != nil {
		t.Fatal(err)
	}
	if sequence, ok := cellRuntime["sequence"].(float64); !ok || sequence != 0 {
		t.Fatalf("empty cell runtime has the wrong bootstrap sequence: %#v", cellRuntime)
	}
}

func TestFirstGatewayEndpointRebuildsAnEmptyBootstrapGeneration(t *testing.T) {
	runtimeDir := t.TempDir()
	c := &client{
		runtimeDir:      runtimeDir,
		gatewayBindings: "[]",
		cellAssignments: map[string]string{"cell-01": "shared-default"},
	}
	empty := state{Domains: map[string]json.RawMessage{}}
	if err := c.activate(empty); err != nil {
		t.Fatal(err)
	}
	bootstrap, err := readGenerationPointer(runtimeDir, "current")
	if err != nil || bootstrap.Revision != 0 {
		t.Fatalf("bootstrap generation was not active: %#v, %v", bootstrap, err)
	}

	c.gatewayBindings = `[{"address":"198.51.100.40","pool":"shared-default","cells":[{"name":"cell-01","http":"127.0.0.1:18081","https":"127.0.0.1:18444"}]}]`
	c.gatewayRevision = 1
	c.derivedEnsured = false
	if err := c.ensureDerivedRuntime(empty); err != nil {
		t.Fatal(err)
	}
	active, err := readGenerationPointer(runtimeDir, "current")
	if err != nil || active.Revision != 1 || active.GenerationID == bootstrap.GenerationID {
		t.Fatalf("first gateway endpoint did not replace the bootstrap generation: %#v, %v", active, err)
	}
	var gateway map[string]any
	gatewayBody, err := os.ReadFile(filepath.Join(runtimeDir, "current", "gateway.json"))
	if err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(gatewayBody, &gateway); err != nil {
		t.Fatal(err)
	}
	if revision, ok := gateway["revision"].(float64); !ok || revision != 1 {
		t.Fatalf("first gateway endpoint has the wrong active revision: %#v", gateway)
	}
}

func TestFirstArtifactReplacesAnEqualGatewayGenerationRevision(t *testing.T) {
	runtimeDir := t.TempDir()
	c := &client{
		runtimeDir:      runtimeDir,
		gatewayBindings: "[]",
		gatewayRevision: 1,
		cellAssignments: map[string]string{"cell-01": "shared-default"},
	}
	if err := c.activate(state{Domains: map[string]json.RawMessage{}}); err != nil {
		t.Fatal(err)
	}
	gatewayOnly, err := readGenerationPointer(runtimeDir, "current")
	if err != nil || gatewayOnly.Revision != 1 {
		t.Fatalf("gateway-only generation was not active: %#v, %v", gatewayOnly, err)
	}

	withDomain := state{Sequence: 1, Domains: map[string]json.RawMessage{"1": json.RawMessage(runtimeDomain(1))}}
	if err := c.activate(withDomain); err != nil {
		t.Fatal(err)
	}
	active, err := readGenerationPointer(runtimeDir, "current")
	if err != nil || active.Revision != 1 || active.GenerationID == gatewayOnly.GenerationID {
		t.Fatalf("equal source revisions did not publish the domain generation: %#v, %v", active, err)
	}
	stateOnDisk, err := loadState(filepath.Join(runtimeDir, "current", "state.json"))
	if err != nil || stateOnDisk.Sequence != 1 || len(stateOnDisk.Domains) != 1 {
		t.Fatalf("first domain artifact was not activated: %#v, %v", stateOnDisk, err)
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

func TestOriginTaskRejectsReservedDestinations(t *testing.T) {
	for _, address := range []string{
		"0.1.2.3", "192.0.2.1", "192.88.99.1", "198.51.100.1", "203.0.113.1",
		"239.1.2.3", "240.0.0.1", "64:ff9b::7f00:1", "2001:db8::1",
	} {
		task := edgeTask{}
		task.Payload.Addresses = []string{address}
		task.Payload.Origin.Scheme = "http"
		task.Payload.Origin.HostHeader = "origin.example"
		task.Payload.Origin.Port = 80
		result := runOriginTest(task)
		if result["failure_reason"] != "blocked_destination" {
			t.Fatalf("reserved destination %s was accepted: %#v", address, result)
		}
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
		}}, "security": []map[string]any{{"domain_id": 1, "reason_code": "client_rate_exceeded", "count": 3}}, "cell": map[string]any{"name": "shared-default", "status": "ready", "capacity": map[string]any{}}})
	}))
	defer server.Close()
	c := &client{http: server.Client(), statusToken: "status-secret", statusURLs: []string{server.URL}}
	cells, failures, security := c.runtimeStatus()
	if len(failures) != 1 || failures[0]["hostname"] != "www.example.test" {
		t.Fatalf("passive failures were not collected: %#v", failures)
	}
	if len(cells) != 1 || cells[0]["name"] != "shared-default" {
		t.Fatalf("cell status was not collected: %#v", cells)
	}
	if len(security) != 1 || security[0]["reason_code"] != "client_rate_exceeded" {
		t.Fatalf("security top-N was not collected: %#v", security)
	}
}

func TestGatewayStatusIncludesActivationCounter(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte("cdnfoundry_gateway_ready 1\ncdnfoundry_gateway_activations_total 9\n"))
	}))
	defer server.Close()
	c := &client{http: server.Client(), gatewayStatusURL: server.URL}
	status := c.gatewayStatus()
	if status["ready"] != true || status["activations"] != uint64(9) {
		t.Fatalf("gateway activation counter was not parsed: %#v", status)
	}
}

func TestEmergencyModeTargetsOneCellWithBoundedActions(t *testing.T) {
	controlCalls := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Edge-Status-Token") != "status-secret" {
			http.NotFound(w, r)
			return
		}
		if r.URL.Path == "/passive-failures" {
			_ = json.NewEncoder(w).Encode(map[string]any{"data": []any{}, "security": []any{}, "cell": map[string]any{"name": "quarantine-default", "status": "ready", "capacity": map[string]any{}}})
			return
		}
		var command struct {
			Action  string   `json:"action"`
			Active  bool     `json:"active"`
			Actions []string `json:"actions"`
		}
		_ = json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&command)
		if command.Action != "emergency_mode" || !command.Active || len(command.Actions) != 2 {
			http.Error(w, "invalid emergency command", http.StatusBadRequest)
			return
		}
		controlCalls++
		_, _ = w.Write([]byte(`{"data":{"accepted":true}}`))
	}))
	defer server.Close()
	c := &client{http: server.Client(), statusToken: "status-secret", statusURLs: []string{server.URL + "/passive-failures"}, dir: t.TempDir()}
	var task edgeTask
	task.ID = "emergency-1"
	task.Type = "emergency_mode"
	task.Payload.CellNames = []string{"quarantine-default"}
	task.Payload.EmergencyActive = true
	task.Payload.EmergencyActions = []string{"allow_get_head_only", "disable_origin_retries"}
	result, status := c.runEmergencyMode(task)
	if status != "succeeded" || result["status"] != "completed" || controlCalls != 1 {
		t.Fatalf("emergency mode did not reach the selected cell: status=%s result=%#v calls=%d", status, result, controlCalls)
	}
	controls, err := c.loadEmergencyControls()
	if err != nil || !controls["quarantine-default"].Active {
		t.Fatalf("emergency mode was not persisted: controls=%#v err=%v", controls, err)
	}
	c.runtimeStatus()
	if controlCalls != 2 {
		t.Fatalf("persisted emergency mode was not restored: calls=%d", controlCalls)
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

func TestCachePurgeFansOutToEveryAuthenticatedCell(t *testing.T) {
	calls := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Edge-Status-Token") != "status-secret" || r.URL.Path != "/control" {
			http.NotFound(w, r)
			return
		}
		var command struct {
			Action, Domain, Type string
			CacheEpoch           uint64   `json:"cache_epoch"`
			CacheKeys            []string `json:"cache_keys"`
		}
		if json.NewDecoder(io.LimitReader(r.Body, 128<<10)).Decode(&command) != nil || command.Action != "cache_purge" || command.Domain != "example.test" || command.Type != "urls" || command.CacheEpoch != 4 || len(command.CacheKeys) != 1 {
			http.Error(w, "invalid purge", http.StatusBadRequest)
			return
		}
		calls++
		_, _ = w.Write([]byte(`{"data":{"accepted":true}}`))
	}))
	defer server.Close()
	c := &client{http: server.Client(), statusToken: "status-secret", statusURLs: []string{server.URL + "/passive-failures", server.URL + "/passive-failures"}}
	var task edgeTask
	task.ID, task.Type = "purge-1", "cache_purge"
	task.Payload.Domain, task.Payload.PurgeType, task.Payload.CacheEpoch = "example.test", "urls", 4
	task.Payload.CacheKeys = []string{"https|example.test|/app.css?a=1"}
	result, status := c.runCachePurge(task)
	if status != "succeeded" || result["status"] != "completed" || result["applied_cells"] != 2 || calls != 2 {
		t.Fatalf("cache purge fanout failed: status=%s result=%#v calls=%d", status, result, calls)
	}
}

func TestWriteCellRuntimesKeepsStableSlotsAndEmptyUnassignedState(t *testing.T) {
	dir := t.TempDir()
	c := &client{runtimeDir: dir, cellAssignments: map[string]string{"cell-01": "shared-default", "cell-02": ""}}
	pools := map[string]map[string]any{
		"shared-default": {"schema_version": 1, "sequence": uint64(9), "hosts": map[string]any{"www.example.test": map[string]any{}}},
	}
	if err := c.writeCellRuntimes(9, pools); err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"cell-01", "cell-02"} {
		if _, err := os.Stat(filepath.Join(dir, name+".json")); err != nil {
			t.Fatalf("missing stable runtime for %s: %v", name, err)
		}
	}
	var empty map[string]any
	body, err := os.ReadFile(filepath.Join(dir, "cell-02.json"))
	if err != nil || json.Unmarshal(body, &empty) != nil {
		t.Fatalf("invalid unassigned runtime: %v", err)
	}
	if empty["sequence"] != float64(9) || len(empty["hosts"].(map[string]any)) != 0 {
		t.Fatalf("unexpected unassigned runtime: %#v", empty)
	}
}

func TestValidCellNameIsBounded(t *testing.T) {
	for _, name := range []string{"cell-01", "cell-08", "cell-32"} {
		if !validCellName(name) {
			t.Fatalf("expected %s to be valid", name)
		}
	}
	for _, name := range []string{"shared-default", "cell-00", "cell-1", "cell-33", "cell-01-extra"} {
		if validCellName(name) {
			t.Fatalf("expected %s to be invalid", name)
		}
	}
}

func TestUnassignedSlotsReceiveAdditionalRuntimePoolsDeterministically(t *testing.T) {
	c := &client{cellAssignments: map[string]string{"cell-01": "shared-default", "cell-02": "", "cell-03": ""}}
	resolved := c.resolvedCellAssignments(map[string]map[string]any{
		"shared-default": {}, "reserved-z": {}, "dedicated-a": {},
	})
	if resolved["cell-01"] != "shared-default" || resolved["cell-02"] != "dedicated-a" || resolved["cell-03"] != "reserved-z" {
		t.Fatalf("unexpected deterministic assignments: %#v", resolved)
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
	return json.RawMessage(`{"domain":"example.test","revision":` + strconv.Itoa(revision) + `,"pools":["shared-default"],"settings":{"enabled":true},"cache":{"enabled":true,"epoch":2},"hostnames":[{"hostname":"www.example.test","origin":{"host":"origin.example"}}]}`)
}

func TestRuntimeUpgradeWritesOnlyBoundedIntentAndWaitsForReportedVersions(t *testing.T) {
	digest := strings.Repeat("a", 64)
	versions := map[string]string{
		"gateway":     "registry.test/gateway@sha256:" + digest,
		"agent":       "registry.test/agent@sha256:" + digest,
		"normal_cell": "registry.test/cell@sha256:" + digest,
		"waf_cell":    "registry.test/waf@sha256:" + digest,
	}
	task := edgeTask{ID: "upgrade-1", Type: "runtime_upgrade"}
	task.Payload.Versions = versions
	client := &client{dir: t.TempDir()}

	complete, _, _ := client.runRuntimeUpgrade(task)
	if complete {
		t.Fatal("upgrade must remain pending until the fixed installer reports the desired versions")
	}
	body, err := os.ReadFile(filepath.Join(client.dir, "desired-runtime.json"))
	if err != nil {
		t.Fatal(err)
	}
	if bytes.Contains(body, []byte("command")) {
		t.Fatal("runtime intent must never contain an arbitrary command")
	}

	t.Setenv("EDGE_RUNTIME_VERSIONS", string(mustJSON(t, versions)))
	complete, result, status := client.runRuntimeUpgrade(task)
	if !complete || status != "succeeded" || result["status"] != "completed" {
		t.Fatalf("expected completed upgrade, got complete=%v status=%s result=%v", complete, status, result)
	}
}

func TestRuntimeUpgradeRejectsMutableImageTag(t *testing.T) {
	task := edgeTask{ID: "upgrade-2", Type: "runtime_upgrade"}
	task.Payload.Versions = map[string]string{
		"gateway": "registry.test/gateway:latest", "agent": "registry.test/agent:latest",
		"normal_cell": "registry.test/cell:latest", "waf_cell": "registry.test/waf:latest",
	}
	complete, result, status := (&client{dir: t.TempDir()}).runRuntimeUpgrade(task)
	if !complete || status != "failed" || result["failure_reason"] != "invalid_runtime_versions" {
		t.Fatalf("mutable image tag was not rejected: %v %s %v", complete, status, result)
	}
}

func mustJSON(t *testing.T, value any) []byte {
	t.Helper()
	body, err := json.Marshal(value)
	if err != nil {
		t.Fatal(err)
	}
	return body
}
