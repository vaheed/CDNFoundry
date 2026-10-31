package main

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// A DNS node is a PowerDNS secondary. The agent keeps it in step with the hidden primary without
// any inbound connection: it configures the node's catalog zone and TSIG key through the node's
// local API, then, for each zone the control plane reports as changed, makes sure the zone is
// signed with the transfer key and asks PowerDNS to pull it. Everything it does is outbound.

const (
	dnsChangesPerPage  = 200
	dnsPagesPerSync    = 5
	dnsStuckAfter      = 10 * time.Minute
	dnsCatalogRefresh  = time.Minute
	dnsTransferRefresh = time.Hour
	dnsKeyEnvelopeID   = "dns-transfer-key"
)

type dnsTransfer struct {
	Primary   string      `json:"primary"`
	Catalog   string      `json:"catalog"`
	KeyName   string      `json:"key_name"`
	Algorithm string      `json:"algorithm"`
	Secret    keyEnvelope `json:"secret"`
}

type dnsChange struct {
	Sequence uint64 `json:"sequence"`
	Zone     string `json:"zone"`
	Serial   uint64 `json:"serial"`
	Deleted  bool   `json:"deleted"`
}

// dnsState is what the agent remembers about this node's DNS service between synchronizations.
type dnsState struct {
	Cursor       uint64               `json:"cursor"`
	configured   string               // digest of the primary, catalog, and secret last applied locally
	configuredAt time.Time            // when the transfer settings were last fetched
	transfer     dnsTransfer          // the last fetched settings
	secret       string               // the opened TSIG secret
	catalogAt    time.Time            // when the catalog was last asked to refresh
	firstSeen    map[string]time.Time // when a zone was first seen unapplied
}

func (c *client) dnsEnabled() bool { return c.dnsAPIURL != "" }

func (c *client) dnsCursorPath() string { return filepath.Join(c.dir, "dns-cursor.json") }

func (c *client) loadDNSState() {
	if c.dns.firstSeen != nil {
		return
	}
	c.dns.firstSeen = map[string]time.Time{}
	if body, err := os.ReadFile(c.dnsCursorPath()); err == nil {
		_ = json.Unmarshal(body, &c.dns)
	}
}

// dnsAPI calls the node-local PowerDNS API.
func (c *client) dnsAPI(method, path string, body any) (int, []byte, error) {
	var reader io.Reader
	if body != nil {
		encoded, err := json.Marshal(body)
		if err != nil {
			return 0, nil, err
		}
		reader = bytes.NewReader(encoded)
	}
	ctx, cancel := c.requestContext(controlRequestTimeout)
	defer cancel()
	request, err := http.NewRequestWithContext(ctx, method, strings.TrimRight(c.dnsAPIURL, "/")+"/api/v1/servers/localhost"+path, reader)
	if err != nil {
		return 0, nil, err
	}
	request.Header.Set("X-API-Key", c.dnsAPIKey)
	request.Header.Set("Content-Type", "application/json")
	client := c.dnsHTTP
	if client == nil {
		client = &http.Client{}
	}
	response, err := client.Do(request)
	if err != nil {
		return 0, nil, err
	}
	defer response.Body.Close()
	data, _ := io.ReadAll(io.LimitReader(response.Body, 1<<20))
	return response.StatusCode, data, nil
}

func (c *client) dnsAPIOK(method, path string, body any) ([]byte, error) {
	status, data, err := c.dnsAPI(method, path, body)
	if err != nil {
		return nil, err
	}
	if status < 200 || status >= 300 {
		return nil, fmt.Errorf("local DNS API %s %s returned %d", method, path, status)
	}
	return data, nil
}

func zoneID(name string) string { return url.PathEscape(strings.TrimSuffix(name, ".") + ".") }

// configureDNS makes the node's catalog consumer and TSIG key match what the control plane says.
func (c *client) configureDNS() error {
	if c.dns.transfer.Primary != "" && time.Since(c.dns.configuredAt) < dnsTransferRefresh {
		return nil
	}
	var response struct {
		Data dnsTransfer `json:"data"`
	}
	if err := c.request("GET", "/edge/v1/dns/transfer", nil, &response, true); err != nil {
		return err
	}
	transfer := response.Data
	secret, err := openEnvelope(c.id.PrivateKey, c.id.EdgeID, dnsKeyEnvelopeID, transfer.Secret)
	if err != nil {
		return err
	}
	if transfer.Primary == "" || transfer.Catalog == "" || transfer.KeyName == "" {
		return errors.New("incomplete DNS transfer settings")
	}
	sum := sha256.Sum256([]byte(transfer.Primary + "|" + transfer.Catalog + "|" + transfer.KeyName + "|" + secret))
	signature := hex.EncodeToString(sum[:])
	c.dns.configuredAt = time.Now()
	if signature == c.dns.configured {
		c.dns.transfer, c.dns.secret = transfer, secret
		return nil
	}
	key := url.PathEscape(transfer.KeyName)
	status, _, err := c.dnsAPI("GET", "/tsigkeys/"+key, nil)
	if err != nil {
		return err
	}
	keyBody := map[string]any{"name": transfer.KeyName, "algorithm": transfer.Algorithm, "key": secret}
	if status == http.StatusNotFound {
		_, err = c.dnsAPIOK("POST", "/tsigkeys", keyBody)
	} else {
		_, err = c.dnsAPIOK("PUT", "/tsigkeys/"+key, keyBody)
	}
	if err != nil {
		return err
	}
	catalogBody := map[string]any{"name": transfer.Catalog, "kind": "Consumer", "masters": []string{transfer.Primary}, "slave_tsig_key_ids": []string{transfer.KeyName}}
	status, _, err = c.dnsAPI("GET", "/zones/"+zoneID(transfer.Catalog), nil)
	if err != nil {
		return err
	}
	if status == http.StatusNotFound {
		_, err = c.dnsAPIOK("POST", "/zones", catalogBody)
	} else {
		_, err = c.dnsAPIOK("PUT", "/zones/"+zoneID(transfer.Catalog), map[string]any{"masters": []string{transfer.Primary}, "slave_tsig_key_ids": []string{transfer.KeyName}})
	}
	if err != nil {
		return err
	}
	c.dns.configured, c.dns.transfer, c.dns.secret = signature, transfer, secret
	return nil
}

type localZone struct {
	Serial uint64   `json:"serial"`
	Keys   []string `json:"slave_tsig_key_ids"`
	Kind   string   `json:"kind"`
}

// syncDNS pulls what changed on the primary and returns the report the heartbeat carries.
func (c *client) syncDNS() map[string]any {
	if !c.dnsEnabled() {
		return nil
	}
	c.loadDNSState()
	report := map[string]any{"cursor": c.dns.Cursor}
	fail := func(err error) map[string]any {
		report["error"] = shortError(err)
		return report
	}
	if err := c.configureDNS(); err != nil {
		return fail(err)
	}
	catalog := c.dns.transfer.Catalog
	var latest uint64
	pending := 0
	var unapplied []string
	for page := 0; page < dnsPagesPerSync; page++ {
		var changes struct {
			Data    []dnsChange `json:"data"`
			Cursor  uint64      `json:"cursor"`
			HasMore bool        `json:"has_more"`
			Latest  uint64      `json:"latest"`
		}
		if err := c.request("GET", "/edge/v1/dns/changes?cursor="+strconv.FormatUint(c.dns.Cursor, 10), nil, &changes, true); err != nil {
			return fail(err)
		}
		latest = changes.Latest
		blocked := uint64(0) // the first change in this page that is not yet applied
		pageUnapplied := 0
		for _, change := range changes.Data {
			applied, err := c.pullZone(change)
			if err != nil {
				report["error"] = shortError(err)
			}
			if !applied {
				pageUnapplied++
				unapplied = append(unapplied, change.Zone)
				if blocked == 0 {
					blocked = change.Sequence
				}
			}
		}
		pending += pageUnapplied
		next := changes.Cursor
		if blocked != 0 {
			next = blocked - 1
		}
		if next > c.dns.Cursor {
			c.dns.Cursor = next
			c.saveDNSCursor()
		}
		if blocked != 0 || !changes.HasMore {
			break
		}
	}
	// A zone that is not here yet appears when the catalog is processed; ask for that when something
	// is waiting, and otherwise refresh it once a minute so deletions are noticed too.
	if pending > 0 && len(unapplied) > 0 || time.Since(c.dns.catalogAt) >= dnsCatalogRefresh {
		status, _, err := c.dnsAPI("PUT", "/zones/"+zoneID(catalog)+"/axfr-retrieve", nil)
		switch {
		case err != nil:
			report["error"] = shortError(err)
		case status == http.StatusNotFound:
			// The node lost its catalog, for example when its store was replaced. Configure it again
			// on the next pass instead of waiting for the hourly refresh.
			// Everything it pulled before is gone, so start from the beginning of the feed.
			c.dns.configured, c.dns.configuredAt = "", time.Time{}
			c.dns.Cursor = 0
			c.saveDNSCursor()
			report["error"] = "the node has no catalog zone; reconfiguring"
		case status < 200 || status >= 300:
			report["error"] = fmt.Sprintf("local DNS API catalog refresh returned %d", status)
		}
		c.dns.catalogAt = time.Now()
	}
	report["cursor"] = c.dns.Cursor
	report["pending"] = pending
	report["latest"] = latest
	if status, data, err := c.dnsAPI("GET", "/zones/"+zoneID(catalog), nil); err == nil && status == http.StatusOK {
		var zone localZone
		if json.Unmarshal(data, &zone) == nil {
			report["catalog_serial"] = zone.Serial
		}
	}
	return report
}

func (c *client) saveDNSCursor() {
	body, err := json.Marshal(map[string]uint64{"cursor": c.dns.Cursor})
	if err == nil {
		_ = writeAtomic(c.dnsCursorPath(), body)
	}
}

// pullZone reports whether the node already serves the change, and asks PowerDNS to pull it when not.
// A zone that stays unapplied for ten minutes stops holding the cursor back; it is reported instead.
func (c *client) pullZone(change dnsChange) (bool, error) {
	if change.Deleted {
		// The catalog removes the zone from every node.
		return true, nil
	}
	id := zoneID(change.Zone)
	status, data, err := c.dnsAPI("GET", "/zones/"+id, nil)
	if err != nil {
		return false, err
	}
	if status == http.StatusNotFound {
		return c.unappliedFor(change.Zone), nil
	}
	if status != http.StatusOK {
		return false, fmt.Errorf("local DNS API zone %s returned %d", change.Zone, status)
	}
	var zone localZone
	if err := json.Unmarshal(data, &zone); err != nil {
		return false, err
	}
	// Zones created from the catalog carry no key to sign their pulls with (PowerDNS calls it the
	// "slave" key); without it the primary refuses the transfer.
	if len(zone.Keys) == 0 {
		if _, err := c.dnsAPIOK("PUT", "/zones/"+id, map[string]any{"slave_tsig_key_ids": []string{c.dns.transfer.KeyName}}); err != nil {
			return false, err
		}
	}
	if zone.Serial >= change.Serial {
		delete(c.dns.firstSeen, change.Zone)
		return true, nil
	}
	if _, err := c.dnsAPIOK("PUT", "/zones/"+id+"/axfr-retrieve", nil); err != nil {
		return false, err
	}
	return c.unappliedFor(change.Zone), nil
}

// unappliedFor records that a zone is still waiting and reports true once it has waited too long.
func (c *client) unappliedFor(zone string) bool {
	first, seen := c.dns.firstSeen[zone]
	if !seen {
		c.dns.firstSeen[zone] = time.Now()
		return false
	}
	if time.Since(first) >= dnsStuckAfter {
		logger.Error("DNS zone could not be pulled; the node continues without it", "event", "dns_zone_stuck", "edge_id", c.id.EdgeID, "zone", zone)
		delete(c.dns.firstSeen, zone)
		return true
	}
	return false
}

// shortError keeps an error within the length the heartbeat accepts.
func shortError(err error) string {
	message := err.Error()
	if len(message) > 400 {
		return message[:400]
	}
	return message
}
