package main

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"
)

const version = "1.0.0"

type identity struct{ EdgeID, Certificate, PrivateKey, PublicKey string }
type state struct {
	Sequence uint64                     `json:"sequence"`
	Domains  map[string]json.RawMessage `json:"domains"`
}
type ack struct {
	Sequence        uint64 `json:"sequence"`
	Rejected        bool   `json:"rejected"`
	Reason, Details string
}
type client struct {
	base, dir, runtimeDir, statusToken string
	statusURLs                         []string
	http                               *http.Client
	id                                 identity
}
type manifest struct {
	Sequence                              uint64  `json:"sequence"`
	Kind                                  string  `json:"kind"`
	DomainID                              *uint64 `json:"domain_id"`
	Checksum, Signature, Minimum, Maximum string
}

func main() {
	c := &client{
		base: strings.TrimRight(required("EDGE_CONTROL_URL"), "/"), dir: env("EDGE_STATE_DIR", "/var/lib/cdnfoundry/agent"),
		runtimeDir: env("EDGE_RUNTIME_DIR", ""), statusToken: env("EDGE_STATUS_TOKEN", ""),
		statusURLs: splitNonempty(env("EDGE_CELL_STATUS_URLS", "")), http: &http.Client{Timeout: 15 * time.Second},
	}
	if err := os.MkdirAll(c.dir, 0700); err != nil {
		fatal(err)
	}
	if err := c.loadOrRegister(); err != nil {
		fatal(err)
	}
	if err := c.configureMutualTLS(); err != nil {
		fatal(err)
	}
	once := env("EDGE_ONCE", "false") == "true"
	for {
		if err := c.sync(); err != nil {
			fmt.Fprintln(os.Stderr, err)
		}
		if err := c.processTasks(); err != nil {
			fmt.Fprintln(os.Stderr, err)
		}
		if once {
			return
		}
		time.Sleep(5 * time.Second)
	}
}

type edgeTask struct {
	ID      string `json:"id"`
	Type    string `json:"type"`
	Payload struct {
		Addresses []string `json:"addresses"`
		Allowlist []string `json:"private_allowlist"`
		Origin    struct {
			Host              string `json:"host"`
			Scheme            string `json:"scheme"`
			HostHeader        string `json:"host_header"`
			SNI               string `json:"sni"`
			Port              int    `json:"port"`
			VerifyTLS         bool   `json:"verify_tls"`
			ConnectTimeoutMS  int    `json:"connect_timeout_ms"`
			ResponseTimeoutMS int    `json:"response_timeout_ms"`
			HealthCheck       *struct {
				Path string `json:"path"`
			} `json:"health_check"`
		} `json:"origin"`
	} `json:"payload"`
}

func (c *client) processTasks() error {
	var response struct {
		Data []edgeTask `json:"data"`
	}
	if err := c.request("GET", "/edge/v1/tasks", nil, &response, true); err != nil {
		return err
	}
	for _, task := range response.Data {
		result := map[string]any{"status": "unhealthy", "failure_reason": "task_cancelled"}
		if task.Type == "origin_test" {
			result = runOriginTest(task)
		}
		if err := c.request("POST", "/edge/v1/tasks/"+task.ID+"/result", map[string]any{"status": "succeeded", "result": result}, &map[string]any{}, true); err != nil {
			return err
		}
	}
	return nil
}

func runOriginTest(task edgeTask) map[string]any {
	started := time.Now()
	result := map[string]any{"status": "unhealthy"}
	if len(task.Payload.Addresses) == 0 {
		result["failure_reason"] = "dns_resolution_failed"
		return result
	}
	address := task.Payload.Addresses[0]
	if blockedIP(address, task.Payload.Allowlist) {
		result["failure_reason"] = "blocked_destination"
		return result
	}
	origin := task.Payload.Origin
	connectTimeout := boundedDuration(origin.ConnectTimeoutMS, 100, 10000)
	responseTimeout := boundedDuration(origin.ResponseTimeoutMS, 500, 60000)
	transport := &http.Transport{
		DisableKeepAlives: true,
		DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			return (&net.Dialer{Timeout: connectTimeout}).DialContext(ctx, "tcp", net.JoinHostPort(address, strconv.Itoa(origin.Port)))
		},
		TLSClientConfig: &tls.Config{ServerName: first(origin.SNI, origin.HostHeader), InsecureSkipVerify: !origin.VerifyTLS, MinVersion: tls.VersionTLS12},
	}
	path := "/"
	if origin.HealthCheck != nil && strings.HasPrefix(origin.HealthCheck.Path, "/") {
		path = origin.HealthCheck.Path
	}
	req, err := http.NewRequest("GET", origin.Scheme+"://"+origin.Host+":"+strconv.Itoa(origin.Port)+path, nil)
	if err == nil {
		req.Host = origin.HostHeader
		client := &http.Client{Transport: transport, Timeout: responseTimeout, CheckRedirect: func(_ *http.Request, _ []*http.Request) error { return http.ErrUseLastResponse }}
		var response *http.Response
		response, err = client.Do(req)
		if response != nil {
			result["http_status"] = response.StatusCode
			_ = response.Body.Close()
			if response.StatusCode >= 200 && response.StatusCode < 400 {
				result["status"] = "healthy"
				result["failure_reason"] = nil
			} else {
				result["failure_reason"] = "http_status_unhealthy"
			}
		}
	}
	result["latency_ms"] = time.Since(started).Milliseconds()
	result["resolved_address"] = address
	if origin.Scheme == "https" {
		if err == nil {
			result["tls_result"] = "verified"
		} else {
			result["tls_result"] = "failed"
		}
	}
	if err != nil {
		if timeout, ok := err.(net.Error); ok && timeout.Timeout() {
			result["failure_reason"] = "connect_timeout"
		} else if origin.Scheme == "https" {
			result["failure_reason"] = "tls_handshake_failed"
		} else {
			result["failure_reason"] = "connect_failed"
		}
	}
	return result
}

func boundedDuration(milliseconds, minimum, maximum int) time.Duration {
	if milliseconds < minimum {
		milliseconds = minimum
	}
	if milliseconds > maximum {
		milliseconds = maximum
	}
	return time.Duration(milliseconds) * time.Millisecond
}

func first(values ...string) string {
	for _, value := range values {
		if value != "" {
			return value
		}
	}
	return ""
}

func blockedIP(address string, allowlist []string) bool {
	ip := net.ParseIP(address)
	if ip == nil {
		return true
	}
	for _, cidr := range allowlist {
		_, network, err := net.ParseCIDR(cidr)
		if err == nil && network.Contains(ip) {
			return false
		}
	}
	return ip.IsUnspecified() || ip.IsLoopback() || ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast() || ip.IsMulticast() || ip.IsPrivate()
}

func (c *client) loadOrRegister() error {
	p := filepath.Join(c.dir, "identity.json")
	if b, err := os.ReadFile(p); err == nil {
		if err := json.Unmarshal(b, &c.id); err != nil {
			return err
		}
		if c.id.Certificate == "" || c.id.PrivateKey == "" {
			return errors.New("legacy edge identity requires administrator rotation")
		}
		return nil
	}
	edgeID := required("EDGE_ID")
	csr, privateKey, err := certificateRequest(edgeID)
	if err != nil {
		return err
	}
	body := map[string]any{"edge_id": edgeID, "bootstrap_token": required("EDGE_BOOTSTRAP_TOKEN"), "agent_version": version, "certificate_request": csr}
	// Explicit decode avoids relying on field-name matching for snake_case.
	var raw struct {
		Data map[string]string `json:"data"`
	}
	if err := c.request("POST", "/edge/v1/register", body, &raw, false); err != nil {
		return err
	}
	c.id = identity{EdgeID: raw.Data["edge_id"], Certificate: raw.Data["identity_certificate"], PrivateKey: privateKey, PublicKey: raw.Data["signing_public_key"]}
	return atomicJSON(p, c.id)
}

func certificateRequest(edgeID string) (string, string, error) {
	privateKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return "", "", err
	}
	request, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{Subject: pkix.Name{CommonName: edgeID}}, privateKey)
	if err != nil {
		return "", "", err
	}
	encodedKey, err := x509.MarshalPKCS8PrivateKey(privateKey)
	if err != nil {
		return "", "", err
	}
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE REQUEST", Bytes: request})), string(pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: encodedKey})), nil
}

func (c *client) configureMutualTLS() error {
	certificate, err := tls.X509KeyPair([]byte(c.id.Certificate), []byte(c.id.PrivateKey))
	if err != nil {
		return err
	}
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.TLSClientConfig = &tls.Config{Certificates: []tls.Certificate{certificate}, MinVersion: tls.VersionTLS12}
	c.http.Transport = transport
	return nil
}

func (c *client) sync() error {
	_ = c.flushAcks()
	current, err := loadState(filepath.Join(c.dir, "active", "state.json"))
	if errors.Is(err, os.ErrNotExist) {
		return c.full()
	}
	if err != nil {
		return err
	}
	var response struct {
		Data []struct {
			Sequence            uint64  `json:"sequence"`
			Kind                string  `json:"kind"`
			DomainID            *uint64 `json:"domain_id"`
			Checksum, Signature string
			SchemaVersion       int    `json:"schema_version"`
			Minimum             string `json:"minimum_agent_version"`
			Maximum             string `json:"maximum_agent_version"`
		} `json:"data"`
	}
	if err := c.request("GET", "/edge/v1/config/manifest?cursor="+strconv.FormatUint(current.Sequence, 10), nil, &response, true); err != nil {
		return err
	}
	if len(response.Data) == 0 {
		return c.heartbeat(current.Sequence)
	}
	candidate := state{Sequence: current.Sequence, Domains: clone(current.Domains)}
	for _, item := range response.Data {
		if item.SchemaVersion != 1 || !compatible(item.Minimum, item.Maximum) {
			c.queueAck(ack{Sequence: item.Sequence, Rejected: true, Reason: "incompatible_artifact"})
			return nil
		}
		var artifact struct {
			Encoded string `json:"encoded_payload"`
		}
		if err := c.request("GET", "/edge/v1/config/artifacts/"+item.Checksum, nil, &artifact, true); err != nil {
			return err
		}
		payload, err := verify(artifact.Encoded, item.Checksum, item.Signature, c.id.PublicKey)
		if err != nil {
			c.queueAck(ack{Sequence: item.Sequence, Rejected: true, Reason: "signature_or_checksum_invalid", Details: err.Error()})
			return nil
		}
		if item.DomainID != nil {
			key := strconv.FormatUint(*item.DomainID, 10)
			if item.Kind == "tombstone" {
				delete(candidate.Domains, key)
			} else {
				candidate.Domains[key] = payload
			}
		}
		candidate.Sequence = item.Sequence
	}
	if err := c.activate(candidate); err != nil {
		c.queueAck(ack{Sequence: candidate.Sequence, Rejected: true, Reason: "candidate_validation_failed", Details: err.Error()})
		return nil
	}
	c.queueAck(ack{Sequence: candidate.Sequence})
	_ = c.flushAcks()
	return c.heartbeat(candidate.Sequence)
}

func (c *client) full() error {
	var response struct{ Encoded, Checksum, Signature, Public string }
	var raw map[string]json.RawMessage
	if err := c.request("GET", "/edge/v1/config/full", nil, &raw, true); err != nil {
		return err
	}
	json.Unmarshal(raw["encoded_snapshot"], &response.Encoded)
	json.Unmarshal(raw["checksum"], &response.Checksum)
	json.Unmarshal(raw["signature"], &response.Signature)
	json.Unmarshal(raw["signing_public_key"], &response.Public)
	if c.id.PublicKey != response.Public {
		return errors.New("full snapshot signing key changed")
	}
	payload, err := verify(response.Encoded, response.Checksum, response.Signature, c.id.PublicKey)
	if err != nil {
		return err
	}
	var snapshot struct {
		SchemaVersion int    `json:"schema_version"`
		Minimum       string `json:"minimum_agent_version"`
		Maximum       string `json:"maximum_agent_version"`
		Artifacts     []struct {
			Sequence uint64          `json:"sequence"`
			DomainID *uint64         `json:"domain_id"`
			Kind     string          `json:"kind"`
			Payload  json.RawMessage `json:"payload"`
		} `json:"artifacts"`
	}
	if err := json.Unmarshal(payload, &snapshot); err != nil {
		return err
	}
	if snapshot.SchemaVersion != 1 || !compatible(snapshot.Minimum, snapshot.Maximum) {
		return errors.New("full snapshot is incompatible")
	}
	next := state{Domains: map[string]json.RawMessage{}}
	for _, a := range snapshot.Artifacts {
		if a.Sequence > next.Sequence {
			next.Sequence = a.Sequence
		}
		if a.DomainID != nil && a.Kind != "tombstone" {
			next.Domains[strconv.FormatUint(*a.DomainID, 10)] = a.Payload
		}
	}
	if err := c.activate(next); err != nil {
		return err
	}
	c.queueAck(ack{Sequence: next.Sequence})
	return c.flushAcks()
}

func (c *client) activate(s state) error {
	if s.Domains == nil || len(s.Domains) > 100000 {
		return errors.New("invalid domain count")
	}
	candidate := filepath.Join(c.dir, "candidate")
	os.RemoveAll(candidate)
	if err := os.MkdirAll(candidate, 0700); err != nil {
		return err
	}
	if err := atomicJSON(filepath.Join(candidate, "state.json"), s); err != nil {
		return err
	}
	runtime, pools, err := compileRuntime(s)
	if err != nil {
		return err
	}
	active, previous := filepath.Join(c.dir, "active"), filepath.Join(c.dir, "previous")
	os.RemoveAll(previous)
	if _, err := os.Stat(active); err == nil {
		if err := os.Rename(active, previous); err != nil {
			return err
		}
	}
	if err := os.Rename(candidate, active); err != nil {
		if _, e := os.Stat(previous); e == nil {
			_ = os.Rename(previous, active)
		}
		return err
	}
	if c.runtimeDir != "" {
		if err := os.MkdirAll(c.runtimeDir, 0755); err != nil {
			return c.rollbackActive(active, previous, err)
		}
		if err := atomicPublicJSON(filepath.Join(c.runtimeDir, "active.json"), runtime); err != nil {
			return c.rollbackActive(active, previous, err)
		}
		existing, _ := filepath.Glob(filepath.Join(c.runtimeDir, "*.json"))
		for _, file := range existing {
			name := strings.TrimSuffix(filepath.Base(file), ".json")
			if name != "active" {
				if _, present := pools[name]; !present {
					pools[name] = map[string]any{"schema_version": 1, "sequence": s.Sequence, "hosts": map[string]any{}}
				}
			}
		}
		for name, poolRuntime := range pools {
			if !validPoolName(name) {
				return c.rollbackActive(active, previous, errors.New("invalid runtime pool name"))
			}
			if err := atomicPublicJSON(filepath.Join(c.runtimeDir, name+".json"), poolRuntime); err != nil {
				return c.rollbackActive(active, previous, err)
			}
		}
	}
	return nil
}

func (c *client) rollbackActive(active, previous string, cause error) error {
	os.RemoveAll(active)
	if _, err := os.Stat(previous); err == nil {
		_ = os.Rename(previous, active)
	}
	return cause
}

func compileRuntime(s state) (map[string]any, map[string]map[string]any, error) {
	hosts := map[string]any{}
	poolHosts := map[string]map[string]any{}
	for _, raw := range s.Domains {
		var domain struct {
			Domain    string         `json:"domain"`
			Revision  uint64         `json:"revision"`
			Settings  map[string]any `json:"settings"`
			Pools     []string       `json:"pools"`
			Hostnames []struct {
				Hostname string         `json:"hostname"`
				Origin   map[string]any `json:"origin"`
			} `json:"hostnames"`
		}
		if err := json.Unmarshal(raw, &domain); err != nil {
			return nil, nil, err
		}
		if domain.Domain == "" || len(domain.Hostnames) > 10000 {
			return nil, nil, errors.New("invalid runtime domain")
		}
		for _, host := range domain.Hostnames {
			name := strings.ToLower(strings.TrimSuffix(host.Hostname, "."))
			if name == "" || host.Origin["host"] == nil || hosts[name] != nil {
				return nil, nil, errors.New("invalid or duplicate runtime hostname")
			}
			compiled := map[string]any{"domain": domain.Domain, "revision": domain.Revision, "settings": domain.Settings, "origin": host.Origin}
			hosts[name] = compiled
			for _, pool := range domain.Pools {
				if !validPoolName(pool) {
					return nil, nil, errors.New("invalid runtime pool name")
				}
				if poolHosts[pool] == nil {
					poolHosts[pool] = map[string]any{}
				}
				if poolHosts[pool][name] != nil {
					return nil, nil, errors.New("duplicate runtime pool hostname")
				}
				poolHosts[pool][name] = compiled
			}
		}
	}
	pools := map[string]map[string]any{}
	for name, assigned := range poolHosts {
		pools[name] = map[string]any{"schema_version": 1, "sequence": s.Sequence, "hosts": assigned}
	}
	return map[string]any{"schema_version": 1, "sequence": s.Sequence, "hosts": hosts}, pools, nil
}

func validPoolName(name string) bool {
	if name == "" || len(name) > 100 {
		return false
	}
	for _, character := range name {
		if !(character >= 'a' && character <= 'z' || character >= 'A' && character <= 'Z' || character >= '0' && character <= '9' || character == '-' || character == '_') {
			return false
		}
	}
	return true
}

func (c *client) heartbeat(sequence uint64) error {
	return c.request("POST", "/edge/v1/heartbeat", map[string]any{
		"agent_version": version, "listener_ready": true, "active_sequence": sequence,
		"cells":           []map[string]any{{"name": "shared-default", "status": "ready", "capacity": map[string]any{"active_connections": 0}}},
		"passive_origins": c.passiveFailures(),
	}, &map[string]any{}, true)
}

func (c *client) passiveFailures() []map[string]any {
	failures := []map[string]any{}
	for _, endpoint := range c.statusURLs {
		req, err := http.NewRequest("GET", endpoint, nil)
		if err != nil {
			continue
		}
		req.Header.Set("X-Edge-Status-Token", c.statusToken)
		response, err := c.http.Do(req)
		if err != nil {
			continue
		}
		var decoded struct {
			Data []map[string]any `json:"data"`
		}
		if response.StatusCode == http.StatusOK {
			_ = json.NewDecoder(io.LimitReader(response.Body, 1<<20)).Decode(&decoded)
		}
		_ = response.Body.Close()
		for _, failure := range decoded.Data {
			if len(failures) >= 100 {
				return failures
			}
			failures = append(failures, failure)
		}
	}
	return failures
}
func (c *client) queueAck(a ack) {
	var q []ack
	p := filepath.Join(c.dir, "acks.json")
	if b, e := os.ReadFile(p); e == nil {
		_ = json.Unmarshal(b, &q)
	}
	if len(q) >= 1000 {
		q = q[len(q)-999:]
	}
	q = append(q, a)
	_ = atomicJSON(p, q)
}
func (c *client) flushAcks() error {
	p := filepath.Join(c.dir, "acks.json")
	b, e := os.ReadFile(p)
	if errors.Is(e, os.ErrNotExist) {
		return nil
	}
	if e != nil {
		return e
	}
	var q []ack
	if json.Unmarshal(b, &q) != nil {
		return errors.New("invalid ack buffer")
	}
	for i, a := range q {
		path := "/edge/v1/config/applied"
		body := map[string]any{"sequence": a.Sequence}
		if a.Rejected {
			path = "/edge/v1/config/rejected"
			body = map[string]any{"sequence": a.Sequence, "reason": a.Reason, "details": a.Details}
		}
		if e = c.request("POST", path, body, &map[string]any{}, true); e != nil {
			_ = atomicJSON(p, q[i:])
			return e
		}
	}
	return os.Remove(p)
}

func (c *client) request(method, path string, body any, out any, auth bool) error {
	var r io.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		r = bytes.NewReader(b)
	}
	req, e := http.NewRequest(method, c.base+path, r)
	if e != nil {
		return e
	}
	req.Header.Set("Content-Type", "application/json")
	res, e := c.http.Do(req)
	if e != nil {
		return e
	}
	defer res.Body.Close()
	if res.StatusCode < 200 || res.StatusCode >= 300 {
		b, _ := io.ReadAll(io.LimitReader(res.Body, 4096))
		return fmt.Errorf("%s: %s", res.Status, string(b))
	}
	return json.NewDecoder(io.LimitReader(res.Body, 16<<20)).Decode(out)
}
func verify(encoded, checksum, signature, public string) (json.RawMessage, error) {
	b, e := base64.StdEncoding.DecodeString(encoded)
	if e != nil {
		return nil, e
	}
	sum := sha256.Sum256(b)
	if hex.EncodeToString(sum[:]) != checksum {
		return nil, errors.New("checksum mismatch")
	}
	pk, e := hex.DecodeString(public)
	if e != nil {
		return nil, e
	}
	sig, e := hex.DecodeString(signature)
	if e != nil {
		return nil, e
	}
	if !ed25519.Verify(pk, []byte(checksum), sig) {
		return nil, errors.New("signature mismatch")
	}
	return b, nil
}
func compatible(min, max string) bool {
	return semver(version) >= semver(min) && semver(version) <= semver(max)
}
func semver(v string) int {
	p := strings.Split(strings.SplitN(v, "-", 2)[0], ".")
	n := 0
	for i := 0; i < 3; i++ {
		n *= 1000
		if i < len(p) {
			x, _ := strconv.Atoi(p[i])
			n += x
		}
	}
	return n
}
func loadState(p string) (state, error) {
	var s state
	b, e := os.ReadFile(p)
	if e != nil {
		return s, e
	}
	e = json.Unmarshal(b, &s)
	return s, e
}
func atomicJSON(p string, v any) error {
	b, e := json.Marshal(v)
	if e != nil {
		return e
	}
	tmp := p + ".tmp"
	if e = os.WriteFile(tmp, b, 0600); e != nil {
		return e
	}
	return os.Rename(tmp, p)
}
func atomicPublicJSON(p string, v any) error {
	b, e := json.Marshal(v)
	if e != nil {
		return e
	}
	tmp := p + ".tmp"
	if e = os.WriteFile(tmp, b, 0644); e != nil {
		return e
	}
	return os.Rename(tmp, p)
}
func clone(in map[string]json.RawMessage) map[string]json.RawMessage {
	out := map[string]json.RawMessage{}
	keys := make([]string, 0, len(in))
	for k := range in {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		out[k] = append([]byte(nil), in[k]...)
	}
	return out
}
func env(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}
func splitNonempty(value string) []string {
	items := []string{}
	for _, item := range strings.Split(value, ",") {
		if item = strings.TrimSpace(item); item != "" {
			items = append(items, item)
		}
	}
	return items
}
func required(k string) string {
	v := os.Getenv(k)
	if v == "" {
		fatal(errors.New(k + " is required"))
	}
	return v
}
func fatal(e error) { fmt.Fprintln(os.Stderr, e); os.Exit(1) }
