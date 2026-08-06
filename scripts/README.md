# CDNFoundry production fleet generator

Apply this archive at the root of a current CDNFoundry checkout. It contains the production fleet generator, examples, tests, and deployment documentation.

## Apply

```bash
cd /path/to/CDNFoundry
unzip -o /path/to/CDNFoundry-production.zip
chmod +x scripts/cdnfoundry-fleet scripts/generate-production-env.sh scripts/install-production-prerequisites.sh
```

Run repository preflight and tests:

```bash
./scripts/cdnfoundry-fleet --repo-root "$PWD" doctor
PYTHONPATH=scripts python3 -m pytest -q tests/fleet/test_fleet.py
```

Start the interactive generator:

```bash
./scripts/generate-production-env.sh
```

For production state and bundle paths:

```bash
CDNFOUNDRY_FLEET_STATE_DIR=/var/lib/cdnfoundry-fleet \
CDNFOUNDRY_FLEET_OUTPUT_DIR=/var/lib/cdnfoundry-fleet/bundles \
  sudo -E ./scripts/generate-production-env.sh
```

## Quick starts

- `docs/deployment/production-quick-start.md` — control plus two combined DNS/edge nodes, sizing, firewall rules, manual UUID/token registration, and mTLS verification.
- `docs/deployment/production-quick-start-large-fleet.md` — one control, ten edge, four DNS, and three monitoring nodes, with embedded or remote PostgreSQL.
- `deploy/production/examples/three-node-production.sh` — renders the three-node inventory.
- `deploy/production/examples/large-production.sh` — renders the 18-node inventory.

## Reference documentation

- `docs/deployment/production-fleet-operator-guide.md`
- `docs/deployment/production-fleet-config-reference.md`
- `docs/deployment/production-fleet.md`
