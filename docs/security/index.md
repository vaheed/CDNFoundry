---
title: Security model
description: Understand CDNFoundry trust boundaries, secret handling, authorization, and runtime protection.
---

# Security model

CDNFoundry separates management identities, edge identities, runtime status
tokens, artifact signing, service TLS, database credentials, backup credentials,
and metrics access.

Human and API authorization is policy based:

- active administrators may use `/admin` and administrator API routes;
- active domain users may use `/app` and only assigned domains;
- disabled users lose API tokens and are rejected on later session requests;
- edge agents enroll once and then use short-lived client certificates;
- `/metrics` requires a separate bearer token and is not a user API.

Private keys are never returned by status APIs. Sanctum hashes API tokens.
Custom and managed TLS private keys are encrypted with the application
encryption key. DNS cluster API keys are encrypted and not echoed after storage.

Continue with [Deployment hardening](/security/hardening) and
[Report a vulnerability](/security/reporting).
