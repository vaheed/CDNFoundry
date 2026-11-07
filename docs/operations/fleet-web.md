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
Python environment under your user directory, installs PyYAML and cryptography, and
opens your browser. Any other arguments go to `cdnfoundry-fleet web`, for example
`--port 9000`. Do not run `web.py` directly; it is part of a package.

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
| [Nodes](#nodes) | List hosts, add one, and install it |
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
3. **Signed release.** Trust the public key your CI signs with, then paste the signed
   release manifest.
4. For each edge, enter its country and continent codes. They place it in Geo-DNS.

**Step 2: review and confirm.** Press **Review**. Fleet lists, host by host and in start
order, what it will do. Type `DEPLOY` and press **Deploy to servers**. Fleet installs the
hosts one after another and stops at the first failure. Watch the live log on the job page.

**Expected result:** every host shows **Installed**, and the roadmap on **Overview**
moves on to the health check.

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

Fleet only installs releases that carry a valid signature from a key you trust.

<div class="steps">

1. If no key is trusted yet, paste the public key your release pipeline signs
   with into **Release public key (PEM)**, and choose **Trust this key**.
2. Paste the signed release manifest (JSON) and choose **Show plan**. On a new
   fleet, choose **Use this release for the first install** instead.
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

With one control node, the page offers **Add standby**. Enter the standby host
address and its SSH port, after you authorize the Fleet key on that host.

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

## History

Every action is recorded with the time, the kind, the operator, and the result.
Use it to see what changed before a problem started.

## Command line equivalents

Everything here has a command. See the [Fleet CLI reference](../reference/fleet-cli.md).
