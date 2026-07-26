---
title: Troubleshooting
description: Diagnose CDNFoundry failures from durable state toward derived runtimes.
---

# Troubleshooting

Start with the durable operation and desired revision, then move outward:

1. Check `/api/ready` and administrator component health.
2. Inspect the relevant operation, deployment, task, or certificate order.
3. Compare desired and acknowledged revisions.
4. Check the responsible Horizon lane and failed jobs.
5. Check the private runtime service and its last-valid state.
6. Reconcile only the smallest affected scope.

Choose a symptom:

- [DNS](/troubleshooting/dns)
- [Edge and origin](/troubleshooting/edge-and-origin)
- [TLS and cache](/troubleshooting/tls-and-cache)
- [Telemetry](/troubleshooting/telemetry)

Do not repair desired state in PowerAdmin or by editing generated edge files.
Those are derived and will be overwritten.
