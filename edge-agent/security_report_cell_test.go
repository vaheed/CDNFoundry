package main

import (
	"encoding/json"
	"os"
	"testing"
)

// The report a real cell produced (tests/e2e/security_report_fairness.py writes it and sets CDNF_CELL_REPORT) goes through the agent's
// own selection for two heartbeats whose running totals keep changing, and every domain of the report must have been sent.
func TestARealCellReportOf200DomainsIsSentInTwoHeartbeats(t *testing.T) {
	path := os.Getenv("CDNF_CELL_REPORT")
	if path == "" {
		t.Skip("CDNF_CELL_REPORT names a status report from a real cell")
	}
	body, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var report struct {
		Security []map[string]any `json:"security"`
		Cell     struct {
			Capacity struct {
				Dropped float64 `json:"security_domains_dropped"`
			} `json:"capacity"`
		} `json:"cell"`
	}
	if err := json.Unmarshal(body, &report); err != nil {
		t.Fatal(err)
	}
	expected := map[int64]bool{}
	for _, event := range report.Security {
		expected[int64(number(event["domain_id"]))] = true
	}
	if len(expected) < 200 || report.Cell.Capacity.Dropped != 0 {
		t.Fatalf("the cell's report must hold at least 200 domains and drop none: %d domains, %v dropped", len(expected), report.Cell.Capacity.Dropped)
	}
	c := &client{}
	reported := map[int64]bool{}
	for round := 1; round <= 2; round++ {
		// Sustained attack: every running total grew since the last heartbeat.
		events := make([]map[string]any, 0, len(report.Security))
		for _, event := range report.Security {
			copied := map[string]any{}
			for name, value := range event {
				copied[name] = value
			}
			copied["count"] = number(event["count"]) + float64(round*1000)
			events = append(events, copied)
		}
		sent, _ := fairSecurityEvents(c.pendingSecurityEvents(mergeSecurityEvents(events)), maximumSecurityEntries, c.reportedSecurityAt)
		if len(sent) > maximumSecurityEntries {
			t.Fatalf("a heartbeat carried %d entries, over the limit of %d", len(sent), maximumSecurityEntries)
		}
		c.markSecurityReported(sent)
		for _, event := range sent {
			reported[int64(number(event["domain_id"]))] = true
		}
	}
	if len(reported) != len(expected) {
		t.Fatalf("%d of the cell's %d domains were sent within two heartbeats", len(reported), len(expected))
	}
}
