---
title: Production fleet configuration reference
description: Reference for CDNFoundry production fleet CLI options, setup config schema, node objects, feature configuration, and generated bundle contract.
---

# Production fleet configuration reference

Copy `deploy/production/examples/starter-fleet.json` or `multi-region-fleet.json` to a protected local `fleet.json`, then change deployment data there. Checked-in examples are templates; repository scripts and generated Compose manifests are not configuration surfaces.

## Common command options

These options work before or after a subcommand:

| Option | Default | Purpose |
| --- | --- | --- |
| `--state-dir` | `/var/lib/cdnfoundry-fleet` | Protected authoritative fleet state |
| `--output-dir` | `/var/lib/cdnfoundry-fleet/bundles` | Generated per-node bundles |
| `--repo-root` | Repository containing the script | Base Compose and production overlays |
| `--config` | none | JSON input for setup or node commands |
| `--non-interactive` | false | Never prompt; fail when required input is absent |
| `--dry-run` | false | Validate intent without writing state or bundles |
| `--yes` | false | Confirm destructive or rotation operations |

The convenience wrapper uses repository-local defaults unless environment variables override them:

```text
CDNFOUNDRY_FLEET_STATE_DIR
CDNFOUNDRY_FLEET_OUTPUT_DIR
```

## Setup config schema

```json
{
  "preset": "control-monitoring",
  "global": {
    "operator_domain": "ops.example.com",
    "platform_domain": "example.com",
    "release": "v1.0.0",
    "acme_email": "operations@example.com",
    "ipv6": false
  },
  "nodes": [],
  "features": {
    "monitoring": {"mode": "disabled", "host": null},
    "logs": {"mode": "disabled", "host": null, "endpoint": null},
    "backups": {"mode": "disabled", "repository": null, "region": "us-east-1"}
  }
}
```

### Presets

| Preset | Result |
| --- | --- |
| `control-only` | Control node, monitoring disabled |
| `control-monitoring` | Control node with colocated telemetry services |
| `dedicated-monitoring` | Control node plus a monitoring-role node |
| `custom` | Feature configuration comes from `features` or later commands |

## Node object

| Field | Required | Description |
| --- | --- | --- |
| `name` | yes | Lowercase stable identifier, letters/digits/hyphens |
| `role` | yes | `control`, `dns`, `edge`, `dns-edge`, or `monitoring` |
| `region` | yes | Routing/operations region label |
| `location` | yes | Human-readable site label |
| `hostname` | no | Defaults to `NAME.OPERATOR_DOMAIN` |
| `public_ipv4` | yes | Public or routed IPv4 used for inventory and policy |
| `public_ipv6` | no | IPv6 service address |
| `bind_ipv4` | no | Local listener bind, defaults to `0.0.0.0` |
| `bind_ipv6` | no | IPv6 bind; defaults to `::` in dual-stack fleets |
| `monitor_ipv4` | no | Private monitoring target; otherwise `public_ipv4` |
| `log_ipv4` | no | Private log-source address metadata |
| `release` | no | Per-node immutable override of the global release |
| `extra_env` | no | Explicit per-node Compose overrides; always preserved in the generated `.env.prod`, including variables that have Compose defaults |
| `enabled` | no | Exclude disabled nodes from rendering and targets |
| `draining` | no | Keep node configured but remove it from preferred routing |

Example:

```json
{
  "name": "pop-singapore",
  "role": "dns-edge",
  "region": "asia",
  "location": "singapore",
  "hostname": "pop-singapore.ops.example.com",
  "public_ipv4": "192.0.2.40",
  "public_ipv6": "2001:db8::40",
  "bind_ipv4": "0.0.0.0",
  "bind_ipv6": "::",
  "monitor_ipv4": "10.30.0.40",
  "release": "v1.0.0",
  "extra_env": {},
  "enabled": true,
  "draining": false
}
```

## Manual edge registration fields

Do not put bootstrap tokens in a version-controlled setup JSON. After creating the edge in the running control panel, use:

```bash
./scripts/cdnfoundry-fleet --state-dir /var/lib/cdnfoundry-fleet \
  configure-edge-registration \
  --node edge-1 \
  --edge-id 11111111-2222-3333-4444-555555555555 \
  --bootstrap-token-file /root/edge-1.bootstrap-token \
  --non-interactive
```

This stores:

- `EDGE_ID` in the protected node state;
- the one-time token in `secrets/nodes/NODE/edge-bootstrap-token` with mode `0600`.

After successful mTLS enrollment, run `clear-edge-bootstrap-token --node NODE`, rerender, and recreate only `edge-agent`.

Optional edge overrides such as `EDGE_GATEWAY_ADDRESS_MAP`, `EDGE_RUNTIME_VERSIONS`, MMDB settings, and gateway capacity settings belong in `extra_env`. The renderer preserves explicitly supplied optional variables even when Compose uses `${VAR:-default}`.

## Control database selection

Embedded PostgreSQL is used when neither `DB_URL` nor a non-default `DB_HOST` is present.

Remote PostgreSQL is selected when the control node has either:

```json
"extra_env": {
  "DB_HOST": "postgres.internal.example",
  "DB_PORT": "5432",
  "DB_SSLMODE": "verify-full"
}
```

or a non-empty `DB_URL`.

Use `set-secret --secret control-db-password --from-file FILE` to replace the generated password with the remote database credential without exposing it in command arguments. In remote mode the control bundle omits `control-db` and its volume.

## Feature objects

### Monitoring

```json
{"mode": "disabled", "host": null}
```

- `disabled`: no telemetry stack or node exporters.
- `colocated`: telemetry stack runs on the control node.
- `dedicated`: telemetry stack runs on the named monitoring-role node.

### Logs

```json
{"mode": "centralized", "host": "monitoring-1", "endpoint": null}
```

- `disabled`: no generated log collector.
- `centralized`: every enabled node receives a generated Vector config and node-specific authentication token.
- `endpoint`: optional explicit Loki-compatible URL; otherwise derived from the configured host.

### Backups

```json
{
  "mode": "all-stateful",
  "repository": "s3:s3.example.com/cdnfoundry-production",
  "region": "us-east-1"
}
```

Modes are `disabled`, `control`, and `all-stateful`.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Success |
| `2` | Command-line usage error |
| `3` | Invalid topology, config, or failed doctor check |
| `4` | Missing, locked, or inconsistent fleet state |
| `5` | Compose, PKI, file-copy, or bundle rendering failure |
| `130` | Operator interrupted the command |

## Generated bundle contract

Every rendered node directory includes:

```text
.env.prod
generated Compose manifest
README.md
validate.sh
start.sh
bundle-metadata.json
SHA256SUMS
pki/
secrets/
generated/              # when required
referenced docker/...   # only runtime files used by selected services
```

DNS nodes may additionally receive `reconcile-pdns-password.sh` and a pending password file during a staged rotation. Edge bundles receive `EDGE_ID` plus a one-time token only after `configure-edge-registration`; the token disappears after `clear-edge-bootstrap-token` and rerendering.

## Security properties

- State directories use mode `0700`.
- State, secrets, environment files, manifests, and private keys use mode `0600`.
- Node bundles are assembled in temporary directories and activated atomically.
- Normal rendering does not rotate secrets.
- DNS database credentials are node-scoped.
- CA private keys remain in authoritative fleet state, except the edge identity CA key required by the control service in the control bundle.
- Bundle metadata contains hashes and non-secret inventory only.
- Every operator-controlled Compose interpolation value is present in the node's generated `.env.prod`; production Compose and role overlays provide no fallback deployment values.
- Compose `environment` mappings remain explicit per-service allowlists. Replacing them with a shared `env_file` entry would expose unrelated database, PKI, and API credentials to every container, so containers receive only the variables they own while Compose reads values through `--env-file .env.prod`.
