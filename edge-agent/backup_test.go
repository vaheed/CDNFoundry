package main

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
)

func TestEncryptedRecoveryRestoresLastValidGeneration(t *testing.T) {
	dir := t.TempDir()
	runtimeDir, err := os.MkdirTemp("/dev/shm", "cdnf-edge-runtime-")
	if err != nil {
		t.Skip("tmpfs is unavailable")
	}
	t.Cleanup(func() { _ = os.RemoveAll(runtimeDir) })
	key := make([]byte, 32)
	if _, err := rand.Read(key); err != nil {
		t.Fatal(err)
	}
	c := &client{dir: dir, runtimeDir: runtimeDir, backupKey: key,
		id: identity{EdgeID: "edge-one"}, gatewayBindings: "[]"}
	first := state{Sequence: 4, Domains: map[string]json.RawMessage{"1": runtimeDomain(4)}}
	if err := c.activate(first); err != nil {
		t.Fatal(err)
	}
	sealed, err := os.ReadFile(c.backupPath())
	if err != nil || bytes.Contains(sealed, []byte("domains")) {
		t.Fatal("recovery snapshot contains plaintext state")
	}
	if err := os.RemoveAll(c.runtimeDir); err != nil {
		t.Fatal(err)
	}
	restarted := &client{dir: dir, runtimeDir: c.runtimeDir, backupKey: key, id: c.id}
	if err := restarted.restoreBackup(); err != nil {
		t.Fatal(err)
	}
	recovered, err := loadState(restarted.activeStatePath())
	if err != nil || recovered.Sequence != 4 || restarted.gatewayBindings != "[]" {
		t.Fatalf("previous valid runtime was not restored: %v", err)
	}
	if err := os.RemoveAll(c.runtimeDir); err != nil {
		t.Fatal(err)
	}
	corrupt := append([]byte(nil), sealed...)
	corrupt[len(corrupt)-1] ^= 1
	if err := os.WriteFile(c.backupPath(), corrupt, 0600); err != nil {
		t.Fatal(err)
	}
	if err := restarted.restoreBackup(); err == nil {
		t.Fatal("tampered recovery snapshot was accepted")
	}
	other := &client{dir: dir, runtimeDir: c.runtimeDir, backupKey: key, id: identity{EdgeID: "another-edge"}}
	if err := other.restoreBackup(); err == nil {
		t.Fatal("recovery snapshot was accepted for another edge")
	}
}

func TestRecoveryKeyRequiresPrivateTmpfsFile(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "key")
	if err := os.WriteFile(path, []byte(hex.EncodeToString(make([]byte, 32))), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := readBackupKey(path); err == nil {
		t.Fatal("disk-backed recovery key was accepted")
	}
	memoryFile, err := os.CreateTemp("/dev/shm", "cdnf-edge-key-")
	if err != nil {
		t.Skip("tmpfs is unavailable")
	}
	defer os.Remove(memoryFile.Name())
	if _, err := memoryFile.WriteString(hex.EncodeToString(make([]byte, 32))); err != nil {
		t.Fatal(err)
	}
	if err := memoryFile.Chmod(0400); err != nil {
		t.Fatal(err)
	}
	if err := memoryFile.Close(); err != nil {
		t.Fatal(err)
	}
	swaps, err := os.ReadFile("/proc/swaps")
	if err != nil {
		t.Skip("swap state is unavailable")
	}
	if strings.Contains(strings.TrimSpace(string(swaps)), "\n") {
		if _, err := readBackupKey(memoryFile.Name()); err == nil {
			t.Fatal("memory key was accepted while swap is active")
		}
		return
	}
	var filesystem syscall.Statfs_t
	if err := syscall.Statfs(memoryFile.Name(), &filesystem); err != nil || filesystem.Type != tmpfsMagic {
		t.Skip("/dev/shm is not tmpfs on this host")
	}
	if _, err := readBackupKey(memoryFile.Name()); err != nil {
		t.Fatalf("private tmpfs recovery key was rejected: %v", err)
	}
}

func TestEdgeIdentityIsEncryptedAndAuthenticated(t *testing.T) {
	dir := t.TempDir()
	key := make([]byte, 32)
	if _, err := rand.Read(key); err != nil {
		t.Fatal(err)
	}
	c := &client{dir: dir, backupKey: key}
	path := filepath.Join(dir, "identity.json")
	credentials := identity{EdgeID: "edge-one", PrivateKey: "private-key-material"}
	if err := c.writeAgentSecret(path, credentials); err != nil {
		t.Fatal(err)
	}
	sealed, err := os.ReadFile(path)
	if err != nil || bytes.Contains(sealed, []byte(credentials.PrivateKey)) {
		t.Fatal("edge identity key persisted in plaintext")
	}
	var restored identity
	if err := c.readAgentSecret(path, &restored); err != nil || restored.PrivateKey != credentials.PrivateKey {
		t.Fatal("encrypted identity could not be restored")
	}
	other := filepath.Join(dir, "pending-registration.json")
	if err := os.WriteFile(other, sealed, 0600); err != nil {
		t.Fatal(err)
	}
	if err := c.readAgentSecret(other, &restored); err == nil {
		t.Fatal("encrypted identity was accepted at another path")
	}
}
