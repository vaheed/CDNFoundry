---
title: Bounded fleet rollouts
description: Upgrade the edge runtime image set in canary waves through the API, and pause, resume, or roll back when an edge is unhealthy.
---

# Bounded fleet rollouts

A fleet rollout upgrades the edge runtime on many PoPs in small, checked waves. A PoP (point of presence) is one edge host. You start with a few canary edges, and later waves start only when the earlier ones are healthy.

**You need:** an administrator account or API token, four image references that each end in a registry digest, and the IDs of the edges you want to upgrade.

::: danger Rollout intent is not remote execution
The edge agent accepts only the four fixed image references and writes an intent file for a separate privileged installer. The agent never runs a supplied command and has no container-engine socket. Do not add one.
:::

## What a rollout changes

A rollout manages exactly four image references: gateway, agent, normal cell, and WAF cell. Each one must contain a registry digest (`@sha256:...`). The API rejects tags, shell commands, other component names, and extra slots.

Do not confuse this with a Fleet tool release. The Fleet tool deploys its own signed release manifests to hosts. A rollout is the control plane's API for moving edges between image sets. Both are described in [Fleet engine](./fleet-engine.md).

## Start a rollout

Rollouts have no panel screen. Use the API. Both calls need administrator access and an `Idempotency-Key` header.

<div class="steps">

1. Create the release. Send `name`, `gateway_image`, `agent_image`, `normal_cell_image`, `waf_cell_image`, `minimum_compatible_version`, and `maximum_compatible_version` to `POST /api/admin/fleet/releases`. Expect `201` and the release record. The minimum version cannot be above the maximum.
2. Create the rollout with `POST /api/admin/fleet/rollouts`. Send:
   - `fleet_release_id`, and optionally `previous_release_id` (needed for rollback);
   - `edge_ids`, 1 to 200 edge UUIDs;
   - `canary_edge_ids`, 1 to 20 edges that must also appear in `edge_ids`;
   - `wave_size` and `maximum_parallel`, each 1 to 20;
   - `minimum_ready_percent` (50 to 100) and `maximum_error_percent` (0 to 25);
   - `mixed_version_window_minutes`, 5 to 1,440.

   Expect a record with an operation ID.

</div>

Canary edges always form wave 1. Later edges follow in deterministic waves.

## What happens on each edge

1. The control plane queues one typed intent for the edge.
2. The unprivileged agent checks all four digests and writes `desired-runtime.json` in one atomic step.
3. A separately installed installer with a fixed purpose applies the intent.
4. After the restart, the agent reports its current digests, listener readiness, and revision. Only then does the edge count as upgraded.

## Check progress

Call `GET /api/admin/fleet/rollouts/{id}`. It returns the rollout with its release and per-edge state. In the admin panel, open an edge in **Edges** and compare **Current runtime versions** with **Desired runtime versions**. The edge list shows a **Version drift** column.

## If a rollout pauses

A stale or unready edge pauses the rollout. So does every failed task. Other edges keep serving during the mixed-version window.

1. Read the rollout and note the pause reason and the failed edge.
2. Fix the cause on that edge, such as readiness or connectivity.
3. Resume with `POST /api/admin/fleet/rollouts/{id}/resume`. Do not resume while an edge is still failed.
4. To go back, call `POST /api/admin/fleet/rollouts/{id}/rollback`. It needs a recorded previous release and uses the same wave gates.

Prometheus exposes version drift, edge, cell, and endpoint state, revision mismatch, bounded cell-capacity ratios, and paused-rollout counts. See the [incident runbook](runbooks.md#paused-fleet-rollout).
