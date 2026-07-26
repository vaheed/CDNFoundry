---
title: Troubleshoot edge and origin
description: Diagnose enrollment, heartbeat, artifacts, listeners, placement, and origin connectivity.
---

# Troubleshoot edge and origin

## Agent will not enroll

- Confirm `EDGE_ID` and the one-time token belong to the same enabled edge.
- Verify server trust through `EDGE_CONTROL_CA_CERTIFICATE`.
- Check edge-control port 8443, hostname SAN, firewall, and clock.
- Preserve the pending agent state so an exact CSR retry can succeed.
- If the token was consumed with another CSR or identity state was lost, rotate
  identity and use a new token.

## Heartbeat is stale

Check the agent container, client certificate expiry/serial, edge-control
availability, queue/network reachability, and persistent state permissions.
Do not make the edge routable by editing its timestamp.

## Artifact is rejected

Inspect the agent's bounded rejection reason:

- signature/checksum: verify the shared artifact signing key and transfer;
- incompatible artifact: compare agent and compatibility versions;
- candidate validation: inspect domain/pool runtime data and cell name;
- activation failure: check runtime directory ownership and filesystem capacity.

The old active directory should still serve. Fix desired state or agent/runtime
capacity, then reconcile.

## Listener is not ready

The shared cell must report `ready`, not drained, and the edge heartbeat must
set `listener_ready=true`. Check `/healthz`, token-protected status, runtime JSON,
bootstrap certificate files, MMDB, cache/temp permissions, and cell resource
limits.

## Origin test fails

Use the stable result to distinguish DNS, unsafe destination, connect timeout,
TLS validation, HTTP status, or response timeout. Recheck SNI and `Host`
separately. Origin addresses are validated again after resolution; a DNS change
to a blocked address is intentionally rejected.

Follow [Edge or cell failure](../operations/runbooks.md#edge-or-cell-failure) before
draining or restarting.
