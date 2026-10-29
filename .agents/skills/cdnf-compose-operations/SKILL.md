---
name: cdnf-compose-operations
description: Change CDNFoundry Docker Compose topology, images, profiles, health checks, limits, secrets, or the fleet installer. Use for any change to compose.*.yml, docker/**, deploy/**, or scripts/cdnfoundry_fleet.
---

# cdnf-compose-operations

## Purpose
Keep every role deployable with private internals, explicit migrations, bounded resources, and safe restarts.

## When to use
Changes to `compose.dev.yml`, `compose.prod.yml`, `compose.test.yml`, `docker/**`, `deploy/production/**`, `docker-bake.hcl`, or `scripts/cdnfoundry_fleet/**`.

## Required inputs
The service, its profile (control, dns, edge, telemetry, logs, tools), networks, volumes, ports, limits, health check, and secrets.

## Files normally touched
The Compose files, `deploy/production/Caddyfile*`, `deploy/production/.env.prod.example`, `scripts/cdnfoundry_fleet/render.py`, `scripts/generate-production-env.sh`, `scripts/validate-production-overrides.sh`, `docs/reference/services-and-ports.md`, `docs/reference/configuration.md`.

## Procedure
1. Only DNSdist (53), the edge gateway (80, 443), and control ingress are public. PowerDNS, its API, PostgreSQL, Redis, ClickHouse, and PHP-FPM stay on private networks.
2. Every long-running service has a health check, a restart policy, memory and CPU limits, and bounded logging (`*bounded-logging`). The edge agent, edge gateway, edge-control, Horizon, and scheduler have no health check today (roadmap M11).
3. Migrations run only through the explicit `migrate` and `pdns-migrate` tools services.
4. Publish IPv6 ports wherever IPv4 is published. Production publishes IPv4 only today (roadmap H8).
5. Secrets come from files or env templates and are never committed or printed. Keep `.env.prod.example` placeholders only.
6. Development volumes persist: never `down -v`, and never refresh the dev database.

## Validation commands
- `make config-check` (dev, test, and production example).
- `./scripts/validate-production-overrides.sh`.
- `python3 -m pytest -q tests/fleet/test_fleet.py` (`make fleet-test`) for installer changes.
- Restart qualification: stop and start the changed service and confirm it recovers.

## Definition of done
Config validates for every profile, internals are private, limits and health checks exist, and the docs list every port and variable.

## Stop conditions
Stop if the change needs Kubernetes, a per-domain container, or a public internal service.

## Forbidden shortcuts
No `latest` tags or unpinned images, no removed volumes, and no secrets in the repository or in command output.

## Expected completion summary
Services, profiles, ports, limits, health checks, secrets handling, validation output, and restart evidence.
