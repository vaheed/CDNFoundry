package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

type fakeArtifact struct {
	sequence uint64
	domainID uint64
	kind     string
	revision uint64
	payload  string
}

type fakeControl struct {
	t         *testing.T
	public    string
	private   ed25519.PrivateKey
	artifacts []fakeArtifact
	mu        sync.Mutex
	acks      []map[string]any
	refuse    map[string]int
	outage    bool
	// relabel rewrites a manifest row after it is built, as a tampering intermediary would.
	relabel func(row map[string]any)
}

func newFakeControl(t *testing.T) *fakeControl {
	public, private, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	return &fakeControl{t: t, public: hex.EncodeToString(public), private: private, refuse: map[string]int{}}
}

func (f *fakeControl) checksum(payload string) string {
	sum := sha256.Sum256([]byte(payload))
	return hex.EncodeToString(sum[:])
}

func (f *fakeControl) serve() *httptest.Server {
	return httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		expiresAt := time.Now().Add(5 * time.Minute).Unix()
		switch {
		case r.URL.Path == "/edge/v1/origin-policy":
			serveOriginPolicy(w, r, f.private, testOriginPolicy)
		case r.URL.Path == "/edge/v1/config/manifest":
			var rows []map[string]any
			for _, a := range f.artifacts {
				checksum := f.checksum(a.payload)
				rows = append(rows, map[string]any{
					"sequence": a.sequence, "kind": a.kind, "domain_id": a.domainID, "revision": a.revision, "checksum": checksum,
					"signature": hex.EncodeToString(ed25519.Sign(f.private, []byte(checksum))), "schema_version": 1,
					"minimum_agent_version": "2.0.0", "maximum_agent_version": "2.99.0",
				})
				if f.relabel != nil {
					f.relabel(rows[len(rows)-1])
				}
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"data": rows})
		case strings.HasPrefix(r.URL.Path, "/edge/v1/config/artifacts/"):
			if f.outage {
				http.Error(w, "unavailable", http.StatusServiceUnavailable)
				return
			}
			checksum := strings.TrimPrefix(r.URL.Path, "/edge/v1/config/artifacts/")
			for _, a := range f.artifacts {
				if f.checksum(a.payload) == checksum {
					message := deliveryScope(a.kind, a.domainID, a.revision) + "|" + checksum + "|" + strconv.FormatUint(a.sequence, 10) + "|" + strconv.FormatInt(expiresAt, 10)
					_ = json.NewEncoder(w).Encode(map[string]any{
						"encoded_payload": base64.StdEncoding.EncodeToString([]byte(a.payload)), "data": map[string]any{"sequence": a.sequence},
						"expires_at": expiresAt, "delivery_signature": hex.EncodeToString(ed25519.Sign(f.private, []byte(message))),
					})
					return
				}
			}
			http.NotFound(w, r)
		case r.URL.Path == "/edge/v1/config/applied" || r.URL.Path == "/edge/v1/config/rejected":
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			if code := f.refuse[r.URL.Path]; code != 0 && body["reason"] == "unknown_reason" {
				http.Error(w, "refused", code)
				return
			}
			body["path"] = r.URL.Path
			f.acks = append(f.acks, body)
			_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"accepted": true}})
		default:
			_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"accepted": true}})
		}
	}))
}

func domainPayload(id uint64, name string, revision uint64, hostname string) string {
	return `{"schema_version":1,"domain_id":` + strconv.FormatUint(id, 10) + `,"domain":"` + name + `","revision":` + strconv.FormatUint(revision, 10) +
		`,"pools":["shared-default"],"settings":{"enabled":true},"cache":{"enabled":true},"hostnames":[{"hostname":"` + hostname + `","origin":{"host":"origin.example"}}]}`
}

func syncedClient(t *testing.T, control *fakeControl, server *httptest.Server) *client {
	dir := t.TempDir()
	c := &client{base: server.URL, dir: dir, runtimeDir: filepath.Join(dir, "runtime"), http: server.Client(), id: identity{PublicKey: control.public}}
	if err := c.activate(state{Domains: map[string]json.RawMessage{}}); err != nil {
		t.Fatal(err)
	}
	return c
}

func activeState(t *testing.T, c *client) state {
	active, err := c.loadStoredState()
	if err != nil {
		t.Fatal(err)
	}
	return active
}

func TestBadArtifactIsRejectedAloneAndEveryOtherDomainStillUpdates(t *testing.T) {
	control := newFakeControl(t)
	control.artifacts = []fakeArtifact{
		{1, 1, "domain", 1, domainPayload(1, "one.test", 1, "www.one.test")},
		// A hostname with no origin host cannot be compiled.
		{2, 2, "domain", 1, `{"schema_version":1,"domain_id":2,"domain":"two.test","revision":1,"pools":["shared-default"],"hostnames":[{"hostname":"www.two.test","origin":{}}]}`},
		{3, 3, "domain", 1, domainPayload(3, "three.test", 1, "www.three.test")},
	}
	server := control.serve()
	defer server.Close()
	c := syncedClient(t, control, server)

	if err := c.sync(); err != nil {
		t.Fatal(err)
	}

	active := activeState(t, c)
	if active.Sequence != 3 || active.Domains["1"] == nil || active.Domains["3"] == nil || active.Domains["2"] != nil {
		t.Fatalf("bad artifact froze the PoP or leaked in: sequence %d, domains %v", active.Sequence, keys(active.Domains))
	}
	var rejected, applied []map[string]any
	for _, a := range control.acks {
		if a["path"] == "/edge/v1/config/rejected" {
			rejected = append(rejected, a)
		} else {
			applied = append(applied, a)
		}
	}
	if len(rejected) != 1 || rejected[0]["sequence"] != float64(2) || rejected[0]["reason"] != reasonCandidateValidation {
		t.Fatalf("expected one rejection for sequence 2, got %v", control.acks)
	}
	if len(applied) != 1 || applied[0]["sequence"] != float64(3) {
		t.Fatalf("the applied acknowledgement must reach the last sequence, got %v", control.acks)
	}
	if control.acks[0]["path"] != "/edge/v1/config/rejected" {
		t.Fatalf("rejections must be sent before the applied sequence: %v", control.acks)
	}

	// The next sync starts after the rejected artifact instead of repeating it.
	control.acks = nil
	control.artifacts = control.artifacts[:0]
	if err := c.sync(); err != nil {
		t.Fatal(err)
	}
}

func TestConflictBetweenDomainsRejectsOnlyTheArtifactThatConflicts(t *testing.T) {
	control := newFakeControl(t)
	control.artifacts = []fakeArtifact{
		{1, 1, "domain", 1, domainPayload(1, "one.test", 1, "shared.test")},
		{2, 2, "domain", 1, domainPayload(2, "two.test", 1, "www.two.test")},
		// Each payload is valid alone, but this hostname already belongs to domain 1.
		{3, 3, "domain", 1, domainPayload(3, "three.test", 1, "shared.test")},
		{4, 4, "domain", 1, domainPayload(4, "four.test", 1, "www.four.test")},
	}
	server := control.serve()
	defer server.Close()
	c := syncedClient(t, control, server)

	if err := c.sync(); err != nil {
		t.Fatal(err)
	}

	active := activeState(t, c)
	if active.Sequence != 4 || active.Domains["3"] != nil || active.Domains["1"] == nil || active.Domains["2"] == nil || active.Domains["4"] == nil {
		t.Fatalf("conflict was not isolated: sequence %d, domains %v", active.Sequence, keys(active.Domains))
	}
	rejections := 0
	for _, a := range control.acks {
		if a["path"] == "/edge/v1/config/rejected" {
			rejections++
			if a["sequence"] != float64(3) {
				t.Fatalf("wrong artifact rejected: %v", a)
			}
		}
	}
	if rejections != 1 {
		t.Fatalf("expected exactly one rejection, got %v", control.acks)
	}
}

func TestOutageWhileFetchingAnArtifactRejectsNothingAndKeepsTheCursor(t *testing.T) {
	control := newFakeControl(t)
	control.artifacts = []fakeArtifact{{1, 1, "domain", 1, domainPayload(1, "one.test", 1, "www.one.test")}}
	control.outage = true
	server := control.serve()
	defer server.Close()
	c := syncedClient(t, control, server)

	if err := c.sync(); err == nil {
		t.Fatal("an outage must fail the sync so it is retried")
	}
	if active := activeState(t, c); active.Sequence != 0 {
		t.Fatalf("cursor moved during an outage: %d", active.Sequence)
	}
	for _, a := range control.acks {
		if a["path"] == "/edge/v1/config/rejected" {
			t.Fatalf("an outage must not reject a domain: %v", a)
		}
	}
}

func TestReplayedRevisionIsRejectedWithoutBlockingTheBatch(t *testing.T) {
	control := newFakeControl(t)
	control.artifacts = []fakeArtifact{
		{1, 1, "domain", 5, domainPayload(1, "one.test", 5, "www.one.test")},
		{2, 1, "domain", 4, domainPayload(1, "one.test", 4, "www.one.test")},
		{3, 2, "domain", 1, domainPayload(2, "two.test", 1, "www.two.test")},
	}
	server := control.serve()
	defer server.Close()
	c := syncedClient(t, control, server)

	if err := c.sync(); err != nil {
		t.Fatal(err)
	}

	active := activeState(t, c)
	if active.Sequence != 3 || !strings.Contains(string(active.Domains["1"]), `"revision":5`) || active.Domains["2"] == nil {
		t.Fatalf("replay handling is wrong: %d %v", active.Sequence, keys(active.Domains))
	}
	found := false
	for _, a := range control.acks {
		if a["reason"] == reasonArtifactRevisionReplay && a["sequence"] == float64(2) {
			found = true
		}
	}
	if !found {
		t.Fatalf("the replay was not reported: %v", control.acks)
	}
}

func TestAcknowledgementRefusedForGoodDoesNotHoldBackLaterOnes(t *testing.T) {
	control := newFakeControl(t)
	control.refuse["/edge/v1/config/rejected"] = http.StatusUnprocessableEntity
	server := control.serve()
	defer server.Close()
	c := &client{base: server.URL, dir: t.TempDir(), http: server.Client()}
	c.queueAck(ack{Sequence: 2, Rejected: true, Reason: "unknown_reason"})
	c.queueAck(ack{Sequence: 3})

	if err := c.flushAcks(); err != nil {
		t.Fatal(err)
	}

	if len(control.acks) != 1 || control.acks[0]["sequence"] != float64(3) {
		t.Fatalf("the applied acknowledgement was held back: %v", control.acks)
	}
	if _, err := os.Stat(filepath.Join(c.dir, "acks.json")); !os.IsNotExist(err) {
		t.Fatal("the acknowledgement buffer must drain")
	}
}

func TestEveryRejectionReasonTheAgentSendsIsInTheSharedList(t *testing.T) {
	var shared struct {
		Reasons []string `json:"reasons"`
	}
	if err := json.Unmarshal(rejectionReasonsJSON, &shared); err != nil {
		t.Fatal(err)
	}
	listed := map[string]bool{}
	for _, reason := range shared.Reasons {
		listed[reason] = true
	}
	emitted := []string{reasonIncompatibleArtifact, reasonSignatureOrChecksum, reasonCandidateValidation, reasonInvalidArtifactSchema, reasonArtifactRevisionReplay, reasonArtifactUnavailable}
	for _, reason := range emitted {
		if !listed[reason] {
			t.Fatalf("%s is emitted but missing from rejection_reasons.json", reason)
		}
	}
	if len(emitted) != len(shared.Reasons) {
		t.Fatalf("rejection_reasons.json lists reasons the agent never sends: %v", shared.Reasons)
	}
}

func keys(domains map[string]json.RawMessage) []string {
	var names []string
	for key := range domains {
		names = append(names, key)
	}
	return names
}

func TestFullSnapshotSkipsOneBadDomainAndServesTheRest(t *testing.T) {
	public, private, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	publicHex := hex.EncodeToString(public)
	payload, checksum, signature := signedGzipJSON(t, private, map[string]any{
		"schema_version": 1, "minimum_agent_version": "2.0.0", "maximum_agent_version": "2.99.0",
		"artifacts": []map[string]any{
			{"sequence": 4, "domain_id": 1, "kind": "domain", "payload": json.RawMessage(domainPayload(1, "one.test", 1, "www.one.test"))},
			{"sequence": 6, "domain_id": 2, "kind": "domain", "payload": json.RawMessage(`{"schema_version":1,"domain_id":2,"domain":"two.test","revision":1,"hostnames":[{"hostname":"www.two.test","origin":{}}]}`)},
			{"sequence": 5, "domain_id": 3, "kind": "domain", "payload": json.RawMessage(domainPayload(3, "three.test", 1, "www.three.test"))},
		},
	})
	expiresAt := time.Now().Add(5 * time.Minute).Unix()
	delivery := hex.EncodeToString(ed25519.Sign(private, []byte(snapshotDeliveryScope+"|"+checksum+"|6|"+strconv.FormatInt(expiresAt, 10))))
	var acks []map[string]any
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Path == "/edge/v1/config/full" {
			_ = json.NewEncoder(w).Encode(map[string]any{"encoded_snapshot": payload, "checksum": checksum, "signature": signature, "signing_public_key": publicHex, "sequence": 6, "expires_at": expiresAt, "delivery_signature": delivery})
			return
		}
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		body["path"] = r.URL.Path
		acks = append(acks, body)
		_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"accepted": true}})
	}))
	defer server.Close()
	dir := t.TempDir()
	c := &client{base: server.URL, dir: dir, runtimeDir: filepath.Join(dir, "runtime"), http: server.Client(), id: identity{PublicKey: publicHex}}

	if err := c.full(); err != nil {
		t.Fatal(err)
	}

	active := activeState(t, c)
	if active.Sequence != 6 || active.Domains["1"] == nil || active.Domains["3"] == nil || active.Domains["2"] != nil {
		t.Fatalf("snapshot did not isolate the bad domain: %d %v", active.Sequence, keys(active.Domains))
	}
	if len(acks) != 2 || acks[0]["path"] != "/edge/v1/config/rejected" || acks[0]["sequence"] != float64(6) || acks[1]["sequence"] != float64(6) {
		t.Fatalf("unexpected acknowledgements: %v", acks)
	}
}

func TestRelabelledManifestEntryIsRejectedAndTheDomainKeepsServing(t *testing.T) {
	control := newFakeControl(t)
	control.artifacts = []fakeArtifact{{1, 1, "domain", 1, domainPayload(1, "one.test", 1, "www.one.test")}}
	server := control.serve()
	defer server.Close()
	c := syncedClient(t, control, server)
	if err := c.sync(); err != nil {
		t.Fatal(err)
	}

	// A later signed update is relabelled in the manifest as a tombstone, the way a replayed
	// delivery with an altered manifest would try to remove the domain.
	control.artifacts = []fakeArtifact{{2, 1, "domain", 2, domainPayload(1, "one.test", 2, "www.one.test")}}
	control.relabel = func(row map[string]any) { row["kind"] = "tombstone" }
	control.acks = nil
	if err := c.sync(); err != nil {
		t.Fatal(err)
	}

	if active := activeState(t, c); active.Domains["1"] == nil {
		t.Fatal("a relabelled manifest entry removed the domain")
	}
	if len(control.acks) == 0 || control.acks[0]["path"] != "/edge/v1/config/rejected" || control.acks[0]["reason"] != reasonSignatureOrChecksum {
		t.Fatalf("expected a signature rejection, got %v", control.acks)
	}
}

func TestEqualRevisionIsAReplayAndOnlyAGreaterRevisionIsAccepted(t *testing.T) {
	control := newFakeControl(t)
	control.artifacts = []fakeArtifact{{1, 1, "domain", 3, domainPayload(1, "one.test", 3, "www.one.test")}}
	server := control.serve()
	defer server.Close()
	c := syncedClient(t, control, server)
	if err := c.sync(); err != nil {
		t.Fatal(err)
	}

	control.artifacts = []fakeArtifact{{2, 1, "domain", 3, domainPayload(1, "one.test", 3, "other.one.test")}}
	control.acks = nil
	if err := c.sync(); err != nil {
		t.Fatal(err)
	}
	if string(activeState(t, c).Domains["1"]) != domainPayload(1, "one.test", 3, "www.one.test") {
		t.Fatal("an equal revision replaced the active payload")
	}
	if len(control.acks) == 0 || control.acks[0]["reason"] != reasonArtifactRevisionReplay {
		t.Fatalf("expected a replay rejection, got %v", control.acks)
	}

	control.artifacts = []fakeArtifact{{3, 1, "domain", 4, domainPayload(1, "one.test", 4, "other.one.test")}}
	if err := c.sync(); err != nil {
		t.Fatal(err)
	}
	if string(activeState(t, c).Domains["1"]) != domainPayload(1, "one.test", 4, "other.one.test") {
		t.Fatal("a greater revision was not applied")
	}
}

// serveOriginPolicy answers the policy endpoint with a document signed by the control key.
func serveOriginPolicy(w http.ResponseWriter, r *http.Request, private ed25519.PrivateKey, policy string) {
	serveSignedDocument(w, r, private, originPolicyScope, policy)
}

// serveSignedDocument answers like the control plane's signed single-document endpoints.
func serveSignedDocument(w http.ResponseWriter, r *http.Request, private ed25519.PrivateKey, scope, policy string) {
	sum := sha256.Sum256([]byte(policy))
	checksum := hex.EncodeToString(sum[:])
	if r.URL.Query().Get("checksum") == checksum {
		_ = json.NewEncoder(w).Encode(map[string]any{"unchanged": true, "checksum": checksum})
		return
	}
	expiresAt := time.Now().Add(5 * time.Minute).Unix()
	message := scope + "|" + checksum + "|0|" + strconv.FormatInt(expiresAt, 10)
	_ = json.NewEncoder(w).Encode(map[string]any{
		"encoded_payload": base64.StdEncoding.EncodeToString([]byte(policy)), "checksum": checksum,
		"signature": hex.EncodeToString(ed25519.Sign(private, []byte(checksum))), "expires_at": expiresAt,
		"delivery_signature": hex.EncodeToString(ed25519.Sign(private, []byte(message))),
	})
}

const testOriginPolicy = `{"blocked_addresses":["192.0.2.55"],"blocked_networks":["198.51.100.0/24"],"private_allowlist":["172.16.0.0/12"]}`
