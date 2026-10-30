package main

import (
	"os"
	"path/filepath"
	"time"
)

// livenessWindow is how stale the liveness file may be. One loop pass is five
// seconds plus the work of a sync, whose requests are bounded by their timeouts.
const livenessWindow = 3 * time.Minute

func touchLiveness(dir string) {
	path := filepath.Join(dir, "liveness")
	now := time.Now()
	if err := os.Chtimes(path, now, now); err != nil {
		_ = os.WriteFile(path, nil, 0600)
	}
}

// healthcheck returns the process exit code for `edge-agent --healthcheck`.
func healthcheck(dir string) int {
	info, err := os.Stat(filepath.Join(dir, "liveness"))
	if err != nil || time.Since(info.ModTime()) > livenessWindow {
		return 1
	}
	return 0
}
