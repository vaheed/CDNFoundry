package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"math/big"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func issueIdentity(t *testing.T, public any, signer *ecdsa.PrivateKey, notBefore, notAfter time.Time) string {
	t.Helper()
	template := &x509.Certificate{SerialNumber: big.NewInt(time.Now().UnixNano()), Subject: pkix.Name{CommonName: "edge-1"}, NotBefore: notBefore, NotAfter: notAfter, KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}}
	der, err := x509.CreateCertificate(rand.Reader, template, template, public, signer)
	if err != nil {
		t.Fatal(err)
	}
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}))
}

func identityFixture(t *testing.T, notBefore, notAfter time.Time) identity {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	encoded, _ := x509.MarshalPKCS8PrivateKey(key)
	return identity{EdgeID: "edge-1", Certificate: issueIdentity(t, &key.PublicKey, key, notBefore, notAfter),
		PrivateKey: string(pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: encoded})), PublicKey: "00"}
}

func TestRenewalIsDueAtTwoThirdsOfTheLifetime(t *testing.T) {
	start := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	end := start.Add(300 * 24 * time.Hour)
	if renewalDue(start, end, start.Add(199*24*time.Hour)) || !renewalDue(start, end, start.Add(200*24*time.Hour)) {
		t.Fatal("renewal must start at exactly two thirds of the lifetime")
	}
}

func renewalServer(t *testing.T, refuseProof bool, calls *[]string) *httptest.Server {
	return httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		*calls = append(*calls, r.URL.Path)
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/edge/v1/identity/renew":
			var body struct {
				Request string `json:"certificate_request"`
			}
			_ = json.NewDecoder(r.Body).Decode(&body)
			block, _ := pem.Decode([]byte(body.Request))
			request, err := x509.ParseCertificateRequest(block.Bytes)
			if err != nil {
				http.Error(w, "bad csr", http.StatusUnprocessableEntity)
				return
			}
			signer, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
			certificate := issueIdentity(t, request.PublicKey, signer, time.Now().Add(-time.Minute), time.Now().Add(365*24*time.Hour))
			w.WriteHeader(http.StatusCreated)
			_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]string{"identity_certificate": certificate}})
		case "/edge/v1/gateway/config":
			if refuseProof {
				http.Error(w, "unauthorized", http.StatusUnauthorized)
				return
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{}})
		}
	}))
}

func TestDueIdentityIsRenewedProvenAndPersisted(t *testing.T) {
	var calls []string
	server := renewalServer(t, false, &calls)
	defer server.Close()
	now := time.Now()
	original := identityFixture(t, now.Add(-300*24*time.Hour), now.Add(65*24*time.Hour))
	c := &client{base: server.URL, dir: t.TempDir(), http: server.Client(), id: original}

	if err := c.renewIdentityIfDue(now); err != nil {
		t.Fatal(err)
	}

	if c.id.Certificate == original.Certificate || c.id.PrivateKey == original.PrivateKey {
		t.Fatal("the identity was not replaced")
	}
	var persisted identity
	if err := c.readAgentSecret(filepath.Join(c.dir, "identity.json"), &persisted); err != nil || persisted.Certificate != c.id.Certificate {
		t.Fatalf("the proven identity was not persisted: %v", err)
	}
	if _, err := os.Stat(filepath.Join(c.dir, "pending-identity.json")); !os.IsNotExist(err) {
		t.Fatal("the pending file must be removed once the identity is proven")
	}
	if len(calls) != 2 || calls[0] != "/edge/v1/identity/renew" || calls[1] != "/edge/v1/gateway/config" {
		t.Fatalf("unexpected calls %v", calls)
	}
}

func TestIdentityThatIsNotDueIsLeftAlone(t *testing.T) {
	var calls []string
	server := renewalServer(t, false, &calls)
	defer server.Close()
	now := time.Now()
	original := identityFixture(t, now.Add(-10*24*time.Hour), now.Add(355*24*time.Hour))
	c := &client{base: server.URL, dir: t.TempDir(), http: server.Client(), id: original}

	if err := c.renewIdentityIfDue(now); err != nil || len(calls) != 0 || c.id.Certificate != original.Certificate {
		t.Fatalf("a young identity must not be renewed: err=%v calls=%v", err, calls)
	}
}

func TestUnprovenRenewalKeepsTheOldIdentityAndRetriesLater(t *testing.T) {
	var calls []string
	server := renewalServer(t, true, &calls)
	defer server.Close()
	now := time.Now()
	original := identityFixture(t, now.Add(-300*24*time.Hour), now.Add(65*24*time.Hour))
	c := &client{base: server.URL, dir: t.TempDir(), http: server.Client(), id: original}

	if err := c.renewIdentityIfDue(now); err == nil {
		t.Fatal("a refused proof must be reported")
	}

	if c.id.Certificate != original.Certificate {
		t.Fatal("the old identity must survive an unproven renewal")
	}
	if err := c.renewIdentityIfDue(now.Add(time.Minute)); err != nil || len(calls) != 2 {
		t.Fatalf("a failed renewal must back off: err=%v calls=%v", err, calls)
	}
}

func TestUnfinishedRenewalIsResumedAfterARestart(t *testing.T) {
	var calls []string
	server := renewalServer(t, false, &calls)
	defer server.Close()
	now := time.Now()
	original := identityFixture(t, now.Add(-300*24*time.Hour), now.Add(65*24*time.Hour))
	renewed := identityFixture(t, now.Add(-time.Minute), now.Add(365*24*time.Hour))
	c := &client{base: server.URL, dir: t.TempDir(), http: server.Client(), id: original}
	if err := c.writeAgentSecret(filepath.Join(c.dir, "pending-identity.json"), pendingIdentity{Certificate: renewed.Certificate, PrivateKey: renewed.PrivateKey}); err != nil {
		t.Fatal(err)
	}

	if err := c.renewIdentityIfDue(now); err != nil {
		t.Fatal(err)
	}

	if c.id.Certificate != renewed.Certificate || len(calls) != 1 || calls[0] != "/edge/v1/gateway/config" {
		t.Fatalf("the pending identity must be proven without a second signing request: %v", calls)
	}
}
