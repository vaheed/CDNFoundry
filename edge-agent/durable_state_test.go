package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestReplaceFileReplacesAtomicallyAndIgnoresAStaleTemporary(t *testing.T) {
	directory := t.TempDir()
	path := filepath.Join(directory, "state.json")
	if err := os.WriteFile(path+".tmp", []byte("left behind by a crash"), 0600); err != nil {
		t.Fatal(err)
	}
	for _, content := range []string{"first", "second"} {
		if err := replaceFile(path, []byte(content), 0600); err != nil {
			t.Fatal(err)
		}
		got, err := os.ReadFile(path)
		if err != nil || string(got) != content {
			t.Fatalf("state = %q, %v; want %q", got, err, content)
		}
	}
	if _, err := os.Stat(path + ".tmp"); !os.IsNotExist(err) {
		t.Fatalf("temporary file must not remain: %v", err)
	}
	info, err := os.Stat(path)
	if err != nil || info.Mode().Perm() != 0600 {
		t.Fatalf("mode = %v, %v; want 0600", info.Mode().Perm(), err)
	}
}
