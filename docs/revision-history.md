# Configuration revisions and automatic deployment

A domain revision is a monotonic change identifier, not a count that must stay small. Revision `300` is safe: the database uses a 64-bit integer, edges compare revisions rather than allocating an array or process per number, and deleted history does not cause numbers to be reused. Never reset or renumber a domain because an old edge or queued job could then mistake obsolete state for current state.

Revision numbers are identifiers, not a promise that every intermediate number becomes an artifact. If a pool move requests revision `11` and a concurrent TLS or cache change creates revision `12` before publication, reconciliation intentionally skips obsolete revision `11` and publishes a revision `12` artifact containing both changes. Edges must converge on the latest desired revision; gaps prove coalescing and do not indicate missing production state.

Every DNS, origin, proxy, TLS, cache, lifecycle, rollback, or placement mutation stores desired state and increments the domain revision once. The request then queues reconciliation automatically. Users do not need a deploy button. The browser panels intentionally omit manual deployment; failed work is retried from the operation failure workflow. `POST /api/domains/{domain}/deploy` remains a policy-scoped recovery endpoint that requeues the latest desired revision without creating a new revision.

The domain page shows when the desired revision last changed, when the active edge revision was validated, each DNS cluster's desired/active revision and acknowledgement time, and the creation time beside each retained rollback revision. Audit history remains the durable answer to who changed what and when. The `revision_changed_at` column is updated automatically whenever a domain revision changes, so DNS-only revisions have an accurate time even when no edge snapshot is required.

Derived edge snapshots and per-edge artifacts are bounded by the **Configuration history** platform settings:

- **Revision retention (days)** defaults to `1` and keeps every revision within that window.
- **Minimum revisions per domain** defaults to `10` and preserves recent rollback points even when they are older than the time window.
- The current desired revision, active edge revision, and an in-progress placement revision are always protected.

The scheduler runs `php artisan edge:prune-revisions` daily. The command is bounded to 1,000 deletions by default (`--limit` accepts `1` through `10000`) and deletes only derived `edge_revisions` and matching `edge_artifacts`. It does not change desired state, active state, audit logs, domain revision numbers, PowerDNS data, or live edge files. An edge that reconnects after pruning receives the latest complete domain artifact; sequence gaps are valid.
