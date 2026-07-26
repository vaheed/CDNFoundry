# Edge emergency mode runbook

Emergency mode is an administrator-only, audited, asynchronous control for an
edge or cell. Pool withdrawal is a separate revisioned control that removes only
that pool from new DNS answers. Prefer an expiring duration; the maximum is
1,440 minutes.

1. Identify the smallest target and record healthy comparison traffic.
2. Select only required actions. Supported controls include GET/HEAD-only,
   disabled bodies or retries, reduced keep-alive/origin concurrency,
   cache-only/stale-only, maintenance response, quarantine, unknown-host
   rejection, and service-IP withdrawal.
3. Record the operation and task results from every participating edge. The
   agent persists active cell controls and reapplies them after restart.
4. Verify the target emits `edge_emergency_mode` while a healthy cell/domain is
   unaffected. Verify IPv4 and IPv6 service paths.
5. Clear the mode explicitly or confirm automatic expiry sends a durable clear
   task. Replayed idempotency keys return the original operation; a changed
   payload conflicts.
6. For pool withdrawal, confirm only that pool disappears from new PowerDNS
   answers. Restore it only after cells and addresses are ready.

Emergency mode does not stop traffic upstream of the service address and cannot
recover a saturated physical circuit.
