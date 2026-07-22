# Production scaling and larger topologies

The [three-host quick start](production-quick-start.md) is a minimum deployment
example, not a two-edge product limit. Production profiles and host overrides
are repeatable. Each new host has its own `.env.prod`, named volumes, public
addresses, firewall rules, and immutable `CDNF_RELEASE`.

CDNFoundry is deliberately bounded rather than "unlimited." The current control
plane accepts up to eight public nameserver identities and sixteen PowerDNS
cluster targets in one platform-DNS revision. A cluster target may front more
than one DNSdist/PowerDNS server through operator-owned load balancing or
anycast. Edge enrollment is not tied to the number of DNS clusters. Add edge
nodes and bounded cells as measured capacity requires; never create a process
or container per customer domain.

## What can scale independently

| Capacity | Supported expansion |
|---|---|
| Control web/API | Multiple `core` + `web` replicas behind an HTTPS load balancer, sharing PostgreSQL and Valkey |
| Edge-control | Multiple replicas behind an L4 TLS pass-through load balancer so mTLS reaches edge-control unchanged |
| Queue execution | Additional `horizon` containers or worker hosts against the shared database and Valkey |
| Scheduler | Exactly one active scheduler; use host fencing/failover, not active-active copies |
| PostgreSQL | External/operator-managed primary plus replicas or HA endpoint; all application replicas use one writer endpoint |
| Valkey | External/operator-managed replicated/failover endpoint shared by sessions, cache, queues, and locks |
| Authoritative DNS | Additional independent DNS hosts/clusters, up to the bounded platform target limit; add DNSdist capacity behind each target as needed |
| Edge traffic | Repeated enrolled edge hosts and bounded OpenResty cells, added from measured capacity |
| Telemetry | A dedicated telemetry host for the starter, or an operator-managed ClickHouse cluster behind a stable HTTPS endpoint |
| Vector | One bounded disk buffer per DNS/edge host; new hosts add their own buffer automatically |
| Backups | Larger or replicated object storage without changing application topology |

The repository does not pretend that starting several PostgreSQL, Valkey, or
ClickHouse containers creates a safe database cluster. Their replication,
quorum, backups, failover, and upgrade procedures are operator-owned. The
application accepts external endpoints through `DB_URL`, `DB_HOST`,
`DB_SSLMODE`, `REDIS_URL`, and `CLICKHOUSE_URL`.

When dependency traffic crosses public networks, require TLS, certificate
verification, default-deny firewalls, and exact source addresses. Never expose
plaintext database ports to the Internet.

### Repeatable restricted-port firewall pattern

Use one exact rule per peer when adding control workers, edges, DNS APIs, or
telemetry gateways. This example protects a Docker-published telemetry gateway;
change the destination, port, and chain name for the specific role. Preserve
the public DNS/HTTP rules from the quick start.

**Run on: the host receiving a restricted connection.**

**Why:** extend a default-deny policy to multiple approved public source
addresses without opening the port globally.

```bash
SERVICE_PUBLIC_IPV4=198.51.100.50
SERVICE_PORT=8444
PEER_PUBLIC_IPV4S=(198.51.100.20 198.51.100.30 198.51.100.40)
CHAIN=CDNF-TELEMETRY

sudo ufw default deny incoming
for peer in "${PEER_PUBLIC_IPV4S[@]}"; do
  sudo ufw allow proto tcp from "${peer}" to "${SERVICE_PUBLIC_IPV4}" port "${SERVICE_PORT}"
done

sudo iptables -N "${CHAIN}" 2>/dev/null || true
sudo iptables -F "${CHAIN}"
sudo iptables -A "${CHAIN}" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
for peer in "${PEER_PUBLIC_IPV4S[@]}"; do
  sudo iptables -A "${CHAIN}" -s "${peer}" -p tcp -m conntrack \
    --ctorigdst "${SERVICE_PUBLIC_IPV4}" --ctorigdstport "${SERVICE_PORT}" -j ACCEPT
done
sudo iptables -A "${CHAIN}" -p tcp -m conntrack --ctstate NEW \
  --ctorigdst "${SERVICE_PUBLIC_IPV4}" --ctorigdstport "${SERVICE_PORT}" -j DROP
sudo iptables -A "${CHAIN}" -j RETURN
sudo iptables -C DOCKER-USER -j "${CHAIN}" 2>/dev/null || sudo iptables -I DOCKER-USER 1 -j "${CHAIN}"
sudo netfilter-persistent save
```

For a host-native TLS PostgreSQL or Valkey endpoint, the UFW/provider rules
apply but `DOCKER-USER` does not. Also allow only the exact database peers
needed for replication and health checks; those ports depend on the selected
database topology and are not inferred by CDNFoundry.

## Deployment building blocks

| Host role | Compose files | Start target/profile |
|---|---|---|
| Three-host combined controller/telemetry | base + `compose.control-host.yml` | `control`, `telemetry` |
| Control replica using external PostgreSQL/Valkey | base + `compose.external-control-data.yml` | selected `core`, `web`, `edge-control` services |
| Worker host using external PostgreSQL/Valkey | base + `compose.external-control-data.yml` | selected `horizon`; one host may run `scheduler` |
| DNS-only host | base + `compose.dns-host.yml` | `dns` |
| Edge-only host | base + `compose.edge-host.yml` | `edge` |
| Combined DNS/edge starter host | base + `compose.dns-edge-host.yml` | `dns`, `edge` |
| Dedicated telemetry host | base + `compose.telemetry-host.yml` | `telemetry` |

Here, "base" means `compose.prod.yml`; override files are under
`deploy/production/`.

## Example fleet sizes

### Minimum: three VPSs

- One controller/telemetry host.
- Two combined DNS/edge hosts in different failure domains.
- Appropriate for qualification and low initial traffic.
- One controller failure stops management changes but not last-valid DNS or
  edge serving.

Follow the complete [three-host quick start](production-quick-start.md).

### Regional: eleven service hosts plus managed data

- Two control web/edge-control replicas behind public load balancers.
- Two Horizon worker hosts; run the scheduler on only one.
- Two DNS-only hosts in separate locations.
- One telemetry host.
- Four edge-only hosts across at least two locations.
- Provider-managed HA PostgreSQL and Valkey endpoints.

This layout separates DNS disk/CPU from cache traffic and lets queue work,
public traffic, and telemetry scale independently. It is still a sizing
starting point, not an availability or throughput promise.

### Heavy traffic: 20 or more hosts

- Two or more control replicas behind HTTPS and L4 pass-through load balancers.
- Three or more Horizon worker hosts with lane budgets; one fenced scheduler.
- Three PostgreSQL nodes and three Valkey nodes, or equivalent managed HA
  services.
- Four to eight public DNS nodes or several anycast/load-balanced DNS clusters.
- Three or more ClickHouse nodes behind an operator-qualified endpoint.
- Eight or more edge hosts, added per geography and measured bandwidth demand.
- Separate backup/object storage and monitoring failure domains.

At this size, use stable public egress addresses per edge region so telemetry
and edge-control allow-lists do not grow with every ephemeral machine. Those
egress addresses are still public and must be exact firewall sources.

## Hardware planning examples

These are conservative procurement starting points, not benchmark results.
Storage endurance, CPU generation, TLS mix, cache hit ratio, object sizes,
origin latency, retention, and network commit often matter more than VM labels.

| Role | Low traffic starting point | Regional/heavy starting point | Primary scaling signal |
|---|---|---|---|
| Control web/API replica | 4 vCPU, 8 GiB RAM, 50 GiB SSD | 8 vCPU, 16 GiB RAM, 100 GiB NVMe | request latency, PHP-FPM saturation |
| Horizon worker host | 4 vCPU, 8 GiB RAM | 16 vCPU, 32 GiB RAM | queue depth/age by lane, job failures |
| Scheduler host | 2 vCPU, 4 GiB RAM | 4 vCPU, 8 GiB RAM | missed/late scheduled work |
| PostgreSQL writer | 8 vCPU, 32 GiB RAM, 500 GiB NVMe | 16–32 vCPU, 64–128 GiB RAM, 1–4 TiB high-endurance NVMe | transaction latency, WAL, locks, IOPS |
| Valkey | 4 vCPU, 8 GiB RAM | 8–16 vCPU, 32–64 GiB RAM | memory headroom, evictions, command latency |
| DNS-only host | 4 vCPU, 8 GiB RAM, 50 GiB SSD, 1 Gbit/s | 8–16 vCPU, 16–32 GiB RAM, 10 Gbit/s | query latency/errors, CPU, packet drops |
| Telemetry/ClickHouse | 8 vCPU, 32 GiB RAM, 1 TiB NVMe | 32+ vCPU, 128+ GiB RAM, 4–16 TiB NVMe | ingest lag, merges, query memory, disk throughput |
| Edge-only host | 8 vCPU, 16 GiB RAM, 500 GiB NVMe cache, 10 Gbit/s | 32+ vCPU, 64+ GiB RAM, 2–8 TiB NVMe cache, 25+ Gbit/s | TLS CPU, connections, cache pressure, NIC saturation |

Reserve operating-system, agent, activation, telemetry, file-descriptor, and
temporary-storage headroom on every edge. Do not allocate all RAM or disk to
OpenResty cache cells. For heavy usage, benchmark the intended server with the
real IPv4/IPv6, TLS, cache HIT/MISS, object-size, concurrency, and origin mix,
then publish throughput, latency, errors, and saturation.

## Add an edge-only host

Adding an edge does not require a new DNS cluster or a new per-domain runtime.
It receives only domains assigned to its bounded cell/pool.

**Run on: the new edge host.**

**Why:** validate and start only the edge services with explicit public IPv4
and IPv6 listeners.

```sh
cd /opt/cdnfoundry
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.edge-host.yml \
  --profile edge config --quiet
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.edge-host.yml \
  --profile edge up -d
```

Create the edge in the admin panel, set its one-time `EDGE_ID` and
`EDGE_BOOTSTRAP_TOKEN`, verify enrollment and cell readiness, then erase the
bootstrap token as described in the quick start.

Add the edge's public IPv4 to `EDGE_PUBLIC_IPV4_ALLOWLIST` on every telemetry
gateway it uses. If the controller and telemetry share the starter Caddy,
recreate only Caddy after updating `.env.prod`.

**Run on: the controller/telemetry host.**

**Why:** admit the new edge's exact source without restarting Laravel or
ClickHouse.

```sh
cd /opt/cdnfoundry
sudoedit .env.prod
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.control-host.yml \
  --profile control --profile telemetry up -d --force-recreate caddy
```

Append the same source-specific `8443` and `8444` firewall rules used by the
quick start; do not replace default deny with a broad CIDR unless that entire
CIDR is an operator-owned egress range.

## Add a DNS-only host or cluster

Each DNS host gets a unique API key, database password, public addresses, API
hostname, and server certificate. Certificate issuance is repeatable and does
not regenerate existing fleet keys.

**Run on: the protected control administration host.**

**Why:** issue one hostname-bound DNS API certificate from the existing server
CA while refusing to overwrite another host's identity.

```sh
cd /opt/cdnfoundry
sudo ./scripts/issue-production-dns-api-certificate.sh \
  /etc/cdnfoundry/pki \
  dns-api-eu-3 \
  dns-api-eu-3.ops.example.com
```

Transfer that certificate, its key, and `edge-server-ca.crt` to the new DNS
host. Set `CONTROL_PUBLIC_IPV4_ALLOWLIST` to every exact public address used by
Horizon/control workers. Mirror the list in UFW, provider firewall, and
`DOCKER-USER` rules for destination TCP `8444`.

**Run on: the new DNS host.**

**Why:** migrate its derived PowerDNS database and start only DNS services.

```sh
cd /opt/cdnfoundry
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-host.yml \
  --profile dns config --quiet
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-host.yml \
  --profile tools run --rm pdns-migrate
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.dns-host.yml \
  --profile dns up -d
```

Create and test the new DNS cluster while disabled. Add its HTTPS API hostname
to platform `cluster_targets`, wait for reconciliation, then enable it. If it
is a new public nameserver identity, add matching registrar glue and the system
DNS nameserver entry before delegating customers to it. Platform settings bound
nameserver identities to eight and cluster targets to sixteen; use multiple
servers behind a target when measured capacity needs more machines.

## Move telemetry to its own host

The dedicated telemetry override runs ClickHouse and a Caddy HTTPS gateway. It
accepts an operator-maintained space-separated list of exact edge public IPv4
addresses. On very large fleets, use stable public regional egress addresses.

**Run on: the dedicated telemetry host.**

**Why:** start a standalone encrypted telemetry endpoint without exposing raw
ClickHouse port `8123`.

```sh
cd /opt/cdnfoundry
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.telemetry-host.yml \
  --profile telemetry config --quiet
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.telemetry-host.yml \
  --profile telemetry up -d
```

Set `TELEMETRY_HOSTNAME`, `PUBLIC_BIND_IPV4`, `PUBLIC_BIND_IPV6`, and a quoted
space-separated `EDGE_PUBLIC_IPV4_ALLOWLIST`. Point each edge's
`CLICKHOUSE_URL` to `https://TELEMETRY_HOSTNAME:8444`. Point Laravel at the
operator-qualified ClickHouse query endpoint. Do not run the starter
controller's local telemetry profile after the migration is verified.

## Add control and worker hosts

All control replicas must share the same `APP_KEY`, artifact-signing key,
PostgreSQL writer, Valkey, certificate trust, and durable TLS material. Use TLS
external endpoints and source-restricted public firewalls. The external-data
override prevents accidental local PostgreSQL and Valkey primaries.

Each replica's `.env.prod` points to stable TLS endpoints. Use percent-encoded
credentials and a system-trusted CA or an explicitly installed private CA:

```dotenv
DB_URL=postgresql://cdnf:ENCODED_PASSWORD@postgres.ops.example.com:5432/cdnf?sslmode=verify-full
REDIS_URL=tls://:ENCODED_PASSWORD@valkey.ops.example.com:6379
CLICKHOUSE_URL=https://clickhouse.ops.example.com:8444
```

**Run on: each additional web/control replica.**

**Why:** start stateless application ingress against the shared external data
services. The public load balancer is operator-owned and must health-check
`/api/ready`.

```sh
cd /opt/cdnfoundry
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.external-control-data.yml \
  --profile control config --quiet
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.external-control-data.yml \
  --profile control up -d core web edge-control
```

Terminate edge-control TLS only at edge-control. An upstream load balancer must
use L4 pass-through so client certificate authentication is preserved.

**Run on: each additional worker host.**

**Why:** add queue capacity without adding another database, web listener, or
scheduler.

```sh
cd /opt/cdnfoundry
docker compose --env-file .env.prod \
  -f compose.prod.yml \
  -f deploy/production/compose.external-control-data.yml \
  --profile control up -d horizon
```

Run `scheduler` on exactly one fenced host. Run migrations exactly once per
release before rolling application and workers. Use `php artisan
horizon:terminate` for graceful worker replacement and verify per-lane queue
depth and age before adding more workers.

## Capacity gate

Before and after expansion, record:

- exact image digests and hardware;
- database dataset and telemetry retention;
- DNS query and mutation rate;
- queue depth/age per lane;
- edge TLS/cache/origin traffic mix and concurrency;
- IPv4 and IPv6 latency, throughput, errors, and resource saturation;
- failure behavior with control, queue, and ClickHouse unavailable;
- last-valid DNS and edge revision acknowledgements;
- backup verification and rollback result.

Run `make dev-scale-e2e` for control/DNS correctness evidence, then perform a
real non-browser load test on the intended production hardware. No VM size or
host count in this guide is a universal capacity guarantee.
