package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestRequestTimeoutScalesWithWhatTheRequestMayTransfer(t *testing.T) {
	if got := requestTimeout(16 << 20); got != snapshotRequestTimeout && got != controlRequestTimeout {
		t.Fatalf("unexpected %s", got)
	}
	if requestTimeout(1<<20) != controlRequestTimeout {
		t.Fatal("a small response must use the short control timeout")
	}
	if requestTimeout(96<<20) != snapshotRequestTimeout {
		t.Fatal("a full snapshot needs the long timeout")
	}
}

func TestNextIntervalBacksOffWithJitterAndStaysBounded(t *testing.T) {
	low, high := func() float64 { return 0 }, func() float64 { return 0.999999 }
	if got := nextInterval(0, low); got != pollInterval {
		t.Fatalf("healthy interval = %s", got)
	}
	if got := nextInterval(0, high); got < pollInterval || got > pollInterval+pollInterval/5 {
		t.Fatalf("healthy jitter out of bounds: %s", got)
	}
	previous := time.Duration(0)
	for failures := 1; failures <= 12; failures++ {
		floor, ceiling := nextInterval(failures, low), nextInterval(failures, high)
		if floor < previous {
			t.Fatalf("backoff must not shrink: %s then %s", previous, floor)
		}
		if ceiling > maxRetryInterval || floor < pollInterval {
			t.Fatalf("failure %d outside bounds: %s..%s", failures, floor, ceiling)
		}
		if floor == ceiling {
			t.Fatalf("failure %d has no jitter", failures)
		}
		previous = floor
	}
	if nextInterval(12, high) < maxRetryInterval-time.Second {
		t.Fatal("the delay must reach the one-minute ceiling")
	}
}

func TestRequestsStopWhenTheAgentLifetimeEnds(t *testing.T) {
	release := make(chan struct{})
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { <-release }))
	defer server.Close()
	defer close(release)
	ctx, stop := context.WithCancel(context.Background())
	c := &client{base: server.URL, http: server.Client(), lifetime: ctx}
	done := make(chan error, 1)
	go func() { done <- c.request("GET", "/edge/v1/tasks", nil, &map[string]any{}, false) }()
	time.Sleep(100 * time.Millisecond)
	stop()
	select {
	case err := <-done:
		if err == nil {
			t.Fatal("a cancelled request must fail")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("shutdown did not cancel the in-flight request")
	}
}
