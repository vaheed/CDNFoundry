# Frontend route coverage

This audit covers the application-owned routes in `core/routes/web.php` and
`core/routes/api.php`. Framework routes for Livewire, Filament, Sanctum,
Horizon internals, local storage, and Laravel's fallback health route are not
product actions and are excluded.

The browser UI is intentionally a policy-aware Filament surface, not an HTTP
client pasted over the API. Browser actions call the same controllers,
validators, policies, models, and queued jobs as their API equivalents. An API
route remains API-only when it exists for automation, pagination, detailed
machine diagnostics, or an edge agent rather than for a person to manage.

## Public and account routes

| Route family | Browser surface | Coverage |
|---|---|---|
| `GET /`, `/api/health`, `/api/ready`, `/api/nameservers` | Product landing page links to both panels and service health; nameservers appear in domain delegation | Complete |
| `/api/auth/login`, `/api/auth/logout`, `/api/me` | Filament login/logout and shared Profile page | Complete |
| `/api/me/tokens` | Shared API tokens page with one-time secret display and revoke confirmation | Complete |
| `/api/operations/{operation}` | Domain actions show operation IDs; administrator Operations lists global detail and retry state | Complete |

## Domain-user routes

| API route family | Browser surface | Coverage |
|---|---|---|
| `/api/domains` and `/api/domains/{domain}` | Domains resource: assigned-domain list, create, status, display label, disable, and lifecycle state | Complete |
| `/verify-nameservers`, `/activate`, `/status` | Domain actions and Domain status card | Complete |
| `/dns/records`, `/dns/import`, `/dns/export` | DNS records relation with create/edit/delete/bulk delete, BIND import/export, type-aware validation, and permission-gated NS rows | Complete |
| `/dns/records/{record}/geo` and `/geo/preview` | Geo-DNS fields and Preview record action | Complete |
| `/dns/deployment` and `/dns/reconcile` | Authoritative DNS deployment card and **Reconcile authoritative DNS** action | Complete |
| `/proxy`, `/origin`, `/origin/test`, `/origin/health` | Delivery settings, proxied-record origin form, test action, and visible health state | Complete |
| `/deployment`, `/deploy`, `/rollback`, `/revisions` | Delivery status, validated revisions, and rollback action. Edge deploy remains deliberately automatic in the UI per the roadmap; the API trigger is for automation | Complete by contract |
| `/cache`, `/cache/development-mode`, `/cache/purge`, `/cache/purges` | Cache menu: settings, bounded development mode, epoch/URL purge, and latest delivery state | Complete |
| `/tls`, `/tls/status`, `/tls/reissue`, `/tls/renew`, `/tls/upload`, `/tls/custom-certificate` | TLS menu and TLS status card | Complete |
| `/security`, `/security/rules`, `/security/rules/import` | Security profile action and ordered Security rules relation | Complete |
| `/security/ddos`, `/security/ddos/status`, `/security/ddos/events`, `/security/events` | Security readiness/status card, bounded settings, state actions for admins, and recent reason codes | Complete |
| `/analytics/*`, `/logs/*` | Assigned-domain Analytics and logs page with all aggregate views and bounded masked previews | Complete |
| `/usage`, `/usage/export` | Finalized usage table and session-authenticated CSV export | Complete |

Advanced custom origin ports remain API-only as required by the roadmap. The
browser form exposes the standard HTTP/80 and HTTPS/443 pairs so the common
path stays safe and understandable.

## Administrator routes

| API route family | Browser surface | Coverage |
|---|---|---|
| `/api/admin/users` and user domain assignment | Users resource plus the domain Users relation | Complete |
| `/api/admin/audit-logs` | Read-only Audit logs resource | Complete |
| `/api/admin/domains/{domain}/force-verify` | Permission-gated Force verify domain action | Complete |
| `/api/admin/dns/clusters` | DNS clusters resource with secret-safe create/edit, test, enable, and disable | Complete |
| `/api/admin/dns/deployments`, `/failed-deployments`, `/reconcile` | Domain deployment cards and cluster-level **Reconcile all zones** action; failures are searchable in Operations | Complete |
| `/api/admin/system/status`, `/api/admin/operations` | Dashboard queue/health cards and Operations resource with guarded retry | Complete |
| `/api/admin/system/settings*` | Platform settings and System DNS identity pages with preview/confirmation for high-risk identity changes | Complete |
| `/api/admin/edges` | Edges resource with enrollment secret boundary, edit, rotate, enable/disable, drain/undrain, and emergency state | Complete |
| `/api/admin/edge-pools` | Service pools resource with provisioning, enable/disable, withdrawal, and emergency state | Complete |
| `/api/admin/edge-cells*` | Per-edge Cells relation with address edit, drain/undrain, restart, emergency state, and runtime diagnostics | Complete |
| `/api/admin/edge-deployments`, `/edge-routing`, `/edge-deployments/reconcile` | Domain delivery cards, pool targets, edge diagnostics, and **Reconcile all domains** maintenance action | Complete |
| `/api/admin/domains/{domain}/isolation`, `/move`, `/restrict`, `/quarantine`, `/release` | Permission-gated Delivery and Security domain actions | Complete |
| `/api/admin/analytics/*`, `/logs/*`, `/usage`, `/usage/export`, `/usage/rebuild` | Telemetry and usage page, masked previews, global CSV, and bounded **Rebuild usage** action | Complete |

## Machine-only routes

`/edge/v1/register`, heartbeat, manifest/artifact/full configuration,
apply/reject acknowledgements, task polling, and task results are an authenticated
edge-agent protocol. Exposing them as human buttons would violate the runtime
architecture and one-time enrollment boundary. Their human-observable state is
available through Edges, Cells, Operations, Audit logs, and deployment cards.

Cursor pagination and machine export variants stay API-only; their human
equivalents use Filament pagination, bounded tables, and session-authenticated
CSV downloads. Horizon remains the authenticated administrator UI for its own
framework routes.
