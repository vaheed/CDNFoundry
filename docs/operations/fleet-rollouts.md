---
title: Bounded fleet rollouts
description: Immutable canary and wave upgrades for the fixed edge runtime.
---

# Bounded fleet rollouts

Fleet rollouts manage exactly four immutable image references: gateway, agent,
normal cell, and WAF cell. Every reference must contain a registry digest. Tags,
shell commands, arbitrary component names, dynamic slots, and container-engine
access from the edge agent are rejected.

Create a release with `POST /api/admin/fleet/releases`. Create a rollout with
`POST /api/admin/fleet/rollouts`, an explicit bounded edge list, at least one
canary, wave size, parallelism, health thresholds, and a mixed window between 5
and 1,440 minutes. Mutations require administrator access and
`Idempotency-Key`.

Canaries are always wave 1. Later edges use deterministic bounded waves. The
runtime queue delivers one typed intent. The unprivileged agent validates all
four digests and atomically writes `desired-runtime.json`. A separately
installed fixed-purpose privileged installer applies that intent; the agent
never executes a supplied command and never receives a Docker socket. After
restart, the agent reports current digests, listener readiness, and revision
before acknowledging success.

A stale or unready edge and every failed task pause the rollout. Inspect
`GET /api/admin/fleet/rollouts/{id}` and the **Edges** current/desired version
fields. Resume only after clearing failure. Rollback requires a recorded
previous release and uses the same wave gates.

Prometheus exposes version drift, edge/cell/endpoint state, revision mismatch,
bounded cell-capacity ratios, and paused-rollout counts. See the
[incident runbooks](runbooks.md#paused-fleet-rollout).
