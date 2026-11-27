package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"
)

// The origin probe worker is the agent binary started with --origin-probe-worker in its own container. It has no inbound network API,
// no datastore and no secret: it listens on a unix socket that only the agent's container shares, runs the same probe the agent runs
// itself, and returns the result. The agent delivers the control plane's task and forwards the result, so the probe is the only thing
// the worker does. Its limits (slots, queue, ceilings per probe) are fixed here, not configurable.
const (
	probeWorkerSlots     = 8
	probeWorkerQueue     = 64
	probeWorkerTaskBytes = 64 * 1024
	probeWorkerReply     = 10 * time.Second
)

// probeWorker bounds the probes of one worker: at most probeWorkerSlots run at once, at most probeWorkerQueue more wait, and a task
// beyond that is refused at once so the agent can report the worker as busy instead of letting a backlog build up.
type probeWorker struct {
	slots   chan struct{}
	waiting chan struct{}
	run     func(edgeTask) map[string]any
}

func newProbeWorker() *probeWorker {
	return &probeWorker{slots: make(chan struct{}, probeWorkerSlots), waiting: make(chan struct{}, probeWorkerSlots+probeWorkerQueue), run: runOriginTest}
}

func (w *probeWorker) handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/probe", func(response http.ResponseWriter, request *http.Request) {
		if request.Method != http.MethodPost {
			http.Error(response, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		var task edgeTask
		if err := json.NewDecoder(io.LimitReader(request.Body, probeWorkerTaskBytes)).Decode(&task); err != nil || task.Type != "origin_test" {
			http.Error(response, "not an origin test", http.StatusBadRequest)
			return
		}
		select {
		case w.waiting <- struct{}{}:
		default:
			http.Error(response, "worker busy", http.StatusTooManyRequests)
			return
		}
		defer func() { <-w.waiting }()
		select {
		case w.slots <- struct{}{}:
		case <-request.Context().Done():
			return
		}
		defer func() { <-w.slots }()
		response.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(response).Encode(w.run(task))
	})
	mux.HandleFunc("/healthz", func(response http.ResponseWriter, _ *http.Request) { response.WriteHeader(http.StatusNoContent) })
	return mux
}

// runProbeWorker serves the socket until the process is stopped. It refuses to start without a socket path.
func runProbeWorker(socket string) error {
	if socket == "" {
		return errors.New("EDGE_PROBE_SOCKET is required")
	}
	if err := os.MkdirAll(filepath.Dir(socket), 0o750); err != nil {
		return err
	}
	_ = os.Remove(socket)
	listener, err := net.Listen("unix", socket)
	if err != nil {
		return err
	}
	if err := os.Chmod(socket, 0o660); err != nil {
		return err
	}
	server := &http.Server{Handler: newProbeWorker().handler(), ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second, WriteTimeout: probeTotalCeiling + 10*time.Second}
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGTERM, syscall.SIGINT)
	go func() {
		<-stop
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		_ = server.Shutdown(ctx)
	}()
	if err := server.Serve(listener); err != nil && !errors.Is(err, http.ErrServerClosed) {
		return err
	}
	return nil
}

// probeWorkerHealth is the worker container's own readiness check: it asks the socket it serves.
func probeWorkerHealth(socket string) int {
	client := unixClient(socket, 3*time.Second)
	response, err := client.Get("http://worker/healthz")
	if err != nil {
		return 1
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusNoContent {
		return 1
	}
	return 0
}

func unixClient(socket string, timeout time.Duration) *http.Client {
	return &http.Client{Timeout: timeout, Transport: &http.Transport{DisableKeepAlives: true,
		DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			return (&net.Dialer{Timeout: 2 * time.Second}).DialContext(ctx, "unix", socket)
		}}}
}

// forwardToProbeWorker has the worker run an origin test and returns its result. An unreachable or busy worker is a result of its own
// (worker_unavailable, worker_busy), never a verdict on the origin, so the control plane can ask another worker.
func forwardToProbeWorker(socket string, task edgeTask) map[string]any {
	failed := func(reason string) map[string]any {
		return map[string]any{"status": "unhealthy", "failure_reason": reason, "latency_ms": 0}
	}
	body, err := json.Marshal(task)
	if err != nil || len(body) > probeWorkerTaskBytes {
		return failed("worker_unavailable")
	}
	response, err := unixClient(socket, probeWorkerReply+probeTotalCeiling).Post("http://worker/probe", "application/json", bytes.NewReader(body))
	if err != nil {
		return failed("worker_unavailable")
	}
	defer response.Body.Close()
	if response.StatusCode == http.StatusTooManyRequests {
		return failed("worker_busy")
	}
	var result map[string]any
	if response.StatusCode != http.StatusOK || json.NewDecoder(io.LimitReader(response.Body, 64*1024)).Decode(&result) != nil || result == nil {
		return failed("worker_unavailable")
	}
	return result
}
