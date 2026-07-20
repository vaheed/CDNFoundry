# CDNFoundry

CDNFoundry is a self-hosted authoritative DNS and private CDN platform for
operators who want predictable failure behaviour and a small operational
footprint. A Laravel modular monolith owns desired state and the administrator
and domain-user panels. DNS and customer traffic stay in PowerDNS/DNSdist and
data-driven OpenResty edge cells; they never pass through Laravel.

The [roadmap](docs/roadmap.md) is the product contract and records verified
implementation status. The [architecture guide](docs/architecture.md) explains
the complete traffic, control, placement, and failure flows in operator terms.

## Core properties

- PostgreSQL is the source of truth for domains, records, origins, settings,
  edge placement, operations, and audit history.
- PowerDNS zones and edge artifacts are revisioned, derived, and rebuildable.
- DNS changes and edge deployment are asynchronous, idempotent, coalesced, and
  preserve the previous valid runtime on failure.
- Domain changes deploy automatically; monotonic revision history is bounded
  without renumbering and retains current state plus recent rollback points.
- DNSdist is the only public authoritative DNS endpoint.
- One generic OpenResty runtime serves many domains without per-domain
  containers, processes, server blocks, reloads, or timers.
- Edge agents verify signed artifacts, activate them atomically, and continue
  serving during control-plane outages.
- Platform policy is typed and stored in PostgreSQL; environment files contain
  only deployment/bootstrap wiring and secrets.

## Quick start

Requirements are Docker Engine with the Compose plugin, GNU Make, at least 8
GiB RAM for the full topology, and the ports listed in the
[installation guide](docs/installation.md).

```sh
git clone https://github.com/vaheed/CDNFoundry.git
cd CDNFoundry
make dev-up
make dev-migrate
make dev-test
```

The administrator panel is `http://localhost:8080/admin`, the domain-user panel
is `http://localhost:8080/app`, and diagnostic-only PowerAdmin is
`http://localhost:9191`. Application startup never runs migrations.

Create edge rows in the administrator panel before enrolling the optional local
agents:

```sh
cp .env.dev.example .env.dev
# Enter the two UI-generated edge IDs and one-time bootstrap tokens.
chmod 600 .env.dev
make dev-edge-up
make dev-edge-status
```

Remove the one-time token values from `.env.dev` after enrollment. Full local
ports, credentials, edge behaviour, and troubleshooting are in the
[development stack guide](docs/development-stack.md).

## DNS proxy behaviour

A proxied hostname has one origin. At the zone apex, CDNFoundry publishes
managed A and AAAA answers for the domain's assigned service pool, so ordinary
apex MX, TXT, CAA, and similar records may remain. A competing apex A, AAAA, or
CNAME must be edited or removed. A proxied subdomain publishes a pool-specific
CNAME such as `pool-1.proxy.cdnf.example`; this is intentional stable placement,
not a missing `proxy.cdnf.example` record.

A service pool is a bounded group of equivalent OpenResty cells and their
public addresses. `shared` is the normal default, `quarantine` isolates risky or
noisy domains, and `dedicated` is an explicit exceptional allocation. See
[origin and proxy policy](docs/origin-and-proxy-policy.md) for the exact model.

## Qualification

```sh
make config-check
make openapi-check
make docs-check
make dev-test
make dev-e2e
make dev-scale-e2e
```

`make dev-e2e` uses the real HTTP APIs, PostgreSQL, queues, PowerDNS, DNSdist,
mTLS edge control, pool migration, Pebble DNS-01 issuance, cache/purge delivery,
and OpenResty HTTP/HTTPS runtime. Browser acceptance is deliberately manual and is specified in
[manual browser qualification](docs/manual-browser-qualification.md). The
[frontend route coverage audit](docs/frontend-route-coverage.md) maps every
application route family to its human, automation-only, or agent-only surface.

Production uses immutable commit-SHA GHCR images through
[`compose.prod.yml`](compose.prod.yml); it has no `latest` tags and no production
host builds. Follow the [production layout](docs/production-layout.md) and
[installation guide](docs/installation.md) before deployment.

## Contributing and security

Contributions are welcome under [CONTRIBUTING.md](CONTRIBUTING.md). Please report
security issues through the private process in [SECURITY.md](SECURITY.md), not a
public issue. CDNFoundry is available under the [MIT License](LICENSE).
