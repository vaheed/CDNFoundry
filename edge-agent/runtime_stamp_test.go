package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestCellIdentityIsAContentStampThatOnlyChangesWithContent(t *testing.T) {
	directory := t.TempDir()
	c := &client{runtimeDir: directory}
	cell := func(host string) map[string]any {
		return map[string]any{"schema_version": 1, "hosts": map[string]any{host: map[string]any{}}, "certificates": map[string]any{}}
	}
	read := func() string {
		stamp, err := os.ReadFile(filepath.Join(directory, "cell-01.stamp"))
		if err != nil {
			t.Fatal(err)
		}
		return strings.TrimSpace(string(stamp))
	}

	if err := c.writeCellRuntime(directory, "cell-01", 5, cell("a.test")); err != nil {
		t.Fatal(err)
	}
	first := read()
	if len(first) != 64 {
		t.Fatalf("the stamp must be a 64-character digest, got %q", first)
	}
	// The same content at another sequence has the same identity; different content does not.
	if err := c.writeCellRuntime(directory, "cell-01", 9, cell("a.test")); err != nil || read() != first {
		t.Fatalf("an unchanged cell must keep its identity across sequences: %v", err)
	}
	if err := c.writeCellRuntime(directory, "cell-01", 10, cell("b.test")); err != nil || read() == first {
		t.Fatalf("changed content must change the identity: %v", err)
	}
}
