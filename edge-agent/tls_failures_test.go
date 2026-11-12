package main

import (
	"testing"
	"time"
)

func TestTLSRejectionCountersReadsOnlyTheHandshakeSeries(t *testing.T) {
	counters := tlsRejectionCounters("cdnfoundry_gateway_connections_rejected_by_reason_total{reason=\"invalid_hello\"} 90\n" +
		"cdnfoundry_gateway_tls_handshakes_rejected_by_reason_total{reason=\"invalid_hello\"} 7\n" +
		"cdnfoundry_gateway_tls_handshakes_rejected_by_reason_total{reason=\"unknown_route\"} nope\n")
	if len(counters) != 1 || counters["invalid_hello"] != 7 {
		t.Fatalf("unexpected counters %v", counters)
	}
}

func TestTLSFailureEventsAreNewBoundedAndNamedByReason(t *testing.T) {
	reporter := &tlsFailureReporter{}
	now := time.Unix(1_800_000_000, 0)
	if events := reporter.newEvents(map[string]uint64{"invalid_hello": 500}, now); len(events) != 0 {
		t.Fatalf("a counter that predates the agent is a baseline, not new failures: %v", events)
	}
	if events := reporter.newEvents(map[string]uint64{"invalid_hello": 500}, now); len(events) != 0 {
		t.Fatal("no change must report nothing")
	}
	events := reporter.newEvents(map[string]uint64{"invalid_hello": 503, "unknown_route": 1}, now)
	if len(events) != 4 {
		t.Fatalf("expected one event per new refusal, got %d", len(events))
	}
	for _, event := range events {
		if event["event_type"] != "tls" || event["domain_id"] != 0 || event["security_action"] != "allow" || event["hostname"] != "" {
			t.Fatalf("unexpected event %v", event)
		}
	}
	flood := reporter.newEvents(map[string]uint64{"invalid_hello": 1_000_000, "unknown_route": 1}, now)
	if len(flood) != tlsFailureEventsPerReport {
		t.Fatalf("a flood must be bounded to %d events, got %d", tlsFailureEventsPerReport, len(flood))
	}
	if flood[0]["tls_error"] != "gateway_invalid_hello" {
		t.Fatalf("the reason must say it came from the gateway: %v", flood[0])
	}
	if again := reporter.newEvents(map[string]uint64{"invalid_hello": 1_000_000}, now); len(again) != 0 {
		t.Fatal("a bounded flood is consumed, not replayed")
	}
	if reset := reporter.newEvents(map[string]uint64{"invalid_hello": 2}, now); len(reset) != 0 {
		t.Fatal("a counter that restarted lower must not report a negative or huge delta")
	}
}
