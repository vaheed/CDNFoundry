package main

import (
	"errors"
	"net/url"
	"regexp"
	"strings"
)

var ruleHostnamePattern = regexp.MustCompile(`^[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?$`)

func validHostname(host string) bool {
	if !ruleHostnamePattern.MatchString(host) {
		return false
	}
	for _, label := range strings.Split(host, ".") {
		if len(label) == 0 || len(label) > 63 || strings.HasPrefix(label, "-") || strings.HasSuffix(label, "-") {
			return false
		}
	}
	return true
}

// Reject malformed rule bundles before publishing a generation. Cells also check actions when executing them.
func validateRequestRules(rules map[string]any) error {
	for kind, raw := range rules {
		if kind != "redirects" && kind != "rewrites" && kind != "headers" && kind != "caches" {
			return errors.New("invalid request rule type")
		}
		list, ok := raw.([]any)
		if !ok || len(list) > 50 {
			return errors.New("invalid request rule list")
		}
		for _, rawRule := range list {
			rule, ok := rawRule.(map[string]any)
			if !ok {
				return errors.New("invalid request rule")
			}
			conditions, ok := rule["conditions"].([]any)
			if !ok || len(conditions) > 5 {
				return errors.New("invalid request rule conditions")
			}
			for _, rawCondition := range conditions {
				condition, ok := rawCondition.(map[string]any)
				if !ok || !validRequestCondition(condition) {
					return errors.New("invalid request rule condition")
				}
			}
			action, ok := rule["action"].(map[string]any)
			if !ok || !validRequestAction(kind, action) {
				return errors.New("invalid request rule action")
			}
		}
	}
	return nil
}

func validRequestCondition(condition map[string]any) bool {
	field, ok := condition["field"].(string)
	if !ok || !strings.Contains("|hostname|path|query_present|method|country|continent|asn|client_ip|user_agent|referer|referer_host|referer_present|file_extension|header|cookie_present|tls_version|", "|"+field+"|") {
		return false
	}
	operator, ok := condition["operator"].(string)
	if !ok || !strings.Contains("|equals|not_equals|in_list|not_in_list|starts_with|ends_with|contains|greater_than|less_than|", "|"+operator+"|") {
		return false
	}
	if field == "header" || field == "cookie_present" {
		name, ok := ruleText(condition["name"], 64)
		if !ok || name == "" {
			return false
		}
	}
	if values, ok := condition["value"].([]any); ok {
		if len(values) == 0 || len(values) > 100 {
			return false
		}
		for _, value := range values {
			if _, ok := ruleText(value, 2048); !ok {
				if _, number := value.(float64); !number {
					return false
				}
			}
		}
		return true
	}
	switch value := condition["value"].(type) {
	case string:
		_, ok := ruleText(value, 2048)
		return ok
	case bool, float64:
		return true
	}
	return false
}

func ruleText(value any, maximum int) (string, bool) {
	text, ok := value.(string)
	if !ok || len(text) > maximum {
		return "", false
	}
	for _, c := range text {
		if c < 32 || c == 127 {
			return "", false
		}
	}
	return text, true
}

func validRulePath(value any, allowEmpty bool) bool {
	text, ok := ruleText(value, 2048)
	if !ok || (text == "" && !allowEmpty) || (text != "" && !strings.HasPrefix(text, "/")) || strings.ContainsAny(text, " ?#\\") {
		return false
	}
	decoded, err := url.PathUnescape(text)
	if err != nil || strings.ContainsAny(decoded, "\\\r\n\x00") {
		return false
	}
	for _, segment := range strings.Split(decoded, "/") {
		if segment == "." || segment == ".." {
			return false
		}
	}
	return true
}

func validRequestAction(kind string, action map[string]any) bool {
	switch kind {
	case "redirects":
		status, ok := action["status_code"].(float64)
		if !ok || (status != 301 && status != 302 && status != 307 && status != 308) {
			return false
		}
		switch action["target"] {
		case "prefix":
			return validRulePath(action["from_prefix"], false) && validRulePath(action["to_prefix"], false)
		case "https":
			return true
		case "host":
			host, ok := ruleText(action["host"], 253)
			return ok && validHostname(host)
		case "url":
			text, ok := ruleText(action["url"], 2048)
			if !ok || strings.ContainsAny(text, " \\") || strings.HasPrefix(text, "//") {
				return false
			}
			if strings.HasPrefix(text, "/") {
				return true
			}
			target, err := url.Parse(text)
			return err == nil && (target.Scheme == "http" || target.Scheme == "https") && validHostname(target.Hostname()) && target.User == nil
		}
	case "rewrites":
		if action["query"] != "keep" && action["query"] != "drop" && action["query"] != "set" {
			return false
		}
		if action["query"] == "set" {
			query, ok := ruleText(action["query_value"], 1024)
			if !ok || strings.ContainsAny(query, " #") {
				return false
			}
		}
		switch action["path"] {
		case "keep":
			return true
		case "set":
			return validRulePath(action["path_value"], false)
		case "prefix":
			return validRulePath(action["from_prefix"], false) && validRulePath(action["to_prefix"], true)
		}
	case "headers":
		count := 0
		for _, side := range []string{"request", "response"} {
			changes, ok := action[side].([]any)
			if !ok {
				return false
			}
			count += len(changes)
			for _, raw := range changes {
				change, ok := raw.(map[string]any)
				if !ok || !validRuleHeader(change) {
					return false
				}
			}
		}
		return count > 0 && count <= 10
	case "caches":
		if action["mode"] == "bypass" {
			return true
		}
		if action["mode"] != "override" {
			return false
		}
		for _, key := range []string{"edge_ttl_seconds", "browser_ttl_seconds"} {
			if value, exists := action[key]; exists {
				ttl, ok := value.(float64)
				if !ok || ttl < 0 || ttl > 31536000 || ttl != float64(int64(ttl)) {
					return false
				}
			}
		}
		return true
	}
	return false
}

func validRuleHeader(change map[string]any) bool {
	name, ok := ruleText(change["name"], 64)
	if !ok || name == "" {
		return false
	}
	for _, c := range name {
		if !strings.ContainsRune("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!#$%&'*+.^_`|~-", c) {
			return false
		}
	}
	name = strings.ToLower(name)
	for _, prefix := range []string{"x-forwarded-", "x-cdn-", "x-cdnf-"} {
		if strings.HasPrefix(name, prefix) {
			return false
		}
	}
	for _, protected := range []string{"host", "content-length", "transfer-encoding", "connection", "keep-alive", "proxy-connection", "te", "trailer", "upgrade", "proxy-authenticate", "proxy-authorization", "forwarded", "x-real-ip", "accept-encoding", "content-encoding", "content-range"} {
		if name == protected {
			return false
		}
	}
	if change["op"] == "remove" {
		return true
	}
	_, ok = ruleText(change["value"], 1024)
	return change["op"] == "set" && ok
}
