---
title: Upgrade and rollback
description: Upgrade CDNFoundry with explicit migrations, canaries, compatibility checks, and safe rollback.
---

# Upgrade and rollback

::: danger Preserve durable state
Never remove named volumes, regenerate encryption or CA keys, or run a
destructive database refresh during an upgrade. Roll back images only within
the proven schema compatibility envelope.
:::

## Before the change

1. Pin the current and target immutable image references.
2. Create and verify an encrypted off-host control backup.
3. Retain `.env.prod`, `APP_KEY`, artifact signing key, both CA keys,
   listener identities, Restic password, and externally held custom TLS material.
4. Validate the target production Compose file and every generated node bundle.
5. Review migrations for expand/contract compatibility.
6. Run the target's automated and real-runtime qualification.

## September 2026 security changes

This checkout is **not yet qualified for production**; see the
[audit evidence](../operations/security-audit.md). The following order is required
when qualifying an upgrade that includes these changes:

1. Preserve the encrypted backup, existing PKI and previous image references.
2. Deploy the updated `web` and `edge-control` Nginx configurations **before**
   the updated Laravel core. Edge-control now forwards the TLS-verified client
   certificate; ordinary ingress clears that header. The old core ignores this
   extra header. The new core rejects missing or mismatched certificates with
   HTTP 401, so deploying it behind an old edge-control image pauses enrollment
   identity authentication and runtime delivery.
3. Drain old nameserver-verification workers and pause new claim submissions
   during the control-plane transition. Apply
   `2026_09_05_160000_add_domain_delegation_claims` forward, then replace core,
   Horizon and Scheduler together. Existing verified domains retain their
   nameservers. Pending applicants receive fresh claim-specific nameservers;
   an old queued verification must not run with the old shared-NS verifier.
4. Confirm every serving domain has explicit pool/cell assignments. The old
   no-placement artifact broadcast is removed; an unassigned edge receives no
   new domain configuration. Preserve active generations while qualifying this
   boundary. Verify edge heartbeats, issued artifact acknowledgements, and customer
   serving before continuing. No identity fingerprint database migration is
   needed: authentication compares the certificate already stored at enrollment.
5. Rotate existing edge identities one canary at a time using
   [the identity rotation procedure](certificates.md#rotation). Earlier issuance
   could inherit `CA:TRUE` from the host OpenSSL configuration. New identities
   explicitly use `CA:FALSE`, digital-signature use and client authentication.
   Exact enrolled-certificate matching contains this flaw during rotation;
   it does not retroactively change an old certificate's extensions.

Do not regenerate an existing CA as an upgrade shortcut. Newly generated Fleet
roots use `pathlen:0`; existing roots and recovery material remain intact.
Keep the ingress provenance and exact-certificate authentication fixes when
repairing a failed canary. Rolling back to serial-only authentication restores
the security defect. The claim migration deliberately refuses destructive
rollback; use a forward repair and retain claim/tombstone evidence.

## OpenResty origin-address correction

The September origin-address fix changes cell runtime code, not the artifact
schema or PostgreSQL. Build and qualify the complete edge-runtime image, then
replace cells through the existing canary rollout. Test native IPv6 HTTP and
verified HTTPS origins, including wrong-SNI rejection and blocked destinations,
before advancing. Existing private origins remain subject to explicit allowlists;
carrier-grade addresses now follow the same allowlist rule as the control plane.

Keep the address parser and IPv6 peer-format change together. Earlier runtime
code both stripped required peer brackets and matched unsafe IPv6 addresses by
text. Applying only the connection-format correction would expose gaps in that
old guard. Reverting the complete fix restores broken IPv6 origin connections
and the weaker guard. Preserve active artifacts, certificates and cache data;
this fix needs no customer-domain reload or data migration.

## Complete origin DNS validation

The origin DNS correction uses the same artifact schema and needs only an
edge-runtime image update through the existing canary rollout. Confirm that
operator DNS returns successful NODATA answers for absent address families.
An AAAA resolver failure can no longer be hidden by a successful A answer, or
vice versa. Keep answer sets within 64 combined answer records and qualify the
three-second DNS ceiling (or a smaller configured response timeout) before
advancing the canary. Cache hits retain their existing behavior; uncached origin
attempts now validate both families even when an upstream keepalive connection
exists. No database migration or per-domain reload is required.

Reverting restores first-answer admission and the older timeout behavior. Retain
active artifacts, certificates and cache data during any recovery; fix resolver
health rather than bypassing destination checks or disabling IPv6 validation.

## Origin connection accounting

The origin-capacity accounting correction requires the matching OpenResty Lua
runtime and Nginx configuration from the same rebuilt image: the configuration
declares the request reservation variable used across internal redirects. Use
the existing canary rollout and run `tests/e2e/origin_destinations.py` before
advancing. Confirm excess requests remain 503 while the origin slot is occupied,
then return to normal after it finishes. No database or artifact-schema migration
is needed. Reverting restores the limit bypass and inaccurate connection counts;
retain active artifacts and certificates during rollback.

The follow-up lifecycle correction releases an in-flight failed request's
reservation even if its hostname has already left the runtime snapshot. Canary
qualification must cover removal during an origin response and subsequent
restoration without a cell restart. It requires an updated runtime image only;
no data migration is needed. Rolling back can leave occupied slots after such
removals, preventing restored traffic when the configured limit is exhausted.

## Origin retry budgets

The retry-budget correction likewise ships in the edge runtime/config image.
Qualify configured retries of zero, one and two against a controlled failing
origin, then a recovering origin. The hard ceiling is three total origin
attempts; security policy may lower it. A recovered request must not activate
backup or create a passive failure. No database, agent or artifact-schema
migration is needed. Rolling back restores retry-budget replenishment and
first-response health accounting.

## Rollout order

For additive migrations and compatible agents:

1. run `make prod-pull`;
2. run `make prod-migrate`;
3. apply the additive ClickHouse telemetry migrations that are newer than the
   deployed release, including
   `docker/clickhouse/migrations/2026_07_29_add_compression_telemetry.sql`
   and
   `docker/clickhouse/migrations/2026_07_29_add_origin_failover_telemetry.sql`,
   and
   `docker/clickhouse/migrations/2026_07_29_add_managed_waf_telemetry.sql`,
   with `clickhouse-client --multiquery` before replacing Vector;
4. canary one control web process;
5. replace Horizon workers gracefully with `php artisan horizon:terminate`;
6. replace the scheduler;
7. run `make prod-pdns-migrate` before PowerDNS code that needs it;
8. canary one DNS target and verify UDP/TCP answers;
9. canary one edge agent and cell;
10. verify artifact compatibility, acknowledgement, HTTP/HTTPS, cache,
    compression, managed WAF, TLS, and telemetry;
11. continue one failure domain at a time.

Nginx/OpenResty services receive `SIGQUIT` and bounded stop grace. Do not force
remove healthy listeners during routine rollout.

## Compatibility

Artifacts carry schema, minimum, and maximum agent versions. An incompatible
agent rejects the candidate and keeps its active state. The upgrade qualification
builds prior and current core/agent images, migrates a temporary PostgreSQL
database forward, verifies old state, and exercises signed compatibility.

## Queued origin tests

The control plane now binds new origin-test operations to the selected origin
configuration and rechecks domain verification and actor access at dispatch and
delivery. An operation queued by older code without this binding fails with an
instruction to request a new test. Existing task payloads already include their
origin and remain readable; obsolete tasks are cancelled when polled. No schema
migration, agent wire change, or runtime restart is needed for this change.
Upgrade core and queue workers together; drain older workers so they cannot
continue dispatching unbound tests. Reverting core restores the old dispatch
behavior and does not undo an origin-health result already recorded.

## Application rollback

If the target application is compatible with the already-applied schema, restore
the prior verified `CDNF_*_IMAGE` digest references and replace services in reverse
order. Do not roll the database backward for a normal application rollback.
Contract migrations must remove old columns only in a later release after every
old binary is gone.

## Runtime rollback

Domain edge rollback creates a new revision from a retained validated snapshot.
DNS keeps its prior active RRsets when replacement fails. TLS preserves its
valid active certificate. These runtime guarantees are independent of container
image rollback.

## Stop conditions

Stop the rollout if component health is unavailable, queue age grows without
bound, a candidate is rejected, a DNS cluster loses correct answers, edge
listener readiness falls, or new migrations break the prior binary. Preserve
the last healthy canary and collect operation/task evidence before retrying.
