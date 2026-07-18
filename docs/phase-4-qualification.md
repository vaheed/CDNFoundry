# Phase 4 qualification

Phase 4 agent-owned implementation and non-browser qualification completed on 2026-07-18. Browser usability and traffic through owner-operated physical edge hosts remain manual release checks.

## Automated application and agent checks

- `make dev-test` covers policy scope, exact-one-origin validation, unsafe destination rejection, retry-safe enrollment, identity revocation, incremental and full-snapshot distribution, checksums/signatures, acknowledgements/rejections, last-valid-state, health reporting, placement state, and Filament route rendering. It also exercises plain-HTTP form normalization with a disabled health check, visible field-level origin/CNAME errors, Geo-CNAME without a continent override, edge-cell editing/readiness text, pre-edge desired-state backfill, and pool migration with an unaddressed non-participating edge.
- The pinned Go 1.24 suite and build cover crash-safe enrollment persistence, bounded downloads/decompression, candidate activation, acknowledgement buffering, destination revalidation, origin checks, authenticated cell tasks, and persisted drain restoration.
- Laravel Pint, Python syntax compilation, OpenAPI generation, frontend compilation, and development/production Compose validation are release checks.

Observed cumulative application result:

```text
Tests: 100 passed (773 assertions)
```

The frontend production bundle built without external font downloads. All six distinct production application images built successfully, contain their packaged assets/runtime files, and carry repository linkage metadata; the agent image executes its Go tests as part of the build. Production Compose contains no application build or mutable/local tag fallback and selects every image through one commit-SHA release value.

## Real control-plane and authoritative-routing qualification

Run:

```text
python3 tests/e2e/phase4_control_plane.py
```

The job provisions two desired edges, binds two distinct real client certificates through an ephemeral mTLS edge-control listener, submits fresh dual-stack cell heartbeats, and acknowledges signed artifacts through the agent API. It verifies that PowerDNS publishes only fresh cells through DNSdist for IPv4 and IPv6, administrative drain removes one edge, and undrain restores it. Its assertions select artifacts by disposable domain ID and tolerate unrelated durable ready edges, so the supported persistent development database does not weaken or break the qualification.

It then creates an active domain with different apex/subdomain origins and verifies shared-pool CNAME and apex-safe Lua routing. A move to quarantine must retain source and target in the candidate, wait for both target acknowledgements, publish target DNS, start the drain only after DNS deployment, create a second source-removal artifact, and remain running until both edges acknowledge it. The final placement and DNS answer must use only quarantine.

Observed result:

```text
phase4_control_plane_e2e=passed edges=2 pool_dns=ipv4+ipv6 drain=passed migration=acknowledged
```

## Real mTLS and OpenResty runtime qualification

Run:

```text
python3 tests/e2e/phase4_mtls.py
python3 tests/e2e/phase4_runtime.py
```

The first job verifies that the dedicated edge-control listener rejects a client without a trusted certificate and accepts a signed identity. The runtime job uses real shared, quarantine, and dedicated OpenResty process groups. It verifies HTTP/HTTPS origin routing, repeated mismatched-SNI rejection across workers without cross-origin TLS session reuse, certificate verification, Host/forwarding normalization, IPv4/IPv6 clients, unknown Host/SNI rejection, blocked destinations, passive failures, malformed framing, 2,000 dynamic domains without reload, disabled/tombstoned behavior, cell isolation, and last-valid-state after an invalid candidate.

The same job calls the private authenticated cell-control boundary, verifies drain and undrain, replaces only that cell's workers under a temporary drain, preserves the master process, records restart time, and confirms traffic recovery. It also verifies that an empty passive-failure list is a JSON array accepted by the Go agent. Stopping a sibling cell leaves the shared cell and edge agent running.

Observed results:

```text
Phase 4 edge-control mTLS qualification passed.
Phase 4 OpenResty runtime qualification passed.
```

## Remaining owner acceptance

Coding agents do not launch browser automation. Complete the Phase 4 section of [manual-browser-qualification.md](manual-browser-qualification.md) using two owner-operated reachable edge hosts, record browser and viewport details, and retain operation IDs/revisions. These manual checkboxes remain open until the owner records a passing release run.
