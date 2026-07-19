# DDoS readiness profiles

Every proxied domain uses `standard`, `protected`, or `quarantine`. The selected
profile supplies safety ceilings; a user may make a value stricter but cannot
raise the ceiling or disable the platform guardrail. `suspected` and
`restricted` state enforce at least `protected`; `quarantined` enforces
`quarantine` regardless of the selected normal profile.

The operational states are `normal`, `suspected`, `restricted`, `quarantined`,
and `recovering`. Edge heartbeats report at most 20 aggregated noisy-domain
events, so attacker-controlled cardinality cannot create a heartbeat explosion.
Repeated rate, connection, origin-capacity, circuit, or cache-abuse signals can
move a normal domain to suspected and then restricted. A quiet ten-minute
window advances suspected to normal and restricted through recovering to
normal. Full quarantine remains subject to `manual`, `automatic`, or
`automatic_with_admin_notification` policy and target-first placement safety.

These controls preserve bounded edge and origin capacity; they are not
volumetric scrubbing. If an attack saturates a physical uplink, transit,
load-balancer, or service-IP capacity before traffic reaches OpenResty, upstream
provider mitigation or scrubbing is required.
