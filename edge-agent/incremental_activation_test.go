package main

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"syscall"
	"testing"
)

func inode(t *testing.T, path string) (uint64, uint64) {
	t.Helper()
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	stat := info.Sys().(*syscall.Stat_t)
	return stat.Ino, uint64(stat.Nlink)
}

func largeDomain(id, revision int) json.RawMessage {
	return json.RawMessage(fmt.Sprintf(`{"schema_version":1,"domain_id":%d,"domain":"d%d.test","revision":%d,"pools":["shared-default"],"cells":["cell-%02d"],"settings":{"enabled":true},"cache":{"enabled":true},"tls":{"mode":"disabled"},"hostnames":[{"hostname":"www.d%d.test","origin":{"host":"origin.example"}}]}`,
		id, id, revision, id%8+1, id))
}

// One domain change at 10,000 domains rewrites that domain's state file and the runtime file of
// the one cell that serves it; every other state file and cell file is carried over untouched.
func TestOneDomainChangeAtTenThousandDomainsRewritesOnlyItsFiles(t *testing.T) {
	const domains = 10000
	dir := t.TempDir()
	assignments := map[string]string{}
	for slot := 1; slot <= 8; slot++ {
		assignments[fmt.Sprintf("cell-%02d", slot)] = "shared-default"
	}
	c := &client{dir: dir, runtimeDir: filepath.Join(dir, "runtime"), cellAssignments: assignments}
	full := state{Sequence: 1, Domains: make(map[string]json.RawMessage, domains)}
	for id := 1; id <= domains; id++ {
		full.Domains[strconv.Itoa(id)] = largeDomain(id, 1)
	}
	if err := c.activate(full); err != nil {
		t.Fatal(err)
	}
	before := map[string]uint64{}
	for id := 1; id <= domains; id++ {
		before[strconv.Itoa(id)], _ = inode(t, filepath.Join(c.domainDir(), strconv.Itoa(id)+".json"))
	}

	const changed = 4242 // served by cell-03
	next := state{Sequence: 2, Domains: make(map[string]json.RawMessage, domains)}
	for key, raw := range full.Domains {
		next.Domains[key] = raw
	}
	next.Domains[strconv.Itoa(changed)] = largeDomain(changed, 2)
	if err := c.activate(next); err != nil {
		t.Fatal(err)
	}

	rewritten := 0
	for id := 1; id <= domains; id++ {
		key := strconv.Itoa(id)
		if now, _ := inode(t, filepath.Join(c.domainDir(), key+".json")); now != before[key] {
			rewritten++
			if id != changed {
				t.Fatalf("domain %d was rewritten but did not change", id)
			}
		}
	}
	if rewritten != 1 {
		t.Fatalf("expected exactly one domain state file to be rewritten, got %d", rewritten)
	}
	fresh := []string{}
	for slot := 1; slot <= 8; slot++ {
		name := fmt.Sprintf("cell-%02d.json", slot)
		if _, links := inode(t, filepath.Join(c.runtimeDir, "current", name)); links == 1 {
			fresh = append(fresh, name)
		}
	}
	if len(fresh) != 1 || fresh[0] != "cell-03.json" {
		t.Fatalf("only the serving cell's file may be rewritten, got %v", fresh)
	}
	stored, err := (&client{dir: dir}).loadStoredState()
	if err != nil || stored.Sequence != 2 || len(stored.Domains) != domains {
		t.Fatalf("durable state is wrong: %v sequence=%d domains=%d", err, stored.Sequence, len(stored.Domains))
	}
}

func TestAnUnchangedReplayOfADomainIsAcceptedWithoutAnyWrite(t *testing.T) {
	base := state{Sequence: 3, Domains: map[string]json.RawMessage{"7": largeDomain(7, 2)}}
	next, applicable, rejected := foldArtifacts(base, []preparedArtifact{{Sequence: 4, Kind: "domain", DomainKey: "7", Revision: 2, Payload: largeDomain(7, 2)}})
	if len(rejected) != 0 || len(applicable) != 1 || string(next.Domains["7"]) != string(base.Domains["7"]) {
		t.Fatalf("an identical payload at the same revision was applied before; got rejected=%v", rejected)
	}
}
