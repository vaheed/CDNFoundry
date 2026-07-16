# DNS cluster runbook

DNSdist is the only public authoritative endpoint. PowerDNS DNS and API ports, its PostgreSQL database, and API keys remain private. Control-plane HTTP requests write desired state only; Horizon's `runtime` lane performs reconciliation.

## Runtime schema

PowerDNS schema changes are separate from Laravel migrations. Apply them before starting or upgrading PowerDNS:

```bash
make dev-pdns-migrate
# production, with .env.prod populated
make prod-pdns-migrate
```

The migrations are idempotent. Never rely on container startup to modify an existing PowerDNS database.

## Registering a cluster

Administrators register clusters through `POST /api/admin/dns/clusters`. Supply a private `api_url`, API key, PowerDNS server identifier, location, capacity, and at least two nameserver hostnames. API keys are encrypted at rest and never returned by the API.

Enabling a cluster queues bounded domain reconciliation. Disabling it stops new deployments without deleting its active zones. A failed target is visible independently; successful targets and their last valid snapshots remain active.

## Reconciliation

Record mutations increment the domain revision and enqueue one unique job per domain. The job renders SOA, NS, and desired records deterministically, checks that the revision is still current, and replaces RRsets through the private PowerDNS API. Removed RRsets are explicitly deleted. Deployment rows record desired and deployed revisions, checksum, attempts, timestamps, and bounded errors.

Use `POST /api/domains/{domain}/dns/reconcile` to request an asynchronous retry. Repeated requests coalesce to the same pending operation. Inspect `GET /api/domains/{domain}/dns/deployment` for per-cluster state.

## Drift and recovery

PowerAdmin is diagnostic only. Direct edits are drift and a later reconciliation overwrites them. PostgreSQL control-plane data is authoritative and can rebuild every runtime zone.

When a deployment fails:

1. Confirm the cluster API is reachable only from the runtime worker network.
2. Run the separate PowerDNS runtime migration.
3. Check the deployment's `last_error` without exposing the API key.
4. Correct the cluster or desired record data and request reconciliation.
5. Query DNSdist, not PowerDNS directly, for final qualification.

An invalid or unreachable replacement must never cause deletion of the deployment's recorded last-valid RRsets.
