---
name: cdnf-codebase-map
description: Map of the CDNFoundry repository: where each concern lives, the entry points, and the matching tests. Use it before searching the tree, to open the right file first.
---

# cdnf-codebase-map

Read this instead of listing or grepping the whole tree. Paths are relative
to the repository root. When CodeGraph is enabled (`.mcp.json`), prefer its
symbol and caller queries over reading whole files.

## Control plane (Laravel 13, PHP 8.3, Filament 5) — `core/`

| Concern | Entry points | Tests (`core/tests/Feature`) |
| --- | --- | --- |
| Routes | `routes/api.php` (customer + `/api/admin`), `routes/web.php` (`/edge/v1` agent API), `routes/console.php` (scheduler) | `OpenApiContractTest` |
| Domains and claims | `Models/Domain.php`, `Http/Controllers/DomainController.php`, `DomainLifecycleController.php`, `Support/DomainNameserverVerification.php`, `Support/NameserverResolver.php`, `Jobs/VerifyDomainNameservers.php` | `DomainApiTest`, `DomainLifecycleTest`, `DomainClaimSecurityTest`, `ParentDelegationTest` |
| DNS records and zones | `DnsRecordController.php`, `Support/DnsRecordData.php`, `DnsZoneValidator.php`, `PowerDnsZone.php` (customer zone render), `PlatformDnsZone.php` (platform zone), `Jobs/ReconcileDnsZone.php`, `Support/PowerDnsClient.php`, `GeoDnsCompiler.php` | `DnsRecordApiTest`, `DnsReconciliationTest`, `GeoDnsTest`, `DnsZoneImportExportTest` |
| Edge config | `Jobs/ReconcileEdgeDomain.php` (snapshot + artifacts), `Actions/PlanDomainEdgeCells.php`, `Actions/PromoteReadyEdgePlacements.php`, `Http/Controllers/EdgeAgentController.php`, `Support/ArtifactSigner.php` | `EdgeProxyTest`, `MultiCellPlacementTest`, `EdgeRevisionHistoryTest` |
| Pools, cells, endpoints | `Models/EdgePool.php`, `EdgeCell.php`, `EdgePoolEndpoint.php`, `Admin/EdgePoolController.php`, `Admin/EdgeController.php` | `PoolServiceEndpointTest`, `SimpleAnycastPoolTest` |
| Origins | `Support/OriginData.php`, `NetworkAddress.php`, `ProxyController.php` | `OriginDestinationSafetyTest`, `OriginFailoverTest`, `OriginTestLifecycleTest` |
| Cache and purge | `CacheController.php`, `Support/CachePolicy.php`, `CacheKey.php`, `Actions/DispatchCachePurge.php` | `CacheApiTest` |
| TLS | `TlsController.php`, `Support/UploadedCertificate.php`, `AcmeClient.php`, `ManagedCertificateNames.php`, `Jobs/IssueManagedCertificate.php`, `EnsureManagedCertificates.php` | `TlsApiTest`, `ManagedTlsTest` |
| Security and WAF | `SecurityController.php`, `WafController.php`, `Support/SecurityConfig.php`, `ManagedWaf.php`, `Admin/SecurityOperationsController.php` | `SecurityApiTest`, `ManagedWafTest` |
| Analytics and usage | `Support/AnalyticsStore.php` (ClickHouse queries), `AnalyticsController.php`, `UsageController.php`, `Jobs/BuildUsageRollups.php` | `AnalyticsApiTest` |
| Settings | `Support/PlatformSettings.php`, `Admin/SystemSettingsController.php`, `Filament/Admin/Pages/PlatformSettings.php` | `SystemSettingsTest` |
| Operations and audit | `Models/Operation.php` (`coalesceDomain`), `AuditLog.php`, `Http/Middleware/IdempotentRequest.php` | `OperationsApiTest` |
| UI | `app/Filament/Admin/**` (admin panel `/admin`), `app/Filament/Domain/**` (domain panel `/app`), `app/Filament/Shared/Pages` | `FilamentPanelAccessTest`, `FilamentWorkflowTest` |
| Queues | `config/horizon.php` (lanes: interactive, runtime, certificate_purge, bulk_maintenance) | — |

## Runtime

| Component | Files | Tests |
| --- | --- | --- |
| Edge agent (Go) | `edge-agent/main.go` (sync, activate, tasks, acks), `generation.go`, `backup.go` | `edge-agent/*_test.go` |
| Edge gateway (Go, L4 SNI/Host router) | `edge-gateway/main.go` | `edge-gateway/*_test.go` |
| Cell runtime (OpenResty) | `docker/openresty/runtime.lua`, `docker/nginx/edge-runtime.conf`, `proxy-cache.conf`, `docker/openresty/waf.conf` | `tests/e2e/edge_purge_protocol.py`, `origin_destinations.py` |
| DNS | `docker/pdns/pdns.conf`, `docker/dnsdist/dnsdist.conf`, `docker/pdns/geoip-zones.yml` | `tests/e2e/phase2_dns.py`, `phase3_geo_dns.py`, `dns_images.py` |
| Telemetry | `docker/vector/vector.yaml`, `docker/clickhouse/init.sql`, `docker/clickhouse/migrations/` | `tests/e2e/phase7_analytics.py`, `vector_traffic.py` |
| Control ingress | `docker/nginx/default.conf` (panel/API), `docker/nginx/edge-control.conf` (agent mTLS), `deploy/production/Caddyfile*` | `tests/e2e/phase4_mtls.py`, `caddy_ingress.py` |

## Deployment and tooling

- Compose: `compose.dev.yml` (full dev stack), `compose.test.yml` (isolated SQLite test override), `compose.prod.yml` (profiles: control, dns, edge, telemetry, logs, tools).
- Fleet installer: `scripts/cdnfoundry-fleet` → `scripts/cdnfoundry_fleet/*.py`; tests in `tests/fleet`.
- Make targets: `make dev-test`, `make dev-e2e`, `make config-check`, `make openapi-check`, `make docs-check`, `make contract-check`.
- Docs site: `docs/` (VitePress). Product contract: `AGENTS.md`. Roadmap: `docs/roadmap.md`.
