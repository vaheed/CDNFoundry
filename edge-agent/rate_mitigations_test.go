package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

const rateTestHash = "0123456789abcdef0123456789abcdef"

func TestHeavyKeysAreForwardedWithTheCellNameAndBoundedAndMalformedOnesAreDropped(t *testing.T) {
	c := &client{}
	good := map[string]any{"domain_id": float64(7), "rule_id": float64(3), "hash": rateTestHash, "window": float64(100), "count": float64(40)}
	c.collectLongRate("cell-01", []any{
		good,
		map[string]any{"domain_id": float64(7), "rule_id": float64(3), "hash": "not-a-hash", "window": float64(100), "count": float64(40)},
		map[string]any{"domain_id": float64(7), "rule_id": float64(3), "hash": rateTestHash, "window": float64(100), "count": float64(0)},
		"junk",
	})
	if len(c.longRate) != 1 || c.longRate[0]["cell"] != "cell-01" || c.longRate[0]["hash"] != rateTestHash {
		t.Fatalf("unexpected entries: %#v", c.longRate)
	}
	for index := 0; index < 3000; index++ {
		c.collectLongRate("cell-02", []any{good})
	}
	if len(c.longRate) != 32*maximumLongRateKeys {
		t.Fatalf("the heartbeat is not bounded: %d", len(c.longRate))
	}
}

func TestMitigationsAreFetchedValidatedAndHandedToACellWithTheirRemainingTime(t *testing.T) {
	var pushed []map[string]any
	cell := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var command map[string]any
		_ = json.NewDecoder(r.Body).Decode(&command)
		pushed = append(pushed, command)
	}))
	defer cell.Close()
	control := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"version": 9, "items": []map[string]any{
			{"domain_id": 7, "rule_id": 3, "hash": rateTestHash, "seconds": 600},
			{"domain_id": 7, "rule_id": 3, "hash": "bad", "seconds": 600},
			{"domain_id": 7, "rule_id": 3, "hash": rateTestHash, "seconds": 0},
		}}})
	}))
	defer control.Close()
	c := &client{base: control.URL, http: control.Client(), id: identity{EdgeID: "edge-1"}}

	c.refreshRateMitigations()
	if c.rateMitigations.Version != 9 || len(c.rateMitigations.Items) != 1 {
		t.Fatalf("unexpected set: %#v", c.rateMitigations)
	}
	c.rateMitigations.FetchedAt = time.Now().Add(-100 * time.Second)
	command := c.rateMitigationsCommand()
	items := command["items"].([]rateMitigation)
	if command["action"] != "rate_mitigations" || command["version"] != int64(9) || len(items) != 1 || items[0].Seconds > 500 || items[0].Seconds < 495 {
		t.Fatalf("the remaining time was not subtracted: %#v", command)
	}
	c.rateMitigations.FetchedAt = time.Now().Add(-700 * time.Second)
	if items := c.rateMitigationsCommand()["items"].([]rateMitigation); len(items) != 0 {
		t.Fatal("an ended mitigation was handed on")
	}

	c.statusToken = "token"
	endpoint := cell.URL + "/passive-failures"
	c.rateMitigations.FetchedAt = time.Now()
	c.pushRateMitigations(endpoint, float64(9))
	if len(pushed) != 0 {
		t.Fatal("a cell at the current version was pushed to again")
	}
	c.pushRateMitigations(endpoint, nil)
	c.pushRateMitigations(endpoint, float64(4))
	if len(pushed) != 2 || pushed[0]["action"] != "rate_mitigations" || !strings.HasPrefix(pushed[0]["task_id"].(string), "rate-mitigations-9-") {
		t.Fatalf("a cell without or behind the version was not given the set: %#v", pushed)
	}
	if (&client{}).pushRateMitigations(endpoint, nil); len(pushed) != 2 {
		t.Fatal("nothing should be pushed before the control plane issued a version")
	}
}
