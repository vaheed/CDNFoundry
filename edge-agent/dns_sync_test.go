package main

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/ecdh"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

// sealForTest mirrors the control plane's EdgeKeyEnvelope::seal.
func sealForTest(t *testing.T, identity *ecdsa.PrivateKey, edgeID, id, secret string) keyEnvelope {
	t.Helper()
	peer, err := identity.PublicKey.ECDH()
	if err != nil {
		t.Fatal(err)
	}
	ephemeral, err := ecdh.P256().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	shared, err := ephemeral.ECDH(peer)
	if err != nil {
		t.Fatal(err)
	}
	context := "cdnfoundry-edge-key-v1|" + edgeID + "|" + id
	block, _ := aes.NewCipher(hkdfSHA256(shared, context))
	aead, _ := cipher.NewGCM(block)
	nonce := make([]byte, aead.NonceSize())
	_, _ = rand.Read(nonce)
	return keyEnvelope{
		CertificateID: id, Algorithm: keyEnvelopeAlgorithm,
		EphemeralPublicKey: base64.StdEncoding.EncodeToString(ephemeral.PublicKey().Bytes()),
		Nonce:              base64.StdEncoding.EncodeToString(nonce),
		Ciphertext:         base64.StdEncoding.EncodeToString(aead.Seal(nil, nonce, []byte(secret), []byte(context))),
	}
}

type fakeNode struct {
	mu          sync.Mutex
	tsig        map[string]string
	zones       map[string]*fakeZone
	primary     map[string]uint64 // zones the catalog will create on the next refresh, with their serials
	catalogRuns int
}

type fakeZone struct {
	Serial uint64
	Keys   []string
	Kind   string
}

func newFakeNode() *fakeNode {
	return &fakeNode{tsig: map[string]string{}, zones: map[string]*fakeZone{}, primary: map[string]uint64{}}
}

func (n *fakeNode) handler() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		n.mu.Lock()
		defer n.mu.Unlock()
		if r.Header.Get("X-API-Key") != "local-key" {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		path := strings.TrimPrefix(r.URL.Path, "/api/v1/servers/localhost")
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		switch {
		case strings.HasPrefix(path, "/tsigkeys"):
			name := strings.TrimPrefix(path, "/tsigkeys/")
			if r.Method == "GET" {
				if _, ok := n.tsig[name]; !ok {
					http.NotFound(w, r)
					return
				}
				_, _ = w.Write([]byte(`{}`))
				return
			}
			if r.Method == "POST" {
				name = body["name"].(string)
			}
			n.tsig[name] = body["key"].(string)
			w.WriteHeader(http.StatusCreated)
			_, _ = w.Write([]byte(`{}`))
		case strings.HasSuffix(path, "/axfr-retrieve"):
			name := strings.TrimSuffix(strings.TrimPrefix(path, "/zones/"), "/axfr-retrieve")
			zone := n.zones[name]
			if zone == nil {
				http.NotFound(w, r)
				return
			}
			if zone.Kind == "Consumer" {
				n.catalogRuns++
				for member, serial := range n.primary {
					if n.zones[member] == nil {
						n.zones[member] = &fakeZone{Kind: "Slave"}
					}
					_ = serial
				}
			} else if len(zone.Keys) > 0 {
				zone.Serial = n.primary[name]
			}
			_, _ = w.Write([]byte(`{"result":"queued"}`))
		case strings.HasPrefix(path, "/zones"):
			name := strings.TrimPrefix(strings.TrimPrefix(path, "/zones"), "/")
			if r.Method == "POST" {
				name = body["name"].(string)
				n.zones[name] = &fakeZone{Kind: body["kind"].(string)}
				if keys, ok := body["slave_tsig_key_ids"].([]any); ok {
					for _, key := range keys {
						n.zones[name].Keys = append(n.zones[name].Keys, key.(string))
					}
				}
				w.WriteHeader(http.StatusCreated)
				_, _ = w.Write([]byte(`{}`))
				return
			}
			zone := n.zones[name]
			if zone == nil {
				http.NotFound(w, r)
				return
			}
			if r.Method == "PUT" {
				if keys, ok := body["slave_tsig_key_ids"].([]any); ok {
					zone.Keys = nil
					for _, key := range keys {
						zone.Keys = append(zone.Keys, key.(string))
					}
				}
				_, _ = w.Write([]byte(`{}`))
				return
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"serial": zone.Serial, "slave_tsig_key_ids": zone.Keys, "kind": zone.Kind})
		default:
			http.NotFound(w, r)
		}
	})
}

func dnsTestClient(t *testing.T, changes *[]dnsChange) (*client, *fakeNode) {
	t.Helper()
	identity, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	der, _ := x509.MarshalPKCS8PrivateKey(identity)
	privatePEM := string(pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: der}))
	envelope := sealForTest(t, identity, "edge-1", dnsKeyEnvelopeID, "c2VjcmV0LWJ5dGVz")
	control := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/edge/v1/dns/transfer":
			_ = json.NewEncoder(w).Encode(map[string]any{"data": dnsTransfer{Primary: "192.0.2.10:5300", Catalog: "catalog.cdnfoundry.invalid.", KeyName: "cdnf-nodes", Algorithm: "hmac-sha256", Secret: envelope}})
		case "/edge/v1/dns/changes":
			cursor, _ := strconv.ParseUint(r.URL.Query().Get("cursor"), 10, 64)
			var page []dnsChange
			for _, change := range *changes {
				if change.Sequence > cursor {
					page = append(page, change)
				}
			}
			next := cursor
			if len(page) > 0 {
				next = page[len(page)-1].Sequence
			}
			latest := uint64(0)
			if len(*changes) > 0 {
				latest = (*changes)[len(*changes)-1].Sequence
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"data": page, "cursor": next, "has_more": false, "latest": latest})
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(control.Close)
	node := newFakeNode()
	local := httptest.NewServer(node.handler())
	t.Cleanup(local.Close)
	c := &client{base: control.URL, dir: t.TempDir(), http: control.Client(), dnsHTTP: local.Client(), dnsAPIURL: local.URL, dnsAPIKey: "local-key",
		id: identity0(privatePEM)}
	return c, node
}

func identity0(privatePEM string) identity {
	return identity{EdgeID: "edge-1", PrivateKey: privatePEM}
}

func TestANodeWithoutADNSServiceDoesNothing(t *testing.T) {
	c := &client{}
	if report := c.syncDNS(); report != nil {
		t.Fatalf("a PoP with no local DNS service must report nothing, got %v", report)
	}
}

func TestANewNodeConfiguresItselfThenPullsEveryChangedZone(t *testing.T) {
	changes := []dnsChange{{Sequence: 1, Zone: "a.test", Serial: 7}, {Sequence: 2, Zone: "b.test", Serial: 9}}
	c, node := dnsTestClient(t, &changes)
	node.primary = map[string]uint64{"a.test.": 7, "b.test.": 9}

	first := c.syncDNS()
	if node.tsig["cdnf-nodes"] != "c2VjcmV0LWJ5dGVz" {
		t.Fatalf("the transfer key was not installed with the opened secret: %v", node.tsig)
	}
	catalog := node.zones["catalog.cdnfoundry.invalid."]
	if catalog == nil || catalog.Kind != "Consumer" || len(catalog.Keys) != 1 {
		t.Fatalf("the catalog consumer was not created and signed: %+v", catalog)
	}
	if first["cursor"] != uint64(0) || first["pending"] != 2 {
		t.Fatalf("zones the catalog has not created yet are pending and hold the cursor: %v", first)
	}
	if node.catalogRuns == 0 {
		t.Fatal("the catalog must be refreshed when zones are waiting")
	}

	c.syncDNS() // the zones now exist: they are signed and pulled
	for _, name := range []string{"a.test.", "b.test."} {
		if len(node.zones[name].Keys) != 1 {
			t.Fatalf("%s was not given the transfer key", name)
		}
	}
	third := c.syncDNS()
	if third["cursor"] != uint64(2) || third["pending"] != 0 || third["latest"] != uint64(2) {
		t.Fatalf("a converged node reports the latest cursor and nothing pending: %v", third)
	}
	if node.zones["a.test."].Serial != 7 || node.zones["b.test."].Serial != 9 {
		t.Fatalf("the zones were not pulled: %+v %+v", node.zones["a.test."], node.zones["b.test."])
	}
}

func TestTheCursorStaysBehindTheFirstUnappliedZoneAndIsRememberedAcrossRestarts(t *testing.T) {
	changes := []dnsChange{{Sequence: 1, Zone: "ok.test", Serial: 3}, {Sequence: 2, Zone: "missing.test", Serial: 3}, {Sequence: 3, Zone: "later.test", Serial: 3}}
	c, node := dnsTestClient(t, &changes)
	node.primary = map[string]uint64{"ok.test.": 3, "later.test.": 3} // missing.test never reaches this node
	for i := 0; i < 3; i++ {
		c.syncDNS()
	}
	report := c.syncDNS()
	if report["cursor"] != uint64(1) || report["pending"] != 1 {
		t.Fatalf("the cursor must stop before the first zone the node cannot serve: %v", report)
	}

	restarted := &client{base: c.base, dir: c.dir, http: c.http, dnsHTTP: c.dnsHTTP, dnsAPIURL: c.dnsAPIURL, dnsAPIKey: c.dnsAPIKey, id: c.id}
	restarted.loadDNSState()
	if restarted.dns.Cursor != 1 {
		t.Fatalf("the cursor must survive a restart, got %d", restarted.dns.Cursor)
	}
}

func TestAZoneStuckForTenMinutesStopsHoldingTheCursorBack(t *testing.T) {
	changes := []dnsChange{{Sequence: 1, Zone: "missing.test", Serial: 3}, {Sequence: 2, Zone: "ok.test", Serial: 3}}
	c, node := dnsTestClient(t, &changes)
	node.primary = map[string]uint64{"ok.test.": 3}
	for i := 0; i < 3; i++ {
		c.syncDNS()
	}
	if c.dns.Cursor != 0 {
		t.Fatalf("the cursor must wait for the missing zone, got %d", c.dns.Cursor)
	}
	c.dns.firstSeen["missing.test"] = time.Now().Add(-11 * time.Minute)
	report := c.syncDNS()
	if report["cursor"] != uint64(2) || report["pending"] != 0 {
		t.Fatalf("a zone that cannot be pulled must be given up on and reported, not hold every later zone: %v", report)
	}
}

func TestDeletedZonesNeedNoLocalAction(t *testing.T) {
	changes := []dnsChange{{Sequence: 1, Zone: "gone.test", Deleted: true}}
	c, _ := dnsTestClient(t, &changes)
	c.syncDNS()
	if report := c.syncDNS(); report["cursor"] != uint64(1) || report["pending"] != 0 {
		t.Fatalf("a deleted zone is removed by the catalog and is already applied: %v", report)
	}
}

func TestAnUnreachableLocalServiceIsReportedNotFatal(t *testing.T) {
	changes := []dnsChange{}
	c, _ := dnsTestClient(t, &changes)
	c.dnsAPIURL = "http://127.0.0.1:1"
	report := c.syncDNS()
	if report["error"] == nil || report["cursor"] != uint64(0) {
		t.Fatalf("the failure must be reported in the heartbeat: %v", report)
	}
}

func TestANodeThatLosesItsCatalogIsConfiguredAgain(t *testing.T) {
	changes := []dnsChange{{Sequence: 1, Zone: "a.test", Serial: 7}}
	c, node := dnsTestClient(t, &changes)
	node.primary = map[string]uint64{"a.test.": 7}
	for i := 0; i < 3; i++ {
		c.syncDNS()
	}
	if c.dns.Cursor != 1 {
		t.Fatalf("the node must have converged first, cursor %d", c.dns.Cursor)
	}

	// The node's store is replaced: no key, no catalog, no zones.
	node.tsig, node.zones = map[string]string{}, map[string]*fakeZone{}
	c.dns.catalogAt = time.Time{}
	for i := 0; i < 4; i++ {
		c.syncDNS()
	}
	if node.tsig["cdnf-nodes"] == "" || node.zones["catalog.cdnfoundry.invalid."] == nil {
		t.Fatalf("a node that lost its catalog must be configured again: %+v %v", node.zones, node.tsig)
	}
	if c.dns.Cursor != 1 {
		t.Fatalf("and must pull its zones again, cursor %d", c.dns.Cursor)
	}
}
