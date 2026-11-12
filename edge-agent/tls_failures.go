package main

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"
)

// A client that fails the handshake at the gateway never reaches a cell, so no cell can log it. The
// gateway counts those refusals by reason; the agent, which already reads that counter and holds the
// node secret, turns each new refusal into one bounded `tls` event for the local Vector. Prometheus
// keeps the exact count. The rollup keeps at most tlsFailureEventsPerReport rows per reason per
// report, so a flood cannot flood telemetry, and a failure here never affects serving or heartbeats.
const (
	tlsFailureEventsPerReport = 50
	tlsFailureMetricPrefix    = "cdnfoundry_gateway_tls_handshakes_rejected_by_reason_total{reason=\""
)

type tlsFailureReporter struct {
	mu       sync.Mutex
	previous map[string]uint64
	sending  bool
}

// tlsRejectionCounters reads the per-reason handshake refusal counters from the gateway metrics text.
func tlsRejectionCounters(metrics string) map[string]uint64 {
	counters := map[string]uint64{}
	for _, line := range strings.Split(metrics, "\n") {
		if !strings.HasPrefix(line, tlsFailureMetricPrefix) {
			continue
		}
		rest := strings.TrimPrefix(line, tlsFailureMetricPrefix)
		end := strings.Index(rest, "\"")
		if end <= 0 || end > 32 {
			continue
		}
		fields := strings.Fields(rest[end:])
		if len(fields) != 2 {
			continue
		}
		if value, err := strconv.ParseUint(fields[1], 10, 64); err == nil {
			counters[rest[:end]] = value
		}
	}
	return counters
}

// newEvents returns the events for refusals counted since the previous call. The first call only
// records the baseline: a counter that predates the agent is not a new failure.
func (r *tlsFailureReporter) newEvents(counters map[string]uint64, now time.Time) []map[string]any {
	r.mu.Lock()
	defer r.mu.Unlock()
	baseline := r.previous == nil
	if baseline {
		r.previous = map[string]uint64{}
	}
	events := []map[string]any{}
	for reason, current := range counters {
		before := r.previous[reason]
		r.previous[reason] = current
		if baseline || current <= before {
			continue
		}
		count := current - before
		if count > tlsFailureEventsPerReport {
			count = tlsFailureEventsPerReport
		}
		for i := uint64(0); i < count; i++ {
			events = append(events, map[string]any{
				"occurred_at": now.UTC().Format(time.RFC3339), "event_type": "tls", "tls_error": "gateway_" + reason,
				"hostname": "", "domain_id": 0, "status": 0, "security_action": "allow",
			})
		}
	}
	return events
}

// reportTLSFailures sends new gateway handshake refusals to the local Vector. It runs in the
// background, allows one report in flight, and ignores every error.
func (c *client) reportTLSFailures(metrics string) {
	if c.telemetryURL == "" || metrics == "" {
		return
	}
	events := c.tlsFailures.newEvents(tlsRejectionCounters(metrics), time.Now())
	if len(events) == 0 {
		return
	}
	c.tlsFailures.mu.Lock()
	if c.tlsFailures.sending {
		c.tlsFailures.mu.Unlock()
		return
	}
	c.tlsFailures.sending = true
	c.tlsFailures.mu.Unlock()
	go func() {
		defer func() {
			c.tlsFailures.mu.Lock()
			c.tlsFailures.sending = false
			c.tlsFailures.mu.Unlock()
		}()
		body, err := json.Marshal(events)
		if err != nil {
			return
		}
		ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
		defer cancel()
		request, err := http.NewRequestWithContext(ctx, http.MethodPost, c.telemetryURL, bytes.NewReader(body))
		if err != nil {
			return
		}
		request.Header.Set("Content-Type", "application/json")
		request.SetBasicAuth("cdnf", c.statusToken)
		response, err := c.http.Do(request)
		if err == nil {
			response.Body.Close()
		}
	}()
}
