---
title: Fleet engine, vault, and local web
description: How Fleet stores its state, reaches nodes over SSH with one key, upgrades from signed release manifests, and serves its local web page.
---

# Fleet engine, vault, and local web

Fleet is the tool that manages your servers. It handles hosts, roles, containers, releases, certificates, backups,
and control-plane high availability (HA). Domains, DNS records, pools, TLS, WAF, and users stay in the admin and
domain panels and in PostgreSQL. Fleet never edits them.

Fleet has a command line (`cdnfoundry-fleet`) and a local web page. Both run the same engine
(`scripts/cdnfoundry_fleet/engine.py`). Neither has logic of its own. Use whichever you prefer. This page explains
what the engine does. For every command and option, see the [Fleet CLI reference](../reference/fleet-cli.md).

## The vault

Fleet keeps all its state in one SQLite file called the vault. The default path is
`~/.config/cdnfoundry-fleet/fleet.db`. The `--vault` option changes it.

Fleet encrypts every file in the vault with AES-256-GCM. The files are state, secrets, PKI, HA material, the SSH
key, pinned host keys, and release manifests. The encryption key comes from your passphrase through scrypt. The
passphrase needs at least 12 characters. Nobody can recover the vault without it.

Give the passphrase in one of three ways: answer the prompt, set `CDNF_FLEET_PASSPHRASE`, or set
`CDNF_FLEET_PASSPHRASE_FILE` to a file path.

Each command works in four steps. It unlocks the vault into a private temporary directory, runs, seals the
changes, and deletes the directory. A command that fails validation seals nothing. An action that fails on a node
does seal, so its journal survives. Run the same command again and it resumes.

`--state-dir` replaces the vault with a plain directory. It is for tests and development only. It stores secrets
unencrypted.

### Backing up the vault

Run `cdnfoundry-fleet vault-backup`. Fleet copies the vault, which is already encrypted, to the same off-host
restic repository that the control backups use. It tags the copy `fleet-vault` and runs `restic check`. On a new
workstation, restore it like this:

```sh
cdnfoundry-fleet vault-restore --repository s3:https://storage.example/bucket/cdnfoundry-control \
  --password-file restic-password
```

You need three things that live outside the fleet: the repository URL, the restic password, and the vault
passphrase. The command refuses to overwrite an existing vault. Add `--snapshot ID` to restore a specific backup.
If you lose the workstation, you do not lose control of the fleet. The fleet also keeps serving while the
workstation is off.

## One SSH key for the whole fleet

Fleet creates one Ed25519 key pair when you create the fleet. The private half stays inside the vault. Print the
public half and add it to `/root/.ssh/authorized_keys` on every host **before** you add that host:

```sh
cdnfoundry-fleet public-key
```

The command prints one public key line. The local web shows the same line on the **Nodes** page. There is no
per-node key and no private key to paste anywhere.

Fleet uses key authentication only. It pins each host key the first time it connects and refuses a node whose key
later changes. Uploads stream a tar archive to a temporary directory on the node and move it into place, so a
dropped connection never leaves a half-written bundle.

To record how Fleet reaches a node and pin its host key, run:

```sh
cdnfoundry-fleet node-ssh --node pop-1 --host 203.0.113.30 --ssh-port 22 --ssh-user root
```

## Reaching nodes as a non-root user

Fleet manages nodes as root. If the SSH user is not root (for example `node-ssh --ssh-user deploy`), Fleet runs
every remote command through `sudo -n`. That user needs passwordless sudo. Fleet never asks for or stores a
password.

## Adding a node

Add the node to the plan, record how to reach it, then bootstrap it. You can do the same on the **Nodes** page of
the local web. Replace the example values with your own:

```sh
cdnfoundry-fleet add-node --node pop-1 --role edge --region eu --location fra \
  --public-ipv4 203.0.113.30
cdnfoundry-fleet node-ssh --node pop-1 --host 203.0.113.30
cdnfoundry-fleet bootstrap --node pop-1 --country DE --continent EU
```

`bootstrap` needs a release first. Run `set-release`: Fleet builds it for the release you chose at setup, looks up the image digests in the registry, and signs it with its own key. Pass `--manifest release.json` only if you sign releases yourself.
Bootstrap shows a plan and asks for confirmation. Then it does these steps in order:

1. Pins the host key.
2. Installs the prerequisites: Docker with Compose v2, `tar`, `python3`, and `openssl`. On apt systems Fleet installs
   them. Bootstrap refuses a wildcard DNS bind when the host resolver already uses port 53.
3. Pre-pulls the release images and checks their digests.
4. Enrolls a PoP (a point of presence, one edge site) and issues its in-memory key material.
5. Uploads the bundle and validates it on the node.
6. Starts the bundle with its own `start.sh`.
7. Runs the health gate.

When Fleet enrolls a PoP, it runs `cdnf:edges:enroll` on the control node. That command creates the edge, joins
every cell slot to the default `shared` pool, and creates the PoP's service endpoint from its public addresses. It
then hands the node its edge ID and a one-time bootstrap token. The country and continent codes place the PoP in
Geo-DNS.

Edge and DNS-edge hosts keep a listener certificate and key, a state encryption key, and (DNS-edge only) the node
key in `/dev/shm/cdnfoundry`. The files are owned by UID 10101 with mode 0400. Fleet issues them from its own PKI
and stores them in the vault. It writes them again on every deploy, because `/dev/shm` is memory and does not
survive a reboot. Fleet also turns host swap off, as the bundle requires. Nobody copies keys by hand.

If a host binds DNS to a wildcard address and `systemd-resolved` already listens on port 53, bootstrap stops before
it starts anything and names the fix:

```sh
cdnfoundry-fleet update-node --node pop-1 --bind-ipv4 203.0.113.30 --bind-ipv6 2001:db8::30
```

Leave out `--bind-ipv6` if the node has no IPv6 address.

## Adding a standby control node

Run `cdnfoundry-fleet control add-standby HOST`. It needs only the host address. Fleet then names the node, pins
its host key, installs the prerequisites, reads each node's private replication address, and takes the control
hostname's certificate from the active node's Caddy. It also copies the shared secrets from the vault and runs the
documented sequence. See [Control plane high availability](control-plane-high-availability.md).

## Signed release manifests

A release manifest is a signed file that names exactly what a release contains. Fleet deploys only releases that
a manifest describes. It never changes an image or a config file on a host outside a release.

CI publishes and scans the images but does not sign a manifest. The release owner builds and signs one with
`scripts/cdnfoundry-release-manifest`. The owner uses an Ed25519 key that stays outside CI, and the digests come
from the published release evidence. The manifest lists:

- every image digest;
- the sha256 of every static config that the production services mount;
- the environment image references CI built;
- the qualification profile (`full` or `security-fast-lane`);
- whether the release holds an irreversible migration;
- which images and configs belong to each role;
- the known vulnerabilities from the Trivy scan.

By default Fleet builds this manifest itself from your checkout and signs it with a key
it keeps in the vault. If you sign releases yourself, pin your public key once:

```sh
cdnfoundry-fleet trust-release-key --key-file release-signing.pub
```

Fleet then accepts only manifests signed with that key and refuses a manifest in any of these cases:

- it is unsigned, or its signature does not verify;
- it did not pass CI;
- it pins an image by tag instead of by digest;
- it describes a different checkout than the one Fleet renders from (a config checksum or image digest differs).

After pulling images, Fleet also refuses an image whose digest differs from the manifest.

## Upgrades

```sh
cdnfoundry-fleet upgrade --manifest release.json
```

The command prints the release diff (images, configs, migrations, nodes affected) and the plan. It asks for
confirmation and then applies the plan. Nodes whose images and configs do not change are not restarted. Add
`--plan-only` to see the plan and change nothing. The upgrade follows these rules:

<div class="steps">

1. Order: DNS hosts, then PoPs, then control (a standby first, then a planned switchover, then the old active node),
   then monitoring. The first node of the first changed role is the canary. Nodes go one at a time.
2. Pre-pull fetches every new image and verifies its digest before anything restarts.
3. The new bundle is uploaded next to the active one and validated on the node: the bundle's `validate.sh` and the
   service's own validator (`nginx -t`, `dnsdist --check-config`, `caddy validate`, `promtool check config`,
   `amtool check-config`, `vector validate`). A config that fails is never activated.
4. Activation swaps the directories and runs `start.sh`. Only a bundle that passed its health gate becomes the rollback
   target. The gate waits a bounded time and checks that every long-running service is running and healthy, DNS answers
   over UDP and TCP (any reply proves serving; a PoP without zones answers REFUSED), ports 80 and 443 accept connections,
   all on the node's bind addresses over IPv4 and IPv6, and control answers `/api/health` on its own hostname. If activation or the gate fails, that node returns to its previous bundle and the roll stops.
   When the manifest forbids mixed versions, nodes that already upgraded return too.
5. Progress is journaled per step. If the SSH connection drops, run the same command again and it continues after the
   last finished step.

</div>

`cdnfoundry-fleet apply-config` does the same for a changed configuration at the same release.
`cdnfoundry-fleet rollback [--node N]` returns nodes to the previous bundle. Without `--node` it rolls back the
whole fleet. A rollback never reverses a database migration. If a release holds an irreversible migration, you
cannot roll it back. Restore from backup instead.

Major versions of PostgreSQL, ClickHouse, and Valkey are not part of an ordinary upgrade; follow the guided upgrade
procedures for them.

## Checks and security status

`cdnfoundry-fleet doctor` connects to every node. On the **Overview** page of the local web, the button **Run check of the whole stack** does the same. The check reports:

- the host is reachable;
- the containers are healthy;
- the node certificate is valid for at least 30 more days;
- the running release equals the manifest;
- from the control node, every component and queue that the Laravel health page shows. These are PostgreSQL, the
  queue backend, workers, the scheduler, ClickHouse, Vector, HA and replication, edges and their sync, listeners,
  cells, pools, DNS deployments, TLS, purges, MMDB, usage, and backups.

On the monitoring host, the check also confirms that Prometheus reports the `Watchdog` alert. Add `--json` for
machine-readable output.

## Parallel preparation

`deploy`, `bootstrap`, and `upgrade` prepare every host at once: SSH pinning, prerequisites, and image pulls run on all
hosts side by side (up to 6 hosts, and up to 4 images at a time on each host), before the first host is uploaded to,
validated, or restarted. Each host still runs its own steps in order, and each pulled image is checked against its
pinned digest. Nothing in this phase changes what a host serves, so a failed pull needs no rollback; one failure stops
new steps, lets the running ones finish, and keeps the journal so the same command resumes. Uploads, validation,
activation, and the health gate stay one host at a time, in start order.

`cdnfoundry-fleet alert-webhook [--url https://...]` shows or sets where Alertmanager sends alerts. Install, upgrade, and
config runs refuse to start until it is set, because Alertmanager will not start without a notifier.

## Inventory, live watch, and progress

`cdnfoundry-fleet inventory` prints the hardware and system of every host as last read (processor, memory, disk,
kernel, Docker, platform) with the findings that need attention. `--refresh` reads the hosts over SSH first and records
the result in the vault; `--node` limits it to some hosts and `--json` prints it for scripts. The read is one
read-only command per host.

`cdnfoundry-fleet watch` is a live table of every host: state, load per core, memory, disk, and containers, read
every `--interval` seconds (at least 5). `--once` prints one reading and exits non-zero when a host is unreachable;
`--json` prints one JSON line per reading.

Long commands (`deploy`, `bootstrap`, `upgrade`, `apply-config`, `rollback`, `inventory --refresh`) show a progress bar
and the current step on a terminal, and one plain line per step when the output is piped or in CI. The first Ctrl+C
asks Fleet to stop before the next step, so no command is cut half way on a host and the same command resumes from the
journal; a second Ctrl+C interrupts at once.

`cdnfoundry-fleet security` lists the known vulnerabilities in the running release. Each finding has exactly one
status:

- fixed in an available release (pass a newer manifest with `--available-manifest`);
- classified until a date in `supply-chain/trivy-classifications.yaml`;
- no fix upstream yet;
- fixed upstream, but no release carries the fix yet.

Fleet flags an expired classification.

## Backup and restore

`cdnfoundry-fleet backup-now` runs a control backup on the active control node and waits for it to finish.

`cdnfoundry-fleet restore --node N --snapshot ID` shows what it will replace. You must type the phrase
`RESTORE <snapshot>` to continue (pass it with `--confirm`). By default it restores into an empty control node. It
runs `cdnf:backups:restore --snapshot`, which refuses a database that already holds users. To overwrite a live
control node, pass `--overwrite-live` and the preflight `--operation` UUID. See
[Backup and recovery](backup-and-recovery.md).

## Local fleet web

```sh
cdnfoundry-fleet web
```

The command serves the pages on `127.0.0.1:8765` (change the port with `--port`) and asks for the vault
passphrase. It refuses every other bind address. It rejects requests whose `Host` header is not loopback. It
protects forms with a per-process token. The pages use no JavaScript and one stylesheet, which shares its design
tokens with the Filament panels.

| Page | What it does |
| --- | --- |
| Overview | KPIs, the topology with a health color per node, **Needs attention**, and **Run check of the whole stack** |
| Nodes | the fleet public key, the node list, adding a node, and its plan with a live install |
| Security | vulnerabilities in the running release |
| Upgrade | trust a release key, show the diff and plan for a signed manifest, apply with live progress |
| Config | compare and deploy changed bundles |
| Rollback | return the fleet to the previous release, typed confirmation |
| Backups | backup now, vault backup, restore with a typed confirmation |
| HA | standby state and the add, switchover, demote, promote, rejoin, and remove actions |
| Secrets | rotate a fleet secret and deploy it |
| History | every action with its operator and result |

Fleet runs long actions as one job at a time. The job page refreshes itself.

## First-run wizard

This wizard is part of the admin panel, not of Fleet. It is listed here because you meet it right after the first
install.

The admin panel has a **First-run setup** page. It walks through the CDN settings, one step per group. Every
setting shows its default, and each step has a reset. **Keep all defaults** changes nothing.

The page appears only on a fresh install, while the administrator is the only user and nothing has been created or
changed. Creating a user, domain, or edge hides it for good. So does changing any setting, finishing, or skipping.
Every setting stays editable under **Platform settings**.

## Qualification

The Fleet suite (`make fleet-test`) runs the engine over a real SSH connection to a local `sshd` with stub bundles.
It covers a rolling upgrade, resume after a dropped connection, a broken canary that rolls back while the other
nodes stay untouched, a config that the node rejects, and a full rollback. Vault backup and restore run against
real restic. The browser suite for the local web is `tests/browser/run-fleet.sh`. The script
`tests/e2e/fleet_quickstart.py` runs the whole path on disposable hosts, through either the CLI or the web.
