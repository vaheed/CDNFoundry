package main

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

const generationRetention = 5

type generationFile struct {
	Path   string `json:"path"`
	Size   int64  `json:"size"`
	SHA256 string `json:"sha256"`
}

type generationManifest struct {
	SchemaVersion   int              `json:"schema_version"`
	GenerationID    string           `json:"generation_id"`
	Revision        uint64           `json:"revision"`
	CreatedAt       string           `json:"created_at"`
	Files           []generationFile `json:"files"`
	AggregateSHA256 string           `json:"aggregate_sha256"`
}

// generationFault is set only by same-package failure-injection tests.
var generationFault func(string) error

func failGeneration(stage string) error {
	if generationFault != nil {
		return generationFault(stage)
	}
	return nil
}

func publishGeneration(root string, revision uint64, build func(string, string) error) (generationManifest, error) {
	return publishGenerationWithPolicy(root, revision, false, build)
}

func replaceGeneration(root string, revision uint64, build func(string, string) error) (generationManifest, error) {
	return publishGenerationWithPolicy(root, revision, true, build)
}

func publishGenerationWithPolicy(root string, revision uint64, replaceEqual bool, build func(string, string) error) (generationManifest, error) {
	if current, err := readGenerationPointer(root, "current"); err == nil {
		if current.Revision == revision && !replaceEqual {
			return current, nil
		}
		if current.Revision > revision {
			if !replaceEqual {
				return generationManifest{}, errors.New("generation revision cannot move backwards")
			}
			revision = current.Revision
		}
	}
	if err := os.MkdirAll(filepath.Join(root, "generations"), 0750); err != nil {
		return generationManifest{}, err
	}
	if err := cleanupCandidates(root); err != nil {
		return generationManifest{}, err
	}
	candidate, err := os.MkdirTemp(filepath.Join(root, "generations"), ".candidate-")
	if err != nil {
		return generationManifest{}, err
	}
	defer os.RemoveAll(candidate)
	random := make([]byte, 8)
	if _, err := rand.Read(random); err != nil {
		return generationManifest{}, err
	}
	generationID := fmt.Sprintf("%020d-%s", revision, hex.EncodeToString(random))
	if err := build(candidate, generationID); err != nil {
		return generationManifest{}, err
	}
	if err := failGeneration("after_files"); err != nil {
		return generationManifest{}, err
	}
	manifest, err := createGenerationManifest(candidate, revision, generationID)
	if err != nil {
		return generationManifest{}, err
	}
	manifestBody, err := json.Marshal(manifest)
	if err != nil {
		return generationManifest{}, err
	}
	if err := durableWrite(filepath.Join(candidate, "manifest.json"), manifestBody, 0600); err != nil {
		return generationManifest{}, err
	}
	if _, err := verifyGeneration(candidate); err != nil {
		return generationManifest{}, err
	}
	if err := syncTree(candidate); err != nil {
		return generationManifest{}, err
	}
	if err := failGeneration("before_publish"); err != nil {
		return generationManifest{}, err
	}
	final := filepath.Join(root, "generations", manifest.GenerationID)
	if existing, verifyErr := verifyGeneration(final); verifyErr == nil {
		if existing.Revision != revision || existing.AggregateSHA256 != manifest.AggregateSHA256 {
			return generationManifest{}, errors.New("published generation identity collision")
		}
		return existing, activateGenerationPointer(root, existing)
	}
	if err := os.Rename(candidate, final); err != nil {
		if errors.Is(err, os.ErrExist) {
			existing, verifyErr := verifyGeneration(final)
			if verifyErr == nil && existing.Revision == revision && existing.AggregateSHA256 == manifest.AggregateSHA256 {
				return existing, activateGenerationPointer(root, existing)
			}
		}
		return generationManifest{}, err
	}
	if err := syncDirectory(filepath.Join(root, "generations")); err != nil {
		return generationManifest{}, err
	}
	if err := failGeneration("after_publish"); err != nil {
		return generationManifest{}, err
	}
	if err := activateGenerationPointer(root, manifest); err != nil {
		return generationManifest{}, err
	}
	if err := failGeneration("after_pointer"); err != nil {
		return generationManifest{}, err
	}
	if err := pruneGenerations(root, generationRetention); err != nil {
		return generationManifest{}, err
	}
	return manifest, nil
}

func createGenerationManifest(dir string, revision uint64, generationID string) (generationManifest, error) {
	files, err := generationFiles(dir)
	if err != nil {
		return generationManifest{}, err
	}
	h := sha256.New()
	for _, file := range files {
		fmt.Fprintf(h, "%s\x00%d\x00%s\n", file.Path, file.Size, file.SHA256)
	}
	aggregate := hex.EncodeToString(h.Sum(nil))
	return generationManifest{SchemaVersion: 1, GenerationID: generationID, Revision: revision, CreatedAt: time.Now().UTC().Format(time.RFC3339Nano), Files: files, AggregateSHA256: aggregate}, nil
}

func generationFiles(dir string) ([]generationFile, error) {
	files := []generationFile{}
	err := filepath.WalkDir(dir, func(path string, entry os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if entry.IsDir() {
			return nil
		}
		rel, err := filepath.Rel(dir, path)
		if err != nil {
			return err
		}
		rel = filepath.ToSlash(rel)
		if rel == "manifest.json" {
			return nil
		}
		if strings.HasPrefix(rel, "../") || entry.Type()&os.ModeSymlink != 0 {
			return errors.New("generation contains unsafe path or symlink")
		}
		file, err := os.Open(path)
		if err != nil {
			return err
		}
		h := sha256.New()
		size, copyErr := io.Copy(h, file)
		closeErr := file.Close()
		if copyErr != nil {
			return copyErr
		}
		if closeErr != nil {
			return closeErr
		}
		files = append(files, generationFile{Path: rel, Size: size, SHA256: hex.EncodeToString(h.Sum(nil))})
		return nil
	})
	sort.Slice(files, func(i, j int) bool { return files[i].Path < files[j].Path })
	return files, err
}

func verifyGeneration(dir string) (generationManifest, error) {
	var manifest generationManifest
	body, err := os.ReadFile(filepath.Join(dir, "manifest.json"))
	if err != nil {
		return manifest, err
	}
	if err := json.Unmarshal(body, &manifest); err != nil {
		return manifest, err
	}
	if manifest.SchemaVersion != 1 || manifest.GenerationID == "" || len(manifest.Files) == 0 || len(manifest.Files) > 100 {
		return manifest, errors.New("invalid generation manifest bounds")
	}
	actual, err := generationFiles(dir)
	if err != nil {
		return manifest, err
	}
	if len(actual) != len(manifest.Files) {
		return manifest, errors.New("generation file set mismatch")
	}
	for index := range actual {
		if actual[index] != manifest.Files[index] {
			return manifest, fmt.Errorf("generation file mismatch: %s", actual[index].Path)
		}
	}
	expected, err := createGenerationManifest(dir, manifest.Revision, manifest.GenerationID)
	if err != nil {
		return manifest, err
	}
	if expected.AggregateSHA256 != manifest.AggregateSHA256 {
		return manifest, errors.New("generation aggregate digest mismatch")
	}
	return manifest, nil
}

func activateGenerationPointer(root string, target generationManifest) error {
	if _, err := verifyGeneration(filepath.Join(root, "generations", target.GenerationID)); err != nil {
		return err
	}
	current, _ := readGenerationPointer(root, "current")
	if current.GenerationID == target.GenerationID {
		return nil
	}
	if current.Revision > target.Revision {
		return errors.New("generation revision cannot move backwards")
	}
	if current.GenerationID != "" {
		if err := replaceSymlink(root, "previous", current.GenerationID); err != nil {
			return err
		}
	}
	if err := failGeneration("pointer_replace"); err != nil {
		return err
	}
	return replaceSymlink(root, "current", target.GenerationID)
}

func replaceSymlink(root, name, generationID string) error {
	tmp := filepath.Join(root, "."+name+".tmp")
	if err := os.Remove(tmp); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	if err := os.Symlink(filepath.Join("generations", generationID), tmp); err != nil {
		return err
	}
	if err := os.Rename(tmp, filepath.Join(root, name)); err != nil {
		return err
	}
	return syncDirectory(root)
}

func readGenerationPointer(root, name string) (generationManifest, error) {
	target, err := filepath.EvalSymlinks(filepath.Join(root, name))
	if err != nil {
		return generationManifest{}, err
	}
	generations, err := filepath.Abs(filepath.Join(root, "generations"))
	if err != nil {
		return generationManifest{}, err
	}
	target, err = filepath.Abs(target)
	if err != nil || filepath.Dir(target) != generations {
		return generationManifest{}, errors.New("generation pointer escapes generation directory")
	}
	return verifyGeneration(target)
}

func recoverGeneration(root string) (generationManifest, error) {
	if err := cleanupCandidates(root); err != nil {
		return generationManifest{}, err
	}
	if current, err := readGenerationPointer(root, "current"); err == nil {
		return current, nil
	}
	if previous, err := readGenerationPointer(root, "previous"); err == nil {
		if err := replaceSymlink(root, "current", previous.GenerationID); err != nil {
			return generationManifest{}, err
		}
		return previous, nil
	}
	return generationManifest{}, os.ErrNotExist
}

func rollbackGeneration(root string) (generationManifest, error) {
	target, err := readGenerationPointer(root, "previous")
	if err != nil {
		return generationManifest{}, err
	}
	current, err := readGenerationPointer(root, "current")
	if err != nil {
		return generationManifest{}, err
	}
	if err := replaceSymlink(root, "current", target.GenerationID); err != nil {
		return generationManifest{}, err
	}
	if err := replaceSymlink(root, "previous", current.GenerationID); err != nil {
		_ = replaceSymlink(root, "current", current.GenerationID)
		return generationManifest{}, err
	}
	return target, nil
}

func cleanupCandidates(root string) error {
	entries, err := os.ReadDir(filepath.Join(root, "generations"))
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	for _, entry := range entries {
		if strings.HasPrefix(entry.Name(), ".candidate-") {
			if err := os.RemoveAll(filepath.Join(root, "generations", entry.Name())); err != nil {
				return err
			}
		}
	}
	return syncDirectory(filepath.Join(root, "generations"))
}

func pruneGenerations(root string, retain int) error {
	protected := map[string]bool{}
	for _, pointer := range []string{"current", "previous"} {
		if manifest, err := readGenerationPointer(root, pointer); err == nil {
			protected[manifest.GenerationID] = true
		}
	}
	entries, err := os.ReadDir(filepath.Join(root, "generations"))
	if err != nil {
		return err
	}
	names := []string{}
	for _, entry := range entries {
		if entry.IsDir() && !strings.HasPrefix(entry.Name(), ".") {
			names = append(names, entry.Name())
		}
	}
	sort.Sort(sort.Reverse(sort.StringSlice(names)))
	kept := 0
	for _, name := range names {
		if protected[name] || kept < retain {
			kept++
			continue
		}
		if err := os.RemoveAll(filepath.Join(root, "generations", name)); err != nil {
			return err
		}
	}
	return syncDirectory(filepath.Join(root, "generations"))
}

func durableWrite(path string, body []byte, mode os.FileMode) error {
	file, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, mode)
	if err != nil {
		return err
	}
	if _, err = file.Write(body); err == nil {
		err = file.Sync()
	}
	closeErr := file.Close()
	if err != nil {
		return err
	}
	return closeErr
}

func syncTree(root string) error {
	directories := []string{}
	err := filepath.WalkDir(root, func(path string, entry os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if entry.IsDir() {
			directories = append(directories, path)
			return nil
		}
		file, err := os.Open(path)
		if err != nil {
			return err
		}
		if err = file.Sync(); err != nil {
			_ = file.Close()
			return err
		}
		return file.Close()
	})
	if err != nil {
		return err
	}
	sort.Slice(directories, func(i, j int) bool { return len(directories[i]) > len(directories[j]) })
	for _, directory := range directories {
		if err := syncDirectory(directory); err != nil {
			return err
		}
	}
	return nil
}

func syncDirectory(path string) error {
	directory, err := os.Open(path)
	if err != nil {
		return err
	}
	if err = directory.Sync(); err != nil {
		_ = directory.Close()
		return err
	}
	return directory.Close()
}
