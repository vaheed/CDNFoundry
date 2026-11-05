package main

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestHealthcheckFollowsTheLoopNotTheControlPlane(t *testing.T) {
	dir := t.TempDir()
	if healthcheck(dir) == 0 {
		t.Fatal("an agent that never ran its loop must be unhealthy")
	}
	touchLiveness(dir)
	if healthcheck(dir) != 0 {
		t.Fatal("a fresh loop must be healthy")
	}
	stale := time.Now().Add(-livenessWindow - time.Minute)
	if err := os.Chtimes(filepath.Join(dir, "liveness"), stale, stale); err != nil {
		t.Fatal(err)
	}
	if healthcheck(dir) == 0 {
		t.Fatal("a hung loop must be unhealthy")
	}
}
