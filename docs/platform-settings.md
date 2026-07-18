# Platform settings

PostgreSQL is the only runtime source of truth for operator-tunable CDNFoundry policy. The `system_settings` table is seeded by migration with a fixed, typed allow-list; application code never falls back to environment variables or unvalidated keys. Deployment environment files remain responsible only for bootstrap concerns such as database/Valkey endpoints, credentials, encryption and signing keys, certificate paths, bind addresses, and the GeoIP database path.

The administrator **Platform settings** page, administrator API, and `platform:settings:*` CLI commands all read and update the same revisioned rows. Every surface shows the current value, shipped default, type, and full description.

| Group | Setting | Shipped default | Purpose |
|---|---|---:|---|
| DNS lifecycle | Deprovision delay | 7 days | Preserve the last valid serving state after disable before asynchronous removal. |
| DNS lifecycle | Domain reclaim cooldown | 7 days | Reserve a released name before another account can claim it. |
| API rate limits | Login | 10/minute | Bound login attempts by source IP and normalized account. |
| API rate limits | Account reads / mutations | 600 / 240 per minute | Protect authenticated interactive lanes. |
| API rate limits | Bulk / origin tests | 12 / 20 per minute | Keep expensive work from starving interactive work. |
| API rate limits | Edge enrollment / agent | 10/hour / 600/minute | Bound bootstrap and authenticated agent traffic. |
| Edge runtime | Heartbeat freshness | 45 seconds | Remove stale edges from routing. |
| Edge runtime | Placement drain | 300 seconds | Preserve source placement during bounded transition overlap. |
| Edge runtime | Maximum domain artifact | 2,097,152 bytes | Reject oversized per-domain artifacts before signing or activation. |
| Origin destination safety | Private allow-list / blocked networks / blocked addresses | Empty lists | Add narrow operator policy without bypassing the built-in hard blocks. |
| Proxy defaults | Enabled / HTTPS redirect / HTTP versions / retries | On / Off / HTTP 1.1+2 / 0 | Supply deterministic defaults when a domain has no explicit override. |

The API is administrator-only and uses the same authentication, authorization, validation, audit, and idempotency middleware as other mutations:

```text
GET   /api/admin/system/settings
GET   /api/admin/system/settings/{group}
PATCH /api/admin/system/settings
PATCH /api/admin/system/settings/{group}
```

The collection update body is `{"group":"dns_lifecycle","values":{"deprovision_delay_days":14}}`. The group-specific form omits `group`. PATCH is partial within a group, but the merged complete group is revalidated and stored. Unknown groups/fields, duplicate list entries, invalid IP/CIDR values, and values outside documented bounds return `422`. Runtime-affecting edge, origin, and proxy changes return `202` with an operation and queue bounded edge reconciliation; heartbeat policy also queues platform DNS routing reconciliation. Other policy changes return `200` after the transactional revision and audit record commit.

CLI examples:

```sh
php artisan platform:settings:show
php artisan platform:settings:show dns_lifecycle --json
php artisan platform:settings:set dns_lifecycle '{"deprovision_delay_days":14,"domain_reclaim_cooldown_days":7}'
```

Run these commands in the `core` container in Compose deployments. CLI mutations have no browser actor but are still audited. A missing group row is a migration/deployment error and fails closed; run the explicit migration command rather than adding an environment override.

The separate **System DNS identity** settings remain a high-risk workflow because they change public authoritative identity. They continue to require normalized preview, a short-lived confirmation token, an audit entry, and asynchronous deployment rather than using the generic settings mutation.
