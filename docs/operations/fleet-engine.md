---
title: Fleet engine, vault, and local web
description: How Fleet stores its state, reaches nodes over SSH with one key, upgrades from signed release manifests, and serves its local web page.
---

# Fleet engine, vault, and local web

Fleet is the infrastructure tool. It manages hosts, roles, containers, releases, certificates, backups, and HA.
Domains, DNS records, pools, TLS, WAF, and users stay in the Filament panels and PostgreSQL. The Fleet CLI and the
local web page are two faces of one engine (`scripts/cdnfoundry_fleet/engine.py`); neither has logic of its own, and
the local web never edits CDN state.

## The vault

Fleet state is one SQLite file, by default `~/.config/cdnfoundry-fleet/fleet.db` (`--vault` changes it). Every file in
it (state, secrets, PKI, HA material, the SSH key, pinned host keys, release manifests) is encrypted with AES-256-GCM
under a key derived from your passphrase with scrypt. The passphrase needs at least 12 characters, and there is no
recovery without it.

Give the passphrase with a prompt, `CDNF_FLEET_PASSPHRASE`, or `CDNF_FLEET_PASSPHRASE_FILE`. Each command unlocks the
vault into a private temporary directory, runs, seals the changes, and deletes the directory. A command that fails
validation seals nothing. An action that fails against a node does seal, so its journal survives and the same command
resumes.

`--state-dir` replaces the vault with a plain directory. It exists for tests and development and stores secrets
unencrypted.

### Backing up the vault

`cdnfoundry-fleet vault-backup` writes a copy of the (already encrypted) vault to the same off-host restic repository
the control backups use, tagged `fleet-vault`, and runs `restic check`. On a new workstation:

```sh
cdnfoundry-fleet vault-restore --repository s3:https://storage.example/bucket/cdnfoundry-control \
  --password-file restic-password
```

You need three things that live outside the fleet: the repository URL, the restic password, and the vault passphrase.
The command refuses to overwrite an existing vault. Losing the workstation never means losing control of the fleet,
and the cluster serves with the workstation off.

## One SSH key for the whole fleet

Fleet creates one Ed25519 key pair when the fleet is created. The private half stays inside the vault. Print the public
half and add it to `/root/.ssh/authorized_keys` on every host **before** adding that host:

```sh
cdnfoundry-fleet public-key
```

The local web shows the same line on the Nodes page. There is no per-node key and no private key to paste anywhere.

Fleet uses key authentication only, pins each host key on first use, and refuses a node whose key later changes. Uploads
stream a tar archive to a temporary directory on the node and move it into place, so a dropped connection never leaves
a half-written bundle. `cdnfoundry-fleet node-ssh --node NAME --host H [--ssh-port P] [--ssh-user U]` records how to
reach a node and pins its host key.

## Adding a node

```sh
cdnfoundry-fleet add-node ...                          # or the Nodes page of the web
cdnfoundry-fleet node-ssh --node pop-1 --host 203.0.113.30
cdnfoundry-fleet bootstrap --node pop-1 --country DE --continent EU
```

`bootstrap` shows a plan, asks for confirmation, then: pins the host key, installs the prerequisites (Docker with
Compose v2, `tar`, `python3`, `openssl`; on apt systems it installs them), enrolls a PoP, uploads the bundle, validates
it on the node, starts it with the bundle's own `start.sh`, and runs the health gate. Enrolling a PoP runs
`cdnf:edges:enroll` on the control node: it creates the edge, joins every cell slot to the default `shared` pool, and
hands the node its edge ID and one-time bootstrap token. The country and continent codes place the PoP in Geo-DNS.

## Adding a standby control node

`cdnfoundry-fleet control add-standby HOST` needs only the host address. Fleet does the rest: it names the node, pins its
host key, installs the prerequisites, reads each node's private replication address, takes the control hostname's
certificate from the active node's Caddy, copies the shared secrets from the vault, and runs the documented sequence. See
[Control plane high availability](control-plane-high-availability.md).

## Signed release manifests

Fleet deploys only qualified releases and never changes an image or a config file on a host outside a release. CI
builds a manifest with `scripts/cdnfoundry-release-manifest` and signs it with an Ed25519 key kept in the
`FLEET_RELEASE_SIGNING_KEY` secret. It lists every image digest, the sha256 of every static config the production
services mount, the environment image references CI built, the qualification profile (`full` or `security-fast-lane`),
whether the release holds an irreversible migration, which images and configs belong to each role, and the known
vulnerabilities from the Trivy scan.

Trust the public key once (`cdnfoundry-fleet trust-release-key --key-file release-signing.pub`). Fleet then refuses a
manifest that is unsigned, whose signature does not verify, that did not pass CI, that pins an image by tag, or that
describes a different checkout than the one Fleet renders from (a config checksum or image digest that differs). After
pulling, it refuses an image whose digest differs from the manifest.

## Upgrades

`cdnfoundry-fleet upgrade --manifest release.json` prints the release diff (images, configs, migrations, nodes
affected) and the plan, asks for confirmation, and applies it. Nodes whose images and configs do not change are not
restarted.

1. Order: DNS hosts, then PoPs, then control (a standby first, then a planned switchover, then the old active node),
   then monitoring. The first node of the first changed role is the canary. Nodes go one at a time.
2. Pre-pull fetches every new image and verifies its digest before anything restarts.
3. The new bundle is uploaded next to the active one and validated on the node: the bundle's `validate.sh` and the
   service's own validator (`nginx -t`, `dnsdist --check-config`, `caddy validate`, `promtool check config`,
   `amtool check-config`, `vector validate`). A config that fails is never activated.
4. Activation swaps the directories and runs `start.sh`. The health gate then checks that containers are healthy, DNS
   answers over UDP and TCP, HTTP and HTTPS are served on IPv4 and IPv6 where the node has an address, and control
   answers `/api/health`. If activation or the gate fails, that node returns to its previous bundle and the roll stops.
   When the manifest forbids mixed versions, nodes already upgraded return too.
5. Progress is journaled per step. If the SSH connection drops, run the same command again and it continues after the
   last finished step.

`cdnfoundry-fleet apply-config` does the same for a changed configuration at the same release, and
`cdnfoundry-fleet rollback [--node N]` returns nodes to the previous bundle. A rollback never reverses a database
migration: a release marked as holding an irreversible migration cannot be rolled back, so restore from backup.

Major versions of PostgreSQL, ClickHouse, and Valkey are not part of an ordinary upgrade; follow the guided upgrade
procedures for them.

## Checks and security status

`cdnfoundry-fleet doctor` (and the web's "Run check") connects to every node and reports: host reachable, containers
healthy, node certificate valid for 30 more days, running release equals the manifest, and, from the control node, every
component and queue the Laravel health page shows (PostgreSQL, queue backend, workers, scheduler, ClickHouse, Vector,
HA and replication, edges and their sync, listeners, cells, pools, DNS deployments, TLS, purges, MMDB, usage, backups).
On the monitoring host it checks that Prometheus reports the `Watchdog` alert.

`cdnfoundry-fleet security` lists the known vulnerabilities in the running release. Each finding is exactly one of:
fixed in an available release (`--available-manifest`), classified until a date from
`supply-chain/trivy-classifications.yaml`, no fix upstream yet, or fixed upstream with no release carrying it. An
expired classification is flagged.

## Backup and restore

`cdnfoundry-fleet backup-now` runs a control backup on the active control node and waits. `cdnfoundry-fleet restore
--node N --snapshot ID` shows what it will replace and requires the typed phrase `RESTORE <snapshot>`. It restores into
an empty control node by default (`cdnf:backups:restore --snapshot`, which refuses a database that holds users).
Overwriting a live control node needs `--overwrite-live` and the preflight `--operation` UUID.

## Local fleet web

`cdnfoundry-fleet web` serves pages on `127.0.0.1:8765` and asks for the vault passphrase. It refuses every other bind
address, rejects requests whose `Host` header is not loopback, and protects forms with a per-process token. It has no
JavaScript and one stylesheet that uses the same tokens as the Filament panels.

| Page | What it does |
| --- | --- |
| Overview | KPIs, the topology with a health color per node, "Needs attention", and "Run check" |
| Nodes | the fleet public key, the node list, adding a node, and its plan with a live install |
| Security | vulnerabilities in the running release |
| Upgrade | trust a release key, show the diff and plan for a signed manifest, apply with live progress |
| Config | compare and deploy changed bundles |
| Rollback | return the fleet to the previous release, typed confirmation |
| Backups | backup now, vault backup, restore with a typed confirmation |
| HA | standby state and the add, switchover, demote, promote, rejoin, and remove actions |
| Secrets | rotate a fleet secret and deploy it |
| History | every action with its operator and result |

Long actions run as one job at a time; the job page refreshes itself.

## First-run wizard

The admin panel has a **First-run setup** page that walks through the CDN settings, one step per group. Every setting
shows its default, each step has a reset, and **Keep all defaults** changes nothing. It disappears from the navigation
once finished or skipped, and everything stays editable under **Platform settings**.

## Qualification

The Fleet suite (`make fleet-test`) runs the engine over a real SSH connection to a local `sshd` with stub bundles: a
rolling upgrade, resume after a dropped connection, a broken canary rolling back with the other nodes untouched, a config
rejected on the node, and a full rollback. Vault backup and restore run against real restic. The browser suite for the
web is `tests/browser/run-fleet.sh`. `tests/e2e/fleet_quickstart.py` runs the whole path on disposable hosts through
either the CLI or the web.
