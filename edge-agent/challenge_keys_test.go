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
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"
	"time"
)

const (
	testKeyA = "aaaaaaaaaaaaaaaa"
	testKeyB = "bbbbbbbbbbbbbbbb"
)

func testSecret(letter string) string { return strings.Repeat(letter, 64) }

// sealForEdge does what the control plane's EdgeKeyEnvelope::seal does, so the agent's fetch is tested end to end.
func sealForEdge(t *testing.T, identity *ecdsa.PrivateKey, edgeID, id, plain string) keyEnvelope {
	t.Helper()
	ephemeral, err := ecdh.P256().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	peer, err := identity.PublicKey.ECDH()
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
		CertificateID: id, Algorithm: keyEnvelopeAlgorithm, EphemeralPublicKey: base64.StdEncoding.EncodeToString(ephemeral.PublicKey().Bytes()),
		Nonce: base64.StdEncoding.EncodeToString(nonce), Ciphertext: base64.StdEncoding.EncodeToString(aead.Seal(nil, nonce, []byte(plain), []byte(context))),
	}
}

func identityPEM(t *testing.T) (*ecdsa.PrivateKey, string) {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	der, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		t.Fatal(err)
	}
	return key, string(pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: der}))
}

func keySet(version int64, expiresB *int64) challengeKeySet {
	return challengeKeySet{Version: version, Keys: []challengeKey{
		{ID: testKeyA, Secret: testSecret("a"), Signing: true},
		{ID: testKeyB, Secret: testSecret("b"), NotAfter: expiresB},
	}}
}

func TestChallengeKeySetValidation(t *testing.T) {
	good := keySet(3, nil)
	if !validChallengeKeys(good) {
		t.Fatal("a well-formed set was refused")
	}
	cases := map[string]func(*challengeKeySet){
		"no version":       func(s *challengeKeySet) { s.Version = 0 },
		"no keys":          func(s *challengeKeySet) { s.Keys = nil },
		"two signing keys": func(s *challengeKeySet) { s.Keys[1].Signing = true },
		"no signing key":   func(s *challengeKeySet) { s.Keys[0].Signing = false },
		"duplicate id":     func(s *challengeKeySet) { s.Keys[1].ID = s.Keys[0].ID },
		"short secret":     func(s *challengeKeySet) { s.Keys[1].Secret = "abc" },
		"uppercase id":     func(s *challengeKeySet) { s.Keys[1].ID = strings.ToUpper(testKeyB) },
		"too many keys": func(s *challengeKeySet) {
			s.Keys = append(s.Keys, challengeKey{ID: "cccccccccccccccc", Secret: testSecret("c")}, challengeKey{ID: "dddddddddddddddd", Secret: testSecret("d")}, challengeKey{ID: "eeeeeeeeeeeeeeee", Secret: testSecret("e")})
		},
	}
	for name, mutate := range cases {
		set := keySet(3, nil)
		set.Keys = append([]challengeKey(nil), set.Keys...)
		mutate(&set)
		if validChallengeKeys(set) {
			t.Fatalf("%s: an invalid set was accepted", name)
		}
	}
}

type keyCell struct {
	mu       sync.Mutex
	version  any
	commands []map[string]any
}

func (cell *keyCell) handler() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		cell.mu.Lock()
		defer cell.mu.Unlock()
		var command map[string]any
		_ = json.NewDecoder(io.LimitReader(r.Body, 128<<10)).Decode(&command)
		cell.commands = append(cell.commands, command)
		_, _ = w.Write([]byte(`{"data":{"accepted":true}}`))
	})
}

func TestChallengeKeysGoOnlyToACellThatDoesNotHoldTheVersionAndExpiredKeysAreLeftOut(t *testing.T) {
	cell := &keyCell{}
	server := httptest.NewServer(cell.handler())
	defer server.Close()
	past := time.Now().Add(-time.Minute).Unix()
	future := time.Now().Add(time.Hour).Unix()
	c := &client{http: server.Client(), statusToken: "s", dir: t.TempDir(), challenge: keySet(7, &future)}

	c.pushChallengeKeys(server.URL+"/passive-failures", float64(7))
	if len(cell.commands) != 0 {
		t.Fatal("a cell that already holds the version was sent the keys again")
	}
	c.pushChallengeKeys(server.URL+"/passive-failures", float64(6))
	c.pushChallengeKeys(server.URL+"/passive-failures", nil)
	if len(cell.commands) != 2 || cell.commands[0]["action"] != "challenge_keys" || cell.commands[0]["version"] != float64(7) {
		t.Fatalf("unexpected commands: %#v", cell.commands)
	}
	if got := len(cell.commands[0]["keys"].([]any)); got != 2 {
		t.Fatalf("expected two keys, got %d", got)
	}

	c.challenge = keySet(8, &past)
	command, err := c.challengeKeysCommand()
	if err != nil {
		t.Fatal(err)
	}
	if keys := command["keys"].([]challengeKey); len(keys) != 1 || keys[0].ID != testKeyA {
		t.Fatalf("an ended key was handed to a cell: %#v", keys)
	}
	if _, err := (&client{}).challengeKeysCommand(); err == nil {
		t.Fatal("a command was built without keys")
	}
}

func TestChallengeKeysAreFetchedSealedStoredPrivatelyAndKeptWhenAFetchFails(t *testing.T) {
	identity, identityPEMText := identityPEM(t)
	var mu sync.Mutex
	version, failing := int64(5), false
	set := keySet(5, nil)
	control := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		if failing {
			http.Error(w, "down", http.StatusServiceUnavailable)
			return
		}
		plain, _ := json.Marshal(set.Keys)
		_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"version": version, "keys": sealForEdge(t, identity, "edge-1", challengeKeysEnvelopeID, string(plain))}})
	}))
	defer control.Close()
	dir := t.TempDir()
	c := &client{base: control.URL, http: control.Client(), dir: dir, id: identity_(identityPEMText)}

	c.refreshChallengeKeys()
	if c.challenge.Version != 5 || len(c.challenge.Keys) != 2 {
		t.Fatalf("keys not fetched: %#v", c.challenge)
	}
	info, err := os.Stat(c.challengeKeysPath())
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("the key file must be private: %v %v", info, err)
	}

	// A control plane that is down keeps the keys the agent already holds.
	mu.Lock()
	failing = true
	mu.Unlock()
	c.challengeFetched = time.Time{}
	c.refreshChallengeKeys()
	if c.challenge.Version != 5 {
		t.Fatal("a failed fetch dropped the keys")
	}

	// A new agent process reads the stored keys before it has reached the control plane.
	restarted := &client{base: control.URL, http: control.Client(), dir: dir, id: identity_(identityPEMText), challengeFetched: time.Now()}
	restarted.refreshChallengeKeys()
	if restarted.challenge.Version != 5 {
		t.Fatal("stored keys were not reloaded")
	}

	// A rotation is a new version, and a set that does not validate never replaces the stored one.
	mu.Lock()
	failing, version = false, 6
	set = keySet(6, nil)
	set.Keys[1].Signing = true
	mu.Unlock()
	c.challengeFetched = time.Time{}
	c.refreshChallengeKeys()
	if c.challenge.Version != 5 {
		t.Fatal("an invalid set replaced the valid one")
	}
	mu.Lock()
	set = keySet(6, nil)
	set.Keys[0], set.Keys[1] = set.Keys[1], set.Keys[0]
	set.Keys[0].Signing, set.Keys[1].Signing = true, false
	mu.Unlock()
	c.challengeFetched = time.Time{}
	c.refreshChallengeKeys()
	if c.challenge.Version != 6 || !c.challenge.Keys[0].Signing {
		t.Fatalf("a rotation was not applied: %#v", c.challenge)
	}
}

func identity_(privateKey string) identity { return identity{EdgeID: "edge-1", PrivateKey: privateKey} }
