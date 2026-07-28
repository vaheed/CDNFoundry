---
title: Simple Anycast qualification
description: Agent-owned evidence and the remaining operator-owned release gate.
---

# Simple Anycast qualification

Agent-owned implementation and qualification completed on 2026-07-28. The
owner-operated BGP and external-vantage run remains mandatory; CDNFoundry does
not have and must not gain the authority needed to execute it.

## Implemented increment

- `simple_anycast` and `geo_unicast` are explicit pool routing modes.
- One optional-dual-stack pair is stored and uniquely constrained on the pool.
- Addressless per-edge endpoint rows record explicit POP participation,
  desired/active revisions, local readiness, and withdrawal.
- Each participating gateway candidate receives the same pair and only its
  locally assigned ready cells.
- PowerDNS publishes direct A/AAAA pool targets while any attached endpoint is
  ready. Geo-Unicast keeps its country, continent, and global Lua fallback.
- Pool status reports `ready`, `degraded`, or `withdrawn`; gateway state changes
  queue a fresh coalesced DNS reconciliation and explicit forced reconciliation
  recovers a coalesced-work race.
- Unsafe, management, Geo-Unicast, and other Anycast ownership conflicts fail
  before activation. Origins cannot target the Anycast service pair.
- Administrator forms explicitly state that CDNFoundry does not announce BGP,
  control routers, provide upstream scrubbing, or protect a saturated uplink.

## Executed evidence

| Gate | Evidence |
| --- | --- |
| PostgreSQL | Expand migration applied to the persistent development database in 83.82 ms; no volume or destructive refresh used |
| Isolated Laravel | Final suite passed: 188 tests / 11,420 assertions |
| Focused Anycast | 6 tests / 32 assertions cover authorization, typed validation, conflicts, two-POP dual stack, Geo-Unicast coexistence, POP loss, withdrawal, acknowledgement reconciliation, and readiness-gated apex lookup |
| Real runtime | Two disposable mTLS edge identities, two POP candidates, one shared IPv4/IPv6 pair, real PowerDNS publication, controlled POP drain, degraded state, pool withdrawal/restoration, and forced-reconciliation recovery passed |
| Regression | Cache propagation/rollback/purge retry passed; placement migration reached revision 13 with zero obsolete artifacts |
| Artifacts | Compose production overrides, OpenAPI contract, documentation link/lint/build/site checks, edge-agent Go test/build image, and edge-gateway Go test/build image passed |
| IPv4/IPv6 | Dual-stack Anycast and Geo-Unicast candidates/DNS passed; existing IPv4-only pool endpoint and gateway regression remains in the full suite |

The local runtime uses documentation/test service addresses and validates the
control, gateway-candidate, readiness, and DNS behavior. It cannot establish a
real provider Anycast route from this environment.

## Remaining owner release gate

Run all Phase 5 steps in
[Manual browser qualification](https://github.com/vaheed/CDNFoundry/blob/main/docs/manual-browser-qualification.md) with two
provider-approved POPs and at least three independent external vantage points.
Record provider ticket/change IDs, route origin/path and collectors, actual
HTTP/HTTPS POP selection, route withdrawal/restoration convergence, load,
uplink saturation, screenshots, revisions, and failures. Browser automation
was not launched. Until that evidence passes, the implementation is
agent-qualified but the Phase 5 release decision remains **Blocked**.
