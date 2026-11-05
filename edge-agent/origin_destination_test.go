package main

import "testing"

func TestBlockedIPRefusesIPv4TransitionRanges(t *testing.T) {
	for _, address := range []string{
		"2002:808:808::1",                      // 6to4 embedding 8.8.8.8
		"2001:0:4136:e378:8000:63bf:3fff:fdd2", // Teredo
		"::808:808",                            // IPv4-compatible
		"100::1",                               // discard prefix
		"::ffff:8.8.8.8",                       // IPv4-mapped
	} {
		if !blockedIP(address, nil, nil) {
			t.Errorf("%s must be blocked", address)
		}
	}
	for _, address := range []string{"8.8.8.8", "2606:4700:4700::1111"} {
		if blockedIP(address, nil, nil) {
			t.Errorf("%s must be allowed", address)
		}
	}
}

func TestOriginProbeRefusesPortsOutsideTheAllowedSet(t *testing.T) {
	for _, port := range []int{22, 25, 3306, 6379, 9200, 65535} {
		if allowedOriginPorts[port] {
			t.Errorf("port %d must not be allowed", port)
		}
	}
	for _, port := range []int{80, 443, 8080, 8443} {
		if !allowedOriginPorts[port] {
			t.Errorf("port %d must be allowed", port)
		}
	}
	var task edgeTask
	task.Payload.Addresses = []string{"8.8.8.8"}
	task.Payload.Origin.Host = "8.8.8.8"
	task.Payload.Origin.Port = 6379
	if result := runOriginTest(task); result["failure_reason"] != "blocked_destination" {
		t.Errorf("a probe to port 6379 must be blocked, got %v", result)
	}
}
