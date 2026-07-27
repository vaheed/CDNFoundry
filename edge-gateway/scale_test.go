package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"runtime"
	"sort"
	"sync"
	"sync/atomic"
	"syscall"
	"testing"
	"time"
)

func TestScaleSocketThroughput50000Mappings(t *testing.T) {
	if testing.Short() {
		t.Skip("scale qualification")
	}
	upstream, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer upstream.Close()
	go serveScaleUpstream(upstream)

	const mappings = 50000
	candidate := config{
		SchemaVersion: 1, Revision: 9002, Listeners: []string{"127.0.0.60:80"},
		Routes: make([]route, 0, mappings),
	}
	for index := 0; index < mappings; index++ {
		candidate.Routes = append(candidate.Routes, route{
			Address: "127.0.0.60", Hostname: fmt.Sprintf("host-%05d.socket-scale.example.test", index),
			HTTP: upstream.Addr().String(),
		})
	}
	stateDir := t.TempDir()
	g := &gateway{stateDir: stateDir, listeners: map[string]net.Listener{}}
	encoded, err := json.Marshal(candidate)
	if err != nil {
		t.Fatal(err)
	}
	if err := g.activate(encoded); err != nil {
		t.Fatal(err)
	}
	defer g.close()

	acceptedConcurrency := 0
	for _, concurrency := range []int{16, 64, 128} {
		result := runSocketLoad(t, concurrency, 200)
		t.Logf("socket_load mappings=%d concurrency=%d requests=%d seconds=%.3f requests_per_second=%.0f p50_ms=%.3f p95_ms=%.3f p99_ms=%.3f errors=%d",
			mappings, concurrency, result.requests, result.duration.Seconds(), float64(result.requests)/result.duration.Seconds(),
			result.percentile(50), result.percentile(95), result.percentile(99), result.errors)
		if result.errors != 0 {
			if acceptedConcurrency == 0 {
				t.Fatalf("socket load failed at minimum concurrency %d with %d errors", concurrency, result.errors)
			}
			t.Logf("socket_saturation=observed concurrency=%d errors=%d accepted_connection_concurrency=%d upstream=bounded_local_tcp",
				concurrency, result.errors, acceptedConcurrency)
			return
		}
		acceptedConcurrency = concurrency
	}
	t.Logf("socket_saturation=not_observed accepted_connection_concurrency=%d upstream=bounded_local_tcp", acceptedConcurrency)
}

type socketLoadResult struct {
	requests int
	errors   int
	duration time.Duration
	latency  []time.Duration
}

func (result socketLoadResult) percentile(value int) float64 {
	if len(result.latency) == 0 {
		return 0
	}
	return float64(result.latency[(len(result.latency)-1)*value/100].Microseconds()) / 1000
}

func runSocketLoad(t *testing.T, concurrency, each int) socketLoadResult {
	t.Helper()
	started := time.Now()
	latencies := make(chan time.Duration, concurrency*each)
	errors := atomic.Int64{}
	var wait sync.WaitGroup
	for worker := 0; worker < concurrency; worker++ {
		wait.Add(1)
		go func(offset int) {
			defer wait.Done()
			for request := 0; request < each; request++ {
				began := time.Now()
				connection, err := net.DialTimeout("tcp4", "127.0.0.60:80", 3*time.Second)
				if err == nil {
					host := (request + offset) % 50000
					_, err = fmt.Fprintf(connection, "GET / HTTP/1.1\r\nHost: host-%05d.socket-scale.example.test\r\nConnection: close\r\n\r\n", host)
				}
				if err == nil {
					response, readErr := io.ReadAll(io.LimitReader(connection, 1024))
					err = readErr
					if !bytes.Contains(response, []byte("200 OK")) {
						err = fmt.Errorf("invalid response")
					}
				}
				if connection != nil {
					_ = connection.Close()
				}
				if err != nil {
					errors.Add(1)
				} else {
					latencies <- time.Since(began)
				}
			}
		}(worker)
	}
	wait.Wait()
	close(latencies)
	values := make([]time.Duration, 0, concurrency*each)
	for latency := range latencies {
		values = append(values, latency)
	}
	sort.Slice(values, func(left, right int) bool { return values[left] < values[right] })
	return socketLoadResult{requests: concurrency * each, errors: int(errors.Load()), duration: time.Since(started), latency: values}
}

func serveScaleUpstream(listener net.Listener) {
	for {
		connection, err := listener.Accept()
		if err != nil {
			return
		}
		go func() {
			defer connection.Close()
			header := make([]byte, 16)
			if _, err := io.ReadFull(connection, header); err != nil {
				return
			}
			length := int(header[14])<<8 | int(header[15])
			if length > 36 {
				return
			}
			if _, err := io.CopyN(io.Discard, connection, int64(length)); err != nil {
				return
			}
			reader := bufio.NewReaderSize(connection, 4096)
			for {
				line, err := reader.ReadString('\n')
				if err != nil {
					return
				}
				if line == "\r\n" {
					break
				}
			}
			_, _ = io.WriteString(connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok")
		}()
	}
}

func TestScaleTarget50000Mappings(t *testing.T) {
	if testing.Short() {
		t.Skip("scale qualification")
	}
	const mappings = 50000
	candidate := config{
		SchemaVersion: 1,
		Revision:      9001,
		Listeners: []string{
			"192.0.2.10:80", "192.0.2.10:443", "[2001:db8::10]:80", "[2001:db8::10]:443",
			"192.0.2.11:80", "192.0.2.11:443", "[2001:db8::11]:80", "[2001:db8::11]:443",
		},
		Routes: make([]route, 0, mappings),
	}
	addresses := []string{"192.0.2.10", "2001:db8::10", "192.0.2.11", "2001:db8::11"}
	for index := 0; index < mappings; index++ {
		candidate.Routes = append(candidate.Routes, route{
			Address: addresses[index%len(addresses)], Hostname: fmt.Sprintf("host-%05d.scale.example.test", index),
			HTTP: "cell-a:8081", HTTPS: "cell-a:8444",
		})
	}
	runtime.GC()
	var before, after runtime.MemStats
	runtime.ReadMemStats(&before)
	started := time.Now()
	table, err := validate(candidate)
	activationDuration := time.Since(started)
	if err != nil {
		t.Fatal(err)
	}
	runtime.ReadMemStats(&after)
	if len(table.routes) != mappings*2 {
		t.Fatalf("expected %d protocol mappings, got %d", mappings*2, len(table.routes))
	}

	const workers, lookupsPerWorker = 64, 20000
	var found atomic.Uint64
	var usageBefore, usageAfter syscall.Rusage
	_ = syscall.Getrusage(syscall.RUSAGE_SELF, &usageBefore)
	started = time.Now()
	var wait sync.WaitGroup
	for worker := 0; worker < workers; worker++ {
		wait.Add(1)
		go func(offset int) {
			defer wait.Done()
			for index := 0; index < lookupsPerWorker; index++ {
				host := (index + offset) % mappings
				address := addresses[host%len(addresses)]
				if table.routes[fmt.Sprintf("https|%s|host-%05d.scale.example.test", address, host)] != "" {
					found.Add(1)
				}
			}
		}(worker)
	}
	wait.Wait()
	lookupDuration := time.Since(started)
	_ = syscall.Getrusage(syscall.RUSAGE_SELF, &usageAfter)
	cpuSeconds := rusageSeconds(usageAfter) - rusageSeconds(usageBefore)
	total := uint64(workers * lookupsPerWorker)
	if found.Load() != total {
		t.Fatalf("lost lookups: expected %d, found %d", total, found.Load())
	}
	t.Logf(
		"mappings=%d protocol_routes=%d listeners=%d activation_ms=%.3f heap_delta_mib=%.2f concurrent_workers=%d lookups=%d lookup_seconds=%.3f cpu_seconds=%.3f average_cpu_cores=%.2f lookups_per_second=%.0f average_lookup_us=%.3f saturation=not_observed accepted_concurrency=%d",
		mappings, len(table.routes), len(candidate.Listeners), float64(activationDuration.Microseconds())/1000,
		float64(after.HeapAlloc-before.HeapAlloc)/(1024*1024), workers, total, lookupDuration.Seconds(),
		cpuSeconds, cpuSeconds/lookupDuration.Seconds(), float64(total)/lookupDuration.Seconds(),
		float64(lookupDuration.Microseconds())/float64(total), workers,
	)
}

func rusageSeconds(usage syscall.Rusage) float64 {
	return float64(usage.Utime.Sec+usage.Stime.Sec) +
		float64(usage.Utime.Usec+usage.Stime.Usec)/1_000_000
}
