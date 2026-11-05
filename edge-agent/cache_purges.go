package main

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sort"
	"time"
)

// URL purges are kept on disk so a restarted cell can be given them again. A
// cell's purge dictionary lives in memory, and a cell reports whether the agent
// has restored it since it started.
const (
	maximumPurgeKeys      = 50000
	purgeRetention        = 365 * 24 * time.Hour
	purgeRestoreBatchSize = 100
)

var errPurgeCapacity = errors.New("cache purge capacity exceeded")

type persistedPurge struct {
	PurgeID uint64   `json:"purge_id"`
	Domain  string   `json:"domain"`
	Type    string   `json:"type"`
	Epoch   uint64   `json:"cache_epoch"`
	Keys    []string `json:"cache_keys"`
	At      int64    `json:"at"`
}

func (c *client) purgeStatePath() string { return filepath.Join(c.dir, "cache-purges.json") }

func (c *client) loadPurges() ([]persistedPurge, error) {
	body, err := os.ReadFile(c.purgeStatePath())
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var records []persistedPurge
	if err := json.Unmarshal(body, &records); err != nil {
		return nil, err
	}
	return records, nil
}

// persistPurge records a purge before any cell is told about it. Records older
// than the longest cache lifetime are dropped. A purge that would exceed the key
// bound is refused, so a purge is never forgotten silently.
func (c *client) persistPurge(record persistedPurge) error {
	records, err := c.loadPurges()
	if err != nil {
		return err
	}
	cutoff := time.Now().Add(-purgeRetention).Unix()
	kept, keys := records[:0], 0
	for _, existing := range records {
		if existing.PurgeID == record.PurgeID {
			return nil
		}
		if existing.At >= cutoff {
			kept = append(kept, existing)
			keys += len(existing.Keys)
		}
	}
	if keys+len(record.Keys) > maximumPurgeKeys {
		return errPurgeCapacity
	}
	kept = append(kept, record)
	sort.Slice(kept, func(i, j int) bool { return kept[i].PurgeID < kept[j].PurgeID })
	return atomicJSON(c.purgeStatePath(), kept)
}

// restorePurges replays every persisted purge to one cell that reports it has not
// been restored, then marks it restored. Replays use the original purge ids, so
// they are idempotent.
func (c *client) restorePurges(endpoint string) {
	records, err := c.loadPurges()
	if err != nil {
		return
	}
	for _, record := range records {
		for start := 0; start < len(record.Keys) || start == 0; start += purgeRestoreBatchSize {
			end := min(start+purgeRestoreBatchSize, len(record.Keys))
			keys := append([]string{}, record.Keys[start:end]...)
			command := map[string]any{
				"task_id": "restore-purge-" + time.Now().UTC().Format("20060102T150405.000000000"), "action": "cache_purge",
				"domain": record.Domain, "type": record.Type, "cache_epoch": record.Epoch, "purge_id": record.PurgeID, "cache_keys": keys,
			}
			if err := c.control(endpoint, command); err != nil {
				return
			}
			if len(record.Keys) == 0 {
				break
			}
		}
	}
	_ = c.control(endpoint, map[string]any{"task_id": "purges-restored-" + time.Now().UTC().Format("20060102T150405.000000000"), "action": "cache_purges_restored"})
}
