package main

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/ecdh"
	"crypto/ecdsa"
	"crypto/hmac"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
)

const keyEnvelopeAlgorithm = "ecdh-p256-hkdf-sha256-aes256gcm"

var certificateIDPattern = regexp.MustCompile(`^[A-Za-z0-9-]{1,64}$`)

type keyEnvelope struct {
	CertificateID      string `json:"certificate_id"`
	Algorithm          string `json:"algorithm"`
	EphemeralPublicKey string `json:"ephemeral_public_key"`
	Nonce              string `json:"nonce"`
	Ciphertext         string `json:"ciphertext"`
}

// requiredCertificateIDs lists every certificate id referenced by the state.
// Artifacts carry no private keys; the agent fetches them separately.
func requiredCertificateIDs(s state) ([]string, error) {
	seen := map[string]bool{}
	for _, raw := range s.Domains {
		var domain struct {
			TLS struct {
				Certificate  *struct{ ID string }  `json:"certificate"`
				Certificates []struct{ ID string } `json:"certificates"`
			} `json:"tls"`
		}
		if err := json.Unmarshal(raw, &domain); err != nil {
			return nil, err
		}
		if domain.TLS.Certificate != nil {
			seen[domain.TLS.Certificate.ID] = true
		}
		for _, certificate := range domain.TLS.Certificates {
			seen[certificate.ID] = true
		}
	}
	ids := make([]string, 0, len(seen))
	for id := range seen {
		if !certificateIDPattern.MatchString(id) {
			return nil, errors.New("invalid runtime certificate")
		}
		ids = append(ids, id)
	}
	sort.Strings(ids)
	return ids, nil
}

func (c *client) keyDirectory() string { return filepath.Join(c.dir, "keys") }

// certificateKeys returns the private key of every certificate the state needs,
// reading the local cache and fetching missing keys over mTLS.
func (c *client) certificateKeys(s state) (map[string]string, error) {
	ids, err := requiredCertificateIDs(s)
	if err != nil {
		return nil, err
	}
	keys := make(map[string]string, len(ids))
	for _, id := range ids {
		path := filepath.Join(c.keyDirectory(), id+".pem")
		if cached, err := os.ReadFile(path); err == nil && len(cached) > 0 {
			keys[id] = string(cached)
			continue
		}
		var response struct {
			Data keyEnvelope `json:"data"`
		}
		if err := c.request("GET", "/edge/v1/certificates/"+id+"/key", nil, &response, true); err != nil {
			return nil, fmt.Errorf("certificate key %s unavailable: %w", id, err)
		}
		key, err := openKeyEnvelope(c.id.PrivateKey, c.id.EdgeID, id, response.Data)
		if err != nil {
			return nil, fmt.Errorf("certificate key %s rejected: %w", id, err)
		}
		if err := os.MkdirAll(c.keyDirectory(), 0700); err != nil {
			return nil, err
		}
		temporary := path + ".tmp"
		if err := os.WriteFile(temporary, []byte(key), 0600); err != nil {
			return nil, err
		}
		if err := os.Rename(temporary, path); err != nil {
			return nil, err
		}
		keys[id] = key
	}
	return keys, nil
}

// pruneCertificateKeys removes cached keys that the active state no longer uses.
func (c *client) pruneCertificateKeys(keep map[string]string) {
	files, _ := filepath.Glob(filepath.Join(c.keyDirectory(), "*"))
	for _, file := range files {
		name := strings.TrimSuffix(strings.TrimSuffix(filepath.Base(file), ".tmp"), ".pem")
		if _, used := keep[name]; !used || strings.HasSuffix(file, ".tmp") {
			os.Remove(file)
		}
	}
}

func hkdfSHA256(secret []byte, info string) []byte {
	extract := hmac.New(sha256.New, make([]byte, sha256.Size))
	extract.Write(secret)
	expand := hmac.New(sha256.New, extract.Sum(nil))
	expand.Write([]byte(info))
	expand.Write([]byte{1})
	return expand.Sum(nil)
}

func openKeyEnvelope(identityKeyPEM, edgeID, certificateID string, envelope keyEnvelope) (string, error) {
	if envelope.Algorithm != keyEnvelopeAlgorithm || envelope.CertificateID != certificateID {
		return "", errors.New("unexpected key envelope")
	}
	block, _ := pem.Decode([]byte(identityKeyPEM))
	if block == nil {
		return "", errors.New("invalid edge identity key")
	}
	parsed, err := x509.ParsePKCS8PrivateKey(block.Bytes)
	if err != nil {
		return "", err
	}
	identity, ok := parsed.(*ecdsa.PrivateKey)
	if !ok {
		return "", errors.New("edge identity key is not an EC key")
	}
	private, err := identity.ECDH()
	if err != nil {
		return "", err
	}
	point, err := base64.StdEncoding.DecodeString(envelope.EphemeralPublicKey)
	if err != nil {
		return "", err
	}
	peer, err := ecdh.P256().NewPublicKey(point)
	if err != nil {
		return "", err
	}
	shared, err := private.ECDH(peer)
	if err != nil {
		return "", err
	}
	nonce, err := base64.StdEncoding.DecodeString(envelope.Nonce)
	if err != nil {
		return "", err
	}
	ciphertext, err := base64.StdEncoding.DecodeString(envelope.Ciphertext)
	if err != nil {
		return "", err
	}
	context := "cdnfoundry-edge-key-v1|" + edgeID + "|" + certificateID
	cipherBlock, err := aes.NewCipher(hkdfSHA256(shared, context))
	if err != nil {
		return "", err
	}
	aead, err := cipher.NewGCM(cipherBlock)
	if err != nil || len(nonce) != aead.NonceSize() {
		return "", errors.New("invalid key envelope nonce")
	}
	plain, err := aead.Open(nil, nonce, ciphertext, []byte(context))
	if err != nil {
		return "", errors.New("key envelope authentication failed")
	}
	if !strings.Contains(string(plain), "PRIVATE KEY") {
		return "", errors.New("key envelope did not contain a private key")
	}
	return string(plain), nil
}
