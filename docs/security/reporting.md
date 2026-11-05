---
title: Report a vulnerability
description: How to report a CDNFoundry security vulnerability privately, what to include, and what to leave out.
---

# Report a vulnerability

Use this page if you think you found a security problem in CDNFoundry. You need a
GitHub account and the exact version or commit you tested.

::: danger Report privately
Do not open a public issue, discussion, or pull request that contains exploit
details, secrets, customer data, private addresses, or raw production evidence.
:::

## Send the report

Open the private
[GitHub security advisory form](https://github.com/vaheed/CDNFoundry/security/advisories/new)
and include:

- The exact affected version or commit.
- The role of the host and the topology around it.
- The smallest set of steps that reproduces the problem.
- The impact, and which trust boundary it crosses.
- Sanitized logs or requests.
- Any temporary mitigation you found.

Leave out customer data, access tokens, passwords, database dumps, certificate
private keys, CA keys, signing keys, backup credentials, and raw telemetry.

## What to expect

The project sets no fixed response time. The repository's `SECURITY.md` lists
version 1.2.x as the supported line. As an operator, run exact immutable releases,
and apply a security update soon after you qualify it. See
[Software supply chain](../operations/software-supply-chain.md).
