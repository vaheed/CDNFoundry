---
title: Report a vulnerability
description: Report CDNFoundry security vulnerabilities privately without exposing sensitive data.
---

# Report a vulnerability

::: danger Report privately
Do not open a public issue, discussion, or pull request containing exploit
details, secrets, customer data, private addresses, or raw production evidence.
Use the private advisory channel below.
:::

Do not open a public issue for a suspected vulnerability.

Use the repository's private
[GitHub security advisory form](https://github.com/vaheed/CDNFoundry/security/advisories/new).
Include:

- affected exact version or commit;
- deployment role and topology;
- minimal reproduction steps;
- impact and trust boundary;
- sanitized logs or requests;
- any temporary mitigation.

Do not include customer data, access tokens, passwords, database dumps,
certificate private keys, CA keys, signing keys, backup credentials, or raw
telemetry.

The project does not publish a fixed response SLA or supported-version matrix in
the implementation. Operators should track exact immutable releases and apply
security updates after qualification.
