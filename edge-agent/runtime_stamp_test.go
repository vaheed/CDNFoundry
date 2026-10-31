package main

import (
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestRuntimeFileIsFollowedByAStampOfItsDigest(t *testing.T) {
	directory := t.TempDir()
	path := filepath.Join(directory, "cell-01.json")
	if err := atomicRuntimeJSON(path, map[string]any{"schema_version": 1, "hosts": map[string]any{}}); err != nil {
		t.Fatal(err)
	}
	body, _ := os.ReadFile(path)
	stamp, err := os.ReadFile(filepath.Join(directory, "cell-01.stamp"))
	if err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256(body)
	if strings.TrimSpace(string(stamp)) != hex.EncodeToString(sum[:]) {
		t.Fatalf("stamp %q does not hold the digest of the file", stamp)
	}
	first := string(stamp)
	if err := atomicRuntimeJSON(path, map[string]any{"schema_version": 1, "hosts": map[string]any{"a.test": 1}}); err != nil {
		t.Fatal(err)
	}
	changed, _ := os.ReadFile(filepath.Join(directory, "cell-01.stamp"))
	if string(changed) == first {
		t.Fatal("a changed file must change its stamp")
	}
}
