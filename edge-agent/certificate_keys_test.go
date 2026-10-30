package main

import (
	"encoding/json"
	"testing"
)

// The envelope below was produced by the control plane's EdgeKeyEnvelope::seal
// for edge "edge-1" and certificate "cert-1", so this test pins PHP/Go interop.
const interopIdentityKey = `-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgAr8lZhEn1dJp8bBU
zV8tWPUDbXaaWktJHAGBMdD5iDyhRANCAARo1XIdlHexdW3x+daDDLNbokoWPMof
lb1VqGdrklchQVl7R9Z+4il33Jcho2YmgbVSW6mlxxMXVqeIeYr6jDaO
-----END PRIVATE KEY-----`

var interopEnvelope = keyEnvelope{
	CertificateID:      "cert-1",
	Algorithm:          "ecdh-p256-hkdf-sha256-aes256gcm",
	EphemeralPublicKey: "BG+djU87HO50kpWClwO2pFcCCA+AWnnjrxCQdYuAAvT1Zt9HAfreMiPmum1n7/P6VmQ5LL2Oy4p9renRcpxdQFY=",
	Nonce:              "82qGEOibh1jcWs2E",
	Ciphertext:         "oJgoIVZS89WF85Tq9onTyAbVqy7L87zX/3H7AX9dnpU8vMOvWSrrQQojB8e5iz3yUIAEgYVP6Xi7SXvxvPH5B1QSgmM0ciweomfnIl5Lmw==",
}

func TestKeyEnvelopeOpensControlPlaneSeal(t *testing.T) {
	key, err := openKeyEnvelope(interopIdentityKey, "edge-1", "cert-1", interopEnvelope)
	if err != nil {
		t.Fatal(err)
	}
	if key != "-----BEGIN PRIVATE KEY-----\nVECTORKEY\n-----END PRIVATE KEY-----" {
		t.Fatalf("unexpected key %q", key)
	}
}

func TestKeyEnvelopeIsBoundToEdgeAndCertificate(t *testing.T) {
	if _, err := openKeyEnvelope(interopIdentityKey, "edge-2", "cert-1", interopEnvelope); err == nil {
		t.Fatal("envelope opened for a different edge")
	}
	other := interopEnvelope
	other.CertificateID = "cert-2"
	if _, err := openKeyEnvelope(interopIdentityKey, "edge-1", "cert-2", other); err == nil {
		t.Fatal("envelope opened for a different certificate")
	}
}

func TestCompileRuntimeRefusesCertificatesWithoutKeys(t *testing.T) {
	domain := json.RawMessage(`{"schema_version":1,"domain":"example.test","revision":1,"pools":["shared-default"],"tls":{"mode":"managed","certificates":[{"id":"base","certificate_pem":"c"}]},"hostnames":[{"hostname":"www.example.test","tls_certificate_id":"base","origin":{"host":"o"}}]}`)
	s := state{Sequence: 1, Domains: map[string]json.RawMessage{"1": domain}}
	if _, _, err := compileRuntime(s, nil); err == nil {
		t.Fatal("runtime compiled a certificate without its key")
	}
	ids, err := requiredCertificateIDs(s)
	if err != nil || len(ids) != 1 || ids[0] != "base" {
		t.Fatalf("unexpected certificate ids %v %v", ids, err)
	}
}
