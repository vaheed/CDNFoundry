# Security policy

Report suspected vulnerabilities privately through
[GitHub Security Advisories](https://github.com/vaheed/CDNFoundry/security/advisories/new).
Do not open a public issue.

Include the exact affected version, minimal reproduction, impact, topology, and
sanitized evidence. Never include customer data, tokens, passwords, database
dumps, certificate private keys, CA keys, signing keys, backup credentials, or
raw telemetry.

## Supported versions

| Version | Supported |
| --- | --- |
| 2.x | Yes |
| 1.x | No. v2.0.0 is a breaking release; deploy it from scratch. |
| 0.x | No |

There is no fixed response SLA. Operators should deploy immutable, verified
releases and qualify security updates promptly.

See the full [security reporting guide](docs/security/reporting.md) and
[deployment hardening](docs/security/hardening.md).
