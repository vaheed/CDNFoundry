package main

import (
	"crypto/tls"
	"crypto/x509"
	"encoding/pem"
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"time"
)

// An identity certificate is renewed once two thirds of its lifetime have
// passed, so a failed renewal leaves a third of the lifetime to retry in.
const renewalRetryDelay = 10 * time.Minute

type pendingIdentity struct {
	Certificate, PrivateKey string
}

func (c *client) identityValidity() (time.Time, time.Time, error) {
	block, _ := pem.Decode([]byte(c.id.Certificate))
	if block == nil {
		return time.Time{}, time.Time{}, errors.New("edge identity certificate is not PEM")
	}
	certificate, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return time.Time{}, time.Time{}, err
	}
	return certificate.NotBefore, certificate.NotAfter, nil
}

func renewalDue(notBefore, notAfter, now time.Time) bool {
	return !now.Before(notBefore.Add(notAfter.Sub(notBefore) * 2 / 3))
}

// renewIdentityIfDue renews the identity over mTLS when it is due, and finishes
// a renewal that an earlier pass or process left unproven. The old certificate
// stays in identity.json until the new one has authenticated.
func (c *client) renewIdentityIfDue(now time.Time) error {
	if now.Before(c.renewalRetryAt) {
		return nil
	}
	pendingPath := filepath.Join(c.dir, "pending-identity.json")
	var pending pendingIdentity
	err := c.readAgentSecret(pendingPath, &pending)
	switch {
	case errors.Is(err, os.ErrNotExist):
		notBefore, notAfter, err := c.identityValidity()
		if err != nil || !renewalDue(notBefore, notAfter, now) {
			return err
		}
		request, key, err := certificateRequest(c.id.EdgeID)
		if err != nil {
			return err
		}
		var response struct {
			Data map[string]string `json:"data"`
		}
		if err := c.request("POST", "/edge/v1/identity/renew", map[string]any{"certificate_request": request}, &response, true); err != nil {
			c.renewalRetryAt = now.Add(renewalRetryDelay)
			return err
		}
		pending = pendingIdentity{Certificate: response.Data["identity_certificate"], PrivateKey: key}
		if _, err := tls.X509KeyPair([]byte(pending.Certificate), []byte(pending.PrivateKey)); err != nil {
			c.renewalRetryAt = now.Add(renewalRetryDelay)
			return errors.New("renewed identity does not match its key")
		}
		if err := c.writeAgentSecret(pendingPath, pending); err != nil {
			return err
		}
		logger.Info("edge identity renewal issued", "event", "edge_identity_renewal_issued", "edge_id", c.id.EdgeID)
	case err != nil:
		return err
	}
	return c.proveIdentity(pending, pendingPath, now)
}

// proveIdentity authenticates once with the renewed certificate. Control makes
// it current on that first use, so the swap is committed only after success.
func (c *client) proveIdentity(pending pendingIdentity, pendingPath string, now time.Time) error {
	current := c.id
	candidate := current
	candidate.Certificate, candidate.PrivateKey = pending.Certificate, pending.PrivateKey
	c.id = candidate
	if err := c.configureMutualTLS(); err != nil {
		c.restoreIdentity(current)
		_ = os.Remove(pendingPath)
		return err
	}
	if err := c.request("GET", "/edge/v1/gateway/config", nil, &map[string]any{}, true); err != nil {
		c.restoreIdentity(current)
		c.renewalRetryAt = now.Add(renewalRetryDelay)
		var status *httpStatusError
		if errors.As(err, &status) && status.Code == http.StatusUnauthorized {
			// Control does not know this certificate. Start the renewal over.
			_ = os.Remove(pendingPath)
		}
		return err
	}
	// Control now treats the new certificate as current. Keep using it even if
	// persisting fails; the pending file stays and the next pass retries.
	if err := c.writeAgentSecret(filepath.Join(c.dir, "identity.json"), candidate); err != nil {
		return err
	}
	if err := os.Remove(pendingPath); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	logger.Info("edge identity renewed", "event", "edge_identity_renewed", "edge_id", c.id.EdgeID)
	return nil
}

func (c *client) restoreIdentity(previous identity) {
	c.id = previous
	_ = c.configureMutualTLS()
}
