package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"strconv"
	"testing"
	"time"
)

type signedDelivery struct {
	encoded, checksum, signature, delivery string
	expiresAt                              int64
}

func newKey(t *testing.T) (ed25519.PrivateKey, string) {
	public, private, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	return private, hex.EncodeToString(public)
}

func deliver(private ed25519.PrivateKey, payload, scope string, sequence uint64) signedDelivery {
	sum := sha256.Sum256([]byte(payload))
	checksum := hex.EncodeToString(sum[:])
	expiresAt := time.Now().Add(5 * time.Minute).Unix()
	message := scope + "|" + checksum + "|" + strconv.FormatUint(sequence, 10) + "|" + strconv.FormatInt(expiresAt, 10)
	return signedDelivery{
		encoded: base64.StdEncoding.EncodeToString([]byte(payload)), checksum: checksum, expiresAt: expiresAt,
		signature: hex.EncodeToString(ed25519.Sign(private, []byte(checksum))),
		delivery:  hex.EncodeToString(ed25519.Sign(private, []byte(message))),
	}
}

func (d signedDelivery) check(c *client, scope string, sequence uint64) error {
	_, err := c.verifySigned(d.encoded, d.checksum, d.signature, scope, sequence, d.expiresAt, d.delivery)
	return err
}

func endorse(private ed25519.PrivateKey, next string) string {
	return hex.EncodeToString(ed25519.Sign(private, []byte(rotationPrefix+next)))
}

func TestSigningKeyRotationOverlapsThenRetiresTheOldKey(t *testing.T) {
	oldPrivate, oldPublic := newKey(t)
	newPrivate, newPublic := newKey(t)
	c := &client{dir: t.TempDir(), id: identity{EdgeID: "edge-1", PublicKey: oldPublic}}

	if err := c.noteRotation(newPublic, endorse(oldPrivate, newPublic)); err != nil {
		t.Fatal(err)
	}
	// Overlap: deliveries signed by either key are accepted, and the old key is still the trusted one.
	if err := deliver(oldPrivate, `{"a":1}`, "snapshot", 1).check(c, "snapshot", 1); err != nil {
		t.Fatalf("the old key must keep working during the overlap: %v", err)
	}
	if c.id.PublicKey != oldPublic || len(c.id.AcceptedPublicKeys) != 1 {
		t.Fatalf("an old-key delivery must not promote the new key: %+v", c.id)
	}
	// The first delivery signed by the new key proves it and retires the old one.
	if err := deliver(newPrivate, `{"a":2}`, "snapshot", 2).check(c, "snapshot", 2); err != nil {
		t.Fatal(err)
	}
	if c.id.PublicKey != newPublic || len(c.id.AcceptedPublicKeys) != 0 {
		t.Fatalf("the new key must now be the only trusted key: %+v", c.id)
	}
	if err := deliver(oldPrivate, `{"a":3}`, "snapshot", 3).check(c, "snapshot", 3); err == nil {
		t.Fatal("the retired key must no longer be accepted")
	}
	var persisted identity
	if err := c.readAgentSecret(c.dir+"/identity.json", &persisted); err != nil || persisted.PublicKey != newPublic {
		t.Fatalf("the promotion must survive a restart: %v %+v", err, persisted)
	}
}

func TestAnUnendorsedKeyNeverJoinsTheTrustedSet(t *testing.T) {
	_, trusted := newKey(t)
	strangerPrivate, stranger := newKey(t)
	c := &client{dir: t.TempDir(), id: identity{EdgeID: "edge-1", PublicKey: trusted}}

	if err := c.noteRotation(stranger, endorse(strangerPrivate, stranger)); err == nil {
		t.Fatal("a key endorsed only by itself must be refused")
	}
	if err := c.noteRotation(stranger, "00"); err == nil {
		t.Fatal("a malformed endorsement must be refused")
	}
	if len(c.id.AcceptedPublicKeys) != 0 {
		t.Fatalf("nothing may be added: %+v", c.id)
	}
	if err := deliver(strangerPrivate, `{}`, "snapshot", 1).check(c, "snapshot", 1); err == nil {
		t.Fatal("a delivery signed by an unknown key must be refused")
	}
}

func TestEndorsementsAreBoundedAndRepeatsAreHarmless(t *testing.T) {
	oldPrivate, oldPublic := newKey(t)
	_, first := newKey(t)
	_, second := newKey(t)
	_, third := newKey(t)
	c := &client{dir: t.TempDir(), id: identity{EdgeID: "edge-1", PublicKey: oldPublic}}

	for _, next := range []string{first, first, second} {
		if err := c.noteRotation(next, endorse(oldPrivate, next)); err != nil {
			t.Fatal(err)
		}
	}
	if len(c.id.AcceptedPublicKeys) != 2 {
		t.Fatalf("a repeated notice must not add the key twice: %v", c.id.AcceptedPublicKeys)
	}
	if err := c.noteRotation(third, endorse(oldPrivate, third)); err == nil {
		t.Fatal("the set of endorsed keys must stay bounded")
	}
}
