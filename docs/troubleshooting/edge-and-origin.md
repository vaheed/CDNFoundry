---
title: Troubleshoot edge and origin
description: Fix edge agents that will not enroll, stale heartbeats, rejected artifacts, listeners that are not ready, and origin test failures.
---

# Troubleshoot edge and origin

Use this page when an edge agent will not connect, an edge is marked unroutable, a
new configuration is rejected, or CDNFoundry cannot reach an origin. You need
administrator access and shell access to the edge host.

::: danger Preserve identity and active runtime state
Do not delete agent state, reset revision numbers, or edit generated gateway or
cell files. Doing so destroys the evidence and the last valid state you need to
recover safely.
:::

For gateway listener problems, Host or SNI rejection, revision drift, and
last-valid recovery, see [Edge gateway ingress](../operations/gateway-ingress.md).

## Agent will not enroll

<div class="steps">

1. Confirm `EDGE_ID` and the one-time token belong to the same enabled edge.
2. Confirm the agent trusts the server through `EDGE_CONTROL_CA_CERTIFICATE`.
3. Check edge-control port 8443, the hostname in the certificate SAN, the firewall,
   and the clock.
4. Keep the pending agent state. An exact retry of the same certificate request can
   then succeed.

</div>

**Expected result:** the agent enrolls and the edge shows a heartbeat. A bootstrap
token is valid for 24 hours. If the token was used with a different certificate
request, or the identity state is lost, rotate the identity and use a new token.

## Heartbeat is stale

Check these on the edge host:

- the agent container is running;
- the client certificate has not expired, and its serial is the one control expects;
- edge-control is reachable over the network;
- the agent's state directory has the right permissions.

Do not edit a timestamp to make an edge routable.

The administrator edge status and the Cells and Endpoints tables refresh every five
seconds. A healthy agent updates its heartbeat on that interval. If the timestamp
stays fixed, read the agent log for the HTTP error.

`active_sequence was not issued to this edge` means the agent's local active state
is ahead of the edge's acknowledged sequence in the database and of the retained
artifacts. Do not reset the sequence or delete agent state. Either restore the
matching control database, or deliberately reconcile or replace the edge identity
and state as one recovery operation.

`heartbeat_stale` is the main loss-of-contact reason. `gateway_not_ready` is checked
only after the heartbeat is fresh. It means the gateway did not report a valid active
map that matches the agent revision. Fix the first reason shown. Do not restart
unrelated containers.

## Artifact is rejected

An artifact is the signed configuration bundle an edge downloads. Read the agent's
rejection reason:

- **Signature or checksum:** check the shared artifact signing key and the transfer.
- **Expired delivery or sequence replay:** check the clocks on the edge and control
  and the current artifact sequence. Never reset the edge sequence to fit an old download.
- **Incompatible artifact:** compare the agent version and the compatibility versions.
- **Candidate validation:** inspect the domain and pool runtime data and the cell name.
- **Activation failure:** check runtime directory ownership and free disk space.

**Expected result:** the old active bundle keeps serving. Fix the desired state or
the agent capacity, then reconcile.

## Listener is not ready

The shared cell must report `ready` and not be drained. The edge heartbeat must
report `listener_ready=true`. Check:

- `/healthz` on the cell and the token-protected status;
- the runtime JSON;
- the bootstrap certificate files;
- the MMDB (GeoIP) file;
- cache and temp directory permissions;
- the cell resource limits.

## Origin test fails

The test returns a stable result that tells apart these causes: DNS, unsafe
destination, connect timeout, TLS validation, HTTP status, or response timeout.
Check SNI and the `Host` header separately.

CDNFoundry checks the origin address again after name resolution. If a DNS change
points the name at a blocked address, the test fails on purpose. The balancer checks
the numeric peer address once more just before each TCP attempt, including retries.

Client counters and cache-key counters use separate fixed-size shared zones. When
the cache-admission zone is full, the cell skips new cache stores. Per-domain rate
and origin-concurrency counters fail closed for that domain.

Follow [Edge or cell failure](../operations/runbooks.md#edge-or-cell-failure)
before you drain or restart.
