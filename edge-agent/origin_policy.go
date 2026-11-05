package main

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
)

// originPolicy is the platform-wide origin destination policy. The control plane delivers it once
// per PoP, signed, instead of copying it into every domain artifact; the agent injects it into
// each compiled origin, so cells keep reading the same per-origin fields.
type originPolicy struct {
	PrivateAllowlist []string `json:"private_allowlist"`
	BlockedNetworks  []string `json:"blocked_networks"`
	BlockedAddresses []string `json:"blocked_addresses"`
}

type storedOriginPolicy struct {
	Checksum string       `json:"checksum"`
	Policy   originPolicy `json:"policy"`
}

const originPolicyScope = "origin-policy"

func (c *client) originPolicyPath() string {
	return filepath.Join(c.dir, "origin-policy.json")
}

// loadOriginPolicy reads the last verified policy from disk once.
func (c *client) loadOriginPolicy() {
	if c.originPolicyLoaded {
		return
	}
	c.originPolicyLoaded = true
	body, err := os.ReadFile(c.originPolicyPath())
	if err != nil {
		return
	}
	var stored storedOriginPolicy
	if json.Unmarshal(body, &stored) == nil && stored.Checksum != "" {
		c.originPolicy, c.originPolicyChecksum = stored.Policy, stored.Checksum
	}
}

// syncOriginPolicy fetches the policy when its checksum differs from the one held and reports
// whether the held policy changed. While a policy is held, a fetch failure keeps it: an
// unreachable control plane must not stop serving. With none held, the error is returned and the
// caller must not activate runtime files, because an origin without the policy would be unsafe.
func (c *client) syncOriginPolicy() (bool, error) {
	c.loadOriginPolicy()
	var response struct {
		Unchanged         bool   `json:"unchanged"`
		Encoded           string `json:"encoded_payload"`
		Checksum          string `json:"checksum"`
		Signature         string `json:"signature"`
		ExpiresAt         int64  `json:"expires_at"`
		DeliverySignature string `json:"delivery_signature"`
	}
	if err := c.request("GET", "/edge/v1/origin-policy?checksum="+c.originPolicyChecksum, nil, &response, true); err != nil {
		if c.originPolicyChecksum != "" {
			return false, nil
		}
		return false, err
	}
	if response.Unchanged && response.Checksum == c.originPolicyChecksum && c.originPolicyChecksum != "" {
		return false, nil
	}
	payload, err := c.verifySigned(response.Encoded, response.Checksum, response.Signature, originPolicyScope, 0, response.ExpiresAt, response.DeliverySignature)
	var policy originPolicy
	if err == nil {
		err = json.Unmarshal(payload, &policy)
	}
	if err != nil {
		if c.originPolicyChecksum != "" {
			logger.Error("origin policy rejected; the previous policy stays active", "event", "origin_policy_rejected", "edge_id", c.id.EdgeID, "error_code", reasonSignatureOrChecksum, "error", err.Error())
			return false, nil
		}
		return false, errors.New("origin policy rejected: " + err.Error())
	}
	if err := atomicJSON(c.originPolicyPath(), storedOriginPolicy{Checksum: response.Checksum, Policy: policy}); err != nil {
		return false, err
	}
	c.originPolicy, c.originPolicyChecksum = policy, response.Checksum
	logger.Info("origin policy updated", "event", "origin_policy_updated", "edge_id", c.id.EdgeID, "checksum", response.Checksum)
	return true, nil
}

// applyOriginPolicy sets the policy on every compiled origin and its backup. Compiled hosts are
// shared by the combined runtime, the pool runtimes, and the cell runtimes, so one pass covers all.
func applyOriginPolicy(runtime map[string]any, policy originPolicy) {
	hosts, _ := runtime["hosts"].(map[string]any)
	for _, entry := range hosts {
		host, _ := entry.(map[string]any)
		origin, _ := host["origin"].(map[string]any)
		if origin == nil {
			continue
		}
		setPolicy(origin, policy)
		if backup, ok := origin["backup"].(map[string]any); ok {
			setPolicy(backup, policy)
		}
	}
}

func setPolicy(origin map[string]any, policy originPolicy) {
	origin["private_allowlist"] = nonNil(policy.PrivateAllowlist)
	origin["blocked_networks"] = nonNil(policy.BlockedNetworks)
	origin["blocked_addresses"] = nonNil(policy.BlockedAddresses)
}

func nonNil(values []string) []string {
	if values == nil {
		return []string{}
	}
	return values
}
