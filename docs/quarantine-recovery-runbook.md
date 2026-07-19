# Quarantine and recovery runbook

1. Confirm the bounded event aggregates, origin health, current state, active
   and target pool, and whether unrelated domains remain healthy.
2. Use **Restrict** first when reduced limits are sufficient. Record the
   operation, revision, reason, and event window.
3. Use **Quarantine** only when isolation is required. CDNFoundry activates and
   acknowledges the quarantine target before draining the source. A failed
   target keeps the previous valid placement and traffic.
4. Check the target cell's listener, resource limits, public IPv4/IPv6,
   acknowledgement, and request reason codes. Do not delete source state or
   restart unrelated cells.
5. After the attack and origin pressure are quiet, use **Release**. Confirm the
   target-first move back to normal placement and the `recovering` state.
6. Observe at least the recovery/circuit cooldown and verify legitimate IPv4 and
   IPv6 traffic before declaring recovery. The scheduler returns a quiet
   recovering domain to normal.

If the physical uplink is saturated, coordinate upstream mitigation before
changing edge limits. Increasing local ceilings during saturation usually
reduces isolation and is not a substitute for transit scrubbing.
