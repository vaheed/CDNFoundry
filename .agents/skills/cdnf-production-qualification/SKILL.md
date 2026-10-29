---
name: cdnf-production-qualification
description: Qualify CDNFoundry on a real or staging fleet: runtime E2E, DNS, HTTP/S, IPv4/IPv6, failure injection, backup/restore, and release evidence. Use before a release or when asked to qualify a change end to end.
---

# cdnf-production-qualification

## Purpose
Prove the platform works on real infrastructure, and record evidence that cannot be mistaken for unexecuted tests.

## When to use
Before tagging a release, after a roadmap phase, or when asked to qualify a change on a real or staging fleet.

## Required inputs
The release or commit, the fleet (control host, PoPs, regions, address families), the domains used, and the scope of the change.

## Files normally touched
`tests/e2e/production_qualification.py`, `tests/e2e/staging_*.py`, `docs/operations/production-qualification.md`, `docs/operations/releases/*.md`, `CHANGELOG.md`.

## Procedure
1. Record the environment: commit, image digests, hosts, and address families.
2. Run the runtime suites: `make dev-e2e` locally, then `tests/e2e/staging_*.py` and `production_qualification.py` against the fleet.
3. Inject the failure scenarios from the roadmap's critical and high findings: control outage, bad artifact, PoP loss, DNS host loss, purge after restart, and IPv6-only resolution.
4. Check backup and restore: `cdnf:backups:create`, restore preflight, and recovery with the encryption and signing keys.
5. Record every result, including failures and skipped checks, with its command and output.
6. Browser qualification is owner-run under the current AGENTS.md. Hand it over as not run.

## Validation commands
- `make dev-e2e`, `make dev-scale-e2e`, `python3 tests/e2e/production_qualification.py --help`.
- `make config-check`, `make docs-check`, `make supply-chain-check`.

## Definition of done
Every listed check has a recorded result, and the release notes state what was and was not qualified.

## Stop conditions
Stop if a check would touch production customer data, or would need secrets printed to the log.

## Forbidden shortcuts
Never report an unexecuted test as passed, and never replace a real runtime check with a mock.

## Expected completion summary
Environment, checks run with their results, failures, skipped items with reasons, and the browser job status.
