package main

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"syscall"
)

const backupMagic = "CDNFEDGE1"
const maxBackupBytes = 72 << 20
const tmpfsMagic = 0x01021994

type recoverySnapshot struct {
	EdgeID          string `json:"edge_id"`
	State           state  `json:"state"`
	GatewayBindings string `json:"gateway_bindings"`
	GatewayRevision uint64 `json:"gateway_revision"`
}

func readBackupKey(path string) ([]byte, error) {
	if path == "" {
		return nil, nil
	}
	swaps, err := os.ReadFile("/proc/swaps")
	if err != nil || strings.Contains(strings.TrimSpace(string(swaps)), "\n") {
		return nil, errors.New("edge hosts must disable swap for memory-backed TLS keys")
	}
	var filesystem syscall.Statfs_t
	if err := syscall.Statfs(path, &filesystem); err != nil || filesystem.Type != tmpfsMagic {
		return nil, errors.New("edge state encryption key must be on tmpfs")
	}
	info, err := os.Stat(path)
	if err != nil || !info.Mode().IsRegular() || info.Mode().Perm()&0077 != 0 {
		return nil, errors.New("edge state encryption key must be a private regular file")
	}
	content, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	key, err := hex.DecodeString(strings.TrimSpace(string(content)))
	if err != nil || len(key) != 32 {
		return nil, errors.New("edge state encryption key must contain 32 hex-encoded bytes")
	}
	return key, nil
}

func (c *client) backupPath() string {
	return filepath.Join(c.dir, "runtime-recovery.enc")
}

func (c *client) readAgentSecret(path string, value any) error {
	info, err := os.Stat(path)
	if err != nil {
		return err
	}
	if info.Size() > 1<<20 {
		return errors.New("edge secret exceeds size bound")
	}
	content, err := os.ReadFile(path)
	if err != nil {
		return err
	}
	if len(c.backupKey) == 0 {
		return json.Unmarshal(content, value)
	}
	if len(content) > 0 && content[0] == '{' {
		if err := json.Unmarshal(content, value); err != nil {
			return err
		}
		return c.writeAgentSecret(path, value)
	}
	plain, err := openAgentSecret(c.backupKey, filepath.Base(path), content)
	if err != nil {
		return err
	}
	return json.Unmarshal(plain, value)
}

func (c *client) writeAgentSecret(path string, value any) error {
	if len(c.backupKey) == 0 {
		return atomicJSON(path, value)
	}
	plain, err := json.Marshal(value)
	if err != nil {
		return err
	}
	if len(plain) > 1<<20 {
		return errors.New("edge secret exceeds size bound")
	}
	sealed, err := sealAgentSecret(c.backupKey, filepath.Base(path), plain)
	if err != nil {
		return err
	}
	temporary := path + ".tmp"
	_ = os.Remove(temporary)
	if err := durableWrite(temporary, sealed, 0600); err != nil {
		return err
	}
	if err := os.Rename(temporary, path); err != nil {
		return err
	}
	return syncDirectory(filepath.Dir(path))
}

func sealAgentSecret(key []byte, name string, plain []byte) ([]byte, error) {
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	aead, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}
	nonce := make([]byte, aead.NonceSize())
	if _, err := rand.Read(nonce); err != nil {
		return nil, err
	}
	sealed := append([]byte(backupMagic), nonce...)
	return aead.Seal(sealed, nonce, plain, []byte(name)), nil
}

func openAgentSecret(key []byte, name string, sealed []byte) ([]byte, error) {
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	aead, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}
	if len(sealed) < len(backupMagic)+aead.NonceSize()+aead.Overhead() || string(sealed[:len(backupMagic)]) != backupMagic {
		return nil, errors.New("invalid encrypted edge secret")
	}
	nonce := sealed[len(backupMagic) : len(backupMagic)+aead.NonceSize()]
	plain, err := aead.Open(nil, nonce, sealed[len(backupMagic)+aead.NonceSize():], []byte(name))
	if err != nil {
		return nil, errors.New("encrypted edge secret authentication failed")
	}
	return plain, nil
}

func (c *client) saveBackup(s state) error {
	if len(c.backupKey) == 0 {
		return nil
	}
	plain, err := json.Marshal(recoverySnapshot{c.id.EdgeID, s, c.gatewayBindings, c.gatewayRevision})
	if err != nil {
		return err
	}
	if len(plain) > maxBackupBytes {
		return errors.New("edge recovery snapshot exceeds size bound")
	}
	block, err := aes.NewCipher(c.backupKey)
	if err != nil {
		return err
	}
	aead, err := cipher.NewGCM(block)
	if err != nil {
		return err
	}
	nonce := make([]byte, aead.NonceSize())
	if _, err := rand.Read(nonce); err != nil {
		return err
	}
	sealed := append([]byte(backupMagic), nonce...)
	sealed = aead.Seal(sealed, nonce, plain, []byte(c.id.EdgeID))
	path := c.backupPath()
	temporary := path + ".tmp"
	_ = os.Remove(temporary)
	if err := durableWrite(temporary, sealed, 0600); err != nil {
		return err
	}
	if err := os.Rename(temporary, path); err != nil {
		return err
	}
	return syncDirectory(c.dir)
}

func (c *client) restoreBackup() error {
	if c.runtimeDir == "" || len(c.backupKey) == 0 {
		return nil
	}
	if _, err := os.Stat(c.activeStatePath()); err == nil {
		return nil
	} else if !errors.Is(err, os.ErrNotExist) {
		return err
	}
	file, err := os.Open(c.backupPath())
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	sealed, readErr := io.ReadAll(io.LimitReader(file, maxBackupBytes+64))
	closeErr := file.Close()
	if readErr != nil {
		return readErr
	}
	if closeErr != nil {
		return closeErr
	}
	block, err := aes.NewCipher(c.backupKey)
	if err != nil {
		return err
	}
	aead, err := cipher.NewGCM(block)
	if err != nil {
		return err
	}
	if len(sealed) < len(backupMagic)+aead.NonceSize()+aead.Overhead() || len(sealed) > maxBackupBytes+64 || string(sealed[:len(backupMagic)]) != backupMagic {
		return errors.New("invalid edge recovery snapshot")
	}
	nonce := sealed[len(backupMagic) : len(backupMagic)+aead.NonceSize()]
	plain, err := aead.Open(nil, nonce, sealed[len(backupMagic)+aead.NonceSize():], []byte(c.id.EdgeID))
	if err != nil {
		return errors.New("edge recovery snapshot authentication failed")
	}
	var snapshot recoverySnapshot
	if err := json.Unmarshal(plain, &snapshot); err != nil || snapshot.EdgeID != c.id.EdgeID || snapshot.State.Domains == nil {
		return errors.New("invalid edge recovery snapshot schema")
	}
	c.gatewayBindings, c.gatewayRevision = snapshot.GatewayBindings, snapshot.GatewayRevision
	return c.activate(snapshot.State)
}
