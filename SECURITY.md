# Security policy

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub's private
security advisory feature for `vaheed/CDNFoundry` and include:

- the affected version or commit;
- the component and deployment topology;
- reproduction steps or a minimal proof of concept;
- the security impact and any known workaround.

Do not include real customer data, production credentials, certificate private
keys, signing keys, or tokens. Maintainers will acknowledge a complete report,
coordinate validation and remediation privately, and publish an advisory when a
fix is available. No fixed response time is promised for this volunteer project.

## Supported versions

Until tagged stable releases exist, only the current `main` branch is supported.
Operators should deploy an immutable reviewed commit SHA and monitor the public
CI result for that exact commit.
