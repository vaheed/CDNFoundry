from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

from .common import FleetError, StateError, ValidationError, load_json, utc_now
from .render import Renderer
from .state import (
    BACKUP_MODES,
    GLOBAL_SECRET_NAMES,
    LOG_MODES,
    MONITORING_MODES,
    NODE_SECRET_NAMES,
    FleetState,
    ROLES,
)

EXIT_OK = 0
EXIT_USAGE = 2
EXIT_VALIDATION = 3
EXIT_STATE = 4
EXIT_RENDER = 5
EXIT_EXTERNAL = 6


def _common_parser(*, suppressed_defaults: bool) -> argparse.ArgumentParser:
    common = argparse.ArgumentParser(add_help=False)
    default = argparse.SUPPRESS if suppressed_defaults else None
    common.add_argument(
        "--state-dir",
        default=default if suppressed_defaults else "/var/lib/cdnfoundry-fleet",
    )
    common.add_argument(
        "--output-dir",
        default=default if suppressed_defaults else "/var/lib/cdnfoundry-fleet/bundles",
    )
    common.add_argument(
        "--repo-root",
        default=default if suppressed_defaults else str(Path(__file__).resolve().parents[2]),
    )
    common.add_argument("--config", default=default)
    common.add_argument("--non-interactive", action="store_true", default=default if suppressed_defaults else False)
    common.add_argument("--dry-run", action="store_true", default=default if suppressed_defaults else False)
    common.add_argument("--yes", action="store_true", default=default if suppressed_defaults else False)
    return common


def parser() -> argparse.ArgumentParser:
    root_common = _common_parser(suppressed_defaults=False)
    command_common = _common_parser(suppressed_defaults=True)
    root = argparse.ArgumentParser(prog="cdnfoundry-fleet", parents=[root_common])
    sub = root.add_subparsers(dest="command", required=True)

    setup = sub.add_parser(
        "setup",
        parents=[command_common],
        help="interactive or config-driven full fleet setup, validation, and rendering",
    )
    setup.add_argument("--operator-domain")
    setup.add_argument("--platform-domain")
    setup.add_argument("--release")
    setup.add_argument("--acme-email", default="")
    setup.add_argument("--dual-stack", action="store_true")
    setup.add_argument(
        "--preset",
        choices=("control-only", "control-monitoring", "dedicated-monitoring", "custom"),
    )
    setup.add_argument("--control-name", default="control-1")
    setup.add_argument("--control-hostname")
    setup.add_argument("--control-ipv4")
    setup.add_argument("--control-region", default="global")
    setup.add_argument("--control-location", default="primary")
    setup.add_argument("--no-render", action="store_true")

    init = sub.add_parser("init", parents=[command_common])
    init.add_argument("--operator-domain")
    init.add_argument("--platform-domain")
    init.add_argument("--release")
    init.add_argument("--acme-email", default="")
    init.add_argument("--dual-stack", action="store_true")

    add = sub.add_parser("add-node", parents=[command_common])
    _node_arguments(add, require_name=True)

    update = sub.add_parser("update-node", parents=[command_common])
    update.add_argument("--node", required=True)
    _node_arguments(update, require_name=False, optional=True, include_node=False)

    edge_registration = sub.add_parser(
        "configure-edge-registration",
        parents=[command_common],
        help="store a control-plane-created edge UUID and one-time bootstrap token",
    )
    edge_registration.add_argument("--node", required=True)
    edge_registration.add_argument("--edge-id", required=True)
    token_source = edge_registration.add_mutually_exclusive_group(required=True)
    token_source.add_argument("--bootstrap-token-file")
    token_source.add_argument("--bootstrap-token-stdin", action="store_true")

    clear_edge_token = sub.add_parser(
        "clear-edge-bootstrap-token",
        parents=[command_common],
        help="remove the one-time bootstrap token after successful mTLS enrollment",
    )
    clear_edge_token.add_argument("--node", required=True)

    set_secret = sub.add_parser(
        "set-secret",
        parents=[command_common],
        help="replace a supported secret from a protected file without exposing it in argv",
    )
    set_secret.add_argument("--secret", required=True)
    set_secret.add_argument("--node")
    set_secret.add_argument("--from-file", required=True)

    remove = sub.add_parser("remove-node", parents=[command_common])
    remove.add_argument("--node", required=True)

    sub.add_parser("list-nodes", parents=[command_common])
    status = sub.add_parser("status", parents=[command_common])
    status.add_argument("--json", action="store_true")
    doctor = sub.add_parser("doctor", parents=[command_common])
    doctor.add_argument("--json", action="store_true")

    mon = sub.add_parser("configure-monitoring", parents=[command_common])
    mon.add_argument("--mode", choices=sorted(MONITORING_MODES), required=True)
    mon.add_argument("--host")

    logs = sub.add_parser("configure-logs", parents=[command_common])
    logs.add_argument("--mode", choices=sorted(LOG_MODES), required=True)
    logs.add_argument("--host")
    logs.add_argument("--endpoint")

    backups = sub.add_parser("configure-backups", parents=[command_common])
    backups.add_argument("--mode", choices=sorted(BACKUP_MODES), required=True)
    backups.add_argument("--repository")
    backups.add_argument("--region", default="us-east-1")

    render = sub.add_parser("render", parents=[command_common])
    render.add_argument("--node")

    validate = sub.add_parser("validate", parents=[command_common])
    validate.add_argument("--node")

    sub.add_parser("show-start-order", parents=[command_common])

    adopt = sub.add_parser("adopt-existing", parents=[command_common])
    adopt.add_argument("--node", required=True)
    adopt.add_argument("--role", choices=sorted(ROLES), required=True)
    adopt.add_argument("--env-file", required=True)
    adopt.add_argument("--region", required=True)
    adopt.add_argument("--location", required=True)
    adopt.add_argument("--hostname")
    adopt.add_argument("--public-ipv4", required=True)
    adopt.add_argument("--public-ipv6")
    adopt.add_argument("--bind-ipv4", default="0.0.0.0")

    rotate = sub.add_parser("rotate-secret", parents=[command_common])
    rotate.add_argument("--node")
    rotate.add_argument("--secret", required=True)
    rotate.add_argument("--phase", choices=("prepare", "commit", "abort"), default="prepare")

    return root


def _node_arguments(
    target: argparse.ArgumentParser,
    *,
    require_name: bool,
    optional: bool = False,
    include_node: bool = True,
) -> None:
    if include_node:
        target.add_argument("--node", required=require_name)
    target.add_argument("--role", choices=sorted(ROLES), required=require_name)
    target.add_argument("--region", required=require_name)
    target.add_argument("--location", required=require_name)
    target.add_argument("--hostname")
    target.add_argument("--public-ipv4", required=require_name)
    target.add_argument("--public-ipv6")
    target.add_argument("--bind-ipv4", default=None if optional else "0.0.0.0")
    target.add_argument("--bind-ipv6")
    target.add_argument("--monitor-ipv4")
    target.add_argument("--log-ipv4")
    target.add_argument("--release")
    target.add_argument("--extra-env", action="append", default=[])
    target.add_argument("--disabled", action="store_true", default=None if optional else False)
    target.add_argument("--draining", action="store_true", default=None if optional else False)


def _config(args: argparse.Namespace) -> dict[str, Any]:
    return load_json(Path(args.config)) if args.config else {}


def _read_input(prompt: str, *, secret: bool = False) -> str:
    import getpass

    try:
        return getpass.getpass(prompt) if secret else input(prompt)
    except EOFError as exc:
        raise ValidationError(
            "Interactive input is unavailable. Run in a terminal or use --non-interactive with --config."
        ) from exc


def _prompt(value: str | None, label: str, *, secret: bool = False, default: str | None = None) -> str:
    if value:
        return value
    while True:
        suffix = f" [{default}]" if default else ""
        entered = _read_input(f"{label}{suffix}: ", secret=secret).strip()
        if entered:
            return entered
        if default is not None:
            return default


def _prompt_choice(label: str, choices: list[tuple[str, str]], *, default: str | None = None) -> str:
    print(f"\n{label}")
    for index, (value, description) in enumerate(choices, 1):
        marker = " (default)" if value == default else ""
        print(f"  {index}) {description}{marker}")
    while True:
        entered = _read_input("Select an option: ").strip()
        if not entered and default is not None:
            return default
        if entered.isdigit() and 1 <= int(entered) <= len(choices):
            return choices[int(entered) - 1][0]
        for value, _ in choices:
            if entered == value:
                return value
        print("Invalid selection; enter the number or option name.")


def _prompt_yes_no(label: str, *, default: bool = False) -> bool:
    suffix = "[Y/n]" if default else "[y/N]"
    while True:
        entered = _read_input(f"{label} {suffix}: ").strip().lower()
        if not entered:
            return default
        if entered in {"y", "yes"}:
            return True
        if entered in {"n", "no"}:
            return False
        print("Please answer yes or no.")


def _node_payload(args: argparse.Namespace, config: dict[str, Any], *, update: bool = False) -> dict[str, Any]:
    source = config.get("node", config)
    payload: dict[str, Any] = {}
    mapping = {
        "name": "node",
        "role": "role",
        "region": "region",
        "location": "location",
        "hostname": "hostname",
        "public_ipv4": "public_ipv4",
        "public_ipv6": "public_ipv6",
        "bind_ipv4": "bind_ipv4",
        "bind_ipv6": "bind_ipv6",
        "monitor_ipv4": "monitor_ipv4",
        "log_ipv4": "log_ipv4",
        "release": "release",
    }
    for target, arg_name in mapping.items():
        value = getattr(args, arg_name, None)
        if value is None:
            value = source.get(target)
        if value is not None:
            payload[target] = value
    extra = dict(source.get("extra_env", {}))
    for item in getattr(args, "extra_env", []) or []:
        if "=" not in item:
            raise ValidationError("--extra-env must be KEY=VALUE")
        key, value = item.split("=", 1)
        extra[key] = value
    if extra:
        payload["extra_env"] = extra
    if getattr(args, "disabled", None) is not None:
        payload["enabled"] = not args.disabled
    if getattr(args, "draining", None) is not None:
        payload["draining"] = args.draining
    if not update and not args.non_interactive:
        payload["name"] = _prompt(payload.get("name"), "Node name")
        payload["role"] = _prompt(payload.get("role"), "Role")
        payload["region"] = _prompt(payload.get("region"), "Region")
        payload["location"] = _prompt(payload.get("location"), "Location")
        payload["public_ipv4"] = _prompt(payload.get("public_ipv4"), "Public IPv4")
    return payload


def _confirm(args: argparse.Namespace, message: str) -> None:
    if args.yes:
        return
    if args.non_interactive:
        raise ValidationError(f"{message}; rerun with --yes")
    answer = input(f"{message} [y/N]: ").strip().lower()
    if answer not in {"y", "yes"}:
        raise ValidationError("Operation cancelled")


def _interactive_node(state: dict[str, Any], *, role: str | None = None, defaults: dict[str, Any] | None = None) -> dict[str, Any]:
    defaults = defaults or {}
    if role is None:
        role = _prompt_choice(
            "Node role",
            [
                ("dns", "DNS only"),
                ("edge", "Edge only"),
                ("dns-edge", "Combined DNS + edge"),
                ("monitoring", "Dedicated monitoring"),
            ],
        )
    name_default = defaults.get("name") or f"{role}-1"
    name = _prompt(defaults.get("name"), "Node name", default=name_default)
    operator_domain = state["global"]["operator_domain"]
    return {
        "name": name,
        "role": role,
        "region": _prompt(defaults.get("region"), "Region", default="global"),
        "location": _prompt(defaults.get("location"), "Location", default="primary"),
        "hostname": _prompt(defaults.get("hostname"), "Hostname", default=f"{name}.{operator_domain}"),
        "public_ipv4": _prompt(defaults.get("public_ipv4"), "Public IPv4"),
        "public_ipv6": defaults.get("public_ipv6"),
        "bind_ipv4": defaults.get("bind_ipv4") or "0.0.0.0",
        "bind_ipv6": defaults.get("bind_ipv6"),
        "monitor_ipv4": defaults.get("monitor_ipv4"),
        "log_ipv4": defaults.get("log_ipv4"),
        "extra_env": defaults.get("extra_env", {}),
    }


def _apply_setup_features(store: FleetState, state: dict[str, Any], config: dict[str, Any], preset: str) -> dict[str, Any]:
    features = config.get("features", {})
    monitoring = features.get("monitoring")
    if monitoring:
        state = store.configure_feature(state, "monitoring", monitoring)
    elif preset == "control-monitoring":
        state = store.configure_feature(state, "monitoring", {"mode": "colocated", "host": None})
    elif preset == "control-only":
        state = store.configure_feature(state, "monitoring", {"mode": "disabled", "host": None})
    elif preset == "dedicated-monitoring":
        monitoring_nodes = [node for node in state["nodes"].values() if node["role"] == "monitoring"]
        if not monitoring_nodes:
            raise ValidationError("The dedicated-monitoring preset requires a monitoring-role node")
        state = store.configure_feature(
            state, "monitoring", {"mode": "dedicated", "host": monitoring_nodes[0]["name"]}
        )

    if features.get("logs"):
        state = store.configure_feature(state, "logs", features["logs"])
    if features.get("backups"):
        state = store.configure_feature(state, "backups", features["backups"])
    return state


def _setup(args: argparse.Namespace, store: FleetState, output_dir: Path, config: dict[str, Any]) -> int:
    global_cfg = config.get("global", config)
    created = not store.exists()
    if created:
        operator = args.operator_domain or global_cfg.get("operator_domain")
        platform = args.platform_domain or global_cfg.get("platform_domain")
        release = args.release or global_cfg.get("release")
        if not args.non_interactive:
            print("CDNFoundry production fleet setup")
            print("This wizard creates fleet state, node bundles, certificates, secrets, and operator runbooks.")
            operator = _prompt(operator, "Operator domain")
            platform = _prompt(platform, "Platform domain")
            release = _prompt(release, "Exact release tag or commit")
        if not all((operator, platform, release)):
            raise ValidationError("operator_domain, platform_domain, and release are required")
        state = store.init(
            {
                "operator_domain": operator,
                "platform_domain": platform,
                "release": release,
                "acme_email": args.acme_email or global_cfg.get("acme_email", ""),
                "ipv6": args.dual_stack or bool(global_cfg.get("ipv6", False)),
            }
        )
        print(f"Initialized fleet state: {store.state_file}")
    else:
        state = store.load()
        print(f"Using existing fleet state: {store.state_file}")

    preset = args.preset or config.get("preset")
    if not preset and not args.non_interactive:
        preset = _prompt_choice(
            "Deployment topology",
            [
                ("control-monitoring", "Control + monitoring on the same host"),
                ("control-only", "Control plane only"),
                ("dedicated-monitoring", "Control plane + dedicated monitoring host"),
                ("custom", "Custom fleet"),
            ],
            default="control-monitoring",
        )
    preset = preset or "custom"

    configured_nodes = config.get("nodes", [])
    if configured_nodes and not isinstance(configured_nodes, list):
        raise ValidationError("setup config field 'nodes' must be a list")

    if configured_nodes:
        for payload in configured_nodes:
            if not isinstance(payload, dict):
                raise ValidationError("Every setup node must be an object")
            name = payload.get("name")
            if not name:
                raise ValidationError("Every setup node requires a name")
            current = store.load()
            if name in current["nodes"]:
                state = store.update_node(current, name, payload)
                print(f"Updated node: {name}")
            else:
                state = store.add_node(current, payload)
                print(f"Added node: {name}")
    elif created or not state["nodes"]:
        control_payload = {
            "name": args.control_name,
            "role": "control",
            "region": args.control_region,
            "location": args.control_location,
            "hostname": args.control_hostname,
            "public_ipv4": args.control_ipv4,
            "bind_ipv4": "0.0.0.0",
        }
        if not args.non_interactive:
            control_payload = _interactive_node(state, role="control", defaults=control_payload)
        elif not args.control_ipv4:
            raise ValidationError("--control-ipv4 or a config nodes list is required in non-interactive setup")
        state = store.add_node(store.load(), control_payload)
        print(f"Added control node: {control_payload['name']}")

        if preset == "dedicated-monitoring":
            if args.non_interactive:
                raise ValidationError("Dedicated monitoring in non-interactive mode requires a monitoring node in --config")
            monitor_payload = _interactive_node(state, role="monitoring", defaults={"name": "monitoring-1"})
            state = store.add_node(store.load(), monitor_payload)
            print(f"Added monitoring node: {monitor_payload['name']}")

        if not args.non_interactive:
            while _prompt_yes_no("Add a DNS or edge node now?", default=False):
                payload = _interactive_node(state)
                state = store.add_node(store.load(), payload)
                print(f"Added node: {payload['name']}")

    state = _apply_setup_features(store, store.load(), config, preset)
    store.validate(state, require_secrets=not args.dry_run)
    print(f"Fleet validation passed ({len(state['nodes'])} node(s)).")

    paths: list[Path] = []
    if not args.no_render:
        renderer = Renderer(Path(args.repo_root), store, output_dir, dry_run=args.dry_run)
        paths = renderer.render(state)
        if not args.dry_run:
            with store.transaction() as candidate:
                candidate["metadata"]["last_successful_validation"] = utc_now()
                candidate["metadata"]["last_successful_render"] = utc_now()
        print(f"Generated {len(paths)} node bundle(s) in {output_dir}")
        for path in paths:
            print(f"  - {path}")
    result = {
        "status": "configured",
        "preset": preset,
        "state_dir": str(store.state_dir),
        "output_dir": str(output_dir),
        "nodes": sorted(state["nodes"]),
        "bundles": [str(path) for path in paths],
    }
    print(json.dumps(result))
    return EXIT_OK


def _status(state: dict[str, Any], output_dir: Path, *, as_json: bool) -> None:
    payload = {
        "global": state["global"],
        "features": state["features"],
        "metadata": state["metadata"],
        "nodes": [state["nodes"][name] for name in sorted(state["nodes"])],
        "output_dir": str(output_dir),
    }
    if as_json:
        print(json.dumps(payload, indent=2))
        return
    print("CDNFoundry fleet status")
    print(f"  Release: {state['global']['release']}")
    print(f"  Operator domain: {state['global']['operator_domain']}")
    print(f"  Monitoring: {state['features']['monitoring']['mode']}")
    print(f"  Logs: {state['features']['logs']['mode']}")
    print(f"  Backups: {state['features']['backups']['mode']}")
    print(f"  Bundles: {output_dir}")
    print("  Nodes:")
    if not state["nodes"]:
        print("    (none)")
    for name in sorted(state["nodes"]):
        node = state["nodes"][name]
        flags = []
        if not node["enabled"]:
            flags.append("disabled")
        if node["draining"]:
            flags.append("draining")
        suffix = f" [{', '.join(flags)}]" if flags else ""
        print(f"    - {name}: {node['role']} / {node['hostname']} / {node['public_ipv4']}{suffix}")


def _doctor(args: argparse.Namespace, store: FleetState) -> int:
    import shutil

    root = Path(args.repo_root)
    required = [
        "compose.prod.yml",
        "deploy/production/compose.control-host.yml",
        "deploy/production/compose.dns-host.yml",
        "deploy/production/compose.edge-host.yml",
        "deploy/production/compose.dns-edge-host.yml",
        "deploy/production/compose.telemetry-host.yml",
    ]
    checks: list[dict[str, Any]] = []
    for relative in required:
        checks.append({"check": relative, "ok": (root / relative).is_file()})
    for executable in ("python3", "openssl"):
        checks.append({"check": executable, "ok": shutil.which(executable) is not None})
    checks.append({"check": "docker", "ok": shutil.which("docker") is not None, "required_for_render": False})
    if store.exists():
        try:
            store.validate(store.load(), require_secrets=not args.dry_run)
            checks.append({"check": "fleet-state", "ok": True})
        except FleetError as exc:
            checks.append({"check": "fleet-state", "ok": False, "detail": str(exc)})
    else:
        checks.append({"check": "fleet-state", "ok": True, "detail": "not initialized yet"})
    ok = all(item["ok"] for item in checks if item.get("required_for_render", True))
    if args.json:
        print(json.dumps({"ok": ok, "checks": checks}, indent=2))
    else:
        for item in checks:
            print(f"{'OK' if item['ok'] else 'FAIL'}  {item['check']}" + (f" — {item['detail']}" if item.get("detail") else ""))
        print("Doctor result: " + ("ready" if ok else "problems found"))
    return EXIT_OK if ok else EXIT_VALIDATION


def execute(args: argparse.Namespace) -> int:
    state_dir = Path(args.state_dir)
    output_dir = Path(args.output_dir)
    store = FleetState(state_dir, dry_run=args.dry_run)
    config = _config(args)

    if args.command == "doctor":
        return _doctor(args, store)

    with store.locked(exclusive=args.command not in {"list-nodes", "show-start-order", "status"}):
        if args.command == "setup":
            return _setup(args, store, output_dir, config)
        if args.command == "init":
            values = config.get("global", config)
            operator = args.operator_domain or values.get("operator_domain")
            platform = args.platform_domain or values.get("platform_domain")
            release = args.release or values.get("release")
            if not args.non_interactive:
                operator = _prompt(operator, "Operator domain")
                platform = _prompt(platform, "Platform domain")
                release = _prompt(release, "Exact release tag or commit")
            if not all((operator, platform, release)):
                raise ValidationError("operator_domain, platform_domain, and release are required")
            state = store.init(
                {
                    "operator_domain": operator,
                    "platform_domain": platform,
                    "release": release,
                    "acme_email": args.acme_email or values.get("acme_email", ""),
                    "ipv6": args.dual_stack or bool(values.get("ipv6", False)),
                }
            )
            print(json.dumps({"status": "initialized", "state_dir": str(state_dir), "generation": state["metadata"]["generation"]}))
            return EXIT_OK

        state = store.load()
        if args.command == "add-node":
            state = store.add_node(state, _node_payload(args, config))
            print(json.dumps({"status": "added", "node": args.node or config.get("name")}))
        elif args.command == "update-node":
            state = store.update_node(state, args.node, _node_payload(args, config, update=True))
            print(json.dumps({"status": "updated", "node": args.node}))
        elif args.command == "configure-edge-registration":
            if args.node not in state["nodes"]:
                raise ValidationError(f"Unknown node: {args.node}")
            if state["nodes"][args.node]["role"] not in {"edge", "dns-edge"}:
                raise ValidationError("Edge registration is valid only for edge-capable nodes")
            from uuid import UUID

            try:
                edge_id = str(UUID(args.edge_id))
            except ValueError as exc:
                raise ValidationError("--edge-id must be a valid UUID") from exc
            if args.bootstrap_token_stdin:
                token = sys.stdin.read().rstrip("\r\n")
            else:
                token_path = Path(args.bootstrap_token_file)
                token = token_path.read_text(encoding="utf-8").rstrip("\r\n")
            if not token:
                raise ValidationError("Bootstrap token must not be empty")
            current = state["nodes"][args.node]
            extra = dict(current.get("extra_env", {}))
            extra.pop("EDGE_BOOTSTRAP_TOKEN", None)
            extra["EDGE_ID"] = edge_id
            state = store.update_node(state, args.node, {"extra_env": extra})
            store.write_secret("edge-bootstrap-token", token, node=args.node)
            print(json.dumps({"status": "configured", "node": args.node, "edge_id": edge_id}))
        elif args.command == "clear-edge-bootstrap-token":
            if args.node not in state["nodes"]:
                raise ValidationError(f"Unknown node: {args.node}")
            current = state["nodes"][args.node]
            extra = dict(current.get("extra_env", {}))
            if "EDGE_BOOTSTRAP_TOKEN" in extra:
                extra.pop("EDGE_BOOTSTRAP_TOKEN", None)
                state = store.update_node(state, args.node, {"extra_env": extra})
            store.delete_secret("edge-bootstrap-token", node=args.node)
            print(json.dumps({"status": "cleared", "node": args.node}))
        elif args.command == "set-secret":
            if args.node:
                if args.node not in state["nodes"]:
                    raise ValidationError(f"Unknown node: {args.node}")
                if args.secret not in NODE_SECRET_NAMES:
                    raise ValidationError(f"Unsupported node secret: {args.secret}")
            elif args.secret not in GLOBAL_SECRET_NAMES:
                raise ValidationError(f"Unsupported global secret: {args.secret}")
            value = Path(args.from_file).read_text(encoding="utf-8").rstrip("\r\n")
            store.write_secret(args.secret, value, node=args.node)
            print(json.dumps({"status": "stored", "secret": args.secret, "node": args.node}))
        elif args.command == "remove-node":
            _confirm(args, f"Remove node {args.node} from fleet state")
            store.remove_node(state, args.node)
            print(json.dumps({"status": "removed", "node": args.node}))
        elif args.command == "list-nodes":
            rows = [
                {
                    "name": node["name"],
                    "role": node["role"],
                    "region": node["region"],
                    "location": node["location"],
                    "hostname": node["hostname"],
                    "public_ipv4": node["public_ipv4"],
                    "enabled": node["enabled"],
                    "draining": node["draining"],
                }
                for node in sorted(state["nodes"].values(), key=lambda item: item["name"])
            ]
            print(json.dumps(rows, indent=2))
        elif args.command == "status":
            _status(state, output_dir, as_json=args.json)
        elif args.command == "configure-monitoring":
            store.configure_feature(state, "monitoring", {"mode": args.mode, "host": args.host})
            print(json.dumps({"status": "configured", "feature": "monitoring", "mode": args.mode}))
        elif args.command == "configure-logs":
            store.configure_feature(
                state, "logs", {"mode": args.mode, "host": args.host, "endpoint": args.endpoint}
            )
            print(json.dumps({"status": "configured", "feature": "logs", "mode": args.mode}))
        elif args.command == "configure-backups":
            store.configure_feature(
                state,
                "backups",
                {"mode": args.mode, "repository": args.repository, "region": args.region},
            )
            print(json.dumps({"status": "configured", "feature": "backups", "mode": args.mode}))
        elif args.command in {"render", "validate"}:
            renderer = Renderer(Path(args.repo_root), store, output_dir, dry_run=args.dry_run)
            store.validate(state, require_secrets=not args.dry_run)
            if args.command == "render":
                paths = renderer.render(state, node_name=args.node)
                if not args.dry_run:
                    with store.transaction() as candidate:
                        candidate["metadata"]["last_successful_render"] = utc_now()
                print(json.dumps({"status": "rendered", "bundles": [str(path) for path in paths]}))
            else:
                # Render to a disposable directory to exercise Compose filtering and minimal environments.
                if args.dry_run:
                    renderer.render(state, node_name=args.node)
                else:
                    import tempfile

                    with tempfile.TemporaryDirectory(dir=state_dir) as tmp:
                        Renderer(Path(args.repo_root), store, Path(tmp), dry_run=False).render(state, node_name=args.node)
                with store.transaction() as candidate:
                    candidate["metadata"]["last_successful_validation"] = utc_now()
                print(json.dumps({"status": "valid"}))
        elif args.command == "show-start-order":
            print(json.dumps(Renderer.start_order(state), indent=2))
        elif args.command == "adopt-existing":
            env = parse_env(Path(args.env_file))
            if not store.exists():
                raise StateError("Initialize fleet state before adopting an existing node")
            payload = {
                "name": args.node,
                "role": args.role,
                "region": args.region,
                "location": args.location,
                "hostname": args.hostname,
                "public_ipv4": args.public_ipv4,
                "public_ipv6": args.public_ipv6,
                "bind_ipv4": args.bind_ipv4,
                "extra_env": {k: v for k, v in env.items() if k not in secret_env_names()},
            }
            state = store.add_node(state, payload)
            import_secret_env(store, args.node, args.role, env)
            print(json.dumps({"status": "adopted", "node": args.node}))
        elif args.command == "rotate-secret":
            if args.node:
                if args.secret not in NODE_SECRET_NAMES:
                    raise ValidationError(f"Unsupported node secret: {args.secret}")
                if args.node not in state["nodes"]:
                    raise ValidationError(f"Unknown node: {args.node}")
                if args.secret == "pdns-db-password":
                    if state["nodes"][args.node]["role"] not in {"dns", "dns-edge"}:
                        raise ValidationError("pdns-db-password is valid only for DNS-capable nodes")
                    if args.phase == "prepare":
                        _confirm(args, f"Prepare a local PowerDNS PostgreSQL password rotation for {args.node}")
                        store.prepare_secret_rotation(args.secret, node=args.node)
                    elif args.phase == "commit":
                        _confirm(
                            args,
                            f"Commit the PowerDNS PostgreSQL password after reconcile-pdns-password.sh succeeded on {args.node}",
                        )
                        store.commit_secret_rotation(args.secret, node=args.node)
                    else:
                        _confirm(args, f"Abort the pending PowerDNS PostgreSQL password rotation for {args.node}")
                        store.abort_secret_rotation(args.secret, node=args.node)
                else:
                    if args.phase != "prepare":
                        raise ValidationError("commit and abort phases are used only for pdns-db-password")
                    _confirm(args, f"Rotate {args.secret} for {args.node}")
                    store.rotate_secret(args.secret, node=args.node)
            else:
                if args.phase != "prepare":
                    raise ValidationError("commit and abort phases require --node and pdns-db-password")
                _confirm(args, f"Rotate global secret {args.secret}")
                store.rotate_secret(args.secret)
            print(
                json.dumps(
                    {
                        "status": args.phase if args.secret == "pdns-db-password" and args.node else "rotated",
                        "secret": args.secret,
                        "node": args.node,
                    }
                )
            )
        else:  # pragma: no cover
            raise ValidationError(f"Unsupported command: {args.command}")
    return EXIT_OK


def parse_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue
        if "=" not in stripped:
            raise ValidationError(f"Invalid env line {number} in {path}")
        key, value = stripped.split("=", 1)
        values[key] = value.strip().strip('"').strip("'")
    return values


def secret_env_names() -> set[str]:
    return {
        "APP_KEY",
        "EDGE_ARTIFACT_SIGNING_KEY",
        "CONTROL_DB_PASSWORD",
        "REDIS_PASSWORD",
        "PDNS_DB_PASSWORD",
        "PDNS_API_KEY",
        "EDGE_STATUS_TOKEN",
        "CLICKHOUSE_PASSWORD",
        "GRAFANA_ADMIN_PASSWORD",
        "GRAFANA_CLICKHOUSE_PASSWORD",
        "GRAFANA_POSTGRES_PASSWORD",
    }


def import_secret_env(store: FleetState, node: str, role: str, env: dict[str, str]) -> None:
    global_map = {
        "APP_KEY": "app-key",
        "EDGE_ARTIFACT_SIGNING_KEY": "artifact-signing-key",
        "CONTROL_DB_PASSWORD": "control-db-password",
        "REDIS_PASSWORD": "valkey-password",
        "CLICKHOUSE_PASSWORD": "clickhouse-password",
        "GRAFANA_ADMIN_PASSWORD": "grafana-admin-password",
        "GRAFANA_CLICKHOUSE_PASSWORD": "grafana-clickhouse-password",
        "GRAFANA_POSTGRES_PASSWORD": "grafana-postgres-password",
    }
    node_map = {
        "PDNS_DB_PASSWORD": "pdns-db-password",
        "PDNS_API_KEY": "pdns-api-key",
        "EDGE_STATUS_TOKEN": "edge-status-token",
    }
    for key, name in global_map.items():
        if env.get(key):
            path = store.secret_path(name)
            from .common import atomic_write

            atomic_write(path, env[key] + "\n", 0o600)
    for key, name in node_map.items():
        if env.get(key):
            path = store.secret_path(name, node=node)
            from .common import atomic_write

            atomic_write(path, env[key] + "\n", 0o600)


def main(argv: list[str] | None = None) -> int:
    try:
        effective = list(sys.argv[1:] if argv is None else argv)
        if not effective:
            if sys.stdin.isatty():
                effective = ["setup"]
            else:
                parser().print_help()
                return EXIT_OK
        return execute(parser().parse_args(effective))
    except ValidationError as exc:
        print(f"validation error: {exc}", file=sys.stderr)
        return EXIT_VALIDATION
    except StateError as exc:
        print(f"state error: {exc}", file=sys.stderr)
        return EXIT_STATE
    except FleetError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return EXIT_RENDER
    except KeyboardInterrupt:
        print("cancelled", file=sys.stderr)
        return 130


if __name__ == "__main__":
    raise SystemExit(main())
