---
title: "Clean rebuild gap report"
description: Product and documentation gaps found while rebuilding the stage fleet from the starter guide.
---

# Clean rebuild gap report

Date: 2026-10-29. Scope: stage hosts wiped, then rebuilt step by step from the
[starter guide](../deployment/production-quick-start.md) with release `41177d7` and
dev GHCR images pinned by digest. Every item below was observed on the real hosts.

## Product problems and status

Status key: **fixed** (code changed and covered by a test), **documented** (procedure
in the starter guide; no code change), **open** (decision or further work needed),
**retracted** (not a product defect after verification).

| ID | Problem | Evidence | Status and change |
| --- | --- | --- | --- |
| P1 | `fleet --dry-run setup` printed "Initialized", "Added node" and "Generated" but persisted nothing. | No state or bundles after the dry run; `validate` then failed with `Missing file: fleet.json`. | **fixed**. Messages say `Dry run, not persisted:`; the JSON status is `validated`; no bundle paths are listed. Tests: `test_setup_dry_run_uses_in_memory_state`, `test_dry_run_does_not_create_state_or_secrets`. |
| P2 | Control `start.sh` stops the whole activation when one optional one-shot service fails. | Grafana init 403 left Grafana and Caddy uncreated. Caddy depends on `web`, not Grafana; Compose stopped the run. | **documented**. The root cause is P3. Procedure: starter guide "Grafana plugin download". |
| P3 | Grafana plugin download from grafana.com is a hard external dependency. | HTTP 403 from the stage control host. | **documented**. Offline seeding procedure from a pinned copy (version 4.21.3) added to the starter guide. |
| P4 | Fleet does not issue the edge listener certificate or the recovery key. | Guide requires both in `/dev/shm/cdnfoundry/`. | **fixed**. `scripts/issue-edge-listener-material.sh` issues them from the fleet CA. Tests: `tests/fleet/test_issue_edge_listener_material.py` (2 passed). |
| P5 | `up --wait` fails on services whose healthcheck is disabled. | Loki exits 1 although running; reproduced with a busybox probe. | **fixed**. Generated `start.sh` starts all services, then waits only on services with an active healthcheck. Test: `test_control_start_waits_only_on_services_with_active_healthchecks`. Stage bundles still carry the old script until re-rendered. |
| P6 | Docker Hub returns 403 to the stage hosts. | `docker pull caddy:…` fails on control and PoPs. | **fixed on stage**. `/etc/docker/daemon.json` with `registry-mirrors` set on the workstation, control, pop-1 and pop-2. Procedure in the starter guide. |
| P7 | A digest-only `docker save` loads untagged; Compose cannot match `image@sha256` and falls back to a registry. | `validate.sh` failed with a Docker Hub error after load. | **documented**. Mirror removes the need on configured hosts. The offline tagging procedure is in the starter guide. |
| P8 | Two error envelopes: documented top-level `{message, code, details}` and nested `{error:{code,message}}` on idempotency and account-disabled responses. | `IdempotentRequest`, `AuthController`, `EnsureAccountIsActive`. | **fixed**. All producers use the documented envelope. Tests updated: `AccessApiTest`, `tests/e2e/e2e.py`, `tests/e2e/phase6_security.py`. `make dev-test`: 367 passed. |
| P9 | Seeded pools `shared-default` and `quarantine-default` are enabled with no cells or endpoints. | Placement (`ReconcileEdgeDomain`) selects any enabled pool. | **open (design)**. Disabling them at migration time broke placement: 38 core tests failed with `No enabled edge pool exists`. The migration was reverted. The real hazard is that placement does not check routing readiness. Decide: check readiness in placement, or keep the documented manual disable step. |
| P10 | Endpoint `acknowledged_at` stays null after `ready`. | Reported from the wrong field name. | **retracted**. The field is `gateway_acknowledged_at`; it is set (2026-10-29T10:37:44Z on both endpoints). |
| P11 | Pagination links use `http://` behind the TLS proxy. | `meta.path` on `GET /admin/dns/clusters`. | **fixed**. Trusted proxy headers now include forwarded proto, host and port. Test: `ProxiedRequestSchemeTest`. The change needs a new core image; the running control still returns `http://` until it is redeployed. |

## Documentation gaps

| ID | Gap | Status |
| --- | --- | --- |
| D1 | Dry-run output wording | **documented** (guide step 2) |
| D2 | Real `setup` needs explicit `--state-dir` and `--output-dir` | open: add to the operator guide |
| D3 | Workstation hosts-file mapping to 127.0.0.1 | open: local environment note; use `curl --resolve` |
| D4 | Grafana plugin offline seeding | **documented** |
| D5 | Location of `pki/nodes/<pop>/node.key` on the workstation | **documented** (edge listener section) |
| D6 | Example `bind_ipv4: 0.0.0.0` for DNS roles conflicts with systemd-resolved | open: the example file still uses `0.0.0.0`; step 2 explains the fix |
| D7 | Loading upstream images without registry access | **documented** |
| D8 | Edge listener certificate and recovery key issuance | **documented** and scripted (P4) |
| D9 | `Idempotency-Key` must be a UUID | open: add to the API reference |

## Environment notes (not product defects)

- The workstation's outbound port 53 is intercepted: a query to `203.0.113.77`, a
  documentation address, returned answers. Stage DNS checks run from the control host.
- The workstation `/etc/hosts` maps `control.cdnf.vaheed.net` to `127.0.0.1`.

## Owner decisions still needed

1. Customer test zone: `10dl.ir` needs a re-claim (ROADMAP P-03), or another owned
   disposable zone with registrar access. Customer-facing features cannot run until then.
2. P9 placement design: readiness-aware placement, or the documented manual disable.
3. Role mapping: the live operator DNS and fleet state agree on control `.76`,
   pop-1 `.78`, pop-2 `.77`. The roadmap's P-02 is still an owner confirmation.
4. Deploy the P11 image and re-render the stage bundles to pick up P5 in `start.sh`.
