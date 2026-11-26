package main

import "sort"

// maximumSecurityEntries bounds the security events one heartbeat carries. A domain with thousands of events takes one slot
// per round, exactly like a domain with one, so a noisy domain cannot keep a quiet one out of the report.
const maximumSecurityEntries = 100

// maximumMergedSecurityEntries bounds what the agent holds between merging cells and choosing what to send.
const maximumMergedSecurityEntries = 2000

type securityKey struct {
	domain int64
	reason string
}

func number(value any) float64 {
	switch typed := value.(type) {
	case float64:
		return typed
	case int:
		return float64(typed)
	case int64:
		return float64(typed)
	}
	return 0
}

// mergeSecurityEvents combines the events of several cells into one entry per domain and reason: counts add up, the largest client
// evidence and the latest time win. The first appearance of each domain keeps its place, so the cells' own order (most recently
// active domains first) carries through.
func mergeSecurityEvents(events []map[string]any) []map[string]any {
	merged := []map[string]any{}
	index := map[securityKey]int{}
	for _, event := range events {
		reason, _ := event["reason_code"].(string)
		domain := int64(number(event["domain_id"]))
		if reason == "" || domain == 0 {
			continue
		}
		key := securityKey{domain, reason}
		position, exists := index[key]
		if !exists {
			if len(merged) >= maximumMergedSecurityEntries {
				continue
			}
			copied := map[string]any{}
			for name, value := range event {
				copied[name] = value
			}
			index[key] = len(merged)
			merged = append(merged, copied)
			continue
		}
		existing := merged[position]
		existing["count"] = number(existing["count"]) + number(event["count"])
		if number(event["clients"]) > number(existing["clients"]) {
			existing["clients"] = event["clients"]
		}
		if number(event["occurred_at"]) > number(existing["occurred_at"]) {
			existing["occurred_at"] = event["occurred_at"]
		}
		if host, _ := existing["hostname"].(string); host == "" {
			existing["hostname"] = event["hostname"]
		}
	}
	return merged
}

// fairSecurityEvents chooses at most limit entries: every domain's busiest reason first, then every domain's second busiest, and so
// on. Domains are taken in the order of when they were last reported (lastSent, a heartbeat counter; a domain never reported counts
// as 0), oldest first, and in the order they appear among equals. Sustained events on every domain change every running total, so
// without this the same first domains would take every heartbeat and the rest would never be sent. It returns the chosen entries
// and how many domains were left out entirely.
func fairSecurityEvents(events []map[string]any, limit int, lastSent map[int64]uint64) ([]map[string]any, int) {
	order := []int64{}
	byDomain := map[int64][]map[string]any{}
	for _, event := range events {
		domain := int64(number(event["domain_id"]))
		if _, seen := byDomain[domain]; !seen {
			order = append(order, domain)
		}
		byDomain[domain] = append(byDomain[domain], event)
	}
	sort.SliceStable(order, func(i, j int) bool { return lastSent[order[i]] < lastSent[order[j]] })
	for _, rows := range byDomain {
		sort.SliceStable(rows, func(i, j int) bool { return number(rows[i]["count"]) > number(rows[j]["count"]) })
	}
	chosen := []map[string]any{}
	represented := map[int64]bool{}
	for round := 0; len(chosen) < limit; round++ {
		added := false
		for _, domain := range order {
			rows := byDomain[domain]
			if round < len(rows) && len(chosen) < limit {
				chosen = append(chosen, rows[round])
				represented[domain] = true
				added = true
			}
		}
		if !added {
			break
		}
	}
	return chosen, len(order) - len(represented)
}

// pendingSecurityEvents keeps the entries whose count differs from what the control plane was last sent. The cells report running
// totals for an hour, so without this every heartbeat would repeat every entry; an unchanged entry has nothing new to record and
// must not take a slot from one that has.
func (c *client) pendingSecurityEvents(events []map[string]any) []map[string]any {
	pending := make([]map[string]any, 0, len(events))
	for _, event := range events {
		reason, _ := event["reason_code"].(string)
		key := securityKey{int64(number(event["domain_id"])), reason}
		if sent, known := c.reportedSecurity[key]; known && sent == number(event["count"]) {
			continue
		}
		pending = append(pending, event)
	}
	return pending
}

// markSecurityReported records what a heartbeat that the control plane accepted carried. An entry that was chosen but not sent, or
// whose heartbeat failed, is still pending next time.
func (c *client) markSecurityReported(events []map[string]any) {
	if c.reportedSecurity == nil {
		c.reportedSecurity = map[securityKey]float64{}
	}
	if c.reportedSecurityAt == nil {
		c.reportedSecurityAt = map[int64]uint64{}
	}
	c.securityRound++
	for _, event := range events {
		reason, _ := event["reason_code"].(string)
		domain := int64(number(event["domain_id"]))
		c.reportedSecurity[securityKey{domain, reason}] = number(event["count"])
		c.reportedSecurityAt[domain] = c.securityRound
	}
	// Totals expire on the cell after an hour; forget what the cells have stopped reporting so this map stays bounded.
	if len(c.reportedSecurity) > 4*maximumMergedSecurityEntries {
		c.reportedSecurity = map[securityKey]float64{}
		c.reportedSecurityAt = map[int64]uint64{}
	}
}
