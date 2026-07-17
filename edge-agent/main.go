package main

import (
	"bytes"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"
)

const version = "1.0.0"

type identity struct{ EdgeID, Token, PublicKey string }
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
	base, dir, runtimeDir string
	http                  *http.Client
	id                    identity
}
type manifest struct {
	Sequence                              uint64  `json:"sequence"`
	Kind                                  string  `json:"kind"`
	DomainID                              *uint64 `json:"domain_id"`
	Checksum, Signature, Minimum, Maximum string
}

func main() {
	c := &client{base: strings.TrimRight(required("EDGE_CONTROL_URL"), "/"), dir: env("EDGE_STATE_DIR", "/var/lib/cdnfoundry/agent"), runtimeDir: env("EDGE_RUNTIME_DIR", ""), http: &http.Client{Timeout: 15 * time.Second}}
	if err := os.MkdirAll(c.dir, 0700); err != nil {
		fatal(err)
	}
	if err := c.loadOrRegister(); err != nil {
		fatal(err)
	}
	once := env("EDGE_ONCE", "false") == "true"
	for {
		if err := c.sync(); err != nil {
			fmt.Fprintln(os.Stderr, err)
		}
		if once {
			return
		}
		time.Sleep(5 * time.Second)
	}
}

func (c *client) loadOrRegister() error {
	p := filepath.Join(c.dir, "identity.json")
	if b, err := os.ReadFile(p); err == nil {
		return json.Unmarshal(b, &c.id)
	}
	body := map[string]any{"edge_id": required("EDGE_ID"), "bootstrap_token": required("EDGE_BOOTSTRAP_TOKEN"), "agent_version": version}
	// Explicit decode avoids relying on field-name matching for snake_case.
	var raw struct {
		Data map[string]string `json:"data"`
	}
	if err := c.request("POST", "/edge/v1/register", body, &raw, false); err != nil {
		return err
	}
	c.id = identity{EdgeID: raw.Data["edge_id"], Token: raw.Data["identity_token"], PublicKey: raw.Data["signing_public_key"]}
	return atomicJSON(p, c.id)
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
		Artifacts []struct {
			Sequence uint64          `json:"sequence"`
			DomainID *uint64         `json:"domain_id"`
			Kind     string          `json:"kind"`
			Payload  json.RawMessage `json:"payload"`
		} `json:"artifacts"`
	}
	if err := json.Unmarshal(payload, &snapshot); err != nil {
		return err
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
	runtime, err := compileRuntime(s)
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

func compileRuntime(s state) (map[string]any, error) {
	hosts := map[string]any{}
	for _, raw := range s.Domains {
		var domain struct {
			Domain    string         `json:"domain"`
			Revision  uint64         `json:"revision"`
			Settings  map[string]any `json:"settings"`
			Hostnames []struct {
				Hostname string         `json:"hostname"`
				Origin   map[string]any `json:"origin"`
			} `json:"hostnames"`
		}
		if err := json.Unmarshal(raw, &domain); err != nil {
			return nil, err
		}
		if domain.Domain == "" || len(domain.Hostnames) > 10000 {
			return nil, errors.New("invalid runtime domain")
		}
		for _, host := range domain.Hostnames {
			name := strings.ToLower(strings.TrimSuffix(host.Hostname, "."))
			if name == "" || host.Origin["host"] == nil || hosts[name] != nil {
				return nil, errors.New("invalid or duplicate runtime hostname")
			}
			hosts[name] = map[string]any{"domain": domain.Domain, "revision": domain.Revision, "settings": domain.Settings, "origin": host.Origin}
		}
	}
	return map[string]any{"schema_version": 1, "sequence": s.Sequence, "hosts": hosts}, nil
}

func (c *client) heartbeat(sequence uint64) error {
	return c.request("POST", "/edge/v1/heartbeat", map[string]any{"agent_version": version, "listener_ready": true, "active_sequence": sequence, "cells": []map[string]any{{"name": "shared-default", "status": "ready", "capacity": map[string]any{"active_connections": 0}}}}, &map[string]any{}, true)
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
	if auth {
		req.Header.Set("Authorization", "Bearer "+c.id.Token)
	}
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
func required(k string) string {
	v := os.Getenv(k)
	if v == "" {
		fatal(errors.New(k + " is required"))
	}
	return v
}
func fatal(e error) { fmt.Fprintln(os.Stderr, e); os.Exit(1) }
