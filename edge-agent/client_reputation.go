package main

import "net/netip"

// Offending clients for shared reputation. A cell lists the clients that caused the most escalating events on its domains, with the
// requests it saw from each since; the agent forwards them, bounded, in the heartbeat, and the control plane keeps only those of
// domains that opted in. Nothing here is on the request path, and a failed heartbeat only means the next one carries fresh counts.
const maximumOffendingClients = 50

// validClientKey accepts what a cell writes for a client: an IPv4 address or an IPv6 address with the bits beyond its prefix cleared.
func validClientKey(key string) bool {
	address, err := netip.ParseAddr(key)
	return err == nil && len(key) <= 64 && !address.IsUnspecified()
}

// collectOffenders keeps the valid entries a cell reported, tagged with the cell name, up to the bound for this heartbeat.
func (c *client) collectOffenders(cell string, reported any) {
	list, _ := reported.([]any)
	for index, raw := range list {
		if index >= maximumOffendingClients || len(c.offenders) >= 32*maximumOffendingClients {
			return
		}
		entry, _ := raw.(map[string]any)
		key, _ := entry["client"].(string)
		if entry == nil || cell == "" || !validClientKey(key) || number(entry["events"]) < 1 || number(entry["domain_id"]) < 1 || number(entry["requests"]) < 0 {
			continue
		}
		c.offenders = append(c.offenders, map[string]any{"cell": cell, "domain_id": entry["domain_id"], "client": key, "events": entry["events"], "requests": entry["requests"]})
	}
}
