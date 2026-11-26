package main

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"time"
)

// The platform's challenge keys let a token or a seed signed on one cell be checked on any other. The control plane seals them to
// this edge's identity key; the agent keeps them in its state directory (mode 0600), never in a snapshot or a log, and gives them to
// every cell over the private control channel. A cell reports the version it holds, so a restarted or unreachable cell is brought
// up to date on the next status cycle without a separate restore step.
const (
	challengeKeysEnvelopeID = "challenge-keys"
	challengeKeysRefresh    = time.Minute
	maximumChallengeKeys    = 4
)

var (
	challengeKeyIDPattern     = regexp.MustCompile(`^[0-9a-f]{16}$`)
	challengeKeySecretPattern = regexp.MustCompile(`^[0-9a-f]{64}$`)
)

type challengeKey struct {
	ID       string `json:"id"`
	Secret   string `json:"secret"`
	Signing  bool   `json:"signing"`
	NotAfter *int64 `json:"not_after,omitempty"`
}

type challengeKeySet struct {
	Version int64          `json:"version"`
	Keys    []challengeKey `json:"keys"`
}

func (c *client) challengeKeysPath() string { return filepath.Join(c.dir, "challenge-keys.json") }

// validChallengeKeys accepts a set with one signing key, at most four keys, unique ids, and well-formed ids and secrets.
func validChallengeKeys(set challengeKeySet) bool {
	if set.Version < 1 || len(set.Keys) == 0 || len(set.Keys) > maximumChallengeKeys {
		return false
	}
	seen, signing := map[string]bool{}, 0
	for _, key := range set.Keys {
		if !challengeKeyIDPattern.MatchString(key.ID) || !challengeKeySecretPattern.MatchString(key.Secret) || seen[key.ID] {
			return false
		}
		if key.NotAfter != nil && *key.NotAfter <= 0 {
			return false
		}
		seen[key.ID] = true
		if key.Signing {
			signing++
		}
	}
	return signing == 1
}

// loadChallengeKeys reads the stored set once, so cells keep their keys while the control plane is unreachable after a restart.
func (c *client) loadChallengeKeys() {
	if c.challenge.Version != 0 {
		return
	}
	body, err := os.ReadFile(c.challengeKeysPath())
	if err != nil {
		return
	}
	var set challengeKeySet
	if json.Unmarshal(body, &set) == nil && validChallengeKeys(set) {
		c.challenge = set
	}
}

// refreshChallengeKeys fetches the keys at most once a minute. A failure, or a set that does not validate, keeps the previous keys.
func (c *client) refreshChallengeKeys() {
	c.loadChallengeKeys()
	if time.Since(c.challengeFetched) < challengeKeysRefresh {
		return
	}
	c.challengeFetched = time.Now()
	var response struct {
		Data struct {
			Version int64       `json:"version"`
			Keys    keyEnvelope `json:"keys"`
		} `json:"data"`
	}
	if err := c.request("GET", "/edge/v1/challenge-keys", nil, &response, true); err != nil {
		logger.Warn("challenge keys not fetched", "event", "challenge_keys_unavailable", "edge_id", c.id.EdgeID)
		return
	}
	if response.Data.Version == c.challenge.Version {
		return
	}
	plain, err := openEnvelope(c.id.PrivateKey, c.id.EdgeID, challengeKeysEnvelopeID, response.Data.Keys)
	if err != nil {
		logger.Warn("challenge keys rejected", "event", "challenge_keys_rejected", "edge_id", c.id.EdgeID)
		return
	}
	set := challengeKeySet{Version: response.Data.Version}
	if json.Unmarshal([]byte(plain), &set.Keys) != nil || !validChallengeKeys(set) {
		logger.Warn("challenge keys invalid", "event", "challenge_keys_invalid", "edge_id", c.id.EdgeID)
		return
	}
	body, err := json.Marshal(set)
	if err != nil || replaceFile(c.challengeKeysPath(), body, 0600) != nil {
		logger.Warn("challenge keys not stored", "event", "challenge_keys_not_stored", "edge_id", c.id.EdgeID)
		return
	}
	c.challenge = set
	logger.Info("challenge keys updated", "event", "challenge_keys_updated", "edge_id", c.id.EdgeID, "version", set.Version)
}

// challengeKeysCommand is what a cell is given: the keys that have not ended, by this agent's clock.
func (c *client) challengeKeysCommand() (map[string]any, error) {
	if c.challenge.Version == 0 {
		return nil, errors.New("no challenge keys")
	}
	live := make([]challengeKey, 0, len(c.challenge.Keys))
	for _, key := range c.challenge.Keys {
		if key.NotAfter == nil || *key.NotAfter > time.Now().Unix() {
			live = append(live, key)
		}
	}
	return map[string]any{"task_id": "challenge-keys-" + strconv.FormatInt(c.challenge.Version, 10), "action": "challenge_keys", "version": c.challenge.Version, "keys": live}, nil
}

// pushChallengeKeys gives one cell the keys when it does not report the current version.
func (c *client) pushChallengeKeys(endpoint string, reported any) {
	if c.challenge.Version == 0 {
		return
	}
	if held, ok := reported.(float64); ok && int64(held) == c.challenge.Version {
		return
	}
	command, err := c.challengeKeysCommand()
	if err != nil {
		return
	}
	_ = c.control(endpoint, command)
}
