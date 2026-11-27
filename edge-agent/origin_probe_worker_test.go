package main

import (
	"bytes"
	"net"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// serveWorker serves a worker on a unix socket the way the container does and returns the socket path.
func serveWorker(t *testing.T, worker *probeWorker) string {
	t.Helper()
	socket := filepath.Join(t.TempDir(), "probe.sock")
	listener, err := net.Listen("unix", socket)
	if err != nil {
		t.Fatal(err)
	}
	server := &http.Server{Handler: worker.handler()}
	go func() { _ = server.Serve(listener) }()
	t.Cleanup(func() { _ = server.Close() })
	return socket
}

func TestTheAgentGetsTheWorkersResultThroughTheSocket(t *testing.T) {
	worker := newProbeWorker()
	worker.run = func(task edgeTask) map[string]any {
		return map[string]any{"status": "healthy", "http_status": 204, "latency_ms": 3, "echo": task.Payload.Origin.Host}
	}
	socket := serveWorker(t, worker)
	task := edgeTask{Type: "origin_test"}
	task.Payload.Origin.Host = "origin.example"
	result := forwardToProbeWorker(socket, task)
	if result["status"] != "healthy" || result["echo"] != "origin.example" {
		t.Fatalf("the worker's result must come back unchanged: %v", result)
	}
}

func TestAnUnreachableWorkerIsAWorkerFailureAndNotAnOriginVerdict(t *testing.T) {
	result := forwardToProbeWorker(filepath.Join(t.TempDir(), "missing.sock"), edgeTask{Type: "origin_test"})
	if result["failure_reason"] != "worker_unavailable" || result["status"] != "unhealthy" {
		t.Fatalf("a missing worker must read as worker_unavailable: %v", result)
	}
}

func TestTheWorkerRunsOnlyOriginTestsAndRefusesAnythingElse(t *testing.T) {
	server := httptest.NewServer(newProbeWorker().handler())
	defer server.Close()
	for name, body := range map[string]string{"another task type": `{"type":"cache_purge"}`, "not json": `nope`, "empty": ``} {
		response, err := http.Post(server.URL+"/probe", "application/json", bytes.NewReader([]byte(body)))
		if err != nil {
			t.Fatal(err)
		}
		response.Body.Close()
		if response.StatusCode != http.StatusBadRequest {
			t.Fatalf("%s: expected 400, got %d", name, response.StatusCode)
		}
	}
	response, err := http.Get(server.URL + "/probe")
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != http.StatusMethodNotAllowed {
		t.Fatalf("only POST runs a probe, got %d", response.StatusCode)
	}
}

func TestTheWorkerRunsAtMostEightProbesAtOnceAndRefusesBeyondItsQueue(t *testing.T) {
	var running, peak atomic.Int32
	release := make(chan struct{})
	worker := newProbeWorker()
	worker.run = func(edgeTask) map[string]any {
		now := running.Add(1)
		for {
			seen := peak.Load()
			if now <= seen || peak.CompareAndSwap(seen, now) {
				break
			}
		}
		<-release
		running.Add(-1)
		return map[string]any{"status": "healthy"}
	}
	server := httptest.NewServer(worker.handler())
	defer server.Close()
	total := probeWorkerSlots + probeWorkerQueue
	var group sync.WaitGroup
	for i := 0; i < total; i++ {
		group.Add(1)
		go func() {
			defer group.Done()
			response, err := http.Post(server.URL+"/probe", "application/json", bytes.NewReader([]byte(`{"type":"origin_test"}`)))
			if err == nil {
				response.Body.Close()
			}
		}()
	}
	deadline := time.Now().Add(5 * time.Second)
	for len(worker.waiting) < total && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	if peak.Load() != probeWorkerSlots {
		t.Fatalf("expected %d probes running at once, saw %d", probeWorkerSlots, peak.Load())
	}
	response, err := http.Post(server.URL+"/probe", "application/json", bytes.NewReader([]byte(`{"type":"origin_test"}`)))
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != http.StatusTooManyRequests {
		t.Fatalf("a task beyond the queue must be refused at once, got %d", response.StatusCode)
	}
	close(release)
	group.Wait()
	if peak.Load() > probeWorkerSlots {
		t.Fatalf("the slot limit was exceeded: %d", peak.Load())
	}
}

func TestABusyWorkerIsReportedAsBusy(t *testing.T) {
	worker := newProbeWorker()
	for i := 0; i < cap(worker.waiting); i++ {
		worker.waiting <- struct{}{}
	}
	socket := serveWorker(t, worker)
	result := forwardToProbeWorker(socket, edgeTask{Type: "origin_test"})
	if result["failure_reason"] != "worker_busy" {
		t.Fatalf("a full queue must read as worker_busy: %v", result)
	}
}

func TestTheWorkerHealthCheckAsksItsOwnSocket(t *testing.T) {
	socket := serveWorker(t, newProbeWorker())
	if probeWorkerHealth(socket) != 0 {
		t.Fatal("a serving worker must be healthy")
	}
	if probeWorkerHealth(filepath.Join(t.TempDir(), "gone.sock")) == 0 {
		t.Fatal("a worker that is not serving must not be healthy")
	}
}

func TestTheWorkerRunsTheRealProbeAndDropsAnExpiredTask(t *testing.T) {
	socket := serveWorker(t, newProbeWorker())
	task := edgeTask{Type: "origin_test"}
	task.Payload.ProbeExpiresAt = time.Now().Add(-time.Minute).Unix()
	task.Payload.Addresses = []string{"203.0.113.9"}
	task.Payload.Origin.Host = "203.0.113.9"
	task.Payload.Origin.Port = 80
	result := forwardToProbeWorker(socket, task)
	if result["failure_reason"] != "task_expired" {
		t.Fatalf("the worker must apply the same expiry as the agent: %v", result)
	}
}
