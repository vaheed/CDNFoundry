# cdnf-reconciliation

## Purpose
Safely deploy desired state outside Laravel transactions.
## When to use
Mandatory for DNS, edge, TLS, security, cache, and purge effects.
## Required inputs
Desired/active revisions, target, artifact contract, acknowledgement, retry, rollback, and bounds.
## Files normally touched
State/deployment migrations and models, job/compiler/adapter, operation UI/API, tests and runbook.
## Procedure
Commit desired revision; dispatch one unique/coalesced job; skip obsolete revisions; render/checksum/validate; atomically apply and verify; acknowledge; record failure; preserve previous state; implement bounded retry/rollback.
## Validation commands
Feature/retry/rollback tests plus relevant real target validation.
## Definition of done
Duplicates and failures cannot corrupt or replace last-valid state, and status is visible.
## Stop conditions
Stop without a revision, target identity, atomic activation, rollback, or explicit bound.
## Forbidden shortcuts
No request-time deployment, delete-before-replace, unbounded fan-out, mutable artifacts, or hidden partial failure.
## Expected completion summary
Revision flow, coalescing, activation, tests, and observed failures.
