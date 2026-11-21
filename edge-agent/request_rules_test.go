package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestRequestRuleValidationPreservesPublishedGeneration(t *testing.T) {
	root := t.TempDir()
	initial, err := publishGeneration(root, 1, func(dir, id string) error {
		return os.WriteFile(filepath.Join(dir, "state.json"), []byte(`{"hosts":{}}`), 0600)
	})
	if err != nil {
		t.Fatal(err)
	}
	for _, fixture := range []string{
		`{"redirects":[{"conditions":[],"action":{"target":"url","url":"javascript:alert(1)","status_code":301}}]}`,
		`{"rewrites":[{"conditions":[],"action":{"path":"set","path_value":"/%2e%2e/private","query":"keep"}}]}`,
		`{"headers":[{"conditions":[],"action":{"request":[],"response":[{"op":"set","name":"X-Forwarded-For","value":"x"}]}}]}`,
		`{"headers":[{"conditions":[],"action":{"request":[],"response":[{"op":"set","name":"x-test","value":"bad\tvalue"}]}}]}`,
		`{"caches":[{"conditions":[],"action":{"mode":"override","edge_ttl_seconds":31536001}}]}`,
		`{"rewrites":{}}`,
		`{"unknown":[]}`,
	} {
		t.Run(fixture, func(t *testing.T) {
			domain := json.RawMessage(`{"schema_version":1,"domain":"example.test","domain_id":1,"revision":2,"rules":` + fixture + `,"hostnames":[]}`)
			_, err := publishGeneration(root, 2, func(dir, id string) error {
				active, _, err := compileRuntime(state{Sequence: 2, Domains: map[string]json.RawMessage{"1": domain}}, nil)
				if err != nil {
					return err
				}
				body, err := json.Marshal(active)
				if err != nil {
					return err
				}
				return os.WriteFile(filepath.Join(dir, "state.json"), body, 0600)
			})
			if err == nil {
				t.Fatal("invalid rules were published")
			}
			current, err := readGenerationPointer(root, "current")
			if err != nil || current.GenerationID != initial.GenerationID {
				t.Fatalf("last valid generation changed: %v", err)
			}
		})
	}
}

func TestRequestRuleBundleBoundsAndTypes(t *testing.T) {
	var rules map[string]any
	err := json.Unmarshal([]byte(`{
		"redirects":[{"conditions":[],"action":{"target":"prefix","from_prefix":"/old","to_prefix":"/new","status_code":308}}],
		"rewrites":[{"conditions":[],"action":{"path":"prefix","from_prefix":"/api","to_prefix":"","query":"set","query_value":"lang=en"}}],
		"headers":[{"conditions":[],"action":{"request":[{"op":"remove","name":"x-app"}],"response":[{"op":"set","name":"x-frame-options","value":"DENY"}]}}],
		"caches":[{"conditions":[],"action":{"mode":"override","edge_ttl_seconds":60,"browser_ttl_seconds":0}}]
	}`), &rules)
	if err != nil {
		t.Fatal(err)
	}
	if err := validateRequestRules(rules); err != nil {
		t.Fatal(err)
	}
	rule := rules["caches"].([]any)[0]
	tooMany := make([]any, 51)
	for i := range tooMany {
		tooMany[i] = rule
	}
	if validateRequestRules(map[string]any{"caches": tooMany}) == nil {
		t.Fatal("accepted 51 rules")
	}
	if validRulePath("/"+strings.Repeat("a", 2048), false) {
		t.Fatal("accepted oversized path")
	}
	if validRuleHeader(map[string]any{"name": strings.Repeat("a", 65), "op": "remove"}) {
		t.Fatal("accepted oversized header")
	}
}

func TestRequestRuleConditionFields(t *testing.T) {
	for _, field := range []string{"hostname", "path", "query_present", "method", "country", "continent", "asn", "client_ip", "user_agent", "referer", "referer_host", "referer_present", "file_extension", "header", "cookie_present", "tls_version"} {
		condition := map[string]any{"field": field, "operator": "equals", "value": "test"}
		if field == "header" || field == "cookie_present" {
			condition["name"] = "x-test"
		}
		if field == "query_present" || field == "cookie_present" || field == "referer_present" {
			condition["value"] = true
		}
		if field == "asn" {
			condition["value"] = float64(64500)
		}
		if !validRequestCondition(condition) {
			t.Fatalf("rejected supported field %s", field)
		}
	}
	if !validRequestCondition(map[string]any{"field": "country", "operator": "in_list", "value": []any{"US", "DE"}}) {
		t.Fatal("rejected a typed list")
	}
	for _, condition := range []map[string]any{
		{"field": "attack_score", "operator": "equals", "value": float64(10)},
		{"field": "path", "operator": "regex", "value": ".*"},
		{"field": "header", "operator": "equals", "value": "test"},
		{"field": "path", "operator": "in_list", "value": []any{}},
		{"field": "path", "operator": "equals", "value": map[string]any{}},
	} {
		if validRequestCondition(condition) {
			t.Fatalf("accepted malformed condition: %v", condition)
		}
	}
}
