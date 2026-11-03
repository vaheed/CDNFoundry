---
title: Fleet engine, vault, and local web
description: How the Fleet tool stores state, reaches nodes over SSH, upgrades from signed release manifests, and serves its local web page.
---

# Fleet engine, vault, and local web

Fleet is the infrastructure tool. It manages hosts, roles, containers, releases,
certificates, backups, and HA. Domains, DNS records, pools, TLS, WAF, and users
stay in the Filament panels and PostgreSQL. The Fleet CLI and the local web page
are two faces of one engine; neither has logic of its own.

## The vault

Fleet state is one SQLite file, by default `~/.config/cdnfoundry-fleet/fleet.db`
(`--vault` changes it). Every file in it (state, secrets, PKI, HA material, SSH
keys, and pinned host keys) is encrypted with AES-256-GCM under a key derived
from your passphrase with scrypt. The passphrase needs at least 12 characters.
There is no recovery without it.

Give the passphrase with a prompt, `CDNF_FLEET_PASSPHRASE`, or
`CDNF_FLEET_PASSPHRASE_FILE`. Each command unlocks the vault into a private
temporary directory, runs, seals the changes, and deletes the directory. A
command that fails seals nothing.

`--state-dir` replaces the vault with a plain directory. It exists for tests and
development and stores secrets unencrypted.

## SSH transport

A node is reached with a host, a port, a user, and a private key. Fleet uses key
authentication only. It pins the host key the first time it connects and refuses
a node whose key later changes. Uploads stream a tar archive, write to a
temporary directory on the node, and move it into place, so a dropped connection
never leaves a half-written bundle.

## Signed release manifests

Fleet deploys only qualified releases. CI publishes a manifest that lists every
image digest, every rendered config checksum, the qualification profile
(`full` or `security-fast-lane`), whether the release holds an irreversible
migration, and which images and configs belong to each role. The manifest is
signed with Ed25519. Fleet refuses a manifest that is unsigned, whose signature
does not verify, that did not pass CI, or that pins an image by tag instead of
digest. After pulling, it refuses an image whose digest differs from the
manifest.

## Upgrades

An upgrade is a plan, then an apply.

1. The plan lists, per node, pre-pull, upload, validate, activate, and health
   gate. Nodes whose role has no changed image or config are not restarted.
2. Order: DNS hosts, then PoPs, then control (a standby first, then a planned
   switchover, then the old active node), then monitoring and telemetry. The
   first node of the first changed role is the canary. Nodes go one at a time.
3. Pre-pull fetches every new image and verifies its digest before anything
   restarts.
4. The new bundle is uploaded next to the active one and validated on the node
   with the service's own validator: `nginx -t`, `dnsdist --check-config`,
   `caddy validate`, `promtool check config`, `amtool check-config`,
   `vector validate`, and `docker compose config`. A config that fails is never
   activated.
5. Activation swaps the directories and runs `docker compose up -d`. The health
   gate then runs. If activation or the gate fails, that node returns to its
   previous bundle, and the roll stops. When the manifest does not allow mixed
   versions, nodes already upgraded return too.
6. Progress is journaled per step. If the SSH connection drops, run the same
   action again and it continues after the last finished step.

A rollback never reverses a database migration. A release marked as holding an
irreversible migration cannot be rolled back; restore from backup instead.

## Local fleet web

`cdnfoundry-fleet web` serves pages on `127.0.0.1:8765` and asks for the vault
passphrase. It refuses every other bind address, rejects requests whose `Host`
header is not loopback, and protects forms with a per-process token. Pages:
Overview (topology and "Needs attention"), Nodes (including adding a node),
Security, Upgrade, and History. "Run check" connects to every node and reports
reachability, container health, and whether the running release matches.

The web page has no JavaScript and loads one stylesheet that uses the same
tokens as the Filament panels.

## Status

Present: the vault, the SSH transport, signed manifests and diff, the plan and
apply engine with rollback and resume, per-service validators, and the local web
with node add and the stack check. Not yet present: the CLI commands that drive
the engine against real hosts (`upgrade`, `rollback`, node bootstrap), restic
backup of the vault, the full daily-check set, security status from Trivy
classifications, the admin panel first-run wizard, removal of the edge
management address fields, and the scripted quick start on fresh virtual
machines. See the Phase 8 gate in the [roadmap](../roadmap.md).
