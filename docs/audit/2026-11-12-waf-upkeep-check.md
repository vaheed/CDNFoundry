---
title: WAF upkeep check, 2026-11-12
description: A verified status of the false-positive workflow, rule visibility for domain users, and ruleset updates across cells, as inputs for the custom rules phase.
---

# WAF upkeep check, 2026-11-12

Roadmap item 3.5. Each topic was checked against the code and the running system on this date. It is an input to the custom rules
and managed rules phases, not a change to them.

| Topic | Status | Evidence |
| --- | --- | --- |
| False-positive workflow | **Works, but only coarsely.** A domain owner chooses **Observe** to stop blocking while keeping the log. There is no per-rule way out: creating an exclusion returns `409 waf_exclusions_unavailable`, the cell ignores saved exclusions, and the edge artifact carries an empty list. | [Managed WAF guide](../guides/managed-waf.md), `docker/openresty/runtime.lua` (`prepare_waf`), `WafController` |
| Rule visibility for domain users | **Partly visible.** The security log stream shows each WAF verdict with its rule ID, score, action, profile, and body-limit outcome, scoped to the user's own domains. A user cannot see which rule an ID is, why it scored, or a ruleset version. | `AnalyticsStore::logs` (security stream), `tests/e2e/managed_waf.py` |
| Ruleset updates across cells | **Not verifiable per cell.** The ruleset is baked into the cell image and named by one platform value; a pool pins a runtime version an administrator types or copies from it. Cells do not report which ruleset they run, so a pool that did not receive a release is not detected, and an update is only ever a release. | `config/security.php` (`waf.ruleset`), `EdgePoolController`, `edge-agent` (no ruleset field in the heartbeat) |

## What this means for the next phases

- **Per-rule disable and log-only** (managed rules you can adjust) is the real fix for the false-positive gap. Until then, Observe is
  the only escape, and it also stops blocking real attacks on that domain.
- **Explain a rule ID** in the security events view: a name and a short description for each CRS rule that appears.
- **Report the running ruleset version** from each cell in the heartbeat, show it on the pool and cell, and alert on a mismatch with the
  pinned version. Updates keep arriving only through a release, never from the network at runtime.
