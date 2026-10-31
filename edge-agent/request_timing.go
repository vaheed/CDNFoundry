package main

import (
	"context"
	"time"
)

const (
	controlRequestTimeout  = 15 * time.Second
	snapshotRequestTimeout = 2 * time.Minute
	pollInterval           = 5 * time.Second
	maxRetryInterval       = time.Minute
	// A request that may carry a full snapshot needs time to stream it; every other control
	// request is small and must fail fast so the loop and its liveness signal keep moving.
	largeResponseBytes = 8 << 20
)

// requestTimeout bounds one control request by what it may transfer.
func requestTimeout(responseLimit int64) time.Duration {
	if responseLimit > largeResponseBytes {
		return snapshotRequestTimeout
	}
	return controlRequestTimeout
}

// nextInterval is the wait before the next synchronization. After success it is the poll
// interval with up to 20% jitter, so a fleet does not poll in step. After consecutive
// failures the ceiling doubles up to one minute, and the wait is drawn from its upper half,
// so an unreachable control plane is not hammered and recovering PoPs do not return together.
// jitter must return a value in [0, 1).
func nextInterval(failures int, jitter func() float64) time.Duration {
	if failures <= 0 {
		return pollInterval + time.Duration(jitter()*0.2*float64(pollInterval))
	}
	ceiling := pollInterval
	for i := 0; i < failures && ceiling < maxRetryInterval; i++ {
		ceiling *= 2
	}
	if ceiling > maxRetryInterval {
		ceiling = maxRetryInterval
	}
	return ceiling/2 + time.Duration(jitter()*float64(ceiling/2))
}

// requestContext derives a per-request deadline from the agent's lifetime context, so shutdown
// also cancels requests that are in flight.
func (c *client) requestContext(timeout time.Duration) (context.Context, context.CancelFunc) {
	parent := c.lifetime
	if parent == nil {
		parent = context.Background()
	}
	return context.WithTimeout(parent, timeout)
}
