---
title: Fleet web guide
description: Install, check, upgrade, back up, and roll back your hosts from the local Fleet web page, one page at a time.
---

# Fleet web guide

Fleet web is a web page that runs on your own workstation. It does the same jobs
as the Fleet command line: install hosts, check them, upgrade, back up, and roll
back. It never changes domains, DNS records, or users. Those stay in the
[admin and domain panels](../guides/admin-panel.md).

**You need:** the Fleet vault you created during [setup](../deployment/production-quick-start.md),
its passphrase, and a browser on the same workstation.

## Open it

```sh
python3 scripts/fleet-web.py      # Windows: py scripts\fleet-web.py
```

This works the same on Windows, macOS, and Linux. The first run creates a private
Python environment under your user directory, installs PyYAML, cryptography, and certifi, and
opens your browser. Any other arguments go to `cdnfoundry-fleet web`, for example
`--port 9000`. Do not run `web.py` directly; it is part of a package.

**Requirements:** Python 3.10 to 3.13 (3.12 or 3.13 recommended), OpenSSH, and this
repository checkout. Fleet needs the checkout for the Compose files and release checks, so
run it from a clone. Docker is not needed on your workstation; it runs on the hosts Fleet
manages. If your default `python3` is newer than 3.13, the script restarts itself with
`python3.13`, `3.12`, `3.11`, or `3.10` when one is on your `PATH`. Otherwise install one
(macOS: `brew install python@3.13`; Windows: `py -3.13 scripts\fleet-web.py`).

**If setup fails with "No matching distribution found":** your Python is too new for the
prebuilt libraries. Delete the Fleet environment folder (`~/.config/cdnfoundry-fleet/venv`
on macOS and Linux, `%APPDATA%\cdnfoundry-fleet\venv` on Windows) and run the script again
with `python3.13`.

If you run `scripts/cdnfoundry-fleet` with no arguments in a terminal, it asks whether to
open Fleet web (recommended) or run the setup wizard in the terminal.

With no vault yet, the page shows **Set up your fleet**: choose the vault passphrase, enter
the operator domain, platform domain, release, the control host, and one line per other
host (`name role ipv4 [region]`). Select **Create fleet** and Fleet creates the vault, plans
the hosts, renders their bundles, and opens the **Nodes** page. With an existing vault, the
page asks for the passphrase. Fleet serves it at `http://127.0.0.1:8765`; use `--port` to
pick another port.

**Expected result:** your browser shows the **Overview** page with a menu on the left.

Fleet web listens on `127.0.0.1` only. It refuses any other address and any
request that does not come from your own machine. To use it on a remote
workstation, open an SSH tunnel to that port. Do not publish it.

Only one action runs at a time. If you start a second one, the page says
"Another action is running; wait for it to finish".

## The pages

| Page | Use it to |
| --- | --- |
| [Overview](#overview) | See the fleet at a glance and run a health check |
| [Deploy](#deploy) | Follow the road: connect hosts, set the release, review, and confirm |
| [DNS names](#dns-names) | See which names must point to which host, and check them with 1.1.1.1 |
| [Certificates](#certificates) | Use your own certificates, or a local one, for the control, Grafana, and telemetry names |
| [Nodes](#nodes) | List hosts with their live state and hardware, add one, and install it |
| [Inventory](#inventory) | See the processor, memory, disks, kernel, Docker, and containers of every host, and what needs attention |
| [Security](#security) | See known vulnerabilities in the running release |
| [Upgrade](#upgrade) | Trust a release key, review a plan, and upgrade |
| [Config](#config) | Deploy changed configuration |
| [Rollback](#rollback) | Return to the previous release |
| [Backups](#backups) | Back up now, back up the vault, and restore |
| [HA](#ha) | Manage the standby control node |
| [Secrets](#secrets) | Rotate a fleet secret |
| [History](#history) | See every action, who ran it, and the result |

## Deploy

Deploy is the road from an empty plan to installed hosts, in two steps. Nothing is
installed until you confirm in step 2. Every page also has a **What is this page?** box.

**Step 1: prepare.**

1. **Connect your hosts.** Fleet logs in with one SSH key, never a password. The page shows
   the key and the exact command to run on each host. If your SSH port is not 22, or your
   user is not root, set them in the setup form (`pop-1 dns-edge 203.0.113.10 EU port=2222
   user=ubuntu`), on **Save this login for hosts without one**, or on the host's page. A
   non-root user needs passwordless sudo.
2. Press **Test connections**. Each host shows **Connected**, or the reason it failed and
   what to do: key not accepted, nothing listening on the port, no answer in time, or a
   changed host key.
3. **DNS names and certificates.** Create the names on the [DNS names](#dns-names) page.
   Fleet checks them with 1.1.1.1. Or put your own certificates on the
   [Certificates](#certificates) page.
4. **Alert webhook (optional).** The https:// address that receives Alertmanager's alerts. You
   can skip it: without one, alerts still fire and show in Prometheus, but nobody is notified.
   Set it now, or at any time later on this page or on **Config**, then deploy the change from
   **Config**. Leave the field empty and save to turn notifications off again. You can also use
   `cdnfoundry-fleet alert-webhook --url`. If you have no notifier yet, open **What is this, and
   where do I get one?** on the page. To try it out, a throwaway address from webhook.site is
   enough; for real notifications, create a webhook trigger in Zapier, Make, or n8n and forward
   it to email, Slack, or Telegram. A Slack or Discord incoming-webhook address does not work
   directly, because Alertmanager sends its own JSON format.
5. **Release.** Choose **Use release vX**. Fleet installs the release you chose at setup.
   It looks up the published images, builds the release description, and signs it
   with its own key, so you paste nothing. If you sign releases yourself, open
   **I sign releases myself** and use your key and manifest instead.
6. For each edge, enter its country and continent codes. They place it in Geo-DNS.

**Step 2: review and confirm.** Press **Review**. If a management name does not yet point to its host in 1.1.1.1 and you gave no certificate, the review says so: Let's Encrypt cannot issue for it, so Fleet generates a **local certificate** for that name when you deploy (the page opens over HTTPS, and your browser warns until you trust the local CA). Fleet lists, host by host and in start
order, what it will do. Type `DEPLOY` and press **Deploy to servers**. Fleet installs the
hosts one after another and stops at the first failure. Before any host is changed, Fleet
connects to **every host at the same time**, installs prerequisites, and pulls the images
(up to 6 hosts at once and 4 images at once per host), so the install no longer waits for one
server to finish downloading before the next starts. The log says which image each host is
pulling and how far along it is. A failed pull changes no host. Fleet opens the action page,
where you [watch every step](#watch-an-action).

**You can leave and come back.** An installation runs in Fleet web, not in your browser tab.
While it runs, every page shows a **Running** bar with a link to the live view, and **Deploy**
shows the installation in progress. If it stops (a host failed, you pressed **Stop after this
step**, or Fleet web was closed), **Deploy** shows **Continue the installation**. Continue
skips every step that is already done and picks up at the first one that is not.

**Expected result:** every host shows **Installed**, and the roadmap on **Overview**
moves on to the health check.

## Watch an action

Every long action (install, upgrade, config, check, rollback, backup, secret rotation, and
reading hardware) opens one action page.

- **Tasks.** Every step is listed up front, grouped by host, and ticked as it finishes:
  waiting, running, done, failed, or already done in an earlier run, with the time each
  step took.
- **Progress.** A bar and a count ("7 of 18 steps") follow the tasks. A failed step stays
  marked, and the page says where the action stopped and why.
- **Log.** The log follows the end while the action runs. Untick **Follow the end** to read
  it, type in **Filter the log** to find a line, and use **Download log** to keep it.
- **Stop.** **Stop after this step** lets the step in progress finish and stops before the
  next one. Nothing is cut half way on a host, and running the action again resumes. It is
  shown only for actions that have steps.
- The tab title shows the progress, so you can follow it from another tab. The page works
  without JavaScript as a page that refreshes itself.

Fleet keeps the latest 20 actions on this page. Every finished action, with its steps and
log tail, stays in [History](#history).

## Overview

<Screenshot name="fleet-overview" alt="The Fleet web Overview page with four counters, a topology diagram, a Needs attention box, and a Run check button." />

The page shows four counters: the number of nodes, the release, how many nodes
were healthy at the last check, and the backup mode. Below them, a diagram
groups the nodes by role: Control, DNS, Edge, and Telemetry.

**Run a check:**

1. Choose **Run check of the whole stack**.
2. Wait for the action page to finish.
3. Return to **Overview**.

**Expected result:** every dot in the diagram turns green, and **Needs attention**
says "Every check passed." A failed check appears there as a line such as
`pop-tehran-a: <check name>`.

**If it fails:** fix the first line in **Needs attention**, then run the check again.
Before the first check, the box says nothing has been checked in this session.

## DNS names

Fleet lists every name you must create, and the address each must point to. Create them
before the first install: the control node requests its certificates when it starts, and
that fails if the names do not point to it yet.

| Name | Points to | Create it at |
| --- | --- | --- |
| `control.<operator domain>` | the control node | the DNS provider of the operator domain |
| `edge-control.<operator domain>` | the control node | the DNS provider of the operator domain |
| `grafana.<operator domain>` | the monitoring host (the control node when monitoring is on the same host) | the DNS provider of the operator domain |
| `telemetry.<operator domain>` | the monitoring host | the DNS provider of the operator domain |
| `ns1.<platform domain>`, `ns2.<platform domain>` | your DNS hosts | the registrar of the platform domain, as nameserver glue |

Use an `A` record for an IPv4 address and an `AAAA` record for IPv6. Press **Check DNS now**
and Fleet asks the public resolver **1.1.1.1** (the way Let's Encrypt will see your names) and says whether each name points to the right host. It needs UDP port 53 to 1.1.1.1 from your computer.
A new record can take a few minutes to appear. With monitoring disabled, Grafana and
telemetry are not listed.

## Certificates

Caddy on the control node serves the control panel, Grafana, and telemetry names over HTTPS.
By default it asks Let's Encrypt, using the **admin email** you gave in setup. That works
only when 1.1.1.1 sees the names pointing at the host and ports 80 and 443 reach it. If it
cannot, the control panel does not open. You have three ways around that:

| Option | When to use it |
| --- | --- |
| **Automatic** (default) | The names are correct in DNS and the ports are open. |
| **Your own certificate** | You have a certificate (a SAN or wildcard certificate can cover several names). Paste the chain, your certificate first, and the private key without a password. Fleet checks that the key matches, every chosen name is covered, and it has not expired, then keeps the key in the encrypted vault. |
| **Local certificate** | You have nothing yet. Fleet makes its own certificate authority and signs a certificate for the names. Deploy does this by itself for names that are not ready. Download the local CA from the page and trust it in your browser, or replace the certificate later. |

**Use automatic** switches a name back. If hosts are already installed, deploy a change with
**Config**. A control standby serves the same control certificate as the active node.

## Nodes

<Screenshot name="fleet-nodes" alt="The Nodes page with a table of hosts, the Fleet SSH public key, and the Add a node form." />

The table lists each node's name, role, host, IPv4 address, release, and state.
The state is "Not checked" until you run a check.

### Add and install a node

<div class="steps">

1. Copy the **Fleet SSH public key** shown on the page.
2. On the new host, add that line to `/root/.ssh/authorized_keys`. Do this
   **before** the next step. Fleet signs in with the key only.
3. Under **Add a node**, enter the name (lowercase letters, digits, and
   dashes), the role, the public IPv4 address, the SSH port, and the SSH user.
4. Choose **Add node and show plan**.
5. Read the plan on the node page. It lists every step Fleet will do.
6. For an edge node, enter the two-letter country and continent codes. They place
   the PoP in Geo-DNS.
7. Choose **Install and start this node**.

</div>

**Expected result:** an action page shows each step as it runs, then "Finished".

**If it fails:** the action page names the step that failed. Fleet stops at the failing step. Fix the cause and run the install
again.

## Security

The page lists the known vulnerabilities in the images of the running release.
Each row has a severity, the finding, the image, and a state.

| State | Meaning |
| --- | --- |
| Fixed | The release already carries the fix |
| Classified | The team recorded why the finding does not apply, with an expiry date |
| No fix | No fix exists yet |
| Fix upstream | A fix exists upstream and the release does not have it yet |
| Expired | A recorded classification ran out and needs a new decision |

**Expected result:** the page says the release "has no known vulnerabilities in
its scan", or lists rows. Before the first release is recorded, it asks you to
upgrade to a signed manifest first.

## Upgrade

Fleet upgrades to the release your checkout is on. Check out the newer release first
(`git fetch --tags && git checkout vX.Y.Z`); Fleet then does the rest.

<div class="steps">

1. Open **Upgrade**. It shows the running release and the release of your checkout.
2. Choose **Show the plan for vX**. On a new fleet, choose **Use release vX for the
   first install** instead. Fleet looks up the published images and signs the release
   itself. If you sign releases yourself, open **I sign releases myself** and paste your
   key and manifest.
3. Read the page. It shows the release, its profile, whether it holds an
   irreversible migration, each image or config that changes, and the ordered
   plan. Services that do not change are listed as "Unchanged, not restarted".
4. Choose **Upgrade the fleet**.

</div>

**Expected result:** the **Rolling upgrade** action page walks the nodes in order
(DNS hosts, then PoPs, then control, then monitoring), one at a time, and ends
"Finished". Run a check on **Overview** afterwards.

**If it fails:** Fleet stops on the failing node, and that node returns to its
previous bundle. The page says "The plan has expired; show it again" if you wait too long between
steps 3 and 4. Show the plan again.

For the same steps in the command line, see [Upgrade](../deployment/upgrade.md).

## Config

Use this when you changed fleet settings and want hosts to match. Fleet renders the
bundles it would send now, compares them with what each node runs, validates the
new files on the node, and swaps them in, one node at a time.

Choose **Show changes and deploy**.

**Expected result:** an action page lists the nodes it changed. Nodes with no
change are left alone.

## Rollback

The page shows the running release and the previous one. To roll the whole fleet
back, type `ROLLBACK` in the box and choose **Roll the whole fleet back**.

**If it is blocked:** when the running release holds an irreversible migration,
the page says so and offers no button. Restore from a backup instead, as
described in [Backup and recovery](backup-and-recovery.md).

## Backups

- **Back up now** runs a backup of the control node.
- **Back up the Fleet vault to the restic repository** saves your Fleet state.
- **Restore** puts a snapshot onto a control node. Pick the control node, enter the
  snapshot ID, and type `RESTORE` followed by that ID.

By default, restore only works on an empty control node. To overwrite a live one,
tick **Allow overwriting a live control node** and enter the preflight operation
UUID from the admin panel.

The Overview counter shows "disabled" until you configure backups. See
[Backup and recovery](backup-and-recovery.md).

## HA

With one control node, the page is a road in three steps. Deploy the control node first.

1. **Point the addresses.** Enter the standby's name, public IPv4, and SSH port. Fleet
   shows the records to set and checks them with 1.1.1.1: `control.<operator domain>`
   and `edge-control.<operator domain>` stay at the **active** node with a TTL of 60
   seconds (so moving them after a failure is quick), and `<standby name>.<operator domain>`
   points at the standby.
2. **Prepare the standby host.** A fixed public IPv4, a private network or VPN between
   the two control nodes, TCP 5432, 5433, and 6379 open between their private addresses only,
   and the Fleet SSH key on the host.
3. **Deploy the standby.** Type `ADD STANDBY`. Fleet installs the prerequisites, copies the
   secrets and the control certificate, sets up replication, and starts the standby. The step
   is blocked until 1.1.1.1 sees the records; you can choose to continue without correct DNS.

With a standby, the page shows the active and standby nodes and one button for
each action: planned switchover, demote the active node, promote the standby,
rejoin the former active node, remove the standby, and refresh the standby's
control certificate. Each one asks you to type its name in capitals.

See [Control-plane high availability](control-plane-high-availability.md) for when
to use each action.

## Secrets

Pick one of the secrets Fleet can rotate on its own: the metrics token, the
telemetry token, the edge proxy secret, or the log authentication token. Type
`ROTATE` and choose **Rotate and deploy**. Fleet creates the new value and
deploys it to the nodes that use it.

Application keys, the backup password, and the database passwords are not in this
list. They rotate only through the guided command-line procedures.

## Inventory

**Inventory** shows what each host is made of: processor and cores, memory, system disk,
kernel, Docker version, platform (bare metal or the hypervisor), time sync, global IPv6,
network interfaces and speed, and every container with its status. Press **Read hardware of
every host**; Fleet runs one read-only command per host over SSH and records the result in
the vault. It changes nothing on a host.

**Needs attention** lists what deserves a look: a disk over 85 % (bad over 95 %), memory over
90 %, swap in use, high load per core, a clock that is not synchronized, Docker not running,
a container that is not running or not healthy, an IPv6 address in the plan that the host
does not have, and hosts that run different kernels, Docker versions, or operating systems.
These are hygiene checks, not hardware requirements of CDNFoundry.

A host's own page adds its full hardware card, trend lines, disks, interfaces, containers
with a **Logs** link, **Check this host**, and **Read hardware again**. The log page shows the
last 300 lines of one container and can follow it every 5 seconds. Values after names such
as token, secret, and password are hidden, and only containers the host reported can be read.

## Live view

While Fleet web is open it probes every host every 60 seconds with the same read-only
command, so the **Overview**, **Nodes**, and **Inventory** pages show whether each host is
online, has containers that need attention, or is unreachable, with memory and disk gauges.
The topology diagram colors each host by that state and links to its page. The numbers
refresh by themselves while the tab is visible, and **Probe now** reads once on demand.

- Probing is read-only, runs at most 6 hosts at a time, and is skipped while an action runs.
- Readings live in memory only. Closing Fleet web discards them; the last **Read hardware**
  result stays in the vault.
- Change the interval with `cdnfoundry-fleet web --monitor-interval 120`, or turn probing
  off with `0`. The minimum is 15 seconds.

## History

Every action is recorded with the time, the kind, the operator, and the result. Open an
entry to see its steps and the tail of its log. Use it to see what changed before a problem
started.

## Command line equivalents

Everything here has a command. See the [Fleet CLI reference](../reference/fleet-cli.md).

| In Fleet web | On the command line |
| --- | --- |
| Set up your fleet | `cdnfoundry-fleet setup` (with no arguments, choose the terminal wizard) |
| Save a login for hosts without one | `ssh-defaults --ssh-user root --ssh-port 22`, or `node-ssh` per host |
| Test connections | `test-connection` |
| Inventory and **Read hardware** | `inventory` (add `--refresh` to read the hosts first, `--json` for scripts) |
| The live view | `watch` (a table that refreshes; `--once` prints once) |
| **Stop after this step** | Ctrl+C once; a second Ctrl+C interrupts at once |
| DNS names and **Check DNS now** | `dns-names --check` (and `--standby-address` for HA) |
| Certificates: own, local, automatic | `management-tls set`, `local`, `remove`, `list`, `ca` |
| Release | `set-release`, `upgrade` (add `--manifest` only if you sign releases yourself) |
| Review and **Deploy to servers** | `deploy --plan-only`, then `deploy --location pop-1=DE:EU` |
| HA: check DNS, deploy the standby | `dns-names --standby-address`, `control add-standby` |
