# Phase 2 qualification

Qualification was re-run on 2026-07-19 against the development Compose stack. Browser acceptance remains manual and public registrar delegation requires an externally delegated domain; neither is claimed here.

## Environment

- Linux 6.8 x86-64, 32 logical CPUs
- 15 GiB RAM, no swap
- PostgreSQL 18.4
- PowerDNS Authoritative 5.1.3
- DNSdist 2.1.0
- Valkey 9.1.0
- 95 GiB free filesystem space before the scale run

## Automated application checks

`cd core && php artisan test` covers policies, validation, idempotency, lifecycle transitions, atomic bulk changes, import rollback, operation retries, deterministic rendering, last-valid-state preservation, and Filament panel scoping. The committed OpenAPI contract is checked from the registered routes.

The PostgreSQL-backed focused suites are run through Compose so database constraints and JSONB behavior are exercised rather than only SQLite.

## Real authoritative DNS

Run:

```text
python3 tests/e2e/phase2_dns.py
```

The script creates desired state through authenticated APIs, deploys through Horizon to the private PowerDNS API, and queries only the public DNSdist port. It verifies A, AAAA, CNAME, MX, TXT, NS delegation, CAA, SRV, PTR, SOA-backed deployment revision convergence, UDP, TCP, IPv4 client transport, and IPv6 client transport.

On an empty control database it also waits for the asynchronous DNS-cluster health operation and explicitly enables the verified cluster before domain activation. Fresh-volume Compose qualification verifies MMDB activation precedes PowerDNS readiness and that DNSdist does not start without a healthy authoritative backend.

It pauses Horizon, performs 100 rapid updates to one active domain, and proves the Redis runtime queue contains one `ReconcileDnsZone` job for those updates. The assertion filters by job class because the independent platform-identity scheduler may legitimately share the runtime lane. It then confirms that the latest revision is served.

It stops Laravel, the control PostgreSQL database, Valkey, Horizon, the scheduler, and the web frontend while continuing to query the active zone successfully. It restores them, restarts PowerDNS, DNSdist, Horizon, and Laravel, and verifies the same latest A and TXT answers. Cleanup removes the temporary runtime zone and control-plane rows.

Observed result:

```text
phase2_dns_e2e=passed types=9 ipv4_transport=passed ipv6_transport=passed coalesced_updates=100:1 control_outage=passed restart=passed
```

## Dataset and mutation scale

Run:

```text
python3 tests/e2e/phase2_scale.py
```

The script temporarily inserts 500,000 bounded disabled zones and two records per zone into PostgreSQL, verifies exactly 1,000,000 records, then performs 50,000 record creations through authenticated bulk HTTP APIs. Each request is bounded to 5,000 actions and one desired revision. The first two requests form the documented 10,000-mutation controlled burst. All temporary rows are deleted in `finally`.

Observed result:

```text
phase2_scale=passed zones=500000 records=1000000 changes=50000 burst_first_two=10000 dataset_seconds=114.25 mutation_seconds=236.03
```

These numbers qualify correctness and bounded operation on the stated development host; they are not production capacity promises.

## Outstanding owner/external acceptance

- Delegate a real public domain and record successful automated nameserver verification.
- Complete `docs/manual-browser-qualification.md` for administrator assignment, domain onboarding, DNS CRUD, import/export, and agreement between Filament state and real `dig` output.
