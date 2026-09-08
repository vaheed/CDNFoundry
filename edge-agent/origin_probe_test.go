package main

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"encoding/pem"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

// Exercise the real Go resolver against a bounded local UDP DNS fixture.
// Tests using this fixture are deliberately sequential because DefaultResolver
// is process-global. No resolver or HTTP connection is mocked.
func originProbeDNS(t *testing.T, addresses []string) *atomic.Int32 {
	t.Helper()
	listener, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	queries := &atomic.Int32{}
	done := make(chan struct{})
	go func() {
		defer close(done)
		buffer := make([]byte, 4096)
		for {
			n, peer, err := listener.ReadFrom(buffer)
			if err != nil {
				return
			}
			if n < 17 || binary.BigEndian.Uint16(buffer[4:6]) != 1 {
				continue
			}
			end := 12
			for end < n && buffer[end] != 0 {
				length := int(buffer[end])
				if length > 63 {
					end = n
					break
				}
				end += 1 + length
			}
			end += 5
			if end > n {
				continue
			}
			queries.Add(1)
			if len(addresses) == 1 && addresses[0] == "drop" {
				continue
			}
			kind := binary.BigEndian.Uint16(buffer[end-4 : end-2])
			response := append([]byte(nil), buffer[:end]...)
			binary.BigEndian.PutUint16(response[2:4], 0x8180)
			for i := 6; i < 12; i++ {
				response[i] = 0
			}
			count := uint16(0)
			for _, address := range addresses {
				ip := net.ParseIP(address)
				if ip == nil || (kind == 1 && strings.Contains(address, ":")) || (kind == 28 && !strings.Contains(address, ":")) || (kind != 1 && kind != 28) {
					continue
				}
				if kind == 1 {
					ip = ip.To4()
				} else {
					ip = ip.To16()
				}
				response = append(response, 0xc0, 0x0c, byte(kind>>8), byte(kind), 0, 1, 0, 0, 0, 0, 0, byte(len(ip)))
				response = append(response, ip...)
				count++
			}
			binary.BigEndian.PutUint16(response[6:8], count)
			_, _ = listener.WriteTo(response, peer)
		}
	}()
	previous := net.DefaultResolver
	net.DefaultResolver = &net.Resolver{PreferGo: true, Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
		return (&net.Dialer{}).DialContext(ctx, "udp4", listener.LocalAddr().String())
	}}
	t.Cleanup(func() {
		net.DefaultResolver = previous
		_ = listener.Close()
		<-done
	})
	return queries
}

func originProbeServer(t *testing.T, encrypted bool) (edgeTask, *atomic.Int32) {
	t.Helper()
	listener, err := net.Listen("tcp4", "0.0.0.0:0")
	if err != nil {
		t.Fatal(err)
	}
	requests := &atomic.Int32{}
	server := &httptest.Server{Listener: listener, Config: &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests.Add(1)
		if r.Host != "origin.example" {
			t.Errorf("unexpected Host: %s", r.Host)
		}
		if r.URL.Path == "/oversized" {
			w.Header().Set("X-Padding", strings.Repeat("a", 70*1024))
		}
		w.WriteHeader(http.StatusNoContent)
	})}}
	scheme := "http"
	if encrypted {
		server.StartTLS()
		scheme = "https"
	} else {
		server.Start()
	}
	t.Cleanup(server.Close)
	host := privateInterfaceAddress(t)
	task := edgeTask{}
	task.Payload.Addresses = []string{host}
	task.Payload.Allowlist = []string{host + "/32"}
	task.Payload.Origin.Host = "probe.example"
	task.Payload.Origin.HostHeader = "origin.example"
	task.Payload.Origin.Scheme = scheme
	task.Payload.Origin.Port = listener.Addr().(*net.TCPAddr).Port
	task.Payload.Origin.ConnectTimeoutMS = 1000
	task.Payload.Origin.ResponseTimeoutMS = 2000
	return task, requests
}

func TestOriginProbeRevalidatesDNSBeforeConnecting(t *testing.T) {
	for _, scenario := range []string{"unchanged", "loopback", "mixed", "removed", "unapproved", "timeout", "mapped"} {
		t.Run(scenario, func(t *testing.T) {
			task, requests := originProbeServer(t, false)
			addresses := []string{task.Payload.Addresses[0]}
			switch scenario {
			case "loopback":
				addresses = []string{"127.0.0.1"}
			case "mixed":
				addresses = append(addresses, "::1")
			case "removed":
				addresses = nil
			case "unapproved":
				addresses = []string{"8.8.8.8"}
			case "mapped":
				addresses = append(addresses, "::ffff:"+task.Payload.Addresses[0])
			case "timeout":
				addresses = []string{"drop"}
				task.Payload.Origin.ConnectTimeoutMS = 100
			}
			queries := originProbeDNS(t, addresses)
			started := time.Now()
			result := runOriginTest(task)
			if time.Since(started) > 2*time.Second {
				t.Fatal("DNS probe exceeded its bounded deadline")
			}
			if queries.Load() == 0 {
				t.Error("probe skipped fresh DNS resolution")
			}
			if scenario == "unchanged" {
				if result["status"] != "healthy" || requests.Load() != 1 {
					t.Fatalf("unchanged origin did not connect: %#v requests=%d", result, requests.Load())
				}
			} else if result["status"] != "unhealthy" || requests.Load() != 0 {
				t.Fatalf("stale or unsafe DNS reached the old origin: %#v requests=%d", result, requests.Load())
			}
		})
	}
}

func TestOriginProbeRejectsUntrustedTLSAndOversizedHeaders(t *testing.T) {
	t.Run("untrusted certificate", func(t *testing.T) {
		task, requests := originProbeServer(t, true)
		originProbeDNS(t, task.Payload.Addresses)
		task.Payload.Origin.VerifyTLS = true
		task.Payload.Origin.SNI = "example.com"
		result := runOriginTest(task)
		if result["status"] != "unhealthy" || result["tls_result"] != "failed" || requests.Load() != 0 {
			t.Fatalf("untrusted certificate reached HTTP: %#v", result)
		}
	})
	t.Run("header bound", func(t *testing.T) {
		task, _ := originProbeServer(t, false)
		originProbeDNS(t, task.Payload.Addresses)
		task.Payload.Origin.HealthCheck = &struct {
			Path string `json:"path"`
		}{Path: "/oversized"}
		result := runOriginTest(task)
		if result["status"] != "unhealthy" {
			t.Fatalf("oversized response headers were admitted: %#v", result)
		}
	})
}

func TestOriginProbeRejectsMixedApprovedAndMappedAddresses(t *testing.T) {
	for _, address := range []string{"127.0.0.1", "::ffff:8.8.8.8", "0:0:0:0:0:ffff:0808:0808"} {
		t.Run(address, func(t *testing.T) {
			task, requests := originProbeServer(t, false)
			task.Payload.Addresses = append(task.Payload.Addresses, address)
			result := runOriginTest(task)
			if result["failure_reason"] != "blocked_destination" || requests.Load() != 0 {
				t.Fatalf("mixed or mapped approval bypassed safety: %#v", result)
			}
		})
	}
}

func TestOriginProbeVerifiedTLS(t *testing.T) {
	listener, err := net.Listen("tcp4", "0.0.0.0:0")
	if err != nil {
		t.Fatal(err)
	}
	server := &httptest.Server{Listener: listener, Config: &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	})}}
	server.StartTLS()
	defer server.Close()
	// Use a fresh process to load this fixture certificate into the OS trust
	// pool. The production probe still uses normal certificate verification.
	certificate := filepath.Join(t.TempDir(), "fixture-ca.pem")
	if err := os.WriteFile(certificate, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: server.Certificate().Raw}), 0600); err != nil {
		t.Fatal(err)
	}
	task := edgeTask{}
	host := privateInterfaceAddress(t)
	task.Payload.Addresses = []string{host}
	task.Payload.Allowlist = []string{host + "/32"}
	task.Payload.Origin.Host = host
	task.Payload.Origin.HostHeader = "example.com"
	task.Payload.Origin.SNI = "example.com"
	task.Payload.Origin.Scheme = "https"
	task.Payload.Origin.VerifyTLS = true
	task.Payload.Origin.Port = listener.Addr().(*net.TCPAddr).Port
	task.Payload.Origin.ConnectTimeoutMS = 1000
	task.Payload.Origin.ResponseTimeoutMS = 2000
	payload, err := json.Marshal(task)
	if err != nil {
		t.Fatal(err)
	}
	child := exec.Command(os.Args[0], "-test.run=^TestOriginProbeVerifiedTLSChild$")
	child.Env = append(os.Environ(), "SSL_CERT_FILE="+certificate, "SSL_CERT_DIR="+t.TempDir(), "CDNF_PROBE_FIXTURE="+string(payload))
	if output, err := child.CombinedOutput(); err != nil {
		t.Fatalf("verified TLS probe failed: %v %s", err, output)
	}
}

func TestOriginProbeVerifiedTLSChild(t *testing.T) {
	payload := os.Getenv("CDNF_PROBE_FIXTURE")
	if payload == "" {
		t.Skip("invoked by the verified TLS fixture in a fresh trust-store process")
	}
	var task edgeTask
	if err := json.Unmarshal([]byte(payload), &task); err != nil {
		t.Fatal(err)
	}
	result := runOriginTest(task)
	if result["status"] != "healthy" || result["tls_result"] != "verified" {
		t.Fatalf("trusted TLS failed: %#v", result)
	}
}

func TestOriginProbeIPv6(t *testing.T) {
	addresses, err := net.InterfaceAddrs()
	if err != nil {
		t.Fatal(err)
	}
	host := ""
	for _, address := range addresses {
		ip, _, err := net.ParseCIDR(address.String())
		if err == nil && ip.To4() == nil && ip.IsPrivate() && !ip.IsLoopback() {
			host = ip.String()
			break
		}
	}
	if host == "" {
		if os.Getenv("CDNF_QUALIFY_IPV6") == "1" {
			t.Fatal("required isolated IPv6 interface is unavailable")
		}
		t.Skip("run tests/e2e/origin_probes.py for required IPv6 qualification")
	}
	listener, err := net.Listen("tcp6", "[::]:0")
	if err != nil {
		t.Fatal(err)
	}
	requests := &atomic.Int32{}
	server := &httptest.Server{Listener: listener, Config: &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests.Add(1)
		w.WriteHeader(http.StatusNoContent)
	})}}
	server.Start()
	defer server.Close()
	originProbeDNS(t, []string{host})
	for _, originHost := range []string{host, "probe.example"} {
		task := edgeTask{}
		task.Payload.Addresses = []string{host}
		task.Payload.Allowlist = []string{host + "/128"}
		task.Payload.Origin.Host = originHost
		task.Payload.Origin.HostHeader = "origin.example"
		task.Payload.Origin.Scheme = "http"
		task.Payload.Origin.Port = listener.Addr().(*net.TCPAddr).Port
		task.Payload.Origin.ConnectTimeoutMS = 1000
		task.Payload.Origin.ResponseTimeoutMS = 2000
		result := runOriginTest(task)
		if result["status"] != "healthy" || result["resolved_address"] != host {
			t.Fatalf("IPv6 origin %s failed: %#v", originHost, result)
		}
	}
	if requests.Load() != 2 {
		t.Fatalf("expected literal and AAAA-resolved IPv6 traffic; received %d", requests.Load())
	}
}

func TestOriginProbeDoesNotReportUnverifiedTLSAsVerified(t *testing.T) {
	task, requests := originProbeServer(t, true)
	originProbeDNS(t, task.Payload.Addresses)
	result := runOriginTest(task)
	if result["status"] != "healthy" || result["tls_result"] != "unverified" || requests.Load() != 1 {
		t.Fatalf("insecure TLS diagnostic was misreported: %#v", result)
	}
}
