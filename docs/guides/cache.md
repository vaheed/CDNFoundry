---
title: Cache and purge
description: Configure deterministic cache policy, development mode, URL purge, full purge, and retries.
---

# Cache and purge

Cache policy is per domain and revisioned with edge configuration.

| Setting | Allowed values |
| --- | --- |
| enabled | boolean |
| edge TTL | 0–31,536,000 seconds |
| browser TTL | 0–31,536,000 seconds |
| maximum object | 1 MiB, 10 MiB, or 100 MiB |
| respect origin headers | boolean |
| include query string | boolean |
| bypass cookie names | up to 32 distinct names, each 1–64 safe characters |
| stale if error | 0–86,400 seconds |

Defaults are enabled, 3,600-second edge TTL, 300-second browser TTL, 100 MiB
maximum object, origin-header respect, query inclusion, no bypass cookies, and
60 seconds of stale-if-error.

Requests with authorization, unsafe methods, configured cookies, `Set-Cookie`,
private/no-store responses, unsupported `Vary`, ranges, and disallowed status
codes follow the runtime bypass or no-store rules. Cache keys and URL purge use
the same normalized scheme, hostname, path, and optional query logic.

## Development mode

Enable development mode for 1–1,440 minutes. PostgreSQL stores an absolute
expiry and the signed artifact carries it. OpenResty checks the time on each
request, so bypass ends without the scheduler or control plane.

## Purge

A purge is either:

- `all`, which increments the domain cache epoch and does not scan disk;
- `urls`, which accepts 1–100 distinct URLs and generates exact cache keys.

The payload is limited to 128 KiB. A domain may have at most 100 outstanding
purges. The controller creates one durable task per eligible edge and returns
`202`. Full and URL purges are idempotent through the request key and task ID.

The agent sends the task to every matching cell. OpenResty records the new epoch
or bounded URL-key invalidations in shared memory. Failed delivery retains the
same task, records `last_error`, and retries with bounded delay. Traffic
continues throughout.

## Storage isolation

The standard shared and quarantine cells use distinct cache volumes, temporary
filesystems, CPU, memory, and PID limits. Cache exhaustion in one cell does not
scan or delete another cell's storage.
