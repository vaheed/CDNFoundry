package main

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"time"
)

// The state the control plane sets from edge reports reaches a cell as a small per-domain overlay, apart from the
// signed snapshot. Overlays are kept on disk so a restarted cell can be given them again, the same way purges are.
const maximumSecurityOverlays = 256

var errOverlayCapacity = errors.New("security overlay capacity exceeded")

// securityIncident is the incident profile a domain's overlay may carry: the cell enforces it and ends it by its own clock at ExpiresAt.
type securityIncident struct {
	ExpiresAt int64 `json:"expires_at"`
	WAF       struct {
		Mode        string `json:"mode"`
		Sensitivity string `json:"sensitivity"`
	} `json:"waf"`
	Methods        []string `json:"methods"`
	MaxBodyBytes   int64    `json:"max_body_bytes"`
	Challenge      bool     `json:"challenge"`
	BypassPrefixes []string `json:"challenge_bypass_prefixes,omitempty"`
}

type securityOverlay struct {
	DomainID uint64            `json:"domain_id"`
	Sequence uint64            `json:"sequence"`
	State    string            `json:"state"`
	Limits   map[string]int64  `json:"limits"`
	Incident *securityIncident `json:"incident,omitempty"`
}

func (c *client) overlayStatePath() string { return filepath.Join(c.dir, "security-overlays.json") }

func (c *client) loadOverlays() (map[uint64]securityOverlay, error) {
	overlays := map[uint64]securityOverlay{}
	body, err := os.ReadFile(c.overlayStatePath())
	if errors.Is(err, os.ErrNotExist) {
		return overlays, nil
	}
	if err != nil {
		return nil, err
	}
	var list []securityOverlay
	if err := json.Unmarshal(body, &list); err != nil {
		return nil, err
	}
	for _, overlay := range list {
		overlays[overlay.DomainID] = overlay
	}
	return overlays, nil
}

// persistOverlay stores the newest overlay of a domain. An older sequence is ignored, a normal state removes the
// overlay, and a new domain beyond the bound is refused so an overlay is never dropped silently. It reports whether
// the stored set changed.
func (c *client) persistOverlay(next securityOverlay) (bool, error) {
	overlays, err := c.loadOverlays()
	if err != nil {
		return false, err
	}
	if current, exists := overlays[next.DomainID]; exists && current.Sequence > next.Sequence {
		return false, nil
	}
	if next.State == "normal" {
		if _, exists := overlays[next.DomainID]; !exists {
			return false, nil
		}
		delete(overlays, next.DomainID)
	} else {
		if _, exists := overlays[next.DomainID]; !exists && len(overlays) >= maximumSecurityOverlays {
			return false, errOverlayCapacity
		}
		overlays[next.DomainID] = next
	}
	list := make([]securityOverlay, 0, len(overlays))
	for _, overlay := range overlays {
		list = append(list, overlay)
	}
	sort.Slice(list, func(i, j int) bool { return list[i].DomainID < list[j].DomainID })
	return true, atomicJSON(c.overlayStatePath(), list)
}

func overlayCommand(taskID string, overlay securityOverlay) map[string]any {
	command := map[string]any{
		"task_id": taskID, "action": "security_overlay", "domain_id": overlay.DomainID,
		"sequence": overlay.Sequence, "state": overlay.State,
	}
	if overlay.Limits != nil {
		command["limits"] = overlay.Limits
	}
	if overlay.Incident != nil {
		command["incident"] = overlay.Incident
	}
	return command
}

func validOverlay(overlay securityOverlay) bool {
	switch overlay.State {
	case "normal", "suspected", "restricted", "recovering":
	default:
		return false
	}
	if overlay.DomainID == 0 || overlay.Sequence == 0 || len(overlay.Limits) > 64 {
		return false
	}
	for name, value := range overlay.Limits {
		if name == "" || len(name) > 64 || value < 0 {
			return false
		}
	}
	return validIncident(overlay.Incident)
}

// validIncident accepts only what the cell enforces: blocking WAF, known methods, bounded lists, and an absolute expiry.
func validIncident(incident *securityIncident) bool {
	if incident == nil {
		return true
	}
	if incident.ExpiresAt <= 0 || incident.WAF.Mode != "block" || incident.MaxBodyBytes < 0 || len(incident.Methods) > 8 || len(incident.BypassPrefixes) > 8 {
		return false
	}
	known := map[string]bool{"GET": true, "HEAD": true, "OPTIONS": true, "POST": true, "PUT": true, "PATCH": true, "DELETE": true}
	for _, method := range incident.Methods {
		if !known[method] {
			return false
		}
	}
	for _, prefix := range incident.BypassPrefixes {
		if len(prefix) == 0 || len(prefix) > 128 || prefix[0] != '/' {
			return false
		}
	}
	return true
}

// runSecurityOverlay records the overlay, then tells every cell. A cell that cannot be reached is marked unrestored by
// the missing acknowledgement: the next status cycle replays the stored overlays to it.
func (c *client) runSecurityOverlay(task edgeTask) (map[string]any, string) {
	overlay := securityOverlay{
		DomainID: uint64(task.Payload.DomainID), Sequence: task.Payload.OverlaySequence,
		State: task.Payload.OverlayState, Limits: task.Payload.OverlayLimits, Incident: task.Payload.OverlayIncident,
	}
	if !validOverlay(overlay) {
		return map[string]any{"status": "failed", "failure_reason": "invalid_security_overlay"}, "failed"
	}
	if _, err := c.persistOverlay(overlay); err != nil {
		reason := "overlay_state_persist_failed"
		if errors.Is(err, errOverlayCapacity) {
			reason = "overlay_capacity_exceeded"
		}
		return map[string]any{"status": "failed", "failure_reason": reason}, "failed"
	}
	applied := 0
	for _, endpoint := range c.statusURLs {
		if _, ok := c.cellStatus(endpoint); !ok {
			continue
		}
		if err := c.control(endpoint, overlayCommand("overlay-"+task.ID, overlay)); err != nil {
			return map[string]any{"status": "failed", "failure_reason": "overlay_control_failed", "applied_cells": applied}, "failed"
		}
		applied++
	}
	if applied == 0 {
		return map[string]any{"status": "failed", "failure_reason": "cell_not_found"}, "failed"
	}
	return map[string]any{"status": "completed", "applied_cells": applied, "state": overlay.State}, "succeeded"
}

// restoreOverlays replays every stored overlay to one cell that reports it has not been restored since it started,
// then marks it restored. Overlays carry their own sequence, so a replay never moves a cell backwards.
func (c *client) restoreOverlays(endpoint string) {
	overlays, err := c.loadOverlays()
	if err != nil {
		return
	}
	stamp := time.Now().UTC().Format("20060102T150405.000000000")
	for _, overlay := range overlays {
		if err := c.control(endpoint, overlayCommand("restore-overlay-"+strconv.FormatUint(overlay.DomainID, 10)+"-"+stamp, overlay)); err != nil {
			return
		}
	}
	_ = c.control(endpoint, map[string]any{"task_id": "overlays-restored-" + stamp, "action": "security_overlays_restored"})
}
