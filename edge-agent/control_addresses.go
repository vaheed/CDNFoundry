package main

import (
	"context"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// The control plane may run as an active node and a standby. The agent keeps one hostname for TLS
// and SNI, and dials an ordered list of addresses for it. The list comes from `EDGE_CONTROL_ADDRESSES`
// until the control plane delivers its own signed list, which replaces it.

const (
	controlAddressesScope  = "control-addresses"
	controlStateHeader     = "X-CDNF-Control-State"
	controlEpochHeader     = "X-CDNF-Control-Epoch"
	maxControlAddresses    = 2
	controlDialTimeout     = 5 * time.Second
	controlStateStandby    = "standby"
	controlAddressesFile   = "control-addresses.json"
	controlAddressesWindow = 16 << 10
)

type dialOverrideKey struct{}

// controlState is everything about reaching control that must survive a restart: the held list and
// its checksum, the address that last worked, and the highest control epoch ever accepted.
type controlState struct {
	Checksum  string   `json:"checksum"`
	Addresses []string `json:"addresses"`
	LastGood  string   `json:"last_good"`
	Epoch     uint64   `json:"epoch"`
}

func parseControlAddresses(values []string) ([]string, error) {
	if len(values) > maxControlAddresses {
		return nil, errors.New("at most two control addresses are allowed")
	}
	seen := map[string]bool{}
	out := make([]string, 0, len(values))
	for _, value := range values {
		value = strings.TrimSpace(value)
		ip := net.ParseIP(value)
		if ip == nil || seen[ip.String()] {
			return nil, errors.New("control addresses must be distinct IP addresses")
		}
		seen[ip.String()] = true
		out = append(out, ip.String())
	}
	return out, nil
}

func (c *client) controlStatePath() string { return filepath.Join(c.dir, controlAddressesFile) }

func (c *client) loadControlState() {
	if c.controlLoaded {
		return
	}
	c.controlLoaded = true
	body, err := os.ReadFile(c.controlStatePath())
	if err != nil {
		return
	}
	var stored controlState
	if json.Unmarshal(body, &stored) != nil {
		return
	}
	if addresses, err := parseControlAddresses(stored.Addresses); err == nil {
		c.controlRoute = controlState{Checksum: stored.Checksum, Addresses: addresses, LastGood: stored.LastGood, Epoch: stored.Epoch}
	}
}

func (c *client) saveControlState() error {
	return atomicJSON(c.controlStatePath(), c.controlRoute)
}

// controlCandidates is the order in which one request tries to reach control: the address that last
// worked, the other held addresses, and last the hostname's own resolution. The empty string means
// "resolve the hostname", which is also the only candidate while no list is held.
func (c *client) controlCandidates() []string {
	c.loadControlState()
	addresses := c.controlRoute.Addresses
	if len(addresses) == 0 {
		addresses = c.seedAddresses
	}
	if len(addresses) == 0 {
		return []string{""}
	}
	out := make([]string, 0, len(addresses)+1)
	for _, address := range addresses {
		if address == c.controlRoute.LastGood {
			out = append(out, address)
		}
	}
	for _, address := range addresses {
		if address != c.controlRoute.LastGood {
			out = append(out, address)
		}
	}
	return append(out, "")
}

// dialControl connects to the override address when the request context names one, keeping the
// URL's hostname for TLS verification and SNI.
func dialControl(dialer *net.Dialer) func(ctx context.Context, network, address string) (net.Conn, error) {
	return func(ctx context.Context, network, address string) (net.Conn, error) {
		if override, ok := ctx.Value(dialOverrideKey{}).(string); ok && override != "" {
			_, port, err := net.SplitHostPort(address)
			if err != nil {
				return nil, err
			}
			return dialer.DialContext(ctx, network, net.JoinHostPort(override, port))
		}
		return dialer.DialContext(ctx, network, address)
	}
}

// controlResponseVerdict decides whether a response may be used. A standby, a server error, and a
// response from a lower epoch than one already accepted all move the agent to the next address.
func (c *client) controlResponseVerdict(res *http.Response) error {
	if res.Header.Get(controlStateHeader) == controlStateStandby {
		return errors.New("control node is a standby")
	}
	if res.StatusCode >= 500 {
		return errors.New("control node answered " + res.Status)
	}
	if header := res.Header.Get(controlEpochHeader); header != "" {
		epoch, err := strconv.ParseUint(header, 10, 64)
		if err != nil {
			return errors.New("control node sent an invalid epoch")
		}
		if epoch < c.controlRoute.Epoch {
			return errors.New("control node epoch " + header + " is older than the accepted epoch " + strconv.FormatUint(c.controlRoute.Epoch, 10))
		}
		if epoch > c.controlRoute.Epoch {
			c.controlRoute.Epoch = epoch
			if err := c.saveControlState(); err != nil {
				return err
			}
		}
	}
	return nil
}

func (c *client) noteControlAddressWorked(address string) {
	if address == "" || address == c.controlRoute.LastGood {
		return
	}
	c.controlRoute.LastGood = address
	if err := c.saveControlState(); err != nil {
		logger.Warn("control address could not be remembered", "event", "control_address_not_saved", "edge_id", c.id.EdgeID, "error", err.Error())
	}
}

// syncControlAddresses fetches the signed address list when its checksum differs from the one held.
// A failed fetch or a rejected document keeps the held list: control being unreachable or sending
// something unverifiable must not change where the agent looks for it.
func (c *client) syncControlAddresses() {
	c.loadControlState()
	var response struct {
		Unchanged         bool   `json:"unchanged"`
		Encoded           string `json:"encoded_payload"`
		Checksum          string `json:"checksum"`
		Signature         string `json:"signature"`
		ExpiresAt         int64  `json:"expires_at"`
		DeliverySignature string `json:"delivery_signature"`
	}
	if err := c.request("GET", "/edge/v1/control-addresses?checksum="+c.controlRoute.Checksum, nil, &response, true); err != nil {
		return
	}
	if response.Unchanged && response.Checksum == c.controlRoute.Checksum && c.controlRoute.Checksum != "" {
		return
	}
	reject := func(err error) {
		logger.Error("control address list rejected; the held list stays", "event", "control_addresses_rejected", "edge_id", c.id.EdgeID, "error_code", reasonSignatureOrChecksum, "error", err.Error())
	}
	payload, err := c.verifySigned(response.Encoded, response.Checksum, response.Signature, controlAddressesScope, 0, response.ExpiresAt, response.DeliverySignature)
	if err != nil {
		reject(err)
		return
	}
	var document struct {
		Addresses []string `json:"addresses"`
	}
	if err := json.Unmarshal(payload, &document); err != nil {
		reject(err)
		return
	}
	addresses, err := parseControlAddresses(document.Addresses)
	if err != nil {
		reject(err)
		return
	}
	next := c.controlRoute
	next.Checksum, next.Addresses = response.Checksum, addresses
	if next.LastGood != "" && !containsString(addresses, next.LastGood) {
		next.LastGood = ""
	}
	previous := c.controlRoute
	c.controlRoute = next
	if err := c.saveControlState(); err != nil {
		c.controlRoute = previous
		logger.Error("control address list could not be saved", "event", "control_addresses_not_saved", "edge_id", c.id.EdgeID, "error", err.Error())
		return
	}
	if transport, ok := c.http.Transport.(*http.Transport); ok {
		transport.CloseIdleConnections()
	}
	logger.Info("control address list updated", "event", "control_addresses_updated", "edge_id", c.id.EdgeID, "checksum", response.Checksum, "addresses", len(addresses))
}
