---
title: Control-plane high availability
description: Add an optional warm standby control node, switch over, promote after a failure, rejoin the old node, and return to a single node.
---

# Control-plane high availability

This page shows how to add a warm standby control node, switch over, promote after a failure, rejoin the old node, and go back to one node. **You need** two hosts on a private network and the Fleet tool. The requirements are listed below.

CDNFoundry stays serving when its control plane is down: DNS and HTTP use the last
valid state on every node. High availability is about the control plane itself: the
API, both panels, deployments, TLS issuance and renewal, purges, and edge sync.

It is **optional**. A single control node is a supported production setup. The status
page, the **Standby node** check, and the qualification report show `ha: none` as a
warning, never as a failure.

With high availability you run two control nodes:

- the **active** node runs everything;
- the **standby** node holds a streaming copy of both PostgreSQL databases and a
  Valkey replica, and runs no application service until you promote it.

There is no third node, no cluster, and no automatic failover. Two nodes cannot tell
a dead peer from a broken link between them. A standby that promoted itself during a
network fault would leave two nodes accepting writes, and desired state would fork.
Because DNS and HTTP keep serving while control is down, a few minutes without control
costs no traffic. One safe command is simpler than automatic failover on two nodes.

## What you get

| When the active node is lost | Result |
| --- | --- |
| DNS and HTTP | Keep serving the whole time. |
| API, panels, edge sync | Back within 5 minutes after you run `control promote`. |
| Committed desired state | Everything older than the replication lag, which is alerted at 10 seconds. |
| Edges | Resume syncing through their second control address, with no DNS change and no edit on any edge host. |

## What runs where

| Service | Active node | Standby node |
| --- | --- | --- |
| PostgreSQL: control database and PowerDNS primary data | Primary | Streaming replica (asynchronous) |
| Valkey (queues and locks) | Primary | Replica |
| `caddy`, `web`, `edge-control`, `core` | Running | Stopped |
| `horizon`, `scheduler` | Running | Stopped |
| PowerDNS hidden primary | Running | Stopped |
| Prometheus, Alertmanager, node exporter | Running | Running, so each node watches the other |
| ClickHouse, Grafana, Loki, Vector | Run on the node that already ran them, whichever is active | Not run |

Valkey holds no desired state. Everything a lost queue contained is rebuilt from
PostgreSQL by the reconcilers that already recover after a Valkey restart.

## Requirements

- **A private network or VPN between the two control nodes.** PostgreSQL and Valkey
  replication use it. The fleet generator refuses a replication address that is not
  private. Never replicate across the public internet.
- **Two fixed public addresses**, one per node. CDNFoundry does not use a floating or
  virtual address.
- **A certificate for `control.<operator-zone>`** that you obtain yourself (for example
  with DNS-01 from your own tooling). The standby cannot obtain a certificate while it
  serves nothing, so it must hold a valid one before a promotion. `add-standby`
  checks that it covers the name, matches the key, and has at least 14 days left.
- **SSH access as root from the machine that runs `cdnfoundry-fleet`** to both control
  nodes, with Docker and the same checkout layout (`/opt/cdnfoundry`).
- **PostgreSQL data checksums**, which the pinned PostgreSQL 18 image enables by default.
  `rejoin` uses `pg_rewind`; without checksums it falls back to a new base backup.

## How clients find the active node

Edges, DNS hosts, and users always use the names `control.<operator-zone>` and
`edge-control.<operator-zone>`, never a node address. You choose what those names point
at, and nothing else changes after a promotion.

1. **The names point to the active node with a TTL of 60 seconds.** `control promote`
   prints the two record changes. Apply them with your DNS provider.
2. **Edges do not wait for DNS.** Control sends every edge an ordered list of both
   control addresses inside signed configuration. The agent keeps the
   `edge-control` hostname for TLS and SNI, dials the addresses in turn, and remembers
   the one that last worked. It moves to the next address on a connection error, a TLS
   error, a `5xx` response, a `standby` response, or a timeout. Adding or removing a
   standby reaches every PoP through normal sync.
3. **Edges ignore a replaced node.** Every `edge-control` response carries the control
   epoch. An agent that accepted epoch 3 ignores any response with a lower epoch and
   stores the highest epoch it has accepted on disk.

## Keeping a returning node out

Promotion increments `control_epoch` in PostgreSQL. A node starts `horizon` and
`scheduler` only if all of these hold:

- its PostgreSQL is writable and is not in recovery;
- it is the node recorded as active;
- no peer it can reach reports a higher epoch (`cdnf:control:guard`).

A running node checks every minute (`cdnf:control:enforce`) and stops Horizon when it
finds it was replaced. Every scheduled task also runs only on the writable active node,
so a returning old node repeats no backup, render, or TLS task. Its `edge-control`
answers `503` with `X-CDNF-Control-State: standby`, and edges ignore it.

::: warning One limit you must know
If the old active node returns **while its peer is unreachable** and nobody has
demoted it, it cannot learn that it was replaced. Edges still ignore it, because they
have already accepted the higher epoch, but its own panel and API would accept writes
that nothing keeps. Therefore: after `promote`, keep the old node powered off or
isolated until you run `control rejoin`.
:::

## Shared identity

Both nodes hold the same `APP_KEY`, artifact-signing key, edge identity CA, session
key, edge-proxy secret, Valkey password, and ingress certificate, and the same ACME
account and TSIG keys (these two live in PostgreSQL and replicate). The fleet generator
distributes the files. Edge certificates therefore stay valid after a promotion. Each
node reports a **fingerprint** of each secret, never the value, and `ha: ready` needs
the fingerprints to match. A node with a different signing key cannot become ready.

## Add a standby

Authorize the Fleet public key (`cdnfoundry-fleet public-key`) on the new host, put both control nodes on a private
network or VPN, then give Fleet the new host's address and nothing else:

```sh
cdnfoundry-fleet control add-standby 203.0.113.11
```

Fleet already holds everything else. It names the node (`control-2`, or the next free `control-N`), records SSH access
and pins the host key, installs the prerequisites on the host, reads the one private address of each node over SSH for
replication, and reads the certificate the active node's Caddy already holds for `control.<operator zone>` so the standby
can serve it. It copies the shared secrets from its own vault. If a node has no private address or several, Fleet stops
and says so instead of guessing; pass `--replication-address` and `--active-replication-address` then. You can still
supply `--ingress-certificate` and `--ingress-key` to use your own certificate.

The control certificate renews on the active node. Run `cdnfoundry-fleet control refresh-ingress` to copy the renewed
certificate to the standby; `cdnfoundry-fleet doctor` flags a standby certificate with less than 30 days left.

The command stops at the first failure and can be run again to resume. In order, it:

1. Renders both addresses into every allowlist, firewall, and DNS secondary
   configuration, and rolls out only the bundles that changed. Edge and DNS bundles do
   not change.
2. Publishes the control address list to the edges and waits until every enabled PoP
   reports it in its heartbeat (`--edge-timeout`, default 900 seconds).
3. Prepares the active node as a replication source.
4. Copies the standby's bundle and secrets, copies both databases with
   `pg_basebackup`, and starts the replicas and the Valkey replica. It refuses to
   write into a data directory that already holds data.
5. Reports `ha: ready` only when both databases stream within 10 seconds, Valkey is
   linked, the fingerprints match, and every PoP holds the address list.

Replication needs ports 5432 (control database), 5433 (PowerDNS database), and 6379
(Valkey) between the replication addresses. Open them in your firewall between the two
nodes only. Also allow the standby to reach `pdns-primary`'s transfer port from every DNS
host, the same as the active node.

Check the state at any time:

```sh
cdnfoundry-fleet control status        # reads the active node
cdnfoundry-fleet control check         # also compares fingerprints and records the result
```

Run `control check` from a scheduled job. Replication lag and the Valkey link are
observed by the active node every minute without it. Secret fingerprints cannot be read
from the standby by the active node, so they are compared when you run `add-standby`
or `check`.

## Planned switchover

No committed write is lost, because the standby waits to replay the old primary's last
write position.

```sh
cdnfoundry-fleet control switchover
```

This runs `demote` on the active node (stops the application services, makes both
databases read-only, prints the final write positions) and then `promote` on the
standby with those positions. Apply the DNS record changes it prints, then rebuild the
old node:

```sh
cdnfoundry-fleet control rejoin
```

## After the active node is lost

1. Confirm the node is down or isolated. Edges, DNS, and HTTP are still serving.
2. Promote:

   ```sh
   cdnfoundry-fleet control promote --old-active-is-powered-off
   ```

   `promote` **refuses to run** while the old node still answers as active. If the old
   node does not answer at all, that does not prove it is down: confirm with
   `--old-active-is-powered-off`, or run `control demote` on it first. The command
   promotes both databases, makes the Valkey replica the primary, increments the
   epoch, replaces the standby's bundle with its active bundle, starts the application
   services, and waits until every edge syncs with the new node (5 minutes by default).
3. Apply the two record changes `promote` prints (TTL 60). Edges do not need them.
4. The installation now has one node. Rebuild a standby when the old node is back:

   ```sh
   cdnfoundry-fleet control rejoin
   ```

   `rejoin` rewinds the old node with `pg_rewind`, or takes a new base backup and keeps
   the old data directory renamed, then starts it as a replica. It reaches
   `ha: ready` after the same checks as `add-standby`.

A write committed more than 10 seconds before the loss is present after the promotion.
Replication is asynchronous, so a write in the last seconds may be lost; the bound is
alerted on.

## Remove the standby

```sh
cdnfoundry-fleet control remove-standby
```

This drops the replication slots, removes the standby from the edges' address list and
from every allowlist, stops the standby's services (its data stays on disk), and returns
the installation to `ha: none` with no edge or DNS error.

## Alerts

| Alert | Meaning |
| --- | --- |
| `ControlNodeUnreachable` | One control node cannot be scraped by the other for 2 minutes. The standby's own Alertmanager fires when the active node is lost. |
| `ControlStandbyNotStreaming` | The active node sees no streaming replica for 5 minutes. |
| `ControlReplicationLagHigh` | Replay lag over 10 seconds for 2 minutes. |
| `ControlValkeyReplicationBroken` | The Valkey replica is not linked. |
| `ControlSecretFingerprintMismatch` | The nodes do not hold the same secrets. |
| `EdgeMissingControlAddresses` | A PoP has not reported the current address list for 15 minutes. |

Run the external probers and the `Watchdog` receiver outside both control
nodes, so an external dead-man alarm fires if both nodes go silent.

## Limitations

- Two nodes only. No consensus, no automatic failover, no floating address.
- Replication is asynchronous.
- ClickHouse, Grafana, Loki, and Vector are single instances. Only the usage rollup
  tables are backed up (see [Backup and recovery](./backup-and-recovery.md)). Raw logs
  may be lost with the host.
- The fingerprint of the ingress and `edge-control` leaf certificates is not compared:
  the two nodes use leaf certificates from the same operator certificate and the same
  fleet CA. The edge identity CA, the edge server CA, and the ingress certificate are.
- Rotating the PowerDNS database password while a standby exists is not supported:
  remove the standby, rotate, and add it again.
