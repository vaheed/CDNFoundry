package main

import (
	"regexp"
	"strconv"
	"time"
)

// Long-window rate limits of the shared scope are added up by the control plane from what every cell reports. A cell lists the
// heaviest keys of its current windows in its status; the agent forwards them, bounded, in the heartbeat. The control plane sends
// back the keys that went over their threshold across cells, and the agent gives them to each cell, which sets the same marker it
// sets for a key over its own threshold. Nothing here is on the request path: a cell enforces from its own memory whether or not
// this channel works.
const (
	rateMitigationsRefresh = 30 * time.Second
	maximumLongRateKeys    = 50
	maximumRateMitigations = 500
)

var rateHashPattern = regexp.MustCompile(`^[0-9a-f]{32}$`)

type rateMitigation struct {
	DomainID uint64 `json:"domain_id"`
	RuleID   uint64 `json:"rule_id"`
	Hash     string `json:"hash"`
	Seconds  int64  `json:"seconds"`
}

type rateMitigationSet struct {
	Version   int64            `json:"version"`
	Items     []rateMitigation `json:"items"`
	FetchedAt time.Time        `json:"-"`
}

func validRateMitigation(item rateMitigation) bool {
	return item.DomainID > 0 && item.RuleID > 0 && rateHashPattern.MatchString(item.Hash) && item.Seconds >= 1 && item.Seconds <= 86400
}

// collectLongRate keeps the valid heavy-key entries a cell reported, tagged with the cell name, up to the bound for this heartbeat.
func (c *client) collectLongRate(cell string, reported any) {
	list, _ := reported.([]any)
	for _, raw := range list {
		if len(c.longRate) >= 32*maximumLongRateKeys {
			return
		}
		entry, _ := raw.(map[string]any)
		hash, _ := entry["hash"].(string)
		if entry == nil || cell == "" || !rateHashPattern.MatchString(hash) || number(entry["count"]) < 1 || number(entry["domain_id"]) < 1 || number(entry["rule_id"]) < 1 {
			continue
		}
		c.longRate = append(c.longRate, map[string]any{"cell": cell, "domain_id": entry["domain_id"], "rule_id": entry["rule_id"], "hash": hash, "window": entry["window"], "count": entry["count"]})
	}
}

// refreshRateMitigations fetches the mitigations at most twice a minute. A failure keeps what the agent holds; the cells' markers
// end by their own clocks either way.
func (c *client) refreshRateMitigations() {
	if time.Since(c.rateMitigationsFetched) < rateMitigationsRefresh {
		return
	}
	c.rateMitigationsFetched = time.Now()
	var response struct {
		Data rateMitigationSet `json:"data"`
	}
	if err := c.request("GET", "/edge/v1/rate-mitigations", nil, &response, true); err != nil {
		logger.Warn("rate mitigations not fetched", "event", "rate_mitigations_unavailable", "edge_id", c.id.EdgeID)
		return
	}
	set := response.Data
	if len(set.Items) > maximumRateMitigations {
		set.Items = set.Items[:maximumRateMitigations]
	}
	valid := set.Items[:0]
	for _, item := range set.Items {
		if validRateMitigation(item) {
			valid = append(valid, item)
		}
	}
	set.Items, set.FetchedAt = valid, time.Now()
	c.rateMitigations = set
}

// rateMitigationsCommand is what a cell is given: the mitigations with the time each has left by this agent's clock.
func (c *client) rateMitigationsCommand() map[string]any {
	elapsed := int64(time.Since(c.rateMitigations.FetchedAt).Seconds())
	items := make([]rateMitigation, 0, len(c.rateMitigations.Items))
	for _, item := range c.rateMitigations.Items {
		if left := item.Seconds - elapsed; left >= 1 {
			item.Seconds = left
			items = append(items, item)
		}
	}
	return map[string]any{"task_id": "rate-mitigations-" + strconv.FormatInt(c.rateMitigations.Version, 10) + "-" + strconv.FormatInt(time.Now().Unix(), 10), "action": "rate_mitigations", "version": c.rateMitigations.Version, "items": items}
}

// pushRateMitigations gives one cell the mitigations when it does not report the current version, so a restarted cell is caught up on
// the next status cycle. A version of zero means the control plane has never issued one.
func (c *client) pushRateMitigations(endpoint string, reported any) {
	if c.rateMitigations.Version == 0 {
		return
	}
	if held, ok := reported.(float64); ok && int64(held) == c.rateMitigations.Version {
		return
	}
	_ = c.control(endpoint, c.rateMitigationsCommand())
}
