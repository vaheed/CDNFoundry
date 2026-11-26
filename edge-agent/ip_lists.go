package main

import (
	"errors"
	"fmt"
	"math"
)

// Named IP lists reach a cell inside the signed domain snapshot as compiled ranges. The agent checks them before a snapshot is
// activated, so a corrupt, oversized or unordered list rejects its domain's artifact and the previous state keeps serving.
//
// A list is {"v4": [first, last, ...], "v6": [w1, w2, w3, w4, w1, w2, w3, w4, ...]}: IPv4 ranges as whole numbers and IPv6 ranges as
// four 32-bit words per address (first address, then last), sorted by first address and not overlapping. The same rules are
// checked by the cell's own lookup module (docker/openresty/ip_list.lua).
const (
	maximumIPListRanges     = 10000
	maximumIPListsPerDomain = 5
	// The most ranges all lists of the domains on one cell may hold. Every worker keeps its own decoded copy, so this bounds the
	// memory the lists use in a cell (see the measurement recorded in the roadmap).
	maximumIPListRangesPerCell = 100000
)

func ipWord(value any) (float64, bool) {
	number, ok := value.(float64)
	return number, ok && number >= 0 && number <= math.MaxUint32 && number == math.Trunc(number)
}

func ipWordsLess(a, b []float64) bool {
	for index := range a {
		if a[index] != b[index] {
			return a[index] < b[index]
		}
	}
	return false
}

// validateIPLists checks every list of one domain and returns how many ranges they hold.
func validateIPLists(raw any) (int, error) {
	if raw == nil {
		return 0, nil
	}
	lists, ok := raw.(map[string]any)
	if !ok || len(lists) > maximumIPListsPerDomain {
		return 0, errors.New("invalid ip lists")
	}
	total := 0
	for name, value := range lists {
		list, ok := value.(map[string]any)
		if !ok || name == "" || len(name) > 40 {
			return 0, fmt.Errorf("invalid ip list %q", name)
		}
		ranges, err := validateIPList(list)
		if err != nil {
			return 0, fmt.Errorf("ip list %q: %w", name, err)
		}
		total += ranges
	}
	return total, nil
}

func validateIPList(list map[string]any) (int, error) {
	v4, _ := list["v4"].([]any)
	v6, _ := list["v6"].([]any)
	if (list["v4"] != nil && v4 == nil) || (list["v6"] != nil && v6 == nil) || len(v4)%2 != 0 || len(v6)%8 != 0 {
		return 0, errors.New("malformed ranges")
	}
	count := len(v4)/2 + len(v6)/8
	if count > maximumIPListRanges {
		return 0, errors.New("too many ranges")
	}
	previous := -1.0
	for index := 0; index < len(v4); index += 2 {
		first, okFirst := ipWord(v4[index])
		last, okLast := ipWord(v4[index+1])
		if !okFirst || !okLast || first > last || first <= previous {
			return 0, errors.New("unordered or overlapping ipv4 ranges")
		}
		previous = last
	}
	var before []float64
	for index := 0; index < len(v6); index += 8 {
		words := make([]float64, 8)
		for offset := range words {
			word, ok := ipWord(v6[index+offset])
			if !ok {
				return 0, errors.New("invalid ipv6 word")
			}
			words[offset] = word
		}
		first, last := words[:4], words[4:]
		if ipWordsLess(last, first) || (before != nil && !ipWordsLess(before, first)) {
			return 0, errors.New("unordered or overlapping ipv6 ranges")
		}
		before = last
	}
	return count, nil
}
