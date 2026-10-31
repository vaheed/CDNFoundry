package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
)

// The agent's durable desired state is one file per domain plus a small meta file, so a change to
// one domain rewrites that domain's file and nothing else.
//
//	state/meta.json            {"sequence": N}
//	state/domains/<id>.json    one verified domain artifact payload
//
// Domain files are written first and meta.json last. After a crash in between, the files are
// ahead of the sequence: the agent re-fetches the artifacts after the older sequence, and
// foldArtifacts treats an identical payload at the same revision as already applied.

var domainKeyPattern = regexp.MustCompile(`^[0-9]{1,20}$`)

type stateMeta struct {
	Sequence uint64 `json:"sequence"`
}

func (c *client) stateDir() string      { return filepath.Join(c.dir, "state") }
func (c *client) domainDir() string     { return filepath.Join(c.stateDir(), "domains") }
func (c *client) stateMetaPath() string { return filepath.Join(c.stateDir(), "meta.json") }

func digest(raw []byte) string {
	sum := sha256.Sum256(raw)
	return hex.EncodeToString(sum[:])
}

// loadStoredState assembles the durable state. It returns os.ErrNotExist when none was ever stored.
func (c *client) loadStoredState() (state, error) {
	body, err := os.ReadFile(c.stateMetaPath())
	if err != nil {
		return state{}, err
	}
	var meta stateMeta
	if err := json.Unmarshal(body, &meta); err != nil {
		return state{}, err
	}
	entries, err := os.ReadDir(c.domainDir())
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return state{}, err
	}
	loaded := state{Sequence: meta.Sequence, Domains: make(map[string]json.RawMessage, len(entries))}
	hashes := make(map[string]string, len(entries))
	for _, entry := range entries {
		key := entry.Name()[:max(0, len(entry.Name())-len(".json"))]
		if entry.IsDir() || filepath.Ext(entry.Name()) != ".json" || !domainKeyPattern.MatchString(key) {
			continue
		}
		raw, err := os.ReadFile(filepath.Join(c.domainDir(), entry.Name()))
		if err != nil {
			return state{}, err
		}
		loaded.Domains[key] = raw
		hashes[key] = digest(raw)
	}
	c.storedDigests = hashes
	return loaded, nil
}

func (c *client) hasStoredState() (bool, error) {
	if _, err := os.Stat(c.stateMetaPath()); err == nil {
		return true, nil
	} else if !errors.Is(err, os.ErrNotExist) {
		return false, err
	}
	return false, nil
}

// persistState writes the domains that changed, removes the ones that are gone, and records the
// sequence last. It returns how many domain files it wrote or removed.
func (c *client) persistState(s state) (int, error) {
	if c.storedDigests == nil {
		if _, err := c.loadStoredState(); err != nil && !errors.Is(err, os.ErrNotExist) {
			return 0, err
		}
		if c.storedDigests == nil {
			c.storedDigests = map[string]string{}
		}
	}
	if err := os.MkdirAll(c.domainDir(), 0700); err != nil {
		return 0, err
	}
	changed := 0
	for key, raw := range s.Domains {
		if !domainKeyPattern.MatchString(key) {
			return changed, errors.New("invalid domain key " + strconv.Quote(key))
		}
		sum := digest(raw)
		if c.storedDigests[key] == sum {
			continue
		}
		if err := writeAtomic(filepath.Join(c.domainDir(), key+".json"), raw); err != nil {
			return changed, err
		}
		c.storedDigests[key] = sum
		changed++
	}
	for key := range c.storedDigests {
		if _, present := s.Domains[key]; present {
			continue
		}
		if err := os.Remove(filepath.Join(c.domainDir(), key+".json")); err != nil && !errors.Is(err, os.ErrNotExist) {
			return changed, err
		}
		delete(c.storedDigests, key)
		changed++
	}
	meta, err := json.Marshal(stateMeta{Sequence: s.Sequence})
	if err != nil {
		return changed, err
	}
	return changed, writeAtomic(c.stateMetaPath(), meta)
}
