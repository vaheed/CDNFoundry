package main

import (
	"encoding/json"
	"fmt"
	"strings"
	"testing"
)

func domainWithLists(id int, cell string, lists string, hostnames ...string) json.RawMessage {
	var hosts []string
	for _, name := range hostnames {
		hosts = append(hosts, fmt.Sprintf(`{"hostname":%q,"origin":{"host":"origin.example"}}`, name))
	}
	return json.RawMessage(fmt.Sprintf(`{"schema_version":1,"domain":"d%d.test","domain_id":%d,"revision":1,"cells":[%q],"settings":{"enabled":true},"security":{"ip_lists":%s},"tls":{"mode":"disabled"},"hostnames":[%s]}`, id, id, cell, lists, strings.Join(hosts, ",")))
}

func TestIPListsTravelOncePerDomainBesideTheHosts(t *testing.T) {
	lists := `{"office":{"v4":[1,5,9,12],"v6":[]}}`
	runtime, cells, err := compileRuntime(state{Sequence: 3, Domains: map[string]json.RawMessage{"1": domainWithLists(1, "cell-01", lists, "a.test", "www.a.test", "api.a.test")}}, nil)
	if err != nil {
		t.Fatal(err)
	}
	for name, host := range cells["cell-01"]["hosts"].(map[string]any) {
		if _, copied := host.(map[string]any)["security"].(map[string]any)["ip_lists"]; copied {
			t.Fatalf("%s carries its own copy of the domain's lists", name)
		}
	}
	for label, document := range map[string]map[string]any{"cell": cells["cell-01"], "combined": runtime} {
		held, ok := document["ip_lists"].(map[string]any)["1"].(map[string]any)["office"].(map[string]any)
		if !ok || len(held["v4"].([]any)) != 4 {
			t.Fatalf("the %s runtime lost the list: %v", label, document["ip_lists"])
		}
	}
	// A domain without lists adds nothing.
	_, cells, err = compileRuntime(state{Sequence: 4, Domains: map[string]json.RawMessage{"2": domainWithLists(2, "cell-01", `{}`, "b.test")}}, nil)
	if err != nil || len(cells["cell-01"]["ip_lists"].(map[string]any)) != 0 {
		t.Fatalf("an empty list set was carried: %v %v", err, cells["cell-01"]["ip_lists"])
	}
}

func TestACorruptOrOversizedListIsRefused(t *testing.T) {
	var big strings.Builder
	big.WriteString(`{"v4":[`)
	for index := 0; index < 10001; index++ {
		if index > 0 {
			big.WriteString(",")
		}
		fmt.Fprintf(&big, "%d,%d", index*4, index*4+1)
	}
	big.WriteString("]}")
	cases := map[string]string{
		"unsorted":       `{"a":{"v4":[5,9,1,3]}}`,
		"overlapping":    `{"a":{"v4":[1,9,5,12]}}`,
		"odd length":     `{"a":{"v4":[1,2,3]}}`,
		"negative":       `{"a":{"v4":[-1,4]}}`,
		"beyond 32 bits": `{"a":{"v4":[1,4294967296]}}`,
		"fraction":       `{"a":{"v4":[1.5,4]}}`,
		"not a number":   `{"a":{"v4":["1","4"]}}`,
		"v6 length":      `{"a":{"v6":[0,0,0,1,0,0,0]}}`,
		"v6 reversed":    `{"a":{"v6":[0,0,0,2,0,0,0,1]}}`,
		"v6 overlap":     `{"a":{"v6":[0,0,0,1,0,0,0,9,0,0,0,5,0,0,0,12]}}`,
		"v6 word":        `{"a":{"v6":[0,0,0,1,0,0,0,4294967296]}}`,
		"too many":       `{"a":` + big.String() + `}`,
		"not a map":      `["a"]`,
		"six lists":      `{"a":{},"b":{},"c":{},"d":{},"e":{},"f":{}}`,
	}
	for name, lists := range cases {
		if _, _, err := compileRuntime(state{Sequence: 1, Domains: map[string]json.RawMessage{"1": domainWithLists(1, "cell-01", lists, "a.test")}}, nil); err == nil {
			t.Errorf("%s was accepted", name)
		}
	}
	good := `{"a":{"v4":[1,3,5,9],"v6":[0,0,0,1,0,0,0,9,0,0,0,11,0,0,0,12]}}`
	if _, _, err := compileRuntime(state{Sequence: 1, Domains: map[string]json.RawMessage{"1": domainWithLists(1, "cell-01", good, "a.test")}}, nil); err != nil {
		t.Fatalf("a valid list was refused: %v", err)
	}
}

func TestTheCellBudgetRefusesOnlyTheDomainThatExceedsIt(t *testing.T) {
	rangesOf := func(count int) string {
		var out strings.Builder
		out.WriteString(`{"a":{"v4":[`)
		for index := 0; index < count; index++ {
			if index > 0 {
				out.WriteString(",")
			}
			fmt.Fprintf(&out, "%d,%d", index*4, index*4+1)
		}
		out.WriteString("]}}")
		return out.String()
	}
	domains := map[string]json.RawMessage{}
	// Ten domains of 10,000 ranges fill the 100,000 budget of one cell; another cell is unaffected.
	for id := 1; id <= 10; id++ {
		domains[fmt.Sprint(id)] = domainWithLists(id, "cell-01", rangesOf(10000), fmt.Sprintf("d%d.test", id))
	}
	domains["30"] = domainWithLists(30, "cell-02", rangesOf(10000), "other.test")
	if _, _, err := compileRuntime(state{Sequence: 1, Domains: domains}, nil); err != nil {
		t.Fatalf("a full budget was refused: %v", err)
	}
	domains["11"] = domainWithLists(11, "cell-01", rangesOf(10), "over.test")
	if _, _, err := compileRuntime(state{Sequence: 2, Domains: domains}, nil); err == nil || !strings.Contains(err.Error(), "budget") {
		t.Fatalf("the budget was not enforced: %v", err)
	}
}

func TestAnUnchangedRuntimeWithListsIsDeterministic(t *testing.T) {
	domains := map[string]json.RawMessage{"1": domainWithLists(1, "cell-01", `{"a":{"v4":[1,2]},"b":{"v4":[3,4]}}`, "a.test", "b.test")}
	first, _, _ := compileRuntime(state{Sequence: 1, Domains: domains}, nil)
	second, _, _ := compileRuntime(state{Sequence: 1, Domains: domains}, nil)
	a, _ := json.Marshal(first)
	b, _ := json.Marshal(second)
	if string(a) != string(b) {
		t.Fatal("the runtime changed between identical compiles")
	}
}
