package main

import "testing"

func event(domain int, reason string, count int, clients int) map[string]any {
	return map[string]any{"domain_id": float64(domain), "hostname": "d.test", "reason_code": reason, "count": float64(count), "occurred_at": float64(100), "clients": float64(clients)}
}

func TestMergingCellsAddsCountsAndKeepsTheBestEvidenceOncePerDomainAndReason(t *testing.T) {
	merged := mergeSecurityEvents([]map[string]any{
		event(1, "waf_block", 10, 3), event(1, "waf_block", 5, 9), event(1, "client_rate_exceeded", 2, 1), event(2, "waf_block", 1, 1),
	})
	if len(merged) != 3 {
		t.Fatalf("one entry per domain and reason, got %d", len(merged))
	}
	if number(merged[0]["count"]) != 15 || number(merged[0]["clients"]) != 9 {
		t.Fatalf("counts add and the largest client evidence wins: %v", merged[0])
	}
}

func TestMergingDoesNotChangeTheCellsOwnEntries(t *testing.T) {
	original := event(1, "waf_block", 10, 3)
	mergeSecurityEvents([]map[string]any{original, event(1, "waf_block", 5, 3)})
	if number(original["count"]) != 10 {
		t.Fatal("merging changed a cell's entry")
	}
}

func TestEveryDomainGetsASlotBeforeAnyDomainGetsASecondOne(t *testing.T) {
	events := []map[string]any{}
	for _, reason := range []string{"a", "b", "c", "d", "e", "f"} {
		events = append(events, event(1, reason, 1000, 5))
	}
	for domain := 2; domain <= 11; domain++ {
		events = append(events, event(domain, "waf_block", 1, 1))
	}
	chosen, dropped := fairSecurityEvents(events, 10, nil)
	seen := map[int64]int{}
	for _, entry := range chosen {
		seen[int64(number(entry["domain_id"]))]++
	}
	if len(chosen) != 10 || dropped != 1 {
		t.Fatalf("ten of eleven domains fit, one cannot: chosen %d, dropped %d", len(chosen), dropped)
	}
	if seen[1] != 1 {
		t.Fatalf("the noisy domain took %d of the first ten slots", seen[1])
	}
}

func TestTheBusiestReasonOfADomainComesFirst(t *testing.T) {
	chosen, _ := fairSecurityEvents([]map[string]any{event(1, "quiet", 1, 1), event(1, "loud", 90, 1)}, 1, nil)
	if chosen[0]["reason_code"] != "loud" {
		t.Fatalf("chose %v", chosen[0])
	}
}

func TestOnlyChangedTotalsArePendingAndOnlyAcceptedOnesAreRemembered(t *testing.T) {
	c := &client{}
	first := []map[string]any{event(1, "waf_block", 5, 2), event(2, "waf_block", 1, 1)}
	if got := c.pendingSecurityEvents(first); len(got) != 2 {
		t.Fatalf("everything is new at first: %d", len(got))
	}
	// A heartbeat that failed reports nothing as sent, so everything is still pending.
	if got := c.pendingSecurityEvents(first); len(got) != 2 {
		t.Fatalf("an entry stays pending until a heartbeat is accepted: %d", len(got))
	}
	c.markSecurityReported(first)
	if got := c.pendingSecurityEvents(first); len(got) != 0 {
		t.Fatalf("unchanged totals are not sent again: %d", len(got))
	}
	changed := []map[string]any{event(1, "waf_block", 9, 2), event(2, "waf_block", 1, 1)}
	got := c.pendingSecurityEvents(changed)
	if len(got) != 1 || number(got[0]["domain_id"]) != 1 {
		t.Fatalf("only the domain whose total changed is pending: %v", got)
	}
	// A cell restart lowers a total; that is a change too.
	if got := c.pendingSecurityEvents([]map[string]any{event(1, "waf_block", 2, 1)}); len(got) != 1 {
		t.Fatal("a lower total after a restart must be sent")
	}
}

func TestAnUnchangedNoisyDomainDoesNotTakeSlotsFromChangedOnes(t *testing.T) {
	c := &client{}
	noisy := []map[string]any{}
	for _, reason := range []string{"a", "b", "c", "d", "e"} {
		noisy = append(noisy, event(1, reason, 500, 5))
	}
	c.markSecurityReported(noisy)
	all := append(append([]map[string]any{}, noisy...), event(2, "waf_block", 1, 1), event(3, "waf_block", 1, 1))
	chosen, dropped := fairSecurityEvents(c.pendingSecurityEvents(all), 2, c.reportedSecurityAt)
	if len(chosen) != 2 || dropped != 0 {
		t.Fatalf("both changed domains fit: %d chosen, %d dropped", len(chosen), dropped)
	}
}

func TestDomainsReportedLongestAgoGoFirstSoEveryNoisyDomainIsReportedWithinTwoHeartbeats(t *testing.T) {
	c := &client{}
	counts := map[int]int{}
	snapshot := func() []map[string]any {
		events := []map[string]any{}
		for domain := 1; domain <= 200; domain++ {
			// Every total changes before every heartbeat, as under a sustained attack on every domain.
			counts[domain] += 10
			events = append(events, event(domain, "waf_block", counts[domain], 1))
		}
		return events
	}
	reported := map[int64]bool{}
	for heartbeat := 0; heartbeat < 2; heartbeat++ {
		chosen, _ := fairSecurityEvents(c.pendingSecurityEvents(snapshot()), maximumSecurityEntries, c.reportedSecurityAt)
		for _, entry := range chosen {
			reported[int64(number(entry["domain_id"]))] = true
		}
		c.markSecurityReported(chosen)
	}
	if len(reported) != 200 {
		t.Fatalf("200 domains with changing totals must all be reported within two heartbeats, got %d", len(reported))
	}
}
