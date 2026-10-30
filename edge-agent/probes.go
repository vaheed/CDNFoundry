package main

import (
	"context"
	"math/rand"
	"net"
	"strconv"
	"sync"
	"time"
)

// probeInterval separates probe rounds. Each round checks every other PoP's
// public listeners directly, so control can tell a dead PoP from a silent one.
const (
	probeInterval    = 15 * time.Second
	probeTimeout     = 3 * time.Second
	probeConcurrency = 16
)

type probeTarget struct {
	EdgeID  string `json:"edge_id"`
	Address string `json:"address"`
}

type probeResult struct {
	EdgeID  string `json:"edge_id"`
	Address string `json:"address"`
	OK      bool   `json:"ok"`
	Detail  string `json:"detail,omitempty"`
}

// probeListener succeeds only when the PoP's HTTP and TLS listeners both accept
// a connection from here.
func probeListener(address string, httpPort, tlsPort int) (bool, string) {
	for _, port := range []int{httpPort, tlsPort} {
		connection, err := net.DialTimeout("tcp", net.JoinHostPort(address, strconv.Itoa(port)), probeTimeout)
		if err != nil {
			return false, "port " + strconv.Itoa(port) + " unreachable"
		}
		_ = connection.Close()
	}
	return true, ""
}

func (c *client) runProbes() error {
	var response struct {
		Data struct {
			Targets []probeTarget  `json:"targets"`
			Ports   map[string]int `json:"ports"`
		} `json:"data"`
	}
	if err := c.request("GET", "/edge/v1/probes/targets", nil, &response, true); err != nil {
		return err
	}
	httpPort, tlsPort := response.Data.Ports["http"], response.Data.Ports["tls"]
	if httpPort == 0 || tlsPort == 0 || len(response.Data.Targets) == 0 {
		return nil
	}
	if len(response.Data.Targets) > 128 {
		response.Data.Targets = response.Data.Targets[:128]
	}
	results := make([]probeResult, len(response.Data.Targets))
	slots := make(chan struct{}, probeConcurrency)
	var group sync.WaitGroup
	for index, target := range response.Data.Targets {
		group.Add(1)
		slots <- struct{}{}
		go func() {
			defer group.Done()
			defer func() { <-slots }()
			ok, detail := probeListener(target.Address, httpPort, tlsPort)
			results[index] = probeResult{EdgeID: target.EdgeID, Address: target.Address, OK: ok, Detail: detail}
		}()
	}
	group.Wait()
	return c.request("POST", "/edge/v1/probes", map[string]any{"results": results}, &map[string]any{}, true)
}

// probeLoop never blocks synchronization: it runs beside it and a failure only
// means the next round tries again.
func (c *client) probeLoop(ctx context.Context) {
	for {
		if err := c.runProbes(); err != nil {
			logger.Warn("edge probe round failed", "event", "edge_probe_failed", "edge_id", c.id.EdgeID, "error_code", "edge_probe_failed", "error", err.Error())
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(probeInterval + time.Duration(rand.Intn(5000))*time.Millisecond):
		}
	}
}
