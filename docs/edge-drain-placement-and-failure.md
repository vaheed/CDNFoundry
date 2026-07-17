# Edge drain, placement, and failure runbook

Shared placement uses a stable hash of the canonical domain across enabled shared pools. Every installation also has a quarantine pool. Dedicated pools are administrator-created exceptions, never the per-domain default.

For a move, persist the target, deliver and validate it, publish target routing while retaining the source, wait at least the configured DNS drain interval, then remove the source and activate the placement revision. A rejected candidate leaves source routing and the previous snapshot active. Never shorten the drain below the maximum relevant DNS TTL plus routing convergence allowance.

Draining an edge removes it from new preferred routing after the routing artifact converges; it does not delete state. Watch active connections and origin work, then disable it. Delete only after it is both drained and disabled. An edge or cell crash must be handled by restarting only that bounded cell. Do not restart sibling cells or the agent.

When cache or temporary storage is full, stop admission and return controlled errors; never spill into another cell or the host root filesystem. Move a noisy domain to quarantine using the same target-first sequence. If validation, health, disk, or capacity checks fail, cancel the move and retain the last valid shared placement.

For snapshot corruption or agent loss, preserve evidence, start with an empty candidate directory, pull the signed full snapshot, validate it, and atomically activate. If validation fails, keep serving the prior state and report a stable rejection reason. Size shared cells from measured memory, file-descriptor, connection, cache, and temporary-storage use while reserving host capacity for the OS, agent, activation and telemetry.
