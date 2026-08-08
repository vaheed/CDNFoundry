# Production Fleet tooling

CDNFoundry Fleet turns one validated desired-state document into protected, role-filtered production bundles. PostgreSQL remains authoritative for application desired state; Fleet state is deployment authority for host inventory, PKI, secrets, and generated artifacts.

## Start here

Clone an immutable release or commit, then inspect the CLI:

```bash
git clone https://github.com/vaheed/CDNFoundry.git cdnfoundry
cd cdnfoundry
git checkout v1.0.0
./scripts/cdnfoundry-fleet doctor
```

For a first deployment, copy and edit a JSON topology instead of changing a shell script:

```bash
install -m 0600 deploy/production/examples/starter-fleet.json ./fleet.json
python3 -m json.tool fleet.json >/dev/null
./scripts/cdnfoundry-fleet --config fleet.json --non-interactive --dry-run setup
sudo ./scripts/cdnfoundry-fleet --config fleet.json --non-interactive setup
```

Use `multi-region-fleet.json` when control, DNS, edge, and monitoring roles are separated across failure domains.

## Commands

- `cdnfoundry-fleet setup`: create/reuse protected state, validate, and render atomically.
- `validate` and `doctor`: fail closed on invalid topology, missing prerequisites, or broken Compose.
- `add-node`, `update-node`, `remove-node`: mutate bounded host inventory under a lock.
- `configure-monitoring`, `configure-logs`, `configure-backups`: update typed feature state.
- `configure-edge-registration`: import a control-plane-issued UUID and one-time token from a protected file.
- `clear-edge-bootstrap-token`: remove a consumed token before rerendering.
- `render`: deterministically replace complete node bundles while retaining `.previous`.
- `show-start-order`: print dependency-safe rollout order.
- `rotate-secret`: perform explicit staged rotation where supported.

Generated bundles use `docker compose --env-file .env.prod`; `.env.prod` is complete for that node and production Compose does not supply deployment defaults. Do not hand-edit rendered files.

## Documentation

- [Starter fleet quick start](../docs/deployment/production-quick-start.md)
- [Multi-region fleet quick start](../docs/deployment/production-quick-start-multi-region.md)
- [Fleet operator guide](../docs/deployment/production-fleet-operator-guide.md)
- [Fleet configuration reference](../docs/deployment/production-fleet-config-reference.md)
- [Fleet architecture reference](../docs/deployment/production-fleet.md)
