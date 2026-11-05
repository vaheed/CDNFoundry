package main

import (
	"crypto/ed25519"
	"encoding/hex"
	"errors"
	"path/filepath"
	"slices"
)

const rotationPrefix = "rotate|"

// trustedSigningKeys lists the keys the agent accepts: the key it trusts and any key a trusted key
// has endorsed during a rotation. The first is the one that has proven itself.
func (c *client) trustedSigningKeys() []string {
	keys := []string{c.id.PublicKey}
	return append(keys, c.id.AcceptedPublicKeys...)
}

// noteRotation records the next signing key when a trusted key endorses it. The endorsement is the
// only way a key joins the set: control-plane responses cannot add a key on their own.
func (c *client) noteRotation(publicKey, endorsement string) error {
	if slices.Contains(c.trustedSigningKeys(), publicKey) {
		return nil
	}
	signature, err := hex.DecodeString(endorsement)
	if err != nil || len(signature) != ed25519.SignatureSize {
		return errors.New("invalid signing key endorsement")
	}
	next, err := hex.DecodeString(publicKey)
	if err != nil || len(next) != ed25519.PublicKeySize {
		return errors.New("invalid endorsed signing key")
	}
	endorsed := false
	for _, trusted := range c.trustedSigningKeys() {
		key, err := hex.DecodeString(trusted)
		if err == nil && len(key) == ed25519.PublicKeySize && ed25519.Verify(key, []byte(rotationPrefix+publicKey), signature) {
			endorsed = true
			break
		}
	}
	if !endorsed {
		return errors.New("signing key endorsement is not signed by a trusted key")
	}
	if len(c.id.AcceptedPublicKeys) >= 2 {
		return errors.New("too many endorsed signing keys")
	}
	c.id.AcceptedPublicKeys = append(c.id.AcceptedPublicKeys, publicKey)
	if err := c.writeAgentSecret(filepath.Join(c.dir, "identity.json"), c.id); err != nil {
		return err
	}
	logger.Info("signing key endorsed; both keys are accepted until the new one is used", "event", "signing_key_endorsed", "edge_id", c.id.EdgeID)
	return nil
}

// verifySigned checks a payload signature and its scoped delivery signature against each trusted
// key, and requires both to come from the same key. A delivery signed by an endorsed key proves it:
// that key becomes the only trusted key and the previous one is retired.
func (c *client) verifySigned(encoded, checksum, signature, scope string, sequence uint64, expiresAt int64, deliverySignature string) ([]byte, error) {
	var failure error
	for _, key := range c.trustedSigningKeys() {
		payload, err := verify(encoded, checksum, signature, key)
		if err == nil {
			err = verifyDelivery(scope, checksum, sequence, expiresAt, deliverySignature, key)
		}
		if err != nil {
			if failure == nil {
				failure = err
			}
			continue
		}
		if key != c.id.PublicKey {
			c.id.PublicKey, c.id.AcceptedPublicKeys = key, nil
			if err := c.writeAgentSecret(filepath.Join(c.dir, "identity.json"), c.id); err != nil {
				return nil, err
			}
			logger.Info("new signing key proven; the previous key is retired", "event", "signing_key_rotated", "edge_id", c.id.EdgeID)
		}
		return payload, nil
	}
	return nil, failure
}
