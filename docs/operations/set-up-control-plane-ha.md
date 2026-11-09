---
title: Set up control-plane HA step by step
description: Add a standby control node on a local network or over a WireGuard tunnel, check it, practise a switchover, and remove it again.
---

# Set up control-plane HA step by step

This page walks you through adding a standby control node to a running installation. It covers both ways to connect the two control nodes: a **local (private) network** and a **WireGuard tunnel** over the public internet. Pick one in step 2.

For what high availability is, what it does not do, and the failure runbook, read [Control-plane high availability](control-plane-high-availability.md). This page is the hands-on setup.

**You need:**

- a running installation with one control node, installed by Fleet (see [Fleet engine, vault, and local web](fleet-engine.md))
- one more host for the standby: Ubuntu 22.04 or 24.04, the same size as the control node (4 GB of memory is the minimum for a control node), with a fixed public address
- a login on that host that can run `sudo` without a password (root works too)
- the Fleet tool on your workstation, with the vault that manages the installation

Throughout this page the active node is `control-1` at `203.0.113.10`, and the new standby is `control-2` at `203.0.113.11`. Replace them with your own addresses.

::: warning The standby copies a live system
`add-standby` copies both control databases and the secrets to the new host. It stops at the first failure and you can run it again. It refuses to write into a data directory that already holds data, so it never overwrites a node that was used before. Use a clean host.
:::

## 1. Prepare the standby host

1. Install Ubuntu and make sure the host can resolve names. Docker and Fleet download images and packages by name. If `getent hosts ghcr.io` prints nothing, enable the resolver:

   ```sh
   sudo systemctl enable --now systemd-resolved
   getent hosts ghcr.io
   ```

   **Expected result:** `getent` prints an address for `ghcr.io`.

2. Authorize Fleet's key on the new host. On your workstation, print it:

   ```sh
   cdnfoundry-fleet public-key
   ```

   Add that one line to `~/.ssh/authorized_keys` of the login user on `control-2`. Fleet uses key authentication only.

3. Point the names. At your DNS provider:

   - set the TTL of `control.<operator-zone>` and `edge-control.<operator-zone>` to **60 seconds**. Both keep pointing at the active node;
   - create `control-2.<operator-zone>` pointing at the standby's public address.

   Check them from your workstation. Add `--check` to ask the public resolver 1.1.1.1:

   ```sh
   cdnfoundry-fleet dns-names --standby-address 203.0.113.11 --check
   ```

## 2. Connect the two nodes privately

PostgreSQL and Valkey replicate between the nodes. That traffic must never cross the public internet unencrypted, so Fleet refuses a replication address that is not private. Choose the option that matches your hosts.

### Option A: both hosts are on the same private network

Use this when both servers share a private LAN or VLAN (for example `10.0.0.0/24`) with a private address on each host.

1. Check that each host has exactly one private address:

   ```sh
   ip -o -4 addr show scope global
   ```

   **Expected result:** one address in a private range (`10.x`, `172.16-31.x`, or `192.168.x`) per host, besides the public one.

2. Check that the hosts reach each other on it (`ping 10.0.0.2` from `control-1`).

3. Open TCP ports `5432` (control database), `5433` (PowerDNS database), and `6379` (Valkey) between those two addresses only. Do not open them to anything else.

Fleet reads each host's one private address over SSH by itself. You do not pass an address. If a host has several private addresses, Fleet stops and tells you to pass them (step 4).

### Option B: the hosts only have public addresses (WireGuard)

Use this when the two servers are in different data centers or networks. WireGuard builds an encrypted private network between them. The replication addresses are the tunnel addresses.

1. Install WireGuard on both hosts and create a key pair on each:

   ```sh
   sudo apt-get update && sudo apt-get install -y wireguard
   cd /etc/wireguard && sudo sh -c 'umask 077; wg genkey | tee wg0.key | wg pubkey > wg0.pub'
   sudo cat /etc/wireguard/wg0.pub        # the public key to give the other host
   ```

2. On `control-1`, create `/etc/wireguard/wg0.conf`. Replace `<control-1 private key>` with the contents of its `wg0.key` and `<control-2 public key>` with the other host's `wg0.pub`:

   ```ini
   [Interface]
   Address = 10.77.0.1/24
   ListenPort = 51820
   PrivateKey = <control-1 private key>

   [Peer]
   PublicKey = <control-2 public key>
   AllowedIPs = 10.77.0.2/32
   Endpoint = 203.0.113.11:51820
   PersistentKeepalive = 25
   ```

   On `control-2`, create the mirror image: `Address = 10.77.0.2/24`, its own private key, `AllowedIPs = 10.77.0.1/32`, `Endpoint = 203.0.113.10:51820`, and the public key of `control-1`.

   Keep the file private (`chmod 600`). The private key never leaves its host.

3. Allow UDP `51820` between the two public addresses, then start the tunnel on both hosts:

   ```sh
   sudo systemctl enable --now wg-quick@wg0
   ```

4. Check it:

   ```sh
   ping -c 2 10.77.0.2          # from control-1
   sudo wg show                 # "latest handshake" is a few seconds ago
   ```

   **Expected result:** the ping answers and `wg show` reports a recent handshake.

`10.77.0.0/24` is only an example. Any private range that does not clash with your other networks works. Fleet accepts it because it is a private address.

## 3. Add the standby

Run this on your workstation. It needs the vault passphrase as usual.

- Local network (option A):

  ```sh
  cdnfoundry-fleet control add-standby 203.0.113.11 --ssh-user ubuntu
  ```

- WireGuard (option B): pass the tunnel addresses, because the public address is not private and Fleet must not guess:

  ```sh
  cdnfoundry-fleet control add-standby 203.0.113.11 --ssh-user ubuntu \
    --replication-address 10.77.0.2 --active-replication-address 10.77.0.1
  ```

Leave out `--ssh-user` when you log in as root. Fleet names the node `control-2`, stores how to reach it, pins its host key, installs Docker and the other prerequisites, copies the shared secrets and both databases, and starts the replicas. The command takes several minutes: the standby pulls its images and copies the databases.

It runs five steps and prints each one:

1. rendering both control addresses into every allowlist and configuration
2. publishing the control address list to the edges
3. preparing the active node as a replication source
4. deploying the standby and copying both databases
5. checking replication, secrets, and PoPs

Step 5 waits until every PoP reports the two addresses in its heartbeat (`--edge-timeout`, 900 seconds by default). This is normal and can take several minutes.

If it stops, read the message, fix the cause, and run the same command again. It resumes.

## 4. Check that HA works

```sh
cdnfoundry-fleet control status
cdnfoundry-fleet control check
```

**Expected result:** `"ha": "ready"`, `"active": "control-1"`, `"standby": "control-2"`, `"replication_lag_seconds"` near 0, and every enabled edge with a recent heartbeat. Run `control check` from a scheduled job so secret fingerprints are compared regularly.

## 5. Practise a switchover

Do this once, in a quiet hour, so the first real failover is not the first time.

```sh
cdnfoundry-fleet control switchover
```

Do not stop the command with Ctrl+C or a timeout while it runs. It stops the application on `control-1`, makes both of its databases read-only, promotes `control-2` at the next epoch, starts the application there, and waits until the edges sync. It prints the two DNS record changes (`control` and `edge-control`, TTL 60) to apply. Apply them at your DNS provider. Edges do not wait for DNS, so this is for browsers and API clients.

Then rebuild the old node as the standby:

```sh
cdnfoundry-fleet control rejoin
cdnfoundry-fleet control check
```

**Expected result:** `ha: ready` with `control-2` active and `control-1` standby. To go back, run `control switchover` and `control rejoin` again, and point the two records back at `control-1`.

If a switchover is interrupted, check `cdnfoundry-fleet control status` and the [runbook](control-plane-high-availability.md#after-the-active-node-is-lost). A node that Fleet already demoted stays read-only until you promote the other one.

## 6. Remove the standby

```sh
cdnfoundry-fleet control remove-standby
```

This drops the replication slots, removes the standby from the edges' address list and from every allowlist, and stops its services. The installation returns to `ha: none` with no edge or DNS error. The data of the old standby stays on disk.

To reuse the host, delete its CDNFoundry data (`/opt/cdnfoundry`, the Docker volumes) or reinstall it. For WireGuard, also run `sudo systemctl disable --now wg-quick@wg0` and delete `/etc/wireguard/wg0.conf` on both hosts.

## If something goes wrong

| Message or symptom | Cause and fix |
| --- | --- |
| `has no private address` or `several private addresses` | Fleet found none or more than one private address. Pass `--replication-address` and `--active-replication-address`. |
| `could not pull ...: ... lookup ... connection refused` | The standby cannot resolve names. Run `sudo systemctl enable --now systemd-resolved` on it. |
| `Mutable bundle image rejected` when promoting | The standby was added without the release image pins. Update Fleet to a release that adds them, remove the standby, and add it again. |
| Step 5 waits a long time | A PoP has not reported the address list yet. Check `cdnfoundry-fleet watch`; the PoP must reach `edge-control.<operator-zone>`. |
| `refuses to write into a data directory that already holds data` | The host was a standby or control node before. Delete `/opt/cdnfoundry` and its Docker volumes, or use a clean host. |

For the full requirements, the shared identity, and the failure runbook, see [Control-plane high availability](control-plane-high-availability.md). For every option, see the [Fleet CLI reference](../reference/fleet-cli.md).
