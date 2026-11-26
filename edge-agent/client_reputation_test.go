package main

import "testing"

func TestOffendingClientsAreForwardedWithTheCellNameAndBoundedAndMalformedOnesAreDropped(t *testing.T) {
	c := &client{}
	good := map[string]any{"domain_id": float64(7), "client": "198.51.100.7", "events": float64(40), "requests": float64(50)}
	c.collectOffenders("cell-01", []any{
		good,
		map[string]any{"domain_id": float64(7), "client": "2001:db8:1:2:0:0:0:0", "events": float64(3), "requests": float64(3)},
		map[string]any{"domain_id": float64(7), "client": "not-an-address", "events": float64(40), "requests": float64(50)},
		map[string]any{"domain_id": float64(7), "client": "198.51.100.8", "events": float64(0), "requests": float64(50)},
		map[string]any{"domain_id": float64(0), "client": "198.51.100.9", "events": float64(4), "requests": float64(50)},
		"junk",
	})
	if len(c.offenders) != 2 || c.offenders[0]["cell"] != "cell-01" || c.offenders[1]["client"] != "2001:db8:1:2:0:0:0:0" {
		t.Fatalf("unexpected entries: %#v", c.offenders)
	}
	c.collectOffenders("", []any{good})
	if len(c.offenders) != 2 {
		t.Fatalf("an entry without a cell name was kept")
	}
	for index := 0; index < 3000; index++ {
		list := make([]any, 0, 80)
		for len(list) < 80 {
			list = append(list, good)
		}
		c.collectOffenders("cell-02", list)
	}
	if len(c.offenders) != 32*maximumOffendingClients {
		t.Fatalf("the heartbeat is not bounded: %d", len(c.offenders))
	}
}
