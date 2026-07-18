# CDNFoundry Platform Roadmap

> **Product:** CDNFoundry  
> **Short name:** CDNF  
> **Architecture:** Laravel modular monolith control plane, PowerDNS authoritative DNS, DNSdist, OpenResty edge runtime, Go edge agent, Vector, ClickHouse, PostgreSQL, and Redis/Valkey  
> **Product rule:** low feature count, low code, simple operations, high stability

---

## Part One — Production-Ready CDN Platform

Part One is the complete delivery roadmap. When every phase and the final acceptance test in this part passes, CDNFoundry is considered a ready, production-qualified CDN and authoritative DNS platform.

Part One is not an MVP. Features listed in Part Two are optional long-term additions and are not required to complete, deploy, sell, or operate the platform defined here.

---

### 1. Product Definition

CDNFoundry is a self-hosted CDN and authoritative DNS platform for companies and local providers that need a private CDN without the size, feature count, or operational complexity of Cloudflare, Akamai, or Fastly.

The system intentionally supports a small set of capabilities. Every capability that is included must be predictable, recoverable, observable, bounded, and safe under production load.

CDNFoundry is not designed around an MVP that will later be replaced. Every phase must leave production-quality code, migrations, APIs, UI, tests, documentation, and operational behaviour for the functionality introduced in that phase. Every phase also ends with a browser E2E flow and a real runtime test for the capability added in that phase.

> Keep management in one understandable application. Keep DNS and user traffic outside that application. Scale by adding runtime capacity, not by redesigning the control plane.

---

### 2. Non-Negotiable Engineering Principles

1. **Laravel is the control plane, never the traffic plane.**  
   DNS queries, HTTP requests, cache reads, security decisions, certificate selection, and log ingestion never pass through Laravel.

2. **PostgreSQL control-plane data is the desired-state source of truth.**  
   PowerDNS runtime databases, edge snapshots, generated bundles, and aggregate caches can be rebuilt from desired state.

3. **Recovery does not rely on PostgreSQL alone.**  
   A valid recovery set also includes the Laravel application encryption key, artifact-signing or transport secrets when used, and any externally stored custom TLS private keys. Losing encryption keys makes encrypted database values unrecoverable.

4. **Every deployed component keeps its last valid state.**  
   Invalid, incomplete, unsigned, corrupted, or obsolete configuration never replaces the active state.

5. **All external side effects are asynchronous.**  
   HTTP requests store desired state and return. PowerDNS updates, edge delivery, ACME, purge, telemetry rollups, backups, and reconciliation run outside the request lifecycle.

6. **Every untrusted or customer-controlled value is bounded.**  
   Record counts, rule counts, JSON size, import size, purge URL count, API payload size, query range, result size, compiler time, local telemetry disk use, and origin-check frequency have explicit limits.

7. **No process, worker, Nginx server block, or container is created automatically for every domain.**  
   Every edge runs a bounded number of identical, isolated OpenResty cells. Domains normally share bounded cells and attacked domains move to quarantine cells. An administrator may reserve a cell for an exceptional high-risk or high-capacity domain, but per-domain runtimes are never the default architecture.

8. **One bad or attacked domain cannot unnecessarily block another domain.**  
   Compilation, validation, reconciliation, deployment, logging, cache storage, origin concurrency, request limits, and runtime placement isolate failures by domain, artifact shard, or edge cell. CDNFoundry provides DDoS readiness and blast-radius reduction, but does not claim volumetric scrubbing when an uplink is saturated.

9. **Rapid changes are coalesced.**  
   Workers always deploy the latest desired revision and skip obsolete revisions.

10. **No component claims unlimited scale.**  
    Qualification results always include hardware, dataset, query pattern, concurrency, and measured limits.

11. **Proxy and origin behaviour is explicit.**  
    Origin destinations, request normalization, forwarding headers, cache keys, protocol limits, and unsafe-address rules use one documented implementation rather than implicit Nginx defaults.

12. **Runtime compatibility is versioned.**  
    Agents, configuration artifacts, database migrations, and control-plane releases declare compatible versions and support a bounded mixed-version upgrade window.

---

### 3. Product Scope

Part One includes:

1. Administrator and domain-user access
2. Domain assignment
3. Domain onboarding and nameserver verification
4. Authoritative DNS record management
5. DNS-only geographic answers by country and continent
6. System-managed geographic edge selection
7. HTTP and HTTPS reverse proxy
8. One origin per proxied hostname
9. Automatic and uploaded TLS certificates
10. Basic cache controls and asynchronous purge
11. Basic IP, CIDR, country, continent, and rate-limit security
12. Request, DNS, cache, origin, TLS, security, deployment, and edge telemetry
13. Bounded operational analytics
14. Usage rollups suitable for export to an external billing system
15. DNS-cluster and edge-node management
16. Isolated OpenResty edge cells and stable domain placement
17. Per-domain DDoS-readiness profiles, quarantine, and emergency controls
18. Reconciliation, rollback, backup, restore, and failure recovery
19. Prometheus metrics, alert rules, and runbooks

Part One does not include:

- Organisations, teams, departments, projects, or reseller hierarchies
- Custom roles, permission builders, or an RBAC package
- Billing, subscriptions, payment collection, or plan enforcement
- Multiple-origin pools, weighted origin load balancing, or traffic splitting
- Workers, serverless functions, tunnels, object storage, or image optimization
- A custom WAF or page-rule expression language
- CAPTCHA, bot scoring, browser challenges, volumetric DDoS scrubbing, or an availability guarantee when the physical uplink is saturated
- Kubernetes as a requirement
- Microservices, Kafka, CQRS, event sourcing, GraphQL, or a service mesh
- Multiple dashboards or a separate SPA frontend
- Per-domain OpenResty configurations or reloads
- A custom monitoring product
- Direct customer editing of OWASP CRS or ModSecurity rules
- DNSSEC unless it is deliberately added as a separately qualified product capability

Anything outside Part One requires a proven customer or operational requirement and belongs in Part Two before implementation.

---

### 4. Scale and Reliability Contract

The architecture must support growth without application redesign by adding:

- Laravel queue workers
- PostgreSQL capacity or replicas
- Independent PowerDNS runtime clusters
- DNSdist capacity
- ClickHouse storage or compute
- Vector buffers
- Edge nodes
- Cache disks
- Object or backup storage

The qualification dataset is:

- At least **500,000 domains**
- At least **1,000,000 DNS records**
- At least **50,000 DNS changes per day**
- A documented burst test of at least **10,000 DNS mutations in a short controlled window**
- Multiple DNS clusters
- Multiple edge locations
- IPv4, IPv6, and dual-stack records and clients
- At least **20,000 domains** with active analytics data
- Rapid repeated updates to the same domain to prove coalescing

Bandwidth and request-per-second claims are hardware-dependent. Every edge benchmark must publish:

- CPU, memory, storage, and NIC
- OpenResty worker count
- Cache configuration
- TLS mode
- Object-size distribution
- Cache-hit ratio
- Request concurrency
- Measured throughput, latency, errors, and resource saturation

Existing DNS and edge traffic must continue during control-plane, queue, or ClickHouse outages.

---

### 5. Access Model

CDNFoundry has exactly two user types.

#### Administrator

An administrator can:

- Access every domain
- Create, edit, disable, and permanently delete eligible users
- Assign users to domains
- Manage DNS clusters and edge nodes
- View global logs, analytics, usage, deployments, and operations
- Manage typed platform settings
- Inspect and retry failed operations
- Run reconciliation, backup, restore, and recovery workflows

#### Domain User

A domain user can manage only assigned domains:

- DNS records
- Proxied hostnames and origins
- TLS mode and uploaded certificates
- Cache settings and purges
- Basic security rules
- Domain logs, analytics, usage, and deployment state

A domain user cannot access other domains, users, DNS clusters, edge nodes, global telemetry, backups, or platform settings.

Implementation uses:

- `users.type`: `admin` or `user`
- `domain_user` pivot table
- Laravel policies
- Policy-aware route model binding
- The same authorization rules for browser sessions and API tokens

No RBAC package is used.

---

### 6. Target Architecture

#### 6.1 Control Plane

- One Laravel modular monolith
- PHP-FPM for browser/API traffic
- Separate CLI containers or processes for Horizon workers and Scheduler
- Filament 5 for administrator and domain-user panels
- PostgreSQL control database as desired-state source of truth
- Redis or Valkey only for queues, locks, rate limits, cache, and temporary coordination
- Laravel Horizon for queue visibility
- Laravel Scheduler for periodic reconciliation and maintenance
- Laravel Sanctum for browser sessions and API tokens
- OpenAPI documentation generated and tested with the API
- Docker Compose for development and production service definitions

There is no second backend, separate Vue application, service-per-module, or direct runtime mutation from Filament pages.

#### 6.2 DNS Plane

Each DNS location contains:

- DNSdist as the only public UDP/TCP DNS endpoint
- One or more private PowerDNS Authoritative instances
- A private PowerDNS runtime PostgreSQL database or an approved replicated runtime database
- Vector or DNSTap collection for DNS telemetry
- Health checks and Prometheus exporters

The control plane stores desired zones. A dedicated DNS reconciler applies validated zone revisions to every enabled DNS cluster and records target-level deployment state.

PowerDNS runtime databases are disposable runtime state. They are not edited by users and are never the control-plane source of truth.

DNS queries never pass through Laravel.

Geographic DNS uses valid ECS information when present and trusted; otherwise it uses the recursive resolver address visible to the authoritative platform. The UI and documentation must not claim that resolver-based location is always the end user's exact location.

#### 6.3 Edge Plane

Each edge location contains:

- A small Go edge agent outside the traffic-runtime resource groups
- A bounded number of identical OpenResty cells
- At least one restricted quarantine cell
- Vector
- Local cache and temporary storage with explicit quotas
- Local configuration and certificate storage
- Prometheus metrics
- One or more public IPv4 and IPv6 service-address pools

An OpenResty cell is a separate container or systemd process group with explicit:

- CPU quota or CPU-set allocation
- Memory limit
- Process and file-descriptor limits
- Worker and connection limits
- Cache and temporary-storage directories
- Cache and disk quotas
- Lua shared-memory zones
- Log and telemetry-buffer limits
- Network bandwidth ceiling where the host platform supports it

All cells run the same image, Lua code, generic listeners, configuration schema, and agent protocol. CDNFoundry does not create a process or configuration per domain.

Every proxied hostname is assigned to exactly one active edge service pool. A service pool maps to one OpenResty cell at each participating edge location and to the public IPv4/IPv6 addresses returned for domains in that pool. Normal domains are distributed using stable placement so adding a domain does not reshuffle unrelated domains.

Placement modes are:

```text
shared
quarantine
dedicated
```

`dedicated` is an administrator-controlled exception for unusually large or high-risk domains. It is not the default and is never created automatically for every domain.

The edge agent:

- Registers once with a one-time bootstrap token
- Pulls immutable manifests, deltas, and full snapshots per cell
- Sends each cell only the domains and certificates assigned to it
- Validates checksum and schema
- Activates state using temporary files and atomic rename
- Retains the previous valid state for every cell
- Reports heartbeat, capacity, active revision, cell health, failures, and origin-check results
- Receives bounded purge, origin-test, placement, quarantine, and emergency tasks
- Continues serving when the control plane is unavailable

Normal domain changes never require a full OpenResty reload. A cell crash, out-of-memory event, cache exhaustion event, or malformed domain configuration must not terminate the edge agent or unrelated cells.

Each cell performs early rejection and bounded local enforcement before expensive Lua, cache, or origin work:

- Unknown HTTP hostnames and TLS SNI names
- Invalid methods and malformed requests
- Header, body, and timeout limits
- Per-client and per-domain request limits
- Per-client and per-domain connection limits
- TLS-handshake limits where supported
- Origin concurrency and retry budgets
- Cache-admission and cache-variant limits
- Restricted, quarantined, and emergency responses

This is DDoS readiness and noisy-neighbour isolation. It does not provide upstream volumetric scrubbing when the edge's physical network capacity is exhausted.

#### 6.4 Telemetry Plane

- DNSdist or PowerDNS emits DNSTap or structured DNS logs
- OpenResty emits structured HTTP, cache, origin, TLS, and security events
- Vector batches and writes directly to ClickHouse
- Vector uses bounded disk buffers and retries
- Vector enriches records using the shared GeoIP database
- ClickHouse stores short-retention raw data and longer-retention aggregates
- Laravel only queries ClickHouse
- An idempotent rollup job writes compact hourly or daily usage totals to PostgreSQL for external billing export
- Telemetry failure never blocks DNS or HTTP traffic

A custom edge log queue is not built. Vector's bounded disk buffer is the edge-side retry mechanism.

#### 6.5 Shared Geo Database

One `mmdb-updater` process per deployment location:

1. Downloads to a temporary file.
2. Validates file format and age.
3. Atomically replaces the active MMDB file.
4. Keeps the previous valid file.
5. Exposes freshness and failure metrics.

PowerDNS geographic answers, OpenResty security, and Vector enrichment use the same country and continent vocabulary.

An unknown or unsupported IPv6 address produces `unknown` geographic classification. It does not crash the request, disable unrelated security rules, or block the platform.

#### 6.6 Deployment Topology

Only these Compose files exist:

- `compose.dev.yml`
- `compose.prod.yml`

`compose.dev.yml` starts the complete local qualification stack:

- Laravel web
- Horizon worker
- Scheduler
- Core PostgreSQL
- Redis/Valkey
- PowerDNS runtime PostgreSQL
- PowerDNS Authoritative
- DNSdist
- Two OpenResty edges
- Two edge agents
- Two Vector instances or one clearly separated local test pipeline
- ClickHouse
- MMDB updater
- HTTP test origin
- HTTPS test origin
- Prometheus
- Alertmanager
- PowerAdmin for development inspection only

PowerAdmin is never a source of truth. Any direct runtime edit is unsupported, is visible as drift, and may be overwritten by reconciliation. Production must either omit PowerAdmin or give it a read-only database user.

`compose.prod.yml` contains production service definitions. Operators start explicit service sets on each host without Compose profiles:

- Control host: application, workers, Scheduler, core PostgreSQL or external database connection, Redis/Valkey
- DNS host: DNSdist, PowerDNS, runtime database adapter, Vector
- Telemetry host: ClickHouse, Vector receiver when used, Prometheus, Alertmanager
- Edge host: edge agent, multiple isolated OpenResty cells, quarantine cell, Vector

Host-to-host endpoints and credentials are configured through validated environment files and private networks.

---

### 7. Core Data Model

Keep the schema small, but do not force unrelated operational data into one table merely to reduce table count.

#### Required Tables

- `users`
- `personal_access_tokens`
- `domains`
- `domain_user`
- `dns_records`
- `domain_configs`
- `tls_certificates`
- `dns_clusters`
- `edge_nodes`
- `edge_service_pools`
- `edge_cells`
- `domain_edge_placements`
- `configuration_revisions`
- `deployments`
- `purge_jobs`
- `operations`
- `backups`
- `usage_rollups`
- `audit_logs`
- `idempotency_keys`
- `system_settings`

Per-edge delivery state is stored in `deployments`. Per-edge purge delivery may also use `deployments` with a purge resource type instead of creating another delivery table.

#### 7.1 Domains

A domain has an explicit lifecycle state:

```text
pending_nameservers
verifying
active
disabled
deprovisioning
failed
```

Important rules:

- Adding a domain never requires an origin.
- Nameserver verification and zone deployment are separate steps.
- Administrator force-verification bypasses only the external nameserver check. It does not bypass validation, reconciliation, or deployment.
- A disabled domain retains desired configuration.
- Deletion is asynchronous and uses a tombstone and delayed runtime removal.
- A deprovisioning or deleted domain cannot be silently force-activated.

#### 7.2 DNS Records

A DNS record has a mode:

```text
dns_only
proxied
geo_dns
```

Common relational fields remain explicit:

- Domain
- Name
- Type
- TTL
- Priority where applicable
- Enabled state
- Mode
- Desired revision metadata

Mode-specific validated JSONB is allowed only for bounded configuration:

- `proxied`: one origin and proxy-host options
- `geo_dns`: default target and country/continent target mappings

The API never accepts arbitrary JSON.

##### `dns_only`

The configured record is published normally.

##### `proxied`

Allowed only for explicitly supported address or hostname record types.

The entered target is the private origin target. Public authoritative answers route clients to CDNFoundry edges and do not expose the origin target.

Each proxied hostname has exactly one origin. Different hostnames in the same domain may have different origins.

##### `geo_dns`

The record returns user-provided DNS targets according to country or continent. It is DNS-only and has no relationship to CDN edge selection, cache, TLS, or HTTP security.

A record cannot be both `proxied` and `geo_dns`.

#### 7.3 Domain Configuration

`domain_configs` has one row per domain and validated sections for domain-wide defaults:

- `proxy`
- `tls`
- `cache`
- `security`

Origin data does not live at domain level because origin ownership is per proxied hostname.

Security rule arrays may remain in validated JSONB while the rule count is small and bounded. If measured requirements later demand indexed relational rules, that migration is a deliberate decision—not pre-emptive architecture.

#### 7.4 System Settings

`system_settings` is typed and allow-listed. It is not a generic editable key-value dump.

Allowed groups include:

- Platform domain
- Nameserver hostnames
- Nameserver IPv4 and IPv6 glue
- SOA defaults
- Platform proxy hostname
- ACME directory and account settings
- Retention defaults
- Safe platform limits
- Feature availability flags for completed capabilities only

High-risk DNS identity changes require validation, preview, explicit confirmation, audit, and an asynchronous operation.

#### 7.5 Edge Cells and Domain Placement

`edge_service_pools` defines platform-managed traffic pools with:

- Name and enabled state
- Placement mode: `shared`, `quarantine`, or `dedicated`
- Public IPv4 and IPv6 service addresses per edge location
- Default capacity limits
- Health and drain state

`edge_cells` represents the concrete OpenResty runtime for one service pool on one edge node and stores:

- Edge node and service pool
- Runtime identity
- Desired and active revision
- Health, drain, and emergency state
- CPU, memory, connection, cache, and storage limits
- Last heartbeat and bounded capacity summary

`domain_edge_placements` stores the current and desired pool assignment for a proxied domain:

```text
service_pool_id
isolation_mode
placement_revision
placement_status
migration_started_at
last_error_code
```

A domain has exactly one active placement. Migration keeps the previous placement serving until target cells have acknowledged valid configuration and certificates and the DNS drain period has completed.

DDoS-readiness settings remain in the validated `security` section of `domain_configs`; they do not require an unbounded rules table or a separate runtime service.

#### 7.6 Revisions and Deployments

Every deployable resource uses:

```text
desired_revision
active_revision
status
last_success_at
last_error_code
last_error_message
```

Allowed deployment states:

```text
pending
deploying
active
failed
disabled
obsolete
```

`configuration_revisions` stores:

- Resource type and ID
- Monotonic revision
- Schema version
- Checksum
- Immutable artifact location when applicable
- Creation actor
- Creation time
- Validation result

`deployments` stores:

- Revision
- Target type and ID
- Delivery status
- Attempt count
- Last attempt
- Applied checksum
- Target-reported revision
- Error code and bounded error details

#### 7.7 Operations

Every long-running API action creates an `operations` row.

Operation states:

```text
queued
running
succeeded
failed
cancelled
```

Operations expose progress as bounded counters rather than unbounded child-job payloads.

---

### 8. One Reconciliation Pattern

DNS, proxy, edge configuration, TLS, cache, security, and purge use the same operational pattern:

1. Authorize the request.
2. Validate and normalize input.
3. Enforce resource and payload limits.
4. Store desired state in PostgreSQL.
5. Increment the relevant desired revision.
6. Write the audit record in the same transaction.
7. Commit.
8. Dispatch one unique reconciliation job after commit.
9. Load the latest desired revision.
10. Exit if the requested revision is obsolete.
11. Compile the complete bounded artifact for the resource.
12. Validate schema and runtime compatibility.
13. Calculate checksum.
14. Deliver to each required target.
15. Apply atomically.
16. Verify target-reported state.
17. Mark target deployments.
18. Mark the resource active only according to its required target policy.
19. Keep the prior valid state for rollback.

Rules:

- A request never waits for PowerDNS, an edge, ACME, ClickHouse, or purge completion.
- Multiple changes to one domain are coalesced.
- A failed target does not erase successful target state.
- Reconciliation is safe to repeat.
- Bulk reconciliation is paginated, bounded, resumable, and deduplicated.
- One invalid domain never blocks a batch containing other domains.
- Rollback creates a new desired revision based on a previously validated revision; it does not mutate history.

---

### 9. Isolation, DDoS Readiness, and Platform Limits

The platform defines configurable maximums for:

- Domains per user
- DNS records per domain
- Bulk operations per request
- Zone-import bytes and record count
- Geo targets per record
- Proxied hostnames per domain
- Domains per shared edge cell
- Shared cells per edge node
- Dedicated cells per edge node
- CPU, memory, file-descriptor, connection, cache, and temporary-storage limits per cell
- Requests per second per client and per domain
- Concurrent connections per client and per domain
- TLS handshakes per domain where measurable
- Concurrent origin requests per hostname
- Origin retries per incoming request
- Cache admissions and variants per hostname
- Origin-test concurrency
- Scheduled origin-health checks
- Security rules per domain
- Security import rows
- TLS hostnames per certificate request
- Purge URLs per request
- API request body bytes
- Raw-log time range
- Analytics time range
- Query result rows
- ClickHouse execution time and memory
- Edge configuration bytes per domain and per cell
- Vector disk-buffer bytes and age
- Audit retention
- Failed operation error size

Every proxied hostname receives safe platform ceilings automatically. A domain user may choose a stricter supported profile or stricter values but cannot raise limits above platform safety ceilings.

The edge node reserves capacity for the operating system, edge agent, configuration activation, health reporting, and telemetry. OpenResty cells must never be allowed to consume all node memory, processes, file descriptors, or disk.

Runtime code must avoid:

- Unbounded Lua loops
- Unbounded regular expressions
- Per-request filesystem scans
- Per-domain timers
- Per-domain workers or containers
- Full edge reloads
- One shared cache or temporary directory without a quota boundary
- Unlimited request-limit state keyed by attacker-controlled values
- Full-zone rewrites caused only by unrelated edge-health changes
- Loading all domains into one PHP process
- Enqueuing one job per record for a bulk change

DDoS-readiness actions use thresholds, cooldowns, and hysteresis. One short spike must not repeatedly move a domain between normal, restricted, and quarantine states.

---

### 10. API Conventions

#### Success

```json
{
  "data": {},
  "meta": {}
}
```

#### Accepted Asynchronous Operation

Return `202 Accepted`:

```json
{
  "data": {
    "operation_id": "uuid",
    "status": "queued"
  },
  "meta": {}
}
```

#### Validation Error

```json
{
  "message": "The provided data is invalid.",
  "code": "validation_failed",
  "errors": {
    "name": ["The name field is required."]
  }
}
```

#### Stable Error

```json
{
  "message": "The operation could not be completed.",
  "code": "dns_revision_failed",
  "details": {}
}
```

#### Shared Operation Endpoints

```text
GET    /api/operations/{operation}
GET    /api/admin/operations
GET    /api/admin/operations/{operation}
POST   /api/admin/operations/{operation}/retry
```

#### Endpoint Rules

- Domain endpoints use policy-aware route binding.
- Administrator endpoints require `users.type = admin`.
- List endpoints use cursor pagination.
- Mutating endpoints support `Idempotency-Key`.
- Reusing an idempotency key with a different request hash returns a conflict.
- External side effects are asynchronous.
- API resources control response shapes.
- Error codes are stable and machine-readable.
- Login, reads, writes, bulk changes, origin tests, purge, analytics, and edge-agent traffic have separate rate limits.
- Bulk endpoints have explicit item and payload limits.
- Secrets, tokens, and private keys are never returned after their one allowed display.
- Browser and API behaviour are covered by the same policies.

---

### Phase 1 — Foundation, Access, and System Identity

#### Goal

Create the complete application foundation, two-user access model, typed system settings, development stack, and operational conventions without introducing feature-specific abstractions.

#### Implementation

##### Backend

- Laravel application with simple feature folders
- PostgreSQL migrations
- Redis/Valkey queues, locks, and rate limits
- Horizon workers
- Scheduler
- Sanctum browser sessions and API tokens
- Administrator and domain-user Filament panels
- User CRUD, disable, token revocation, and domain-assignment-ready policies
- Audit logging for user and administrator mutations
- Idempotency middleware and response storage
- Generic asynchronous operation model
- Liveness, readiness, and administrator dependency status
- Typed platform DNS identity settings
- OpenAPI generation and endpoint contract tests
- Root `AGENTS.md` implemented from Appendix A
- Project-local coding-agent skills implemented from Appendix B

Queue work uses four bounded lanes:

```text
interactive
runtime
certificate_purge
bulk_maintenance
```

Each lane has an explicit worker budget. Large imports and global reconciliation are chunked, per-resource jobs remain unique, and bulk work cannot consume every worker needed for user-visible or serving-critical operations.

Use standard Laravel controllers, form requests, API resources, policies, Eloquent, jobs, commands, events, and notifications. Do not wrap Eloquent in repositories.

##### Development Stack

`compose.dev.yml` starts and health-checks the complete stack even when later phases do not yet use every component.

It includes two test edges, HTTP and HTTPS test origins, DNSdist, PowerDNS, two PostgreSQL databases, ClickHouse, Vector, MMDB updater, Prometheus, Alertmanager, and development-only PowerAdmin.

##### System DNS Identity

The administrator configures:

- Platform domain, such as `cdnf.net`
- Platform proxy hostname, such as `proxy.cdnf.net`
- Nameservers, such as `ns1.cdnf.net` and `ns2.cdnf.net`
- IPv4 and IPv6 glue for every nameserver
- SOA primary name, responsible mailbox, refresh, retry, expire, and minimum TTL
- Default record TTL
- Internal DNS-cluster targets

Changing platform identity requires validation and an audited operation. It is not a normal inline settings save.

#### API

##### Authentication and Profile

```text
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/me
PATCH  /api/me
PUT    /api/me/password
```

##### API Tokens

```text
GET    /api/me/tokens
POST   /api/me/tokens
DELETE /api/me/tokens/{token}
```

##### Administrator Users

```text
GET    /api/admin/users
POST   /api/admin/users
GET    /api/admin/users/{user}
PATCH  /api/admin/users/{user}
POST   /api/admin/users/{user}/disable
POST   /api/admin/users/{user}/enable
DELETE /api/admin/users/{user}
GET    /api/admin/users/{user}/domains
```

Permanent deletion is allowed only after domain assignments are removed and access tokens are revoked.

##### Health, Audit, and Settings

```text
GET    /api/health
GET    /api/ready
GET    /api/admin/system/status
GET    /api/admin/audit-logs

GET    /api/admin/system/settings/dns
PATCH  /api/admin/system/settings/dns
POST   /api/admin/system/settings/dns/validate
```

`/api/health` is process liveness and performs no network dependency checks. `/api/ready` uses short bounded checks. The administrator status endpoint may provide deeper checks but still uses strict timeouts.

#### Filament UX

##### Administrator

- Overview with component state, failed operations, queue state, and recent audit activity
- Users
- System DNS identity
- Operations
- Audit logs

##### Domain User

- Authentication, profile, tokens, and an empty-domain state
- No administrator navigation
- No placeholder feature pages that pretend later phases are complete

Both panels share design components and models. They are responsive and avoid default CRUD pages when a clearer operational page is required.

#### Completion Checklist

Implementation and non-browser qualification evidence: [Phase 1 qualification](phase-1-qualification.md). Browser automation is a manual user-owned release job.

##### Application

- [x] A clean `compose.dev.yml` deployment becomes healthy.
- [x] Migrations run through an explicit deploy command.
- [x] Application startup never silently changes the database schema.
- [x] Web, Horizon, and Scheduler restart independently.
- [x] Horizon access is administrator-only.
- [x] The two Filament panels use the same application and authorization model.
- [x] `compose.prod.yml` documents explicit service-set commands for control, DNS, telemetry, and edge hosts.

##### Access

- [x] `users.type` accepts only `admin` or `user`.
- [x] Administrators can create, edit, disable, enable, and safely delete eligible users.
- [x] Disabled users lose sessions and tokens according to policy.
- [x] API tokens are named, shown once, hashed at rest, and revocable.
- [x] Browser-session and API permission-boundary tests match.

##### Reliability

- [x] Every user or administrator mutation creates an audit entry.
- [x] Every mutating endpoint supports idempotency.
- [x] Key reuse with a different payload is rejected.
- [x] Cursor pagination is used for all lists.
- [x] Queue and Scheduler restart tests pass.
- [x] Bulk work cannot starve interactive, runtime, certificate, or purge work.
- [x] Queue depth and oldest-job age are visible per lane.
- [x] Health checks have bounded timeouts.
- [x] Development PowerAdmin changes are documented as unsupported runtime drift.
- [x] IPv4 and IPv6 platform identity validation both pass.

##### Browser and Real Runtime

The manual browser qualification is a cumulative release contract through the final phase. Every phase that adds or changes a browser menu, form field, diagnostic surface, port, development credential, or operator workflow updates `docs/manual-browser-qualification.md` with ordered steps, safe example values, and expected results. Development PostgreSQL and named Compose volumes persist across phases; automated tests fail closed unless they use the isolated in-memory test database and never refresh or truncate development PostgreSQL.

- [ ] Administrator login, user creation, disable, enable, profile, and token flows pass in a real browser.
- [x] Domain-user navigation cannot expose administrator pages.
- [x] Restarting web, Horizon, and Scheduler does not lose committed user changes.

##### Documentation

- [x] Installation guide
- [x] Development stack guide
- [x] Production service-layout guide
- [x] Authentication and token API documentation
- [x] System DNS identity guide
- [x] Root `AGENTS.md` matches Appendix A and forbids microservices, custom RBAC, CQRS, repositories, synchronous deployment work, and per-domain runtime processes
- [x] The required project-local coding-agent skills from Appendix B exist and pass one representative dry run each

---

#### Phase 1 Completion Appendix

Phase 1 engineering is complete. The application, access model, typed system identity, asynchronous operation foundation, Compose topologies, documentation, repository contracts, and non-UI qualification are implemented.

Automated end-to-end qualification is Python-based and intentionally limited to backend and runtime behavior. `make dev-e2e` exercises the real Compose API, authentication, authorization boundaries, idempotency, token lifecycle, user disable/enable behavior, auditing, queue execution, and asynchronous system DNS identity application. Coding agents must keep this test non-UI.

Rendered UI and browser usability remain project-owner acceptance responsibilities. The owner follows `docs/manual-browser-qualification.md`; coding agents do not install or launch browser automation. This manual responsibility does not block Phase 2 development, but its roadmap checkbox remains open until the owner records a successful release acceptance run.

GitHub Actions runs PHP tests and formatting, OpenAPI contract checks, frontend asset compilation, development and production Compose validation, the Python real-stack E2E, and test/build commands for every Go module present in the repository. The Go job reports a clean no-op until Phase 2 introduces the first module.

Qualification evidence is maintained in `docs/phase-1-qualification.md`.

---

### Phase 2 — Domains and Authoritative DNS

#### Goal

Allow users to add domains without an origin, verify nameservers, manage standard DNS records, and serve real authoritative DNS safely through multiple runtime targets.

#### Implementation

##### Domains

- Domain CRUD
- Domain-user assignment
- Explicit lifecycle state
- Automated nameserver verification
- Administrator force-verification with audit
- Enable, disable, and delayed deprovision
- Canonical domain uniqueness and safe reclaim rules
- Status and deployment visibility

##### DNS

- Standard record CRUD
- Atomic bulk create, update, and delete
- BIND-compatible zone import and export
- Asynchronous import over the synchronous threshold
- DNS cluster management
- Transactional zone reconciliation
- Per-zone monotonic SOA serial handling
- Deployment status per DNS cluster
- IPv4 and IPv6 support from the first record

At this phase, `dns_only` records are production-active. The schema is ready for `geo_dns` and `proxied`, but those modes are not exposed until their phases are complete.

##### DNS Cluster Model

A DNS cluster contains:

- Name and location
- Enabled state
- Internal runtime database or adapter connection
- Nameserver identities served by the cluster
- Last health result
- Last reconciled global revision
- Capacity and operational notes

PowerDNS and its runtime database remain private. DNSdist is the only public endpoint.

#### API

##### Platform Nameservers

```text
GET    /api/nameservers
```

Returns authoritative nameserver hostnames and A/AAAA glue data.

##### Domains

```text
GET    /api/domains
POST   /api/domains
GET    /api/domains/{domain}
PATCH  /api/domains/{domain}
DELETE /api/domains/{domain}

GET    /api/domains/{domain}/status
POST   /api/domains/{domain}/verify-nameservers
POST   /api/domains/{domain}/activate
POST   /api/domains/{domain}/disable
POST   /api/admin/domains/{domain}/force-verify
```

##### Domain Assignment

```text
GET    /api/admin/domains/{domain}/users
POST   /api/admin/domains/{domain}/users
DELETE /api/admin/domains/{domain}/users/{user}
```

##### DNS Records

```text
GET    /api/domains/{domain}/dns/records
POST   /api/domains/{domain}/dns/records
GET    /api/domains/{domain}/dns/records/{record}
PATCH  /api/domains/{domain}/dns/records/{record}
DELETE /api/domains/{domain}/dns/records/{record}

POST   /api/domains/{domain}/dns/records/bulk
POST   /api/domains/{domain}/dns/import
GET    /api/domains/{domain}/dns/export
GET    /api/domains/{domain}/dns/deployment
POST   /api/domains/{domain}/dns/reconcile
```

##### Administrator DNS Clusters and Operations

```text
GET    /api/admin/dns/clusters
POST   /api/admin/dns/clusters
GET    /api/admin/dns/clusters/{cluster}
PATCH  /api/admin/dns/clusters/{cluster}
POST   /api/admin/dns/clusters/{cluster}/test
POST   /api/admin/dns/clusters/{cluster}/disable
POST   /api/admin/dns/clusters/{cluster}/enable

GET    /api/admin/dns/deployments
GET    /api/admin/dns/failed-deployments
POST   /api/admin/dns/reconcile
```

#### DNS Behaviour

- Normalize names to lowercase ASCII/Punycode.
- Preserve a display form only when useful; runtime identity uses normalized names.
- A canonical zone name can exist only once in the control plane.
- Reject public suffixes and invalid registrable-domain inputs.
- A deleted domain can be reclaimed only after deprovisioning and the configured cooldown complete.
- Parent and delegated child zones may coexist only when their zone boundaries and delegation are explicit and valid.
- Validate names, types, values, priorities, TTLs, and zone boundaries.
- Support at least A, AAAA, CNAME, MX, TXT, NS, CAA, SRV, and reverse-zone PTR.
- Prevent illegal CNAME coexistence.
- Prevent duplicate logical records with database constraints.
- Treat an RRset as a unit where record semantics require it.
- Run bulk changes in one control-plane transaction.
- Increment one zone revision for a bulk operation.
- Use one unique reconciliation job per domain.
- Deploy only the latest desired revision.
- Never remove the active runtime zone until a replacement is valid.
- Apply a valid replacement transactionally in each runtime target.
- Keep failed-target state visible without hiding successful targets.
- Use delayed deprovisioning.
- Allow runtime state to be rebuilt entirely from control-plane data.
- Preserve resolver address or trusted ECS information through DNSdist for later geographic decisions.
- Direct PowerAdmin edits are detected as drift and overwritten by reconciliation.

#### Filament UX

##### Domain List

Show only useful information:

- Domain
- Lifecycle state
- Nameserver verification state
- DNS deployment state
- Assigned users for administrators
- Last meaningful change

Do not reserve large cards for simple counts.

##### Add Domain

The workflow is:

1. Enter domain
2. Display required nameservers and glue
3. Verify nameservers
4. Activate managed DNS

No origin is requested.

##### Domain DNS Page

- Cloudflare-like table density and editing flow without copying branding
- Record type, name, content, TTL, and status
- Bulk selection and bulk action
- Import and export
- Deployment status and clear failure reason
- Proxy and Geo-DNS controls remain absent until their phases are complete

#### Completion Checklist

##### Domain Workflow

- [x] A user adds a domain without an origin.
- [x] Duplicate canonical domains, public suffixes, and Punycode collisions are rejected.
- [x] Deleted domains cannot be reclaimed before deprovisioning and cooldown complete.
- [x] Pending nameserver verification is clear.
- [ ] Automated verification works through real public DNS.
- [x] Administrator force-verification is audited.
- [x] Force-verification still uses normal validation and reconciliation.
- [x] Activation and disable actions are idempotent.
- [x] Disabled domains retain desired state.
- [x] Deprovisioning domains cannot be force-activated.
- [x] Deletion produces tombstones for every runtime target.

##### DNS Correctness

- [x] All supported types pass validation and real `dig` tests.
- [x] A and AAAA behaviour is equivalent.
- [x] Bulk updates are atomic.
- [x] One bulk update creates one zone revision.
- [x] Duplicate jobs do not duplicate records or corrupt serials.
- [x] Invalid desired zones do not replace active zones.
- [x] DNSdist is the only public DNS service.
- [x] PowerDNS identity and management endpoints are private.
- [x] Standard BIND import and export round-trip correctly.

##### Scale and Failure

- [x] Seed at least 500,000 zones.
- [x] Load at least 1,000,000 records.
- [x] Process at least 50,000 daily changes.
- [x] Execute the documented burst-mutation test.
- [x] Modify thousands of records in one bounded bulk request.
- [x] Coalesce repeated updates to one domain.
- [x] Restart PowerDNS, DNSdist, queues, and the control plane without zone corruption.
- [x] DNS continues answering while Laravel, PostgreSQL control DB, and Redis/Valkey are unavailable.
- [x] Both IPv4 and IPv6 DNS query paths pass.

##### Browser and Real Runtime

- [ ] Administrator creates a user and assigns a domain in the browser.
- [ ] User adds a domain without an origin and completes real nameserver verification.
- [ ] User creates, edits, bulk-updates, imports, exports, and deletes records in the browser.
- [ ] Real `dig` tests match the state shown in Filament.

##### Documentation

- [x] Domain onboarding guide
- [x] Registrar and glue-record guide
- [x] DNS record reference
- [x] Import/export guide
- [x] DNS cluster runbook
- [x] Runtime drift warning for PowerAdmin

---

### Phase 3 — DNS-Only Geographic Records

#### Goal

Allow a user to create DNS records that return different user-provided targets by country or continent. This feature is independent from CDN edge selection.

#### Implementation

A `geo_dns` record supports every record type that the qualified PowerDNS Lua runtime can synthesize, subject to the same authorization and zone rules as its DNS-only equivalent:

- One required default target set
- Country overrides
- Continent overrides
- A deterministic priority order
- Type-aware answer validation for A, AAAA, CNAME, MX, TXT, NS, SRV, and PTR
- CAA remains DNS-only because the qualified PowerDNS 5.1 Lua runtime returns no synthesized CAA answer
- Bounded target and rule counts
- Preview using a supplied test IP
- Compilation to PowerDNS-supported runtime data or Lua records
- Last-valid-state rollback

No HTTP redirect, edge selection, deny rule, cache rule, or security rule is part of Geo-DNS.

#### API

Geo-DNS records are created through the normal DNS record endpoint with `mode=geo_dns`.

Additional management endpoints:

```text
GET    /api/domains/{domain}/dns/records/{record}/geo
PUT    /api/domains/{domain}/dns/records/{record}/geo
POST   /api/domains/{domain}/dns/records/{record}/geo/preview
```

Example conceptual data:

```json
{
  "default": ["203.0.113.10"],
  "continents": {
    "EU": ["203.0.113.20"]
  },
  "countries": {
    "IR": ["203.0.113.30"],
    "GB": ["203.0.113.40"]
  }
}
```

#### Geographic Behaviour

Evaluation order:

1. Valid trusted ECS country, when supplied
2. Resolver-address country
3. Country override
4. Continent override
5. Default target

The record compiler:

- Uses one shared country and continent vocabulary
- Validates and normalizes every geographic answer using the selected DNS record type
- Keeps MX priority and SRV priority/weight/port fixed on the record while geography selects their target names
- Rejects duplicate or contradictory mappings
- Produces bounded deterministic runtime code or data
- Never makes a runtime network call to Laravel or an external GeoIP API
- Keeps the previous valid artifact when MMDB or compilation fails
- Returns the default target for unknown geography
- Handles unsupported IPv6 classification as `unknown`

#### Filament UX

The DNS record editor exposes Geo-DNS for every record type available to the current user and zone. Existing NS administrator-only and PTR reverse-zone restrictions still apply:

- DNS only
- Geo-DNS

The Geo-DNS editor includes:

- Default answers
- Country rows
- Continent rows
- Duplicate detection
- Test-IP preview
- Clear explanation that location may represent the recursive resolver when ECS is absent

#### Completion Checklist

##### Correctness

- [x] Country overrides win over continent overrides.
- [x] Continent overrides win over default.
- [x] Unknown geography returns default.
- [x] IPv4 and IPv6 targets validate correctly.
- [x] CNAME, MX, TXT, NS, SRV, and PTR geographic answers use type-aware validation and deterministic Lua compilation.
- [x] Unsupported Lua answer types such as CAA remain DNS-only rather than deploying a record that returns NODATA.
- [x] Invalid mappings never replace the active record.
- [x] DNS-only records remain unaffected.

##### Runtime

- [x] DNS makes no call to Laravel or an external GeoIP service.
- [x] MMDB update failure keeps the previous valid file.
- [x] Geo record failure is isolated to its domain.
- [x] Geographic rules remain bounded under malicious input.
- [x] PowerDNS remains private.

##### Real Validation

- [ ] Queries from at least three geographic vantage points return expected targets.
- [x] Trusted ECS tests return expected targets.
- [x] Resolver-only tests document resolver-location limitations.
- [x] IPv6 unknown-classification tests return default instead of failing.
- [ ] Geo-DNS analytics labels use the same country and continent vocabulary.

##### Browser and Real Runtime

- [ ] User creates and previews a Geo-DNS record in the browser.
- [x] Real geographic DNS queries match the previewed fallback order.
- [x] A failed Geo-DNS revision remains visible without affecting normal records.

##### Documentation

- [x] Geo-DNS user guide
- [x] ECS and resolver-location limitation guide
- [x] Supported country and continent code reference
- [x] Troubleshooting guide

---

### Phase 4 — Proxied Hostnames, Edge Routing, and Edge Agent

#### Goal

Proxy real HTTP and HTTPS traffic through independent, resource-isolated edge cells while keeping healthy cells operational during control-plane outages and domain-specific attacks.

#### Implementation

##### Proxied Hostname Model

Proxy is enabled per supported DNS record or hostname.

Each proxied hostname owns exactly one origin:

- Hostname or IP
- Port
- HTTP or HTTPS
- Origin `Host` header
- TLS SNI name
- TLS verification enabled or disabled
- Connect timeout
- Response timeout
- Bounded retry count
- Optional health-check configuration

Different hostnames in one domain may use different origins. There are no origin pools, weighted origins, traffic splits, or fallback origins.

##### Origin Destination Safety

Every origin hostname is resolved and checked before it can become active and again when an edge establishes a connection.

The default policy blocks:

- Loopback, unspecified, link-local, multicast, and broadcast destinations
- Cloud metadata addresses
- Control-plane, DNS, telemetry, agent, and edge-management addresses
- CDNFoundry public service addresses and any target that creates a proxy loop
- Private address ranges unless an administrator has explicitly allow-listed the destination network

Origin redirects are not followed. Hostname results are revalidated after DNS resolution so a public hostname cannot silently rebind to a blocked address.

Private-origin tunnels or connectors are not part of Part One. They are listed in Part Two and require proven customer demand.

##### System Edge Routing

The platform proxy hostname, such as `proxy.cdnf.net`, represents the healthy edge pool.

Edge preference is intentionally simple:

1. Healthy edge in the same country
2. Healthy edge in the same continent
3. Healthy edge in the global pool
4. Deterministic spread among equivalent healthy edges

This is geographic preference, not latency-based global load balancing.

The routing compiler uses:

- Edge enabled state
- Fresh heartbeat
- Edge listener readiness
- IPv4 and optional IPv6
- Country and continent
- Administrative drain state

An edge-health change updates one shared platform routing artifact. It must not rewrite every proxied domain.

Managed proxied hostnames reference their assigned service pool through the selected PowerDNS mechanism. Non-apex CNAME and apex-safe flattening or bounded Lua behaviour may differ internally, but both use the same placement model and do not embed a complete edge list per domain.

##### Edge Service Pools and Cell Placement

Every edge location runs a bounded number of OpenResty cells. Each shared service pool maps to one isolated cell per participating edge and owns one or more public IPv4 and IPv6 service addresses.

Normal domains use stable placement across shared pools. The placement algorithm must minimize reshuffling when domains or cells are added or removed.

A placement migration follows this order:

1. Store the desired target pool.
2. Deliver configuration and certificates to target cells.
3. Validate target readiness.
4. Begin returning target service addresses through DNS.
5. Keep the source placement valid during the DNS transition.
6. Wait for the bounded drain period.
7. Remove the domain from source cells.
8. Mark the placement revision active.

A failed migration keeps the previous placement active.

At least one quarantine pool exists at every edge location. Dedicated pools are optional administrator-created exceptions.

##### Origin Health

Every edge records passive origin health from real requests.

Active checks are:

- On-demand from the user or administrator
- Optionally scheduled only for explicitly enabled proxied hostnames
- Jittered
- Rate-limited
- Bounded per edge and per domain
- Never enabled automatically for every hostname at provider scale

The agent returns status, latency, resolved address, TLS result, HTTP status, and a stable failure reason.

##### Edge Configuration Distribution

The control plane generates:

- Immutable manifests
- Changed-domain artifacts
- Tombstones
- Compressed full snapshots
- Checksums and signatures
- Artifact schema version
- Minimum and maximum compatible agent versions

The one-time bootstrap token is used only to obtain a unique edge identity. Normal agent communication uses mutually authenticated TLS. An edge identity can be revoked and replaced without changing other edges.

The agent:

- Pulls by cursor or sequence
- Downloads only missing artifacts
- Validates signature, checksum, schema, and compatibility
- Builds a candidate local state
- Runs local validation
- Atomically activates
- Keeps the previous snapshot
- Reports applied or rejected state

##### OpenResty Runtime

- One generic configuration and codebase shared by every cell
- Separate OpenResty process group or container per cell
- Dynamic lookup only for domains assigned to that cell
- Dynamic origin selection from local data
- Dynamic TLS certificate selection
- Bounded worker-local LRU or cell-local shared cache
- Dedicated cache and temporary-storage paths with quotas per cell
- No per-domain worker, timer, container, or server block
- No reload for normal configuration changes
- Safe early rejection for unknown or disabled hostnames and unknown TLS SNI
- Strict limits on headers, bodies, timeouts, requests, connections, origin concurrency, retries, and Lua execution
- Cell-local failure, restart, out-of-memory, and cache-exhaustion boundaries

##### Request Normalization and Protocol Limits

Before proxying, every cell applies one fixed request contract:

- Reject conflicting or ambiguous request framing, including invalid `Content-Length` and `Transfer-Encoding` combinations.
- Reject malformed duplicate headers and invalid header names or values.
- Remove hop-by-hop headers before origin proxying.
- Remove client-supplied forwarding headers and generate trusted `Forwarded` or `X-Forwarded-*` values at the edge.
- Use the validated proxied hostname as the canonical request host.
- Permit WebSocket upgrade only when explicitly enabled for that hostname.
- Apply bounded HTTP/2 concurrent-stream, header-list, request-per-connection, and reset-rate limits.
- Keep HTTP/3 disabled until it has separate resource limits and qualification tests.

#### API

##### Domain Proxy Defaults

```text
GET    /api/domains/{domain}/proxy
PATCH  /api/domains/{domain}/proxy
```

Supported defaults:

- Proxy enabled where the record mode permits it
- HTTP-to-HTTPS redirect
- Allowed client HTTP versions
- Origin retry count
- Optional maintenance response

##### Proxied Host Origin

```text
GET    /api/domains/{domain}/dns/records/{record}/origin
PUT    /api/domains/{domain}/dns/records/{record}/origin
POST   /api/domains/{domain}/dns/records/{record}/origin/test
GET    /api/domains/{domain}/dns/records/{record}/origin/health
```

These endpoints require `record.mode=proxied`.

##### Domain Deployment

```text
GET    /api/domains/{domain}/deployment
POST   /api/domains/{domain}/deploy
POST   /api/domains/{domain}/rollback
GET    /api/domains/{domain}/revisions
```

Manual deploy requeues reconciliation of the latest desired revision. Rollback creates a new revision from a previously validated revision.

##### Administrator Edges

```text
GET    /api/admin/edges
POST   /api/admin/edges
GET    /api/admin/edges/{edge}
PATCH  /api/admin/edges/{edge}
DELETE /api/admin/edges/{edge}

POST   /api/admin/edges/{edge}/rotate-identity
POST   /api/admin/edges/{edge}/disable
POST   /api/admin/edges/{edge}/enable
POST   /api/admin/edges/{edge}/drain
POST   /api/admin/edges/{edge}/undrain

GET    /api/admin/edge-deployments
GET    /api/admin/edge-routing
POST   /api/admin/edge-deployments/reconcile

GET    /api/admin/edge-pools
POST   /api/admin/edge-pools
GET    /api/admin/edge-pools/{pool}
PATCH  /api/admin/edge-pools/{pool}
POST   /api/admin/edge-pools/{pool}/enable
POST   /api/admin/edge-pools/{pool}/disable

GET    /api/admin/edge-cells
GET    /api/admin/edge-cells/{cell}
POST   /api/admin/edge-cells/{cell}/drain
POST   /api/admin/edge-cells/{cell}/undrain
POST   /api/admin/edge-cells/{cell}/restart

GET    /api/admin/domains/{domain}/isolation
PATCH  /api/admin/domains/{domain}/isolation
POST   /api/admin/domains/{domain}/move
```

Edge creation returns a one-time bootstrap token that is exchanged for a unique mTLS edge identity. Revoked identities cannot download configuration or submit heartbeats. Pool creation and domain movement are asynchronous, idempotent, revisioned, and audited.

##### Edge Agent

```text
POST   /edge/v1/register
POST   /edge/v1/heartbeat

GET    /edge/v1/config/manifest?cursor={cursor}
GET    /edge/v1/config/artifacts/{checksum}
GET    /edge/v1/config/full
POST   /edge/v1/config/applied
POST   /edge/v1/config/rejected

GET    /edge/v1/tasks?cursor={cursor}
POST   /edge/v1/tasks/{task}/result
```

Tasks include bounded origin tests, purge delivery, cell placement, drain, quarantine, release, and emergency actions.

Every cell heartbeat reports bounded capacity data:

```text
status
active_revision
assigned_domain_count
active_connections
requests_per_second
origin_connections
cpu_usage
memory_usage
cache_usage
temporary_storage_usage
telemetry_buffer_usage
rejected_requests
last_restart_at
```

Only bounded top-N noisy-domain summaries are included in heartbeats; the agent never sends an unbounded metric set for every domain on every heartbeat.

#### Filament UX

##### DNS Page

Supported records gain a proxy toggle only after this phase.

When proxy is enabled:

- The content field is clearly labelled as the private origin target.
- The UI explains that public DNS returns CDNFoundry edge addresses.
- Unsupported DNS types cannot be proxied.
- Proxy and Geo-DNS are mutually exclusive.
- Validation prevents ambiguous CNAME or apex behaviour.

##### Domain Proxy Page

- Global proxy defaults
- Hostname list
- Origin status per hostname
- Last deployment revision
- Failure reason and retry
- Previous validated revisions
- Clear maintenance-mode state

##### Administrator Edge Page

- Country, continent, IPv4, IPv6
- Enabled, drained, or disabled
- Heartbeat freshness
- OpenResty and agent version
- Active configuration revision
- Capacity summary
- Current deployment failures
- Routing-pool membership
- OpenResty cells and assigned domain counts
- CPU, memory, connections, cache, and temporary-storage usage per cell
- Shared, quarantine, and dedicated placement state
- Drain, restart, and domain-move operations

#### Completion Checklist

##### Control Plane

> **Implementation audit (2026-07-18):** Phase 4 remains open. Shared-cell proxying, mTLS distribution, bounded origin handling, and the administrator/domain UI are implemented and agent-qualified. Production service-pool addresses are not yet represented in durable state or authoritative DNS, so moving a domain to quarantine or dedicated placement does not yet move public traffic to that cell. Cell drain/restart tasks are queued and visible, but the unprivileged edge agent intentionally reports `cell_supervisor_unavailable` until a bounded edge-local supervisor boundary is implemented. The browser and two-edge job below also remains user-owned and unrun.

- [x] A user enables proxy per supported hostname.
- [x] Every proxied hostname has exactly one valid origin.
- [x] Different hostnames can use different origins.
- [x] Repeated deploy requests do not duplicate active deployments.
- [x] Rollback creates a new auditable revision.
- [x] Edge-health updates do not rewrite all proxied domains.
- [x] Every proxied domain has exactly one active service-pool placement.
- [ ] Placement migration keeps the previous pool active until target readiness and DNS drain complete.
- [x] Origin tests are asynchronous or strictly bounded.
- [x] Unsafe, internal, metadata, and loop-producing origin destinations are rejected.
- [x] DNS rebinding cannot change an approved public origin into a blocked destination.

##### Edge Agent

- [x] One-time registration exchanges the bootstrap token for a unique mTLS identity.
- [x] Edge identity revocation and replacement work safely.
- [x] Heartbeats report version, capacity, listener health, active revision, and bounded per-cell summaries.
- [x] Incremental artifacts and full snapshots both work.
- [x] Signature, checksum, schema, or version incompatibility rejects the candidate.
- [x] Invalid state never replaces the last valid state.
- [x] Agent restart preserves active state.
- [x] Buffered acknowledgements retry after control-plane recovery.
- [x] A fresh edge recovers from a full snapshot.

##### Runtime

- [x] Real HTTP traffic reaches the correct origin.
- [x] Real HTTPS traffic reaches HTTPS origins with correct SNI and verification.
- [x] Origin `Host` header works.
- [x] IPv4-only, IPv6-only, and dual-stack clients pass.
- [x] Thousands of domains run under one generic OpenResty codebase distributed across bounded cells.
- [x] Normal domain updates cause no OpenResty reload.
- [x] Unknown HTTP hosts and TLS SNI names are rejected before expensive processing.
- [x] Ambiguous request framing, malformed headers, and untrusted forwarding headers are rejected or normalized consistently.
- [x] HTTP/2 stream and connection limits are enforced; HTTP/3 remains disabled.
- [x] Unknown, disabled, and deprovisioned hosts return defined responses.
- [x] One malformed domain configuration does not affect other domains.
- [x] One cell crash or out-of-memory event does not terminate other cells or the edge agent.
- [x] Cache or temporary-storage exhaustion in one cell does not fill the edge filesystem.
- [ ] Shared, quarantine, and dedicated placements behave consistently for IPv4 and IPv6.
- [x] Edge serves traffic while Laravel, PostgreSQL control DB, Redis/Valkey, and ClickHouse are offline.
- [x] Adding an edge requires only installation, registration, and health qualification.

##### Origin Health

- [x] Passive failures are reported.
- [x] On-demand tests run from selected edges.
- [x] Scheduled checks are opt-in, jittered, and bounded.
- [x] Latency and stable failure codes reach the control plane.
- [x] A large domain count cannot create an unbounded health-check storm.

##### Browser and Real Runtime

- [ ] User enables proxy and configures different origins for apex and subdomain hostnames.
- [ ] Real HTTP and HTTPS requests traverse both registered edges.
- [ ] Administrator drains one edge and observes routing and deployment state change.
- [ ] A rejected edge bundle is visible in Filament while prior traffic continues.

##### Documentation

- [x] Edge installation guide
- [x] Edge registration, identity revocation, and replacement guide
- [x] Origin configuration and unsafe-destination policy guide
- [x] Request normalization and forwarding-header contract
- [x] Proxy DNS behaviour guide
- [x] Edge drain and failure runbook
- [x] Full-snapshot recovery guide
- [x] Edge-cell sizing and service-pool placement guide
- [x] Cell crash, cache exhaustion, and quarantine runbook

---

### Phase 5 — TLS, Cache, and Purge

#### Goal

Provide automatic HTTPS and predictable cache behaviour with a small, understandable settings surface.

#### Implementation

##### Managed TLS

CDNFoundry queues managed TLS when the first hostname in an active, nameserver-verified domain becomes proxied.

The default managed order covers:

- The apex name
- One wildcard level

DNS-only domains do not consume certificate orders, renewal work, or edge certificate storage. No fake or hidden apex A/AAAA record is created.

DNS-01 uses temporary `_acme-challenge` runtime records. Those records are internal operational state and are removed or expired safely after validation.

Rules:

- DNS service remains active even when certificate issuance is pending or failed.
- ACME work is queued, rate-limited, jittered, retryable, and constrained by a global order budget.
- A valid stored certificate is reused instead of issuing a duplicate order.
- The existing valid certificate remains active during renewal failure.
- Deeper proxied hostnames that are not covered by the wildcard trigger a supplemental certificate request.
- Managed certificates may remain prepared even when HTTPS redirect is disabled.
- Custom certificate upload can replace active serving mode without deleting the managed fallback until policy permits.
- Private keys are encrypted in the control database and protected on edge filesystems.
- Optional certificate pre-issuance and a secondary CA are not part of Part One. They are listed in Part Two.

##### TLS Modes

```text
managed
custom
disabled
```

`disabled` controls edge HTTPS behaviour. It does not require destroying an already prepared managed certificate.

##### Cache

Support only:

- Cache enabled
- Default edge TTL
- Browser TTL
- Maximum object size
- Respect origin cache headers
- Include query string in cache key
- Bypass cache for configured cookie names
- Stale-if-error duration
- Development mode

Development mode bypasses cache for the whole domain and has a visible automatic expiry. It is not a permanent hidden boolean.

##### Purge

- Full-domain purge by cache epoch
- URL purge by exact normalized cache keys
- Asynchronous per-edge delivery
- Idempotent retries
- Bounded URL count and payload size
- Visible per-edge state

Full purge never scans cache directories.

#### API

##### TLS

```text
GET    /api/domains/{domain}/tls
GET    /api/domains/{domain}/tls/status
PATCH  /api/domains/{domain}/tls
POST   /api/domains/{domain}/tls/reissue
POST   /api/domains/{domain}/tls/renew
POST   /api/domains/{domain}/tls/upload
DELETE /api/domains/{domain}/tls/custom-certificate
```

##### Cache

```text
GET    /api/domains/{domain}/cache
PATCH  /api/domains/{domain}/cache

POST   /api/domains/{domain}/cache/development-mode
DELETE /api/domains/{domain}/cache/development-mode

POST   /api/domains/{domain}/cache/purge
GET    /api/domains/{domain}/cache/purges
GET    /api/domains/{domain}/cache/purges/{purge}
```

Example purge requests:

```json
{
  "type": "all"
}
```

```json
{
  "type": "urls",
  "urls": [
    "https://example.com/app.css",
    "https://example.com/logo.png"
  ]
}
```

#### TLS Behaviour

Uploaded certificate validation includes:

- Parseable certificate and private key
- Matching public and private key
- Valid chain according to configured policy
- Required hostname coverage
- Not-before and expiry validation
- Supported algorithm and key size
- Bounded chain and payload size

Certificate bundles are part of normal edge revision delivery and atomic activation.

#### Cache Behaviour

- Cache key includes scheme policy, canonical host, normalized path, and configured query handling.
- Request and purge code use the same cache-key implementation.
- Only `GET` and `HEAD` are cacheable; other methods bypass cache.
- Requests with `Authorization` bypass cache.
- Responses with `Set-Cookie`, `Cache-Control: private`, or `Cache-Control: no-store` are not stored.
- `Vary: *` bypasses cache; other `Vary` values are accepted only from a bounded allow-list.
- Range requests, negative responses, and redirects bypass cache in the initial implementation.
- Query parameters are not silently reordered or decoded differently between request and purge paths.
- Client-supplied forwarding headers never participate in the cache key.
- Maximum object size is enforced without unsafe memory buffering.
- Stale content is served only within the configured stale window.
- Cache state emits stable values: `HIT`, `MISS`, `BYPASS`, `EXPIRED`, `STALE`.
- Development mode bypass is evaluated before normal cache rules.

#### Completion Checklist

##### TLS

- [ ] Managed apex and wildcard issuance starts when the first hostname becomes proxied after nameserver verification and activation.
- [ ] DNS-only domains do not consume ACME orders or edge certificate storage.
- [ ] Duplicate valid certificates are reused instead of reissued.
- [ ] No fake hidden apex record is created.
- [ ] DNS-01 works without sending user traffic to the origin.
- [ ] Renewal jobs are jittered and safely retry.
- [ ] CA rate-limit and validation errors are visible.
- [ ] Deep proxied hostnames receive supplemental coverage when needed.
- [ ] Custom upload validates key, chain, names, algorithm, size, and expiry.
- [ ] Private keys are encrypted at rest.
- [ ] Existing certificates continue serving during control-plane outage.
- [ ] Expiring and failed certificates generate administrator alerts.

##### Cache

- [ ] HIT, MISS, BYPASS, EXPIRED, and STALE are correct and logged.
- [ ] Origin cache headers are respected when enabled.
- [ ] Query-string and cookie behaviour match configured policy.
- [ ] Authorization, Set-Cookie, private/no-store, Vary, Range, redirect, and negative-response defaults match the documented cache contract.
- [ ] Maximum object size is enforced safely.
- [ ] Stale content is served during configured origin failure.
- [ ] Development mode bypasses the entire domain and expires automatically.
- [ ] Cache settings roll back through normal revisions.

##### Purge

- [ ] Full purge increments the cache epoch without scanning files.
- [ ] URL purge uses the exact runtime cache-key implementation.
- [ ] Every healthy edge receives the purge.
- [ ] Per-edge state is visible.
- [ ] Repeating the same request is safe.
- [ ] Failed edge delivery retries without a second user-visible purge.
- [ ] Purge backlog cannot grow without bounds.

##### Browser and Real Runtime

- [ ] Managed TLS status progresses from queued to active in the browser.
- [ ] HTTPS works with the dynamically selected certificate.
- [ ] Cache MISS, HIT, development-mode BYPASS, full purge, and URL purge are visible and verified with real requests.

##### Documentation

- [ ] Managed TLS lifecycle guide
- [ ] Custom certificate guide
- [ ] ACME failure guide
- [ ] Cache semantics guide
- [ ] Development-mode guide
- [ ] Purge troubleshooting guide

---

### Phase 6 — Basic Security and DDoS Readiness

#### Goal

Provide understandable request protection, origin protection, noisy-neighbour isolation, quarantine, and emergency controls without claiming to be a volumetric scrubbing or advanced WAF platform.

The objective is:

> Reject abusive traffic early, protect edge resources, protect origins, isolate attacked domains, and keep healthy domains serving whenever edge capacity remains available.

#### Implementation

##### Basic Security Rules

- IPv4 and IPv6 address allow/block rules
- IPv4 and IPv6 CIDR allow/block rules
- Country and continent allow/block rules
- Safe allowed-method policy
- Maximum request-body size
- Basic malformed-request rejection
- Trusted client-IP configuration for deployments behind an approved L4 balancer
- Stable security reason codes
- Local edge enforcement

A security rule supports:

```text
match_type: ip | cidr | country | continent
action: allow | block
priority: integer
note: optional bounded string
```

Evaluation is deterministic:

1. Enabled rules sorted by ascending priority
2. Stable ID as tie-breaker
3. First matching rule wins
4. Default action is allow

##### DDoS-Readiness Profiles

Each proxied hostname uses one platform-managed profile:

```text
standard
protected
quarantine
```

`standard` provides safe normal limits. `protected` lowers expensive limits and increases origin protection during suspicious traffic. `quarantine` applies strict limits, may use cache-only or stale-first behaviour, and may move the domain into the quarantine edge pool.

Users may choose supported profiles and stricter values within platform-defined ranges. Users cannot disable or raise platform safety ceilings.

##### Host and Network Readiness

Every edge node must:

- Expose only required public services
- Keep agent and administrative endpoints private
- Enable and validate Linux TCP SYN-cookie fallback
- Configure bounded listen backlogs and connection tracking appropriate to measured capacity
- Reserve operating-system, edge-agent, health, configuration, and telemetry resources
- Apply explicit cgroup or container limits to every OpenResty cell
- Support emergency withdrawal of one service IP from DNS
- Support optional host-firewall new-connection ceilings where operationally appropriate

These controls improve resilience but do not replace upstream scrubbing when a circuit is saturated.

##### Early Request Rejection

Reject or terminate before expensive Lua, cache, certificate, or origin work:

- Unknown HTTP hostnames
- Unknown TLS SNI names
- Disabled or quarantined domains
- Invalid or unsupported methods
- Malformed protocol syntax or paths
- Oversized headers or bodies
- Header and body read timeouts
- Excessive idle or keep-alive usage

Long-lived protocols such as WebSocket remain disabled unless explicitly enabled for a hostname.

##### Per-Client and Per-Domain Limits

Each proxied hostname receives bounded settings for:

```text
requests_per_second
request_burst
connections_per_client
connections_per_domain
tls_handshakes_per_second
maximum_request_body_size
maximum_header_size
client_header_timeout
client_body_timeout
keepalive_timeout
maximum_requests_per_connection
maximum_request_duration
```

Request and connection controls use both keys:

```text
domain_id + client_ip
domain_id
```

State zones are fixed-size and bounded. Attacker-controlled IPs, hostnames, URLs, or query strings must not create unlimited Lua or shared-memory entries.

##### Origin Protection

Every proxied hostname has:

```text
origin_max_connections
origin_connect_timeout
origin_read_timeout
origin_send_timeout
origin_retry_limit
origin_failure_threshold
origin_recovery_timeout
```

The edge limits simultaneous origin requests and uses a bounded circuit breaker. When origin capacity is exhausted or the circuit is open, the edge may serve cached or stale content or return a controlled `429`, `502`, or `503` response.

One incoming request must never be amplified into unbounded origin retries.

##### Protocol-Level Ceilings

HTTP/1 and HTTP/2 use explicit connection, request, header, and timeout ceilings. HTTP/2 additionally limits concurrent streams, requests per connection, header-list size, and excessive stream resets. HTTP/3 remains disabled in this roadmap.

##### Slow-Client and Cache-Abuse Protection

The edge enforces bounded header, body, idle, keep-alive, request-duration, and upstream timeouts.

Cache protection includes:

- Maximum cacheable object size
- Cache and temporary-file quota per cell
- Cache-admission ceiling
- Maximum cache-key length
- Bounded query-string and cookie variants
- Bypass or reject policy for suspicious high-cardinality query strings
- Optional cache-only or stale-first emergency mode

Random URLs or query strings must not fill the cache indefinitely.

##### Automatic Readiness States

A proxied domain has one operational security state:

```text
normal
suspected
restricted
quarantined
recovering
```

Signals may include request rate, connection rate, TLS handshake rate, rejection rate, attributed cell CPU, origin concurrency, origin failures, cache-admission rate, temporary-storage growth, and unique URL/query-string growth.

Transitions use thresholds, cooldowns, and hysteresis. A single short spike cannot cause repeated movement between cells.

Supported quarantine policy:

```text
manual
automatic
automatic_with_admin_notification
```

Automatic detection may apply `restricted` immediately. Full quarantine behaviour is controlled by the configured policy.

##### Emergency Mode

Emergency mode can target:

- One hostname or domain
- One edge cell
- One service IP or service pool
- One complete edge node

Supported emergency actions include:

```text
reject_unknown_hosts
disable_request_bodies
allow_get_head_only
reduce_keepalive
reduce_origin_concurrency
disable_origin_retries
serve_cache_only
serve_stale_only
return_maintenance_response
quarantine_domain
withdraw_service_ip_from_dns
```

Emergency actions are asynchronous, revisioned, idempotent, audited, and expire automatically unless explicitly made permanent.

##### Stable Security Reasons

```text
unknown_host
unknown_sni
invalid_method
malformed_request
header_too_large
body_too_large
header_timeout
body_timeout
client_rate_exceeded
domain_rate_exceeded
client_connections_exceeded
domain_connections_exceeded
tls_handshake_rate_exceeded
origin_capacity_exceeded
origin_circuit_open
cache_abuse_detected
domain_restricted
domain_quarantined
edge_emergency_mode
```

Telemetry failure never prevents enforcement.

No custom expression language, CAPTCHA, bot score, browser challenge, ModSecurity rule editor, or volumetric DDoS scrubbing is included.

#### API

##### Security Settings

```text
GET    /api/domains/{domain}/security
PATCH  /api/domains/{domain}/security
```

##### Security Rules

```text
GET    /api/domains/{domain}/security/rules
POST   /api/domains/{domain}/security/rules
PATCH  /api/domains/{domain}/security/rules/{rule}
DELETE /api/domains/{domain}/security/rules/{rule}
POST   /api/domains/{domain}/security/rules/import
```

##### DDoS Readiness

```text
GET    /api/domains/{domain}/security/ddos
PATCH  /api/domains/{domain}/security/ddos
GET    /api/domains/{domain}/security/ddos/status
GET    /api/domains/{domain}/security/ddos/events
```

##### Administrator Isolation and Emergency Operations

```text
POST   /api/admin/domains/{domain}/restrict
POST   /api/admin/domains/{domain}/quarantine
POST   /api/admin/domains/{domain}/release

POST   /api/admin/edges/{edge}/emergency-mode
DELETE /api/admin/edges/{edge}/emergency-mode
POST   /api/admin/edge-cells/{cell}/emergency-mode
DELETE /api/admin/edge-cells/{cell}/emergency-mode
POST   /api/admin/edge-pools/{pool}/withdraw
POST   /api/admin/edge-pools/{pool}/restore
```

##### Security Events

```text
GET    /api/domains/{domain}/security/events
```

#### Runtime Behaviour

- Validate IPv4 and IPv6 CIDR notation.
- Use the shared country and continent vocabulary.
- Compile security and readiness settings into the normal cell-specific domain bundle.
- Enforce request and connection limits with bounded edge-local state.
- Do not depend on control-plane Redis for request decisions.
- Apply header and body limits before unsafe buffering.
- Limit origin concurrency before opening an upstream connection.
- Keep retries and circuit-breaker state bounded.
- Record stable reason codes.
- Treat unknown geography as no geographic-rule match unless an explicit future `unknown` rule is supported.
- Continue evaluating IP and CIDR rules when GeoIP classification is unavailable.
- Fail open for telemetry failure, not for invalid configuration.
- Reject invalid security configuration before deployment.
- Enforce rule-count, compiled-size, counter-state, and profile limits.
- Keep the previous valid profile and placement when deployment or quarantine migration fails.

#### Filament UX

##### Domain Security

- One simple allow/block rule table
- Type, value, action, priority, and note
- Import preview before commit
- Current DDoS-readiness profile
- Platform ceilings and user-configurable stricter values
- Request, connection, origin, timeout, body, and cache limits
- Current operational state: normal, suspected, restricted, quarantined, or recovering
- Recent block and readiness events linked to stable reason codes
- No visual expression builder

##### Administrator DDoS Readiness

- Noisy-domain candidates with bounded top-N metrics
- Current domain placement and cell
- Restrict, quarantine, release, and move actions
- Cell CPU, memory, connections, origin concurrency, cache, disk, and rejection rate
- Active emergency modes and automatic expiry
- Service-pool withdraw and restore controls

#### Completion Checklist

##### Rules

- [ ] IPv4, IPv6, CIDR, country, and continent values validate.
- [ ] Priority and tie-breaking are deterministic.
- [ ] Large imports create one desired revision.
- [ ] Rules deploy and roll back through the normal pipeline.
- [ ] Security and analytics use the same geographic vocabulary.
- [ ] Unknown IPv6 geography does not break the domain.

##### Early Rejection and Limits

- [ ] Unknown HTTP hosts and TLS SNI names are rejected before expensive processing.
- [ ] One client IP cannot consume every connection assigned to a domain.
- [ ] One domain cannot consume every request or connection slot in a cell.
- [ ] Per-client and total-domain request limits work together.
- [ ] Fixed-size limit zones cannot grow without bound from random IPs or hostnames.
- [ ] Maximum header and body sizes reject before unsafe buffering.
- [ ] Slow-header, slow-body, idle, and keep-alive attacks release resources within configured timeouts.
- [ ] HTTP/2 stream, header, request-per-connection, and reset-rate limits remain bounded under attack.
- [ ] Disallowed methods return the defined response.

##### Origin and Cache Protection

- [ ] Origin concurrency remains bounded during a request flood.
- [ ] Origin retries do not amplify incoming attacks.
- [ ] The origin circuit breaker serves cached, stale, or controlled error responses.
- [ ] Random URLs and query strings cannot fill cache storage indefinitely.
- [ ] Cache and temporary-storage exhaustion in one cell does not affect other cells.

##### Isolation and Emergency Operations

- [ ] Restricted mode does not reduce limits for unrelated domains.
- [ ] A quarantined domain moves only after target-cell readiness.
- [ ] Failed quarantine migration keeps the previous valid placement.
- [ ] A quarantined domain does not restart unrelated cells.
- [ ] One cell out-of-memory or crash event does not stop other cells or the agent.
- [ ] Emergency mode can target a domain, cell, pool, service IP, or edge.
- [ ] Emergency mode expires automatically when configured.
- [ ] Service-IP withdrawal removes only the affected pool from new DNS answers.
- [ ] IPv4 and IPv6 follow equivalent readiness and placement rules.

##### Events and Availability

- [ ] Every rejection emits a stable reason code.
- [ ] Telemetry failure does not interrupt protection or traffic.
- [ ] Protection rules continue while Laravel, PostgreSQL, Redis/Valkey, and ClickHouse are unavailable.
- [ ] Bounded top-N noisy-domain metrics do not create per-domain heartbeat explosions.
- [ ] Legitimate traffic qualification measures false positives for standard and protected profiles.

##### Scope

- [ ] No CAPTCHA, bot score, challenge, custom WAF language, or volumetric scrubbing exists.
- [ ] No customer-editable ModSecurity or OWASP CRS rule surface exists.
- [ ] No runtime security decision calls Laravel or ClickHouse.
- [ ] Documentation clearly states that physical uplink saturation requires upstream mitigation.

##### Browser and Real Runtime

- [ ] User changes between standard and protected profiles within platform ceilings.
- [ ] Administrator restricts, quarantines, releases, and moves a domain in the browser.
- [ ] Real IPv4, IPv6, rate, connection, body-size, timeout, origin-capacity, and country tests match displayed events.
- [ ] Cache-abuse and random-query tests remain inside disk and memory limits.
- [ ] A simulated attacked domain does not interrupt traffic for a domain in another cell.
- [ ] Invalid readiness deployment leaves prior rules and placement active.

##### Documentation

- [ ] Security-rule and ordering guide
- [ ] DDoS-readiness profile and platform-ceiling guide
- [ ] Request, connection, timeout, and origin-limit semantics
- [ ] Trusted client-IP deployment guide
- [ ] Security and DDoS reason-code reference
- [ ] Quarantine and recovery runbook
- [ ] Edge emergency-mode runbook
- [ ] Explicit volumetric-attack scope boundary

---

### Phase 7 — Logs, Analytics, and Usage Export

#### Goal

Give users and administrators enough visibility to operate domains, diagnose failures, and export usage without building a general business-intelligence or billing platform.

#### Implementation

##### Input Streams

DNS stream:

- DNS queries
- Response codes
- Query type
- Zone/domain
- Resolver or trusted ECS address
- DNS cluster
- Geographic classification
- Processing outcome

Edge stream:

- HTTP requests
- Bytes in and out
- Status code
- Cache outcome
- Origin latency and error
- TLS error
- Security event and reason
- Edge identity
- Client IP and geography
- Deployment and health events

##### ClickHouse Storage

Use separate raw event tables where schemas and retention differ materially, plus materialized views or scheduled aggregates for:

- Requests
- Bandwidth
- Status codes
- Cache ratio
- Top URLs
- Top hostnames
- Top client IPs
- Countries and continents
- Origin latency and errors
- Traffic per edge
- DNS query volume, types, and response codes
- Security blocks
- TLS failures

Raw retention is short and configurable. Hourly and daily aggregates have longer retention.

##### Edge and DNS Buffering

Vector:

- Buffers to bounded local disk
- Batches writes
- Retries with backoff
- Exposes buffer bytes, age, dropped events, and delivery failures
- Drops according to an explicit oldest/newest policy only after the configured hard limit
- Never consumes unlimited disk
- Never blocks OpenResty or DNS processing

No second custom log spooler is added.

##### Telemetry Privacy and Redaction

Telemetry uses a fixed safe schema:

- Never store authorization headers, cookies, API tokens, TLS private material, or request bodies.
- Store URL paths with bounded length; query strings are omitted by default or redacted by an allow-listed policy.
- Sanitize control characters and bound user-agent, referrer, hostname, and error-message lengths.
- Keep raw client IP retention short and apply the configured masking policy in normal views and exports.
- Domain deletion follows a documented desired-state and telemetry-retention policy; ClickHouse data is not silently assumed to disappear immediately.

##### Usage Rollups

An idempotent scheduled operation:

1. Reads finalized ClickHouse intervals.
2. Calculates per-domain request, bandwidth, cache, and DNS usage.
3. Writes compact rollups to PostgreSQL.
4. Uses a unique domain and interval key.
5. Can safely rebuild a missing interval.
6. Exposes finalized and provisional status.
7. Provides JSON and CSV export for an external billing service.

The rollup is reporting data, not request-path enforcement.

#### API

##### Domain Analytics

```text
GET    /api/domains/{domain}/analytics/summary
GET    /api/domains/{domain}/analytics/timeseries
GET    /api/domains/{domain}/analytics/status-codes
GET    /api/domains/{domain}/analytics/cache
GET    /api/domains/{domain}/analytics/countries
GET    /api/domains/{domain}/analytics/hostnames
GET    /api/domains/{domain}/analytics/top-urls
GET    /api/domains/{domain}/analytics/origin
GET    /api/domains/{domain}/analytics/edges
GET    /api/domains/{domain}/analytics/dns
```

##### Domain Logs

```text
GET    /api/domains/{domain}/logs/requests
GET    /api/domains/{domain}/logs/dns
GET    /api/domains/{domain}/logs/errors
GET    /api/domains/{domain}/logs/security
```

##### Domain Usage

```text
GET    /api/domains/{domain}/usage
GET    /api/domains/{domain}/usage/export
```

##### Administrator Analytics and Logs

```text
GET    /api/admin/analytics/summary
GET    /api/admin/analytics/traffic
GET    /api/admin/analytics/dns
GET    /api/admin/logs/errors
GET    /api/admin/logs/security
GET    /api/admin/logs/edges
```

##### Administrator Usage

```text
GET    /api/admin/usage
GET    /api/admin/usage/export
POST   /api/admin/usage/rebuild
```

#### Query Behaviour

- Every endpoint requires or applies an explicit time range.
- Raw-log ranges are stricter than aggregate ranges.
- Raw logs use cursor pagination.
- Summary endpoints use aggregates.
- Domain ID and authorization boundaries are present in every query.
- Sort fields, filters, and groupings are allow-listed.
- ClickHouse queries use execution-time, memory, read-row, and result-row limits.
- Queries are cancelled on client disconnect where practical.
- High-cardinality fields are not used carelessly in aggregate keys.
- IP display and export follow the configured privacy policy.
- ClickHouse failure returns an explicit analytics-unavailable response and never affects traffic.

#### Filament UX

##### Domain

- Summary
- Request and bandwidth timeseries
- Status codes
- Cache ratio
- Countries and continents
- Hostnames
- Top URLs
- Origin health and latency
- Edge distribution
- DNS activity
- Raw request, DNS, error, and security logs
- Usage export

##### Administrator

- Global traffic and DNS
- Edge health and deployment events
- Global errors and security
- Telemetry buffer and ingestion status
- Usage finalization and export

Charts never hide their time range, sampling, units, or partial-data state.

#### Completion Checklist

##### Pipeline

- [ ] DNS telemetry reaches ClickHouse directly through Vector.
- [ ] Edge telemetry reaches ClickHouse directly through Vector.
- [ ] Laravel, core PostgreSQL, and Redis/Valkey never ingest raw traffic logs.
- [ ] Disk buffering survives a temporary ClickHouse outage.
- [ ] Disk buffering has a hard byte and age limit.
- [ ] Dropped events are measured and alerted.
- [ ] IPv4 and IPv6 enrichment works or returns `unknown`.
- [ ] Authorization, cookies, tokens, private keys, and request bodies never appear in telemetry.
- [ ] URL, query, user-agent, referrer, and error fields are bounded and sanitized.
- [ ] Telemetry overload cannot exhaust edge or DNS-host storage.

##### Access and Accuracy

- [ ] Domain users see only assigned domains.
- [ ] Administrators can query global data.
- [ ] Requests, bandwidth, status, cache, hostname, country, edge, and DNS totals match generated traffic.
- [ ] Origin latency and failures identify unhealthy origins.
- [ ] Raw and aggregate queries use consistent domain and time boundaries.
- [ ] Usage rollups are idempotent.
- [ ] Missing usage intervals can be rebuilt.
- [ ] JSON and CSV usage exports are stable for external billing consumers.

##### Failure and Performance

- [ ] ClickHouse restart does not affect DNS, proxy, cache, TLS, or security.
- [ ] Query limits protect ClickHouse and Laravel.
- [ ] Expensive filter combinations are rejected or bounded.
- [ ] Analytics remains responsive across the 20,000-domain qualification dataset.
- [ ] Partial or delayed telemetry is visibly labelled.
- [ ] Vector recovery drains backlog without starving live traffic.

##### Browser and Real Runtime

- [ ] Generated DNS and HTTP traffic appears in domain analytics and logs.
- [ ] Domain and administrator views enforce different scopes.
- [ ] Usage export matches generated traffic and remains stable after rebuilding an interval.
- [ ] ClickHouse interruption is shown as analytics unavailable while traffic continues.

##### Documentation

- [ ] Analytics field and unit reference
- [ ] Log schema reference
- [ ] Retention guide
- [ ] Telemetry-loss semantics
- [ ] Telemetry privacy, redaction, IP masking, and deletion semantics
- [ ] Usage export contract
- [ ] ClickHouse outage runbook

---

### Phase 8 — Operations and Production Qualification

#### Goal

Make the completed platform easy to deploy, observe, recover, and expand without changing its architecture.

#### Implementation

##### Operations

- Component and dependency health
- Failed operation inspection and retry
- DNS, edge, TLS, purge, and usage reconciliation
- Edge stale-revision detection
- DNS-cluster drift detection
- Certificate expiry checks
- Backup and restore
- Audit retention
- Graceful startup and shutdown
- Prometheus metrics
- Alertmanager rules
- Runbooks
- Mixed-version upgrade and rollback qualification
- Clock synchronization and drift monitoring
- Load, restart, failure, and recovery qualification

##### Upgrade Compatibility

Upgrades use a small compatibility contract:

- Edge artifacts declare a schema version and compatible agent range.
- Database changes use expand-then-contract migrations so the previous application release can still run during rollback.
- One DNS target and one edge are upgraded as canaries before wider rollout.
- Rollout stops when health, error rate, or configuration rejection exceeds a defined threshold.
- A rollback must not require restoring an older database backup.

Part One supports safe manual and bounded canary upgrades. Fully automated fleet orchestration belongs to Part Two.

##### Recovery Contract

The minimum serving-state recovery set is:

- Control-plane PostgreSQL backup
- Laravel application encryption key
- Edge identity CA and artifact-signing keys when applicable
- Custom TLS key backup or recoverable encrypted database values
- Application build and migrations
- Typed environment configuration
- Encrypted off-host PostgreSQL backups
- Backup decryption material stored separately from the backup files

From that set:

- PowerDNS runtime state is rebuilt.
- Edge configuration and certificates are rebuilt.
- A new edge recovers from a full snapshot.
- Queue state may be reconstructed through reconciliation.
- ClickHouse is not required to restore DNS or HTTP serving.
- Historical telemetry recovery follows a separate retention and backup policy.

No recovery-time claim is accepted without a tested restore on the qualification dataset and on a fresh replacement host. The runbook records measured RPO and RTO.

Part One requires encrypted off-host backups and clean-host restore. Immutable backup storage and a warm standby control plane belong to Part Two.

#### API

##### System Status and Settings

```text
GET    /api/admin/system/health
GET    /api/admin/system/components
GET    /api/admin/system/settings
PATCH  /api/admin/system/settings
```

The generic settings response exposes only completed, typed setting groups. It never becomes arbitrary key-value editing.

Prometheus metrics are exposed on a private protected endpoint:

```text
GET    /metrics
```

##### Failed Jobs

```text
GET    /api/admin/jobs/failed
POST   /api/admin/jobs/failed/{job}/retry
DELETE /api/admin/jobs/failed/{job}
```

##### Reconciliation

```text
POST   /api/admin/reconcile/dns
POST   /api/admin/reconcile/edges
POST   /api/admin/reconcile/tls
POST   /api/admin/reconcile/purges
POST   /api/admin/reconcile/usage
```

Every endpoint creates one bounded operation with pagination and deduplication. It never creates an unbounded duplicate job for every resource.

##### Backups

```text
GET    /api/admin/backups
POST   /api/admin/backups
GET    /api/admin/backups/{backup}
POST   /api/admin/backups/{backup}/restore
DELETE /api/admin/backups/{backup}
```

Restore requires:

- An exact confirmation value
- Administrator re-authentication
- An audit record
- A preflight validation
- A new operation
- Progress and failure visibility
- A documented maintenance-state policy

Backup files are never served through unauthenticated routes.

#### Health States

Every component reports:

```text
healthy
degraded
unavailable
```

Monitor:

- Core PostgreSQL
- Redis/Valkey
- Horizon workers and queue depth/age per lane
- Scheduler freshness
- Host clock offset and synchronization freshness
- DNSdist per cluster
- PowerDNS per cluster
- Runtime database per cluster
- DNS deployment drift
- MMDB freshness
- ClickHouse
- Vector ingestion and buffer age
- Every edge node
- Every OpenResty cell and service pool
- Edge listener health
- Domain placement drift and failed quarantine migrations
- Cell CPU, memory, connection, cache, and temporary-storage saturation
- Active emergency modes and service-IP withdrawals
- Edge configuration drift
- TLS issuance and renewal
- Failed deployments
- Failed purge tasks
- Usage finalization lag
- Backup freshness

Filament summarizes operational state. Prometheus and Alertmanager remain the monitoring and alerting systems.

#### Production Qualification

##### Recovery

- [ ] Core PostgreSQL backup and restore are executed in a clean environment and on a fresh replacement host.
- [ ] Off-host backups are encrypted and their decryption material is recoverable separately.
- [ ] Encryption keys and secrets are included in the documented recovery set.
- [ ] PowerDNS runtime state is rebuilt from control-plane data.
- [ ] A fresh edge recovers from a full snapshot.
- [ ] Queue loss is repaired by reconciliation.
- [ ] TLS state is reconstructed after edge loss.
- [ ] Usage intervals can be rebuilt from retained ClickHouse data.
- [ ] Runbooks exist for control DB, DNS cluster, edge, certificate, ClickHouse, Vector, queue backlog, and MMDB failure.
- [ ] Measured RPO and RTO are recorded.
- [ ] Clock drift beyond the configured threshold produces a degraded health state and alert.

##### Availability

- [ ] Existing DNS and CDN traffic continues during control-plane downtime.
- [ ] One failed DNS cluster does not corrupt another cluster.
- [ ] One failed edge does not interrupt healthy edges.
- [ ] DNSdist and PowerDNS restart tests pass.
- [ ] ClickHouse and Vector restart without traffic interruption.
- [ ] MMDB provider outage retains the last valid file.
- [ ] Graceful shutdown prevents partially activated state.
- [ ] A canary control-plane/agent upgrade succeeds through a mixed-version window and can roll back without database restore.
- [ ] A stale edge is removed from new system edge-routing answers according to policy.
- [ ] A drained edge stops receiving new preferred traffic while completing existing work according to runtime capability.

##### Scale

- [ ] Operate with at least 500,000 domains.
- [ ] Operate with at least 1,000,000 DNS records.
- [ ] Process at least 50,000 DNS changes per day.
- [ ] Complete the burst-mutation qualification.
- [ ] Coalesce rapid repeated changes to one domain.
- [ ] Run concurrent deployments to multiple DNS clusters and edges.
- [ ] Add edge capacity without control-plane redesign.
- [ ] Prove that an edge-health change does not trigger a 500,000-domain rewrite.
- [ ] Keep analytics bounded and responsive under the qualification dataset.
- [ ] Publish per-edge throughput results with full hardware and test details.

##### Security and Operations

- [ ] Secrets are absent from images, source code, responses, and plain-text logs.
- [ ] API tokens rotate safely, and edge mTLS identities can be revoked and replaced.
- [ ] Administrator actions are audited.
- [ ] Backup access is restricted and logged.
- [ ] PowerDNS, PostgreSQL, ClickHouse, Redis/Valkey, agent APIs, and metrics remain private.
- [ ] Production Compose services define storage, restart, health, log rotation, and resource expectations.
- [ ] Containers run with the minimum required privileges.
- [ ] Edge certificate and configuration files use restrictive permissions.
- [ ] Restore and reconciliation actions require explicit confirmation and produce operation records.

##### Documentation

- [ ] Production installation guide
- [ ] Host-role deployment commands
- [ ] Upgrade, compatibility, canary, and rollback guide
- [ ] Backup, off-host encryption, key recovery, and restore guide
- [ ] Secret rotation guide
- [ ] Monitoring and alert reference
- [ ] Capacity-planning guide
- [ ] Every required failure runbook
- [ ] Complete OpenAPI reference

---

### 11. Final Browser and Real-Traffic Acceptance Test

The final qualification is performed from a clean environment.

1. Administrator configures platform domain, dual-stack nameservers, SOA, and proxy hostname.
2. Administrator creates and qualifies at least two DNS clusters.
3. Administrator installs and registers at least two edges in different geographic locations.
4. Administrator creates a domain user.
5. Administrator assigns a domain.
6. User adds the domain without an origin.
7. User changes registrar nameservers.
8. CDNFoundry verifies nameservers through real public DNS.
9. User creates A, AAAA, MX, TXT, and CAA records.
10. Real `dig` queries return correct authoritative answers.
11. User creates a Geo-DNS record and validates country, continent, and default answers.
12. User enables proxy on an apex hostname and a subdomain.
13. User configures one origin for each proxied hostname.
14. Edge origin tests return status and latency.
15. Enabling the first proxied hostname triggers managed apex and wildcard TLS through DNS-01; a DNS-only domain does not request a certificate.
16. Real HTTPS traffic reaches the correct origin through both edges.
17. Cache MISS followed by HIT is visible.
18. Development mode bypasses cache and expires.
19. Full purge changes the cache epoch.
20. URL purge reaches every healthy edge.
21. An IPv4 rule blocks a real request.
22. An IPv6 CIDR rule blocks a real request.
23. A country rule behaves correctly and unknown geography does not fail traffic.
24. Per-client and total-domain request and connection limits work together.
25. Origin-concurrency, slow-client, and cache-abuse tests remain bounded.
26. Administrator moves one domain into the quarantine pool while a domain in another cell continues serving.
27. Emergency mode activates, expires, and leaves an audit trail.
28. Request, DNS, edge, country, cache, origin, TLS, security, and DDoS-readiness telemetry appears.
29. Usage rollups and exports match generated traffic.
30. Laravel, core PostgreSQL, Redis/Valkey, and ClickHouse are stopped.
31. Existing DNS and edge traffic continues.
32. A malformed configuration is rejected and the prior state remains active.
33. One edge is drained and removed from preferred routing.
34. One edge is deleted and a fresh edge restores from a full snapshot.
35. PowerDNS runtime data is deleted and rebuilt from desired state.
36. Encrypted off-host backup and restore complete on a fresh replacement host.
37. One edge is upgraded as a canary through a mixed-version window and rolled back safely.
38. All measured recovery, scale, isolation, and throughput results are recorded.

---

### 12. Project Structure

Use one Laravel repository with organizational feature folders:

```text
app/Domain/Auth
app/Domain/Users
app/Domain/Domains
app/Domain/Dns
app/Domain/GeoDns
app/Domain/Edges
app/Domain/Tls
app/Domain/Cache
app/Domain/Security
app/Domain/Analytics
app/Domain/Usage
app/Domain/Operations
```

These folders are organization only. Do not install a modular-monolith framework.

A feature contains only what it needs:

```text
Models
Actions
Jobs
Policies
Requests
Resources
Controllers
Filament
Tests
```

Do not create empty layers or folders pre-emptively.

---

### 13. When to Create an Action Class

Create an action only when it:

- Performs an external side effect
- Is reused from HTTP, CLI, and queue contexts
- Coordinates a real multi-step operation

Normal CRUD remains in controllers and Eloquent models.

Keep only these dedicated runtime components unless measured requirements justify more:

- PowerDNS runtime reconciler
- Geo-DNS compiler
- System edge-routing compiler
- Edge configuration compiler
- ACME client
- Purge coordinator
- ClickHouse query service
- Usage rollup service

Do not create an interface and implementation pair unless more than one implementation exists.

---

### 14. Definition of Done for Every Feature

- [ ] Migration or validated JSON schema change
- [ ] Model relationship or typed DTO
- [ ] Policy for user-facing access
- [ ] Form request validation
- [ ] API resource
- [ ] Controller, action, or job
- [ ] Filament page when a person must manage it
- [ ] Audit entry for user or administrator mutation
- [ ] Idempotency for mutation
- [ ] Stable error codes
- [ ] Happy-path feature test
- [ ] Permission-boundary test
- [ ] Invalid-input test
- [ ] Failure and retry test for asynchronous work
- [ ] Restart or last-valid-state test when runtime is affected
- [ ] OpenAPI update
- [ ] User or administrator documentation update
- [ ] No unbounded payload, loop, query, or local disk use

---

### 15. Forbidden Development Patterns

- Repository interfaces around Eloquent
- CQRS
- Event sourcing
- Service registration for every class
- One microservice per feature
- Kafka for control-plane work
- Kubernetes as a required deployment target
- Dynamic customer rule languages
- Plugin marketplaces or plugin runtimes
- Custom RBAC
- Multiple dashboards
- A second admin API
- Direct edge or PowerDNS changes inside HTTP requests
- Automatic or default per-domain Nginx configuration files, OpenResty server blocks, workers, timers, containers, or process groups
- Per-domain OpenResty reloads
- One unrestricted OpenResty process and cache filesystem shared by every domain on an edge
- Phase names or phase numbers in production code, class names, migrations, routes, or filenames; phase labels are allowed only in the roadmap and explicitly phase-oriented test documentation
- Per-domain workers or timers
- Raw traffic logs through Laravel queues
- Runtime network calls from DNS to Laravel
- Runtime security calls to Laravel or ClickHouse
- Unbounded active origin checks
- Duplicating the full edge pool in every proxied DNS record
- Treating PowerAdmin runtime edits as durable configuration
- Creating fake apex records only to issue TLS certificates

---

## Part Two — Long-Term Future Roadmap

Part Two contains optional capabilities that may be developed after Part One is running successfully in production. Nothing in this part is required to declare CDNFoundry ready.

### Future Admission Rules

A future capability enters implementation only when:

- A real customer or repeated operational requirement exists.
- The requirement cannot be solved safely with Part One.
- Its operational cost and failure modes are understood.
- It has measurable completion and rollback tests.
- It can be added without moving DNS or user traffic through Laravel.
- It does not require rewriting the modular-monolith control plane or the generic edge runtime.

Future work is implemented incrementally. CDNFoundry must not pre-build speculative frameworks, plugin systems, or abstraction layers for these options.

### Future Stage 1 — Protocol and Certificate Evolution

Possible capabilities:

- HTTP/3 and QUIC after OpenResty support, resource limits, observability, and attack testing are mature.
- A secondary ACME certificate authority for controlled failover.
- Optional proactive certificate pre-issuance for selected proxied domains.
- DNSSEC signing and lifecycle management after a separate operational qualification.

Completion requires protocol-specific resource ceilings, mixed-protocol traffic tests, rollback, certificate recovery, and proof that failure does not affect HTTP/1.1, HTTP/2, or normal authoritative DNS.

### Future Stage 2 — Private Origin Connectivity

Possible capabilities:

- A small outbound origin connector for customers whose origins are not publicly reachable.
- Authenticated and encrypted private-origin tunnels.
- Private origin health checks through the connector.
- Connector revocation, rotation, bounded bandwidth, and last-valid routing state.

This must remain a focused origin-connectivity feature. It must not become a general VPN, zero-trust platform, arbitrary tunnel product, or service mesh.

### Future Stage 3 — Fleet Automation

Possible capabilities:

- Automated staged edge-agent and OpenResty rollouts.
- Canary groups and rollout waves across edge locations.
- Automatic rollout pause when health, errors, or revision drift exceed limits.
- Automated rollback to the last compatible runtime version.
- Fleet-wide compatibility and upgrade reporting.

Part One already supports compatible versions, bounded canaries, and manual rollback. This stage only automates those proven procedures when fleet size makes manual operation inefficient.

### Future Stage 4 — Extended Disaster Recovery

Possible capabilities:

- Warm control-plane standby.
- Immutable or deletion-protected backup storage.
- Automated recovery-environment provisioning.
- Periodic isolated disaster-recovery exercises.
- Faster restoration of control-plane services in a second location.

DNS and edge traffic must remain independent of this feature. The future standby improves management recovery time; it must not become part of the DNS or HTTP request path.

### Future Stage 5 — Proven Customer Extensions

Only after repeated demand and a separate architecture review, CDNFoundry may evaluate:

- Simple multi-origin failover without weighted traffic splitting.
- Additional tested managed security presets.
- Longer-retention analytics tiers or external archive export.
- Additional edge placement policies that preserve the existing bounded service-pool model.

These are candidates, not commitments. Billing engines, reseller hierarchies, serverless workers, object storage, custom WAF languages, CAPTCHA platforms, bot scoring, Kubernetes requirements, microservices, and general-purpose tunnels remain outside the intended product direction.

### Future Definition of Done

Every future capability must include:

- A clear production requirement and scope boundary
- Data model and API changes
- Backward-compatible artifact or agent behaviour
- Filament management only where human operation is required
- Bounded resource usage
- Failure isolation and last-valid-state behaviour
- Upgrade and rollback procedures
- Browser E2E and real-traffic qualification
- Documentation and runbooks
- Proof that Part One remains functional when the future capability is disabled or unavailable


---

## Final Architecture Rule

> Keep management in one understandable Laravel application. Keep DNS and HTTP traffic independent from Laravel. Store desired behaviour as validated, bounded data. Use one reconciliation pipeline for every deployed change. Keep the last valid state everywhere. Use bounded resource-isolated edge cells; per-domain processes are never the default architecture. Reject abuse early, bound origin and cache work, quarantine noisy domains, and scale traffic by adding cells and edges. Never claim volumetric protection when the physical uplink is saturated.

---

## Appendix A — Root `AGENTS.md`

This appendix defines the mandatory root `AGENTS.md` for the repository. It is an implementation contract for humans and coding agents. It may be shortened for wording, but its rules must not be weakened.

### Purpose

Build CDNFoundry as a small, production-grade private CDN with low feature count, low operational complexity, predictable failure behaviour, and stable scale.

The roadmap is the product contract. `AGENTS.md` is the implementation contract. When an implementation idea conflicts with either, stop and follow the roadmap.

### Product Invariants

- One Laravel modular monolith manages the control plane.
- Filament serves both administrator and domain-user interfaces.
- PostgreSQL is the durable source of truth for desired control-plane state.
- PowerDNS runtime data is derived and rebuildable.
- DNS queries never pass through Laravel.
- HTTP and HTTPS traffic never pass through Laravel.
- ClickHouse stores traffic telemetry; Laravel never ingests raw edge or DNS logs.
- Every deployed runtime keeps its last valid state.
- External side effects are asynchronous and revisioned.
- Traffic capacity scales by adding edge cells and edge nodes, not by rewriting Laravel.
- A domain is not given its own process, container, worker, timer, or Nginx server block by default.

### Scope Discipline

Implement only behaviour already accepted in Part One or explicitly admitted from Part Two.

Do not add speculative frameworks, generic plugin systems, custom rules languages, reseller hierarchy, billing engines, Kubernetes requirements, microservices, Kafka, CQRS, event sourcing, GraphQL, or additional dashboards.

When a requirement is unclear, prefer the smallest implementation that preserves:

1. Correctness
2. Last-valid-state recovery
3. Bounded resource use
4. Failure isolation
5. Operational visibility

### Repository and Naming Rules

- Use simple feature folders under `app/Domain`.
- Create only folders and classes required by the current feature.
- Use descriptive production names.
- Never use roadmap phase names or phase numbers in production filenames, class names, migrations, routes, configuration keys, database tables, or namespaces.
- Phase labels are allowed only in the roadmap and explicitly phase-oriented qualification documents.
- Do not create duplicate `V2`, `New`, `Final`, `Updated`, or `Refactored` implementations. Replace or migrate the existing implementation deliberately.
- Do not create repository interfaces around Eloquent.
- Do not create an interface/implementation pair unless a second implementation exists or an external boundary genuinely requires it.

### Laravel Rules

Use standard Laravel components:

- Controllers
- Form requests
- API resources
- Policies
- Eloquent models
- Jobs
- Commands
- Events
- Notifications
- Scheduler
- Horizon

Normal CRUD stays in controllers and Eloquent models.

Create an action class only when it:

- Coordinates a real multi-step operation
- Performs an external side effect
- Is reused from HTTP, CLI, and queue contexts

Do not hide ordinary CRUD behind service, manager, handler, repository, or gateway layers.

### API Rules

- Domain routes use policy-aware route binding.
- Administrator routes require `users.type = admin`.
- Browser sessions and API tokens use the same policies.
- API resources define all response shapes.
- List endpoints use cursor pagination.
- Bulk endpoints have explicit item and payload limits.
- Mutating endpoints support `Idempotency-Key`.
- Reusing an idempotency key with different input returns a conflict.
- Long-running or external operations return `202 Accepted` with an operation identifier.
- Errors include stable machine-readable codes.
- Never expose secrets, private keys, bootstrap tokens, or certificate material after their allowed one-time display.

### Transaction and Reconciliation Rules

For any change deployed to PowerDNS, edge cells, TLS storage, cache, security, or purge targets:

1. Authorize.
2. Validate typed input.
3. Write desired state to PostgreSQL.
4. Increment the relevant revision.
5. Commit the transaction.
6. Queue one unique reconciliation job.
7. Process the latest desired revision.
8. Render or compute the runtime artifact.
9. Validate and checksum it.
10. Apply it atomically.
11. Verify the target state.
12. Mark the revision active or failed.
13. Preserve the previous valid state.

Rules:

- HTTP requests never wait for PowerDNS, ACME, edge agents, ClickHouse, or purge completion.
- Coalesce repeated changes to the same resource.
- Obsolete jobs exit safely.
- Retries are idempotent.
- Global reconciliation is bounded and chunked.
- Never enqueue one unbounded job per resource from a single request.
- Queue lanes and worker budgets must prevent bulk work from starving serving-critical work.

### DNS Rules

- DNSdist is the only public authoritative DNS entry point.
- PowerDNS remains private.
- The control-plane database owns desired DNS state.
- PowerDNS runtime data may be rebuilt from PostgreSQL.
- Validate record type, owner name, value, TTL, zone boundary, CNAME coexistence, Punycode, and duplicate constraints before deployment.
- IPv4 and IPv6 are first-class throughout the system.
- Never delete the active zone before a replacement is validated.
- Runtime DNS never calls Laravel or an external GeoIP API.
- Development PowerAdmin is diagnostic only. Its changes are not durable product state.
- User Geo-DNS and platform edge selection are separate concepts.

### Origin and Proxy Rules

- A proxied hostname has one origin.
- Adding a domain never requires an origin.
- Origin destinations must reject unsafe loopback, link-local, metadata, multicast, internal platform, edge service, and proxy-loop addresses.
- Revalidate hostname resolution before connection to reduce DNS-rebinding risk.
- Do not follow origin redirects unless an accepted feature explicitly requires it.
- Normalize proxy requests consistently.
- Remove untrusted forwarding and hop-by-hop headers.
- Set trusted client IP, host, scheme, and forwarding headers at the edge.
- Bound origin connections, retries, request duration, headers, bodies, and upstream timeouts.
- One incoming request must never create unbounded origin retries.

### Edge Runtime Rules

- Use one generic OpenResty runtime implementation.
- Domain behaviour is validated data loaded by Lua.
- Do not generate per-domain Nginx server blocks.
- Do not reload all OpenResty cells for normal domain changes.
- Run multiple bounded OpenResty cells per edge.
- Every cell has explicit CPU, memory, process, connection, cache, temporary-storage, and file limits.
- The edge agent remains outside OpenResty cell resource groups.
- Each cell receives only its assigned domains and certificates.
- Use stable domain-to-service-pool placement.
- Shared, quarantine, and exceptional dedicated cells use the same runtime image and artifact format.
- Moving a domain must activate the target before draining the source.
- Bundle activation uses temporary paths, checksum/signature validation, local validation, and atomic replacement.
- A failed bundle never replaces the active bundle.
- Edge identity uses the accepted internal trust model; do not invent alternate token systems per endpoint.

### DDoS-Readiness Rules

The product provides bounded DDoS readiness, not volumetric scrubbing.

- Reject unknown hostnames and unknown SNI early.
- Enforce per-client and per-domain request and connection ceilings.
- Bound HTTP/2 streams and requests per connection.
- Bound TLS handshakes, headers, bodies, keep-alive, slow clients, origin concurrency, origin retries, and cache admission.
- Use fixed-size state zones.
- Platform safety ceilings cannot be disabled by a user.
- A suspected noisy domain may be restricted or quarantined without restarting unrelated cells.
- Emergency modes are revisioned, audited, idempotent, bounded, and automatically expire unless deliberately made permanent.
- Do not claim the system can protect an edge whose physical uplink is saturated.

### TLS Rules

- Use DNS-01 through managed authoritative DNS.
- Issue certificates when the first hostname becomes proxied, not for every DNS-only domain.
- Do not create fake apex records for certificate issuance.
- Validate uploaded certificate key matching, chain, names, and expiry.
- Encrypt private keys at rest.
- Spread renewal work over time.
- Preserve the current valid certificate during renewal or control-plane failure.
- Expose clear failure and expiry state without returning private material.

### Cache and Purge Rules

- Cache keys and bypass rules must be deterministic and shared by normal lookup and URL purge.
- Do not cache authenticated, private, `no-store`, or unsafe cookie responses unless an accepted explicit policy says otherwise.
- Bound object size, temporary files, query-string variation, cache admission, and cell disk usage.
- A full purge changes the domain cache epoch; it does not scan the cache filesystem.
- URL purge is asynchronous, idempotent, retryable, and tracked per edge.
- One domain must not fill the entire edge filesystem.

### Telemetry Rules

- Vector sends structured edge and DNS events directly to ClickHouse.
- Laravel and PostgreSQL never receive raw traffic logs.
- Telemetry failure never blocks DNS, proxy, cache, TLS, or security decisions.
- Disk buffering is bounded.
- When the configured limit is reached, drop telemetry according to policy rather than exhausting the edge.
- Never log authorization headers, cookies, tokens, private keys, request bodies, or secrets.
- Sanitize and bound URLs, query strings, user agents, headers, and error text before ingestion.
- Every ClickHouse query is domain-scoped, time-bounded, filter-allow-listed, and execution-limited.

### Configuration and Secrets

- Do not commit secrets, tokens, private keys, ACME account keys, production passwords, or real customer data.
- Use environment files or secret mounts appropriate to the deployment.
- Keep internal services on private networks.
- Production and development Compose files define explicit networks, volumes, health checks, restart behaviour, and resource limits.
- Preserve encryption and signing keys required to restore durable state.
- Never print secret values in logs, test output, exceptions, fixtures, screenshots, or documentation.

### Database and Migration Rules

- PostgreSQL migrations must be safe for production-sized tables.
- Prefer expand-and-contract changes for fields used by running workers or edge agents.
- Do not combine destructive schema removal with the first deployment of replacement code.
- Add constraints where they protect correctness, especially domain uniqueness, DNS records, revisions, deployments, and idempotency.
- JSONB sections accept only validated typed structures; never arbitrary UI JSON.
- PowerDNS runtime migrations remain separate from control-plane migrations.

### Testing Rules

Every feature includes, where applicable:

- Happy-path feature test
- Permission-boundary test
- Validation test
- Idempotency test
- Failure and retry test
- Last-valid-state or rollback test
- IPv4 and IPv6 test
- Browser E2E test when a human operates the feature
- Real runtime test when the feature changes DNS, HTTP, TLS, cache, security, telemetry, or edge behaviour

Do not replace runtime qualification with mocks alone.

Before declaring a change complete:

- Run the relevant project test commands.
- Run formatting and static checks already configured by the repository.
- Validate Compose configuration when infrastructure changes.
- Validate generated DNS or edge artifacts before activation.
- Confirm no unrelated domain, cell, or service is restarted unnecessarily.
- Update module documentation and runbooks.

### Documentation Rules

For each completed module, update:

- API contract
- Data model notes
- Filament operation notes
- Failure and retry behaviour
- Metrics and logs
- Recovery or rollback procedure
- Real validation commands

Documentation describes current implemented behaviour, not speculative features.

### Change Discipline

Before coding:

1. Identify the roadmap requirement.
2. Identify the durable desired state.
3. Identify external side effects.
4. Identify failure and rollback behaviour.
5. Identify resource bounds.
6. Identify permission boundaries.
7. Identify required real tests.

After coding, report:

- Files changed
- Behaviour implemented
- Tests executed and results
- Migrations or operational actions required
- Remaining limitations

Never claim a test passed unless it was actually executed.

### Forbidden Patterns

Do not introduce:

- Microservices
- Kafka for control-plane work
- Kubernetes as a requirement
- Custom RBAC
- Repository wrappers around Eloquent
- CQRS or event sourcing
- GraphQL
- Multiple dashboards
- A second admin API
- Dynamic customer expression languages
- Plugin runtimes or marketplaces
- Raw traffic logs through Laravel queues
- Runtime DNS or security calls to Laravel
- Direct PowerDNS or edge mutations inside HTTP requests
- Per-domain Nginx files, server blocks, workers, timers, processes, or containers by default
- Unbounded queues, imports, health checks, metrics, caches, logs, retries, or reconciliation
- Phase names in production code or filenames

---

## Appendix B — Required Coding-Agent Skills

CDNFoundry uses a small set of project-local skills for repeated engineering workflows. Skills are execution guides, not alternate architecture documents. `AGENTS.md` and the roadmap always have higher authority.

### Skill Design Rules

Each skill has one `SKILL.md` and may include only small templates or scripts that are genuinely reused.

Every `SKILL.md` must contain:

```text
Purpose
When to use
Required inputs
Files normally touched
Procedure
Validation commands
Definition of done
Stop conditions
Forbidden shortcuts
Expected completion summary
```

Rules:

- Use the smallest applicable skill.
- Skills may call or reference another skill rather than duplicate its instructions.
- Do not create one skill per roadmap phase.
- Do not create one skill per endpoint or model.
- Create a new skill only after the workflow has repeated enough to prove reuse.
- Skills must not silently select architecture, add dependencies, or expand scope.
- Skills must require the agent to report tests actually run.
- Skills must preserve existing naming and never introduce phase-based filenames.

### Initial Required Skill Set

#### 1. `cdnf-feature-module`

Use for a new Laravel feature area or a meaningful extension to an existing feature.

Required inputs:

- Accepted roadmap requirement
- Data ownership
- User types allowed
- API and Filament requirements
- External side effects
- Failure and rollback expectations

Produces only the required combination of migrations, models, policies, requests, resources, controllers, jobs, Filament pages, tests, and module documentation.

It must reject unnecessary repositories, service layers, pre-created folders, or speculative abstractions.

#### 2. `cdnf-api-endpoint`

Use for adding or changing a synchronous or asynchronous API endpoint.

It covers:

- Policy-aware binding
- Form request validation
- Stable response resources
- Cursor pagination
- Idempotency
- `202 Accepted` operations
- Machine-readable errors
- Rate-limit class
- Feature and permission tests
- OpenAPI contract updates

It must explicitly identify whether the endpoint changes only PostgreSQL state or triggers reconciliation.

#### 3. `cdnf-filament-ui`

Use for administrator or domain-user Filament resources, pages, widgets, actions, and operational status screens.

It covers:

- Correct panel and policy scope
- Bounded queries and pagination
- Typed forms
- Safe confirmations for destructive operations
- Desired, active, pending, and failed state visibility
- Retry and rollback actions where supported
- Useful empty, loading, degraded, and failure states
- Browser E2E coverage

It must not create a separate frontend or duplicate business rules in UI code.

#### 4. `cdnf-reconciliation`

Use for anything deployed outside the Laravel transaction, including DNS, edge configuration, TLS, security, cache state, and purge work.

It requires:

- Desired and active revision fields
- Unique/coalesced jobs
- Obsolete-job exit
- Deterministic artifact generation
- Checksum or signature
- Atomic activation
- Target acknowledgement
- Previous-valid-state preservation
- Bounded retries and error reporting
- Reconcile, retry, and rollback tests

This skill is mandatory whenever an HTTP request would otherwise call an external runtime directly.

#### 5. `cdnf-dns-runtime`

Use for authoritative DNS features and PowerDNS reconciliation.

It covers:

- Record and zone validation
- Canonical names and Punycode
- A/AAAA parity
- Zone serial handling
- Bulk atomic changes
- DNS-only Geo records
- Platform edge-selection artifacts
- DNSdist client/ECS behaviour
- PowerDNS runtime updates
- Real `dig` validation
- Runtime rebuild from PostgreSQL

It must preserve PowerDNS privacy and must never make DNS depend on Laravel availability.

#### 6. `cdnf-edge-runtime`

Use for edge-agent, OpenResty, Lua, service-pool, cell-isolation, proxy, cache, security, or DDoS-readiness work.

It covers:

- Generic data-driven OpenResty runtime
- Cell resource boundaries
- Stable domain placement
- Incremental and full bundles
- Artifact verification and atomic activation
- Dynamic TLS selection
- Request normalization
- Origin safety and concurrency
- Cache-key correctness
- Per-domain and per-client limits
- Quarantine and emergency mode
- Last-valid-state and offline operation
- Real HTTP/HTTPS and failure tests

It must reject per-domain server blocks, normal-change reloads, and unbounded Lua state.

#### 7. `cdnf-tls-lifecycle`

Use for ACME issuance, renewal, uploaded certificates, certificate storage, and edge delivery.

It covers:

- Proxy prerequisite checks
- DNS-01 challenge lifecycle
- Renewal spreading and retry
- Existing-certificate reuse
- Key, chain, name, and expiry validation
- Encryption at rest
- Safe edge distribution
- Failure visibility
- Existing-certificate continuity

It must not issue certificates for every DNS-only domain or create fake DNS records.

#### 8. `cdnf-telemetry-analytics`

Use for Vector schemas, ClickHouse tables and views, analytics queries, raw logs, compact PostgreSQL usage summaries, and external billing exports.

It covers:

- Bounded event schemas
- Redaction and privacy
- Disk-buffer limits
- Raw and aggregate retention
- Materialized views
- Domain and time scoping
- Query allow-lists and execution limits
- Accuracy tests against generated traffic
- Telemetry-outage behaviour

It must never route raw traffic logs through Laravel queues or PostgreSQL.

#### 9. `cdnf-compose-operations`

Use for development and production Compose services, networking, health checks, storage, secrets, metrics, backup, restore, and component startup behaviour.

It covers:

- Complete `compose.dev.yml`
- Minimal production topology
- Private internal networks
- Explicit volumes
- Health and readiness checks
- Resource and restart limits
- Graceful startup/shutdown
- Backup and restore commands
- Fresh-host recovery
- Development-only tooling boundaries

It must not add Kubernetes, service discovery frameworks, or a custom monitoring platform.

#### 10. `cdnf-production-qualification`

Use before completing a module that changes runtime behaviour and before declaring Part One complete.

It defines and executes:

- Browser E2E tests
- Real DNS tests
- Real HTTP/HTTPS tests
- IPv4 and IPv6 tests
- Restart tests
- Dependency outage tests
- Last-valid-state tests
- Load and noisy-neighbour tests
- Queue starvation tests
- Backup and clean restore tests
- Mixed-version canary and rollback tests

Its output must clearly separate:

```text
Passed
Failed
Not executed
Blocked
Observed limits
```

It must never convert an unexecuted test into a checklist pass.

### Skill Composition Map

Use skills together as follows:

```text
Normal CRUD feature
  cdnf-feature-module
  + cdnf-api-endpoint
  + cdnf-filament-ui when human-managed

DNS feature
  cdnf-dns-runtime
  + cdnf-reconciliation
  + cdnf-production-qualification

Edge or proxy feature
  cdnf-edge-runtime
  + cdnf-reconciliation
  + cdnf-production-qualification

TLS feature
  cdnf-tls-lifecycle
  + cdnf-reconciliation
  + cdnf-production-qualification

Analytics feature
  cdnf-telemetry-analytics
  + cdnf-api-endpoint
  + cdnf-filament-ui when human-managed

Deployment or recovery change
  cdnf-compose-operations
  + cdnf-production-qualification
```

### Skills Explicitly Not Needed

Do not create skills for:

- Roadmap phases
- Generic repository patterns
- Microservice generation
- Kubernetes manifests
- Kafka consumers
- CQRS commands and queries
- Custom RBAC
- Plugin creation
- GraphQL schemas
- Per-domain Nginx generation
- Generic architecture brainstorming during implementation

### Skill Completion Checklist

- [ ] Every required skill has one focused `SKILL.md`.
- [ ] Each skill references the root `AGENTS.md` and relevant roadmap section.
- [ ] Skills contain no duplicated product architecture.
- [ ] Skills require concrete inputs and produce bounded outputs.
- [ ] Validation commands are executable in the repository.
- [ ] At least one representative task has been completed using each skill.
- [ ] Skill instructions do not permit phase names in production files.
- [ ] Skills do not introduce dependencies or patterns forbidden by `AGENTS.md`.
- [ ] Obsolete skills are removed instead of accumulating conflicting versions.
