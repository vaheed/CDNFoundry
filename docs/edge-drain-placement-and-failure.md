# Edge drain, placement, and failure runbook

Shared placement uses a stable hash of the canonical domain across enabled shared pools. Every installation also has a quarantine pool. Dedicated pools are administrator-created exceptions, never the per-domain default.

For a move, persist the target, deliver and acknowledge the target candidate on every participating fresh edge, publish target routing while retaining the source, and start the configured drain only after every enabled DNS target reports successful deployment. After the drain, deliver a second signed artifact that removes the source. The placement and operation become active/succeeded only after every target edge acknowledges that source-removal artifact. A rejected candidate leaves source routing and the previous snapshot active. Never shorten the drain below the maximum relevant DNS TTL plus routing convergence allowance.

Every participating cell has unique durable IPv4 and optional IPv6 service
addresses. Configure all addresses before enabling a new pool. When an edge is
added after a pool is already enabled, its unaddressed cell is not a participant
and cannot delay or receive that pool's traffic; it joins only after its complete
service-address pair is saved and a ready heartbeat arrives. PowerDNS publishes
country, continent, and global address RRsets per enabled pool; proxied domain
zones reference their active/target pool rather than embedding the complete edge
list. Shared, quarantine, and dedicated transitions use the same state machine.

Cell drain, undrain, and restart tasks travel through the authenticated private OpenResty control listener. Drain state is persisted by the agent and restored after agent/runtime restart. Restart temporarily drains the cell, replaces only that cell's workers, records `last_restart_at`, and resumes traffic after the bounded window unless it was already administratively drained. The agent has no Docker socket and cannot affect sibling cells.

Draining an edge removes it from new preferred routing after the routing artifact converges; it does not delete state. Watch active connections and origin work, then disable it. Delete only after it is both drained and disabled. An edge or cell crash must be handled by restarting only that bounded cell. Do not restart sibling cells or the agent.

When cache or temporary storage is full, stop admission and return controlled errors; never spill into another cell or the host root filesystem. Move a noisy domain to quarantine using the same target-first sequence. If validation, health, disk, or capacity checks fail, cancel the move and retain the last valid shared placement.

For snapshot corruption or agent loss, preserve evidence, start with an empty candidate directory, pull the signed full snapshot, validate it, and atomically activate. If validation fails, keep serving the prior state and report a stable rejection reason. Size shared cells from measured memory, file-descriptor, connection, cache, and temporary-storage use while reserving host capacity for the OS, agent, activation and telemetry.
