from __future__ import annotations

import argparse
import copy
import re
import json
import sys
from pathlib import Path
from typing import Any

from . import control_cli
from .vault import Vault, VaultError
from .common import FleetError, StateError, ValidationError, load_json, utc_now
from .render import Renderer
from .state import (
    BACKUP_MODES,
    GLOBAL_SECRET_NAMES,
    LOG_MODES,
    MONITORING_MODES,
    NODE_SECRET_NAMES,
    NULLABLE_NODE_ADDRESSES,
    FleetState,
    ROLES,
)

DEFAULT_VAULT = Path.home() / ".config" / "cdnfoundry-fleet" / "fleet.db"
PASSPHRASE_ENV = "CDNF_FLEET_PASSPHRASE"
PASSPHRASE_FILE_ENV = "CDNF_FLEET_PASSPHRASE_FILE"

ENGINE_COMMANDS = {"test-connection", "ssh-defaults", "dns-names", "management-tls", "deploy", "set-release", "public-key", "node-ssh", "trust-release-key", "bootstrap", "upgrade", "apply-config", "rollback", "security", "backup-now", "restore", "vault-backup", "inventory", "watch", "alert-webhook"}
EXIT_OK = 0
EXIT_USAGE = 2
EXIT_VALIDATION = 3
EXIT_STATE = 4
EXIT_RENDER = 5
EXIT_EXTERNAL = 6

SETUP_GLOBAL_FIELDS = {"operator_domain", "platform_domain", "release", "acme_email", "alert_webhook_url", "ipv6"}
SETUP_TOP_LEVEL_FIELDS = {"preset", "global", "nodes", "features"} | SETUP_GLOBAL_FIELDS
SETUP_FEATURE_FIELDS = {
    "monitoring": {"mode", "host"},
    "logs": {"mode", "host", "endpoint"},
    "backups": {"mode", "repository", "region"},
}
SETUP_NODE_FIELDS = {
    "name", "role", "region", "location", "hostname", "public_ipv4", "public_ipv6",
    "bind_ipv4", "bind_ipv6", "monitor_ipv4", "monitor_ipv6", "log_ipv4", "log_ipv6", "release", "extra_env",
    "enabled", "draining", "health",
}


def _reject_unknown_fields(value: dict[str, Any], allowed: set[str], context: str) -> None:
    unknown = sorted(set(value) - allowed)
    if unknown:
        raise ValidationError(f"Unknown {context} field(s): {', '.join(unknown)}")


def _validate_setup_config(config: dict[str, Any]) -> None:
    _reject_unknown_fields(config, SETUP_TOP_LEVEL_FIELDS, "setup config")
    global_config = config.get("global", {key: config[key] for key in SETUP_GLOBAL_FIELDS if key in config})
    if not isinstance(global_config, dict):
        raise ValidationError("setup config field 'global' must be an object")
    _reject_unknown_fields(global_config, SETUP_GLOBAL_FIELDS, "global config")
    features = config.get("features", {})
    if not isinstance(features, dict):
        raise ValidationError("setup config field 'features' must be an object")
    _reject_unknown_fields(features, set(SETUP_FEATURE_FIELDS), "feature config")
    for name, feature in features.items():
        if not isinstance(feature, dict):
            raise ValidationError(f"setup feature '{name}' must be an object")
        _reject_unknown_fields(feature, SETUP_FEATURE_FIELDS[name], f"{name} feature")
    nodes = config.get("nodes", [])
    if not isinstance(nodes, list):
        raise ValidationError("setup config field 'nodes' must be a list")
    for index, node in enumerate(nodes):
        if not isinstance(node, dict):
            raise ValidationError(f"setup node at index {index} must be an object")
        _reject_unknown_fields(node, SETUP_NODE_FIELDS, f"node {index}")


def _common_parser(*, suppressed_defaults: bool) -> argparse.ArgumentParser:
    common = argparse.ArgumentParser(add_help=False)
    default = argparse.SUPPRESS if suppressed_defaults else None
    common.add_argument(
        "--vault",
        default=default if suppressed_defaults else str(DEFAULT_VAULT),
        help="encrypted SQLite fleet state (default: %(default)s)" if not suppressed_defaults else argparse.SUPPRESS,
    )
    common.add_argument(
        "--state-dir",
        default=default,
        help="unencrypted state directory for development and tests only; replaces the vault" if not suppressed_defaults else argparse.SUPPRESS,
    )
    common.add_argument(
        "--output-dir",
        default=default if suppressed_defaults else str(DEFAULT_VAULT.parent / "bundles"),
        help="Directory for the rendered host bundles",
    )
    common.add_argument(
        "--repo-root",
        default=default if suppressed_defaults else str(Path(__file__).resolve().parents[2]),
        help="Repository checkout that holds the Compose files",
    )
    common.add_argument("--config", default=default, help="Fleet configuration file to read")
    common.add_argument("--non-interactive", action="store_true", default=default if suppressed_defaults else False, help="Never prompt; fail when an answer is missing")
    common.add_argument("--dry-run", action="store_true", default=default if suppressed_defaults else False, help="Show what would change and change nothing")
    common.add_argument("--yes", action="store_true", default=default if suppressed_defaults else False, help="Answer yes to confirmations")
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
    setup.add_argument("--operator-domain", help="Domain for the control panel and API, for example ops.example.com")
    setup.add_argument("--platform-domain", help="Domain that holds the platform nameserver names")
    setup.add_argument("--release", help="Release manifest version to deploy to this node")
    setup.add_argument("--acme-email", help="Contact address for the certificate authority account")
    setup.add_argument("--dual-stack", action="store_true", help="Plan IPv4 and IPv6 on every node")
    setup.add_argument(
        "--preset",
        choices=("control-only", "control-monitoring", "dedicated-monitoring", "custom"),
        help="Starting layout for the fleet",
    )
    setup.add_argument("--control-name", default="control-1", help="Name of the first control node")
    setup.add_argument("--control-hostname", help="Public host name of the first control node")
    setup.add_argument("--control-ipv4", help="Public IPv4 address of the first control node")
    setup.add_argument("--control-region", default="global", help="Region label of the first control node")
    setup.add_argument("--control-location", default="primary", help="Location label of the first control node")
    setup.add_argument("--no-render", action="store_true", help="Save the plan without rendering the host bundles")

    init = sub.add_parser("init", parents=[command_common], help="Create the fleet state and nothing else")
    init.add_argument("--operator-domain", help="Domain for the control panel and API, for example ops.example.com")
    init.add_argument("--platform-domain", help="Domain that holds the platform nameserver names")
    init.add_argument("--release", help="Release manifest version to deploy to this node")
    init.add_argument("--acme-email", default="", help="Contact address for the certificate authority account")
    init.add_argument("--dual-stack", action="store_true", help="Plan IPv4 and IPv6 on every node")

    add = sub.add_parser("add-node", parents=[command_common], help="Add a host to the plan")
    _node_arguments(add, require_name=True)

    update = sub.add_parser("update-node", parents=[command_common], help="Change a host in the plan")
    update.add_argument("--node", required=True, help="Node name")
    _node_arguments(update, require_name=False, optional=True, include_node=False)

    edge_registration = sub.add_parser(
        "configure-edge-registration",
        parents=[command_common],
        help="store a control-plane-created edge UUID and one-time bootstrap token",
    )
    edge_registration.add_argument("--node", required=True, help="Node name")
    edge_registration.add_argument("--edge-id", required=True, help="Edge identifier shown in the admin panel")
    token_source = edge_registration.add_mutually_exclusive_group(required=True)
    token_source.add_argument("--bootstrap-token-file", help="File that holds the one-time enrollment token")
    token_source.add_argument("--bootstrap-token-stdin", action="store_true", help="Read the one-time enrollment token from standard input")

    clear_edge_token = sub.add_parser(
        "clear-edge-bootstrap-token",
        parents=[command_common],
        help="remove the one-time bootstrap token after successful mTLS enrollment",
    )
    clear_edge_token.add_argument("--node", required=True, help="Node name")

    set_secret = sub.add_parser(
        "set-secret",
        parents=[command_common],
        help="replace a supported secret from a protected file without exposing it in argv",
    )
    set_secret.add_argument("--secret", required=True, help="Name of the secret")
    set_secret.add_argument("--node", help="Node name")
    set_secret.add_argument("--from-file", required=True, help="Read the secret value from this file")

    remove = sub.add_parser("remove-node", parents=[command_common], help="Remove a host from the plan; it does not stop or clean the host")
    remove.add_argument("--node", required=True, help="Node name")

    sub.add_parser("list-nodes", parents=[command_common], help="List the hosts in the plan")
    status = sub.add_parser("status", parents=[command_common], help="Show the fleet and the state of each feature")
    status.add_argument("--json", action="store_true", help="Print the result as JSON")
    doctor = sub.add_parser("doctor", parents=[command_common], help="Check the repository and the local tools")
    doctor.add_argument("--json", action="store_true", help="Print the result as JSON")

    mon = sub.add_parser("configure-monitoring", parents=[command_common], help="Set up monitoring")
    mon.add_argument("--mode", choices=sorted(MONITORING_MODES), required=True, help="How the feature is set up")
    mon.add_argument("--host", help="Host name or address")

    logs = sub.add_parser("configure-logs", parents=[command_common], help="Set up central logs")
    logs.add_argument("--mode", choices=sorted(LOG_MODES), required=True, help="How the feature is set up")
    logs.add_argument("--host", help="Host name or address")
    logs.add_argument("--endpoint", help="Endpoint URL")

    backups = sub.add_parser("configure-backups", parents=[command_common], help="Set up backup settings")
    backups.add_argument("--mode", choices=sorted(BACKUP_MODES), required=True, help="How the feature is set up")
    backups.add_argument("--repository", help="Backup repository location")
    backups.add_argument("--region", default="us-east-1", help="Region label")

    render = sub.add_parser("render", parents=[command_common], help="Generate the host bundles")
    render.add_argument("--node", help="Node name")

    validate = sub.add_parser("validate", parents=[command_common], help="Check that the bundles render, and replace nothing")
    validate.add_argument("--node", help="Node name")

    sub.add_parser("show-start-order", parents=[command_common], help="Show the order in which to start the hosts")

    adopt = sub.add_parser("adopt-existing", parents=[command_common], help="Import the credentials of an existing host")
    adopt.add_argument("--node", required=True, help="Node name")
    adopt.add_argument("--role", choices=sorted(ROLES), required=True, help="Node role")
    adopt.add_argument("--env-file", required=True, help="Existing .env file to adopt")
    adopt.add_argument("--region", required=True, help="Region label")
    adopt.add_argument("--location", required=True, help="Location label")
    adopt.add_argument("--hostname", help="Public host name of the node")
    adopt.add_argument("--public-ipv4", required=True, help="Public IPv4 address")
    adopt.add_argument("--public-ipv6", help="Public IPv6 address")
    adopt.add_argument("--bind-ipv4", default="0.0.0.0", help="IPv4 address the services bind to")

    rotate = sub.add_parser("rotate-secret", parents=[command_common], help="Rotate a supported secret")
    rotate.add_argument("--node", help="Node name")
    rotate.add_argument("--secret", required=True, help="Name of the secret")
    rotate.add_argument("--phase", choices=("prepare", "commit", "abort"), default="prepare", help="Rotation phase to run")

    sub.add_parser("public-key", parents=[command_common], help="print the Fleet SSH public key; add it to root's authorized_keys on every node")
    tc = sub.add_parser("test-connection", parents=[command_common], help="test that Fleet can log in to hosts over SSH (the same test as Fleet web)")
    tc.add_argument("--node", action="append", help="repeatable; default is every host")
    sd = sub.add_parser("ssh-defaults", parents=[command_common], help="save an SSH login (default root, port 22) for every host that has none")
    sd.add_argument("--ssh-user", default="root", help="SSH user")
    sd.add_argument("--ssh-port", type=int, default=22, help="SSH port")
    dn = sub.add_parser("dns-names", parents=[command_common], help="list the DNS names to create and the host each points to")
    dn.add_argument("--check", action="store_true", help="ask the public resolver 1.1.1.1 whether each name points to the right host")
    dn.add_argument("--json", action="store_true", help="Print the result as JSON")
    dn.add_argument("--standby-address", help="public IPv4 of a planned control standby: also list the HA records to set before `control add-standby`")
    dn.add_argument("--standby-name", default="control-2", help="name of the planned standby (default control-2)")
    mt = sub.add_parser("management-tls", parents=[command_common], help="certificates for the control, grafana, and telemetry names")
    mt.add_argument("action", choices=("list", "set", "local", "remove", "ca"), help="list; set (your own certificate); local (generate from a Fleet-local CA); remove (back to automatic); ca (print the local CA)")
    mt.add_argument("--name", action="append", choices=("control", "grafana", "telemetry"), help="repeatable; the management names to change")
    mt.add_argument("--cert-file", help="PEM certificate chain, your certificate first (set)")
    mt.add_argument("--key-file", help="PEM private key without a password (set)")
    dp = sub.add_parser("deploy", parents=[command_common], help="review, then install every host that is not installed yet, in start order")
    dp.add_argument("--plan-only", action="store_true", help="Show the plan and the DNS state and change nothing")
    dp.add_argument("--location", action="append", default=[], metavar="NODE=CC:CONTINENT", help="repeatable; country and continent of an edge, for example pop-1=DE:EU")
    nssh = sub.add_parser("node-ssh", parents=[command_common], help="record how to reach a node over SSH and pin its host key (the node must already authorise the Fleet public key)")
    nssh.add_argument("--node", required=True, help="Node name")
    nssh.add_argument("--host", required=True, help="Host name or address")
    nssh.add_argument("--ssh-port", type=int, default=22, help="SSH port of the node")
    nssh.add_argument("--ssh-user", default="root", help="SSH user for the node")
    trust = sub.add_parser("trust-release-key", parents=[command_common], help="trust the Ed25519 public key that signs release manifests")
    trust.add_argument("--key-file", required=True, help="File that holds the release public key")
    sr = sub.add_parser("set-release", parents=[command_common], help="adopt a signed release manifest for the first install (deploys nothing)")
    sr.add_argument("--manifest", help="Signed release manifest file (default: Fleet builds it for the release your checkout is on)")
    sr.add_argument("--key-file", help="verify with this key instead of the trusted one")
    boot = sub.add_parser("bootstrap", parents=[command_common], help="install prerequisites on a node, deploy its bundle, and start it")
    boot.add_argument("--node", required=True, help="Node name")
    boot.add_argument("--plan-only", action="store_true", help="Show the plan and change nothing")
    boot.add_argument("--country", help="ISO country code of a PoP (Geo-DNS placement)")
    boot.add_argument("--continent", help="continent code of a PoP (Geo-DNS placement)")
    up = sub.add_parser("upgrade", parents=[command_common], help="show, then apply, a rolling upgrade to a signed release manifest")
    up.add_argument("--manifest", help="Signed release manifest file (default: Fleet builds it for the release your checkout is on)")
    up.add_argument("--key-file", help="verify with this key instead of the trusted one")
    up.add_argument("--plan-only", action="store_true", help="Show the plan and change nothing")
    cfg = sub.add_parser("apply-config", parents=[command_common], help="deploy re-rendered configs to the nodes whose bundle changed")
    cfg.add_argument("--plan-only", action="store_true", help="Show the plan and change nothing")
    rb = sub.add_parser("rollback", parents=[command_common], help="return nodes to the previous release and config")
    rb.add_argument("--node", action="append", help="repeatable; default is the whole fleet")
    sec = sub.add_parser("security", parents=[command_common], help="known vulnerabilities in the running release")
    sec.add_argument("--available-manifest", help="a newer signed manifest to check for fixes")
    sec.add_argument("--json", action="store_true", help="Print the result as JSON")
    bk = sub.add_parser("backup-now", parents=[command_common], help="start a control backup on the active control node and wait")
    rs = sub.add_parser("restore", parents=[command_common], help="restore a control backup into a control node")
    rs.add_argument("--node", required=True, help="Node name")
    rs.add_argument("--snapshot", required=True, help="Backup snapshot to restore")
    rs.add_argument("--operation", help="preflight operation UUID, needed with --overwrite-live")
    rs.add_argument("--confirm", help="type RESTORE <snapshot> to proceed")
    rs.add_argument("--overwrite-live", action="store_true", help="allow restoring over a control node that holds data")
    vb = sub.add_parser("vault-backup", parents=[command_common], help="back up the encrypted vault to the off-host restic repository")
    vr = sub.add_parser("vault-restore", parents=[command_common], help="restore the vault on a new workstation")
    vr.add_argument("--snapshot", default="latest", help="Backup snapshot to restore")
    vr.add_argument("--repository", required=True, help="Backup repository location")
    vr.add_argument("--password-file", required=True, help="File that holds the backup repository password")
    alert = sub.add_parser("alert-webhook", parents=[command_common], help="show or set where Alertmanager sends alerts (an https:// URL; optional, and an empty value turns notifications off)")
    alert.add_argument("--url", help="The https:// URL to save, or an empty value to turn notifications off; omit to show the current one")
    hw = sub.add_parser("inventory", parents=[command_common], help="show the hardware and system of every host, as last read; --refresh reads them over SSH first")
    hw.add_argument("--node", action="append", default=[], help="Node name; repeat for several (default: every node)")
    hw.add_argument("--refresh", action="store_true", help="Read the hosts over SSH now and record the result")
    hw.add_argument("--json", action="store_true", help="Print the result as JSON")
    watch = sub.add_parser("watch", parents=[command_common], help="a live table of every host: state, load, memory, disk, and containers")
    watch.add_argument("--interval", type=int, default=10, help="Seconds between readings (minimum 5)")
    watch.add_argument("--once", action="store_true", help="Read once, print, and exit")
    watch.add_argument("--json", action="store_true", help="Print each reading as one JSON line")
    web = sub.add_parser("web", parents=[command_common], help="local fleet web on 127.0.0.1")
    web.add_argument("--host", default="127.0.0.1", help="Host name or address")
    web.add_argument("--port", type=int, default=8765, help="TCP port to listen on")
    web.add_argument("--monitor-interval", type=int, default=60, help="Seconds between read-only probes of every host for the live view; 0 turns probing off (minimum 15)")

    control_cli.add_parser(sub, command_common)

    return root


def _node_arguments(
    target: argparse.ArgumentParser,
    *,
    require_name: bool,
    optional: bool = False,
    include_node: bool = True,
) -> None:
    if include_node:
        target.add_argument("--node", required=require_name, help="Node name")
    target.add_argument("--role", choices=sorted(ROLES), required=require_name, help="Node role")
    target.add_argument("--region", required=require_name, help="Region label")
    target.add_argument("--location", required=require_name, help="Location label")
    target.add_argument("--hostname", help="Public host name of the node")
    target.add_argument("--public-ipv4", required=require_name, help="Public IPv4 address")
    target.add_argument("--public-ipv6", help="Public IPv6 address")
    target.add_argument("--bind-ipv4", default=None if optional else "0.0.0.0", help="IPv4 address the services bind to")
    target.add_argument("--bind-ipv6", help="IPv6 address the services bind to")
    target.add_argument("--monitor-ipv4", help="IPv4 address the monitoring role reaches this node on")
    target.add_argument("--monitor-ipv6", help="IPv6 address the monitoring role reaches this node on")
    target.add_argument("--log-ipv4", help="IPv4 address the log collector reaches this node on")
    target.add_argument("--log-ipv6", help="IPv6 address the log collector reaches this node on")
    target.add_argument("--release", help="Release manifest version to deploy to this node")
    target.add_argument("--extra-env", action="append", default=[], help="Extra KEY=VALUE environment setting for this node; repeat for more")
    target.add_argument("--disabled", action="store_true", default=None if optional else False, help="Keep the node in the plan but deploy nothing to it")
    target.add_argument("--draining", action="store_true", default=None if optional else False, help="Mark the node as draining so it receives no new traffic")


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
        "monitor_ipv6": "monitor_ipv6",
        "log_ipv4": "log_ipv4",
        "log_ipv6": "log_ipv6",
        "release": "release",
    }
    for target, arg_name in mapping.items():
        value = getattr(args, arg_name, None)
        if value is None:
            value = source.get(target)
        if value is not None or (target in NULLABLE_NODE_ADDRESSES and target in source):
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
        "monitor_ipv6": defaults.get("monitor_ipv6"),
        "log_ipv4": defaults.get("log_ipv4"),
        "log_ipv6": defaults.get("log_ipv6"),
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
    _validate_setup_config(config)
    durable_store = store

    def say(message: str) -> None:
        # Dry runs compute the candidate in memory; never report it as written.
        print(f"Dry run, not persisted: {message}" if args.dry_run else message)
    # Assemble a complete candidate without persisting intermediate nodes.
    store = FleetState(store.state_dir, dry_run=True)
    global_cfg = config.get("global", config)
    created = not store.exists()
    if created:
        operator = args.operator_domain or global_cfg.get("operator_domain")
        platform = args.platform_domain or global_cfg.get("platform_domain")
        release = args.release or global_cfg.get("release")
        acme_email = args.acme_email or global_cfg.get("acme_email", "")
        if not args.non_interactive:
            print("CDNFoundry production fleet setup")
            print("This wizard creates fleet state, node bundles, certificates, secrets, and operator runbooks.")
            operator = _prompt(operator, "Operator domain")
            platform = _prompt(platform, "Platform domain")
            release = _prompt(release, "Exact release tag or commit")
            acme_email = _prompt(acme_email, "Admin email for Let's Encrypt certificates")
        if not all((operator, platform, release)):
            raise ValidationError("operator_domain, platform_domain, and release are required")
        state = store.init(
            {
                "operator_domain": operator,
                "platform_domain": platform,
                "release": release,
                "acme_email": acme_email,
                "alert_webhook_url": global_cfg.get("alert_webhook_url", ""),
                "ipv6": args.dual_stack or global_cfg.get("ipv6", False),
            }
        )
        say(f"Initialized fleet state: {store.state_file}")
    else:
        state = store.load()
        # Setup is repeatable; it must not silently ignore conflicting identity
        # or release values in the operator's input file.
        supplied = {key: global_cfg[key] for key in SETUP_GLOBAL_FIELDS if key in global_cfg}
        for key in ('operator_domain', 'platform_domain', 'release', 'acme_email'):
            if getattr(args, key, None) is not None:
                supplied[key] = getattr(args, key)
        if args.dual_stack:
            supplied['ipv6'] = True
        for key, value in supplied.items():
            if value != state['global'].get(key):
                raise ValidationError(f"setup cannot change existing global {key}; retain the current identity and use update-node --release for a planned release change")
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
            current = state
            if name in current["nodes"]:
                state = store.update_node(current, name, payload)
                say("Updated a node from the setup configuration")
            else:
                state = store.add_node(current, payload)
                say("Added a node from the setup configuration")
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
        state = store.add_node(state, control_payload)
        say("Added the control node")

        if preset == "dedicated-monitoring":
            if args.non_interactive:
                raise ValidationError("Dedicated monitoring in non-interactive mode requires a monitoring node in --config")
            monitor_payload = _interactive_node(state, role="monitoring", defaults={"name": "monitoring-1"})
            state = store.add_node(state, monitor_payload)
            say("Added the monitoring node")

        if not args.non_interactive:
            while _prompt_yes_no("Add a DNS or edge node now?", default=False):
                payload = _interactive_node(state)
                state = store.add_node(state, payload)
                say("Added a node")

    state = _apply_setup_features(store, state, config, preset)
    store.validate(state, require_secrets=False)
    # Rendering preflight checks required role values before committing state.
    if not args.no_render:
        Renderer(Path(args.repo_root), store, output_dir, dry_run=True).render(state)
    store = durable_store
    if not args.dry_run:
        store._prepare_dirs()
        store._ensure_global_secrets()
        for candidate_node in state["nodes"].values():
            store._ensure_node_secrets(candidate_node)
        store._write(state)
    print(f"Fleet validation passed ({len(state['nodes'])} node(s)).")

    paths: list[Path] = []
    if not args.no_render:
        renderer = Renderer(Path(args.repo_root), store, output_dir, dry_run=args.dry_run)
        paths = renderer.render(state)
        if not args.dry_run:
            with store.transaction() as candidate:
                candidate["metadata"]["last_successful_validation"] = utc_now()
                candidate["metadata"]["last_successful_render"] = utc_now()
        say(f"Generated {len(paths)} node bundle(s) in {output_dir}")
        for path in paths if not args.dry_run else []:
            print(f"  - {path}")
    result = {
        "status": "validated" if args.dry_run else "configured",
        "preset": preset,
        "state_dir": str(store.state_dir),
        "output_dir": str(output_dir),
        "nodes": sorted(state["nodes"]),
        "bundles": [str(path) for path in paths] if not args.dry_run else [],
    }
    print(json.dumps(result))
    return EXIT_OK


def _status(state: dict[str, Any], output_dir: Path, *, as_json: bool) -> None:
    safe_state = copy.deepcopy(state)
    for node in safe_state["nodes"].values():
        node["extra_env"] = {key: "<redacted>" for key in node.get("extra_env", {})}
    for feature in safe_state["features"].values():
        for field in ("endpoint", "repository"):
            if feature.get(field):
                feature[field] = "<redacted>"
    payload = {
        "global": safe_state["global"],
        "features": safe_state["features"],
        "metadata": safe_state["metadata"],
        "nodes": [safe_state["nodes"][name] for name in sorted(safe_state["nodes"])],
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
    if store.exists() and not args.dry_run:
        from .engine import FleetEngine

        engine = FleetEngine(store.state_dir, root, Path(args.output_dir))
        for c in engine.doctor():
            checks.append({"check": f"{c.node}: {c.name}", "ok": c.ok, "detail": c.detail})
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
    if args.command == "control":
        with store.locked(exclusive=getattr(args, "control_action", "") not in {"status"}):
            if args.control_action in {"add-standby", "refresh-ingress"} and not args.dry_run:
                from .engine import FleetEngine, ha_action

                engine = FleetEngine(state_dir, Path(args.repo_root), output_dir, say=lambda m: print(m, flush=True))
                options = {k: v for k, v in vars(args).items() if k in {"name", "ssh_port", "ssh_user", "replication_address", "active_replication_address",
                                                                       "ingress_certificate", "ingress_key", "public_ipv6", "region", "bind_ipv4", "bind_ipv6"}}
                if args.control_action == "add-standby":
                    options |= {"host": args.host, "edge_wait": args.edge_timeout}
                print(json.dumps(ha_action(engine, args.control_action, **options), indent=2))
                return EXIT_OK
            runner = None
            if (state_dir / "ssh" / "targets.json").exists() and args.ssh_command == " ".join(control_cli.DEFAULT_SSH):
                from .engine import VaultRunner
                from .ssh import SshTransport, load_targets

                runner = VaultRunner(SshTransport(state_dir), load_targets(state_dir), args.deploy_dir)
            print(json.dumps(control_cli.execute(args, store, output_dir, runner=runner), indent=2))
        return EXIT_OK

    with store.locked(exclusive=args.command not in {"list-nodes", "show-start-order", "status", "security"}):
        if args.command in ENGINE_COMMANDS:
            return _engine_command(args, store, output_dir)
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
                    "alert_webhook_url": values.get("alert_webhook_url", ""),
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
            changes = _node_payload(args, config, update=True)
            if "extra_env" in changes and args.node in state["nodes"]:
                extra = dict(state["nodes"][args.node].get("extra_env", {}))
                extra.update(changes["extra_env"])
                changes["extra_env"] = extra
            state = store.update_node(state, args.node, changes)
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
                "bind_ipv6": args.bind_ipv6,
                "monitor_ipv4": args.monitor_ipv4,
                "monitor_ipv6": args.monitor_ipv6,
                "log_ipv4": args.log_ipv4,
                "log_ipv6": args.log_ipv6,
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
                    if state["nodes"][args.node]["role"] != "control":
                        raise ValidationError("pdns-db-password is valid only for the control node, which runs the DNS primary")
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
        value = value.strip()
        if value.startswith("'"):
            if not re.fullmatch(r"'(?:\\'|[^'])*'", value):
                raise ValidationError(f"Invalid single-quoted env value on line {number}")
            value = value[1:-1].replace("\\'", "'")
        elif value.startswith('"'):
            if not re.fullmatch(r'"(?:\\.|[^"\\])*"', value):
                raise ValidationError(f"Invalid double-quoted env value on line {number}")
            value = re.sub(r'\\([\\"nrt$])', lambda match: {"n": "\n", "r": "\r", "t": "\t"}.get(match[1], match[1]), value[1:-1])
        else:
            value = re.split(r"\s+#", value, maxsplit=1)[0].rstrip()
        if key in values:
            raise ValidationError(f"Duplicate env key on line {number}")
        values[key] = value
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
        "CLICKHOUSE_INGEST_PASSWORD",
        "CLICKHOUSE_READER_PASSWORD",
        "CLICKHOUSE_ZONES_PASSWORD",
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
        "CLICKHOUSE_INGEST_PASSWORD": "clickhouse-ingest-password",
        "CLICKHOUSE_READER_PASSWORD": "clickhouse-reader-password",
        "CLICKHOUSE_ZONES_PASSWORD": "clickhouse-zones-password",
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


def _passphrase(*, confirm: bool) -> str:
    import getpass
    import os

    if os.environ.get(PASSPHRASE_ENV):
        return os.environ[PASSPHRASE_ENV]
    if os.environ.get(PASSPHRASE_FILE_ENV):
        return Path(os.environ[PASSPHRASE_FILE_ENV]).read_text(encoding="utf-8").rstrip("\r\n")
    if not sys.stdin.isatty():
        raise StateError(f"Set {PASSPHRASE_ENV} or {PASSPHRASE_FILE_ENV} to unlock the fleet vault")
    value = getpass.getpass("Fleet vault passphrase: ")
    if confirm and getpass.getpass("Repeat passphrase: ") != value:
        raise ValidationError("The passphrases do not match")
    return value


def _vault_restore(args: argparse.Namespace, vault_path: Path) -> int:
    from .vault_backup import restic_env, restore_vault

    password = Path(args.password_file).read_text(encoding="utf-8").rstrip("\r\n")
    restore_vault(vault_path, restic_env(args.repository, password), args.snapshot)
    Vault(vault_path, _passphrase(confirm=False)).unlock()
    print(json.dumps({"status": "restored", "vault": str(vault_path)}))
    return EXIT_OK


def _engine_command(args: argparse.Namespace, store: FleetState, output_dir: Path) -> int:
    """Run an engine command with a step bar on a terminal (plain step lines elsewhere); the first Ctrl+C stops after the current step."""
    import contextlib

    from .progress import BarWriter, TerminalBar, Tracker, graceful_stop

    tracker = Tracker()
    bar = TerminalBar(sys.stdout, sys.stdout.isatty())
    tracker.listeners.append(bar)
    with contextlib.redirect_stdout(BarWriter(bar, sys.stdout)), graceful_stop(tracker):  # type: ignore[type-var]
        try:
            return _engine_command_body(args, store, output_dir, tracker)
        finally:
            bar.close()


def _engine_command_body(args: argparse.Namespace, store: FleetState, output_dir: Path, tracker: Any) -> int:
    from . import management
    from .engine import ControlActions, FleetEngine

    engine = FleetEngine(store.state_dir, Path(args.repo_root), output_dir, say=tracker.line, progress=tracker)

    def outcome(out: Any) -> int:
        print(json.dumps({"ok": out.ok, "failed": out.failed, "detail": out.detail, "rolled_back": out.rolled_back, "steps_done": len(out.done)}))
        return EXIT_OK if out.ok else EXIT_EXTERNAL

    if args.command == "test-connection":
        names = args.node or sorted(engine.state()["nodes"])
        failed = 0
        for name in names:
            try:
                engine.test_connection(name)
                print(json.dumps({"node": name, "connected": True}))
            except FleetError as exc:
                from .web import explain_ssh

                failed += 1
                title, advice = explain_ssh(str(exc), name, name)
                print(json.dumps({"node": name, "connected": False, "problem": title, "fix": advice}))
        return EXIT_OK if not failed else EXIT_EXTERNAL
    if args.command == "ssh-defaults":
        print(json.dumps({"saved": engine.ensure_targets(user=args.ssh_user, port=args.ssh_port)}))
        return EXIT_OK
    if args.command == "dns-names":
        records, glue = engine.dns_names()
        if args.standby_address:
            records = management.ha_records(engine.state(), args.standby_name, args.standby_address)
            glue = []
        results = {r["name"]: management.check_name(r["name"], r["ips"]) for r in records} if args.check and args.standby_address else (engine.check_dns() if args.check else {})
        rows = [{"name": r["name"], "type": "AAAA" if ":" in ip else "A", "address": ip, "host": r["node"], "use": r["use"],
                 **({"ok": results[r["name"]][0], "detail": results[r["name"]][1]} if args.check else {})}
                for group, kind in ((records, "operator-domain dns"), (glue, "registrar glue")) for r in group for ip in r["ips"]]
        for row, kind in zip(rows, [k for group, k in ((records, "operator-domain dns"), (glue, "registrar glue")) for r in group for _ in r["ips"]]):
            row["where"] = kind
        if args.json:
            print(json.dumps(rows, indent=2))
        else:
            for row in rows:
                print(f"{row['type']:<4} {row['name']:<40} -> {row['address']:<16} ({row['host']}; {row['where']}) " + (("OK" if row["ok"] else "NOT READY: " + row["detail"]) if args.check else ""))
        return EXIT_OK if all(r.get("ok", True) for r in rows) else EXIT_EXTERNAL
    if args.command == "management-tls":
        if args.action == "list":
            print(json.dumps(engine.management_certificates(), indent=2))
        elif args.action == "ca":
            ca = engine.local_ca()
            if ca is None:
                raise ValidationError("No local CA has been generated")
            print(ca, end="")
        else:
            if not args.name:
                raise ValidationError("Choose at least one --name")
            if args.action == "set":
                if not (args.cert_file and args.key_file):
                    raise ValidationError("--cert-file and --key-file are required")
                info = engine.set_management_certificate(args.name, Path(args.cert_file).read_text(encoding="utf-8"), Path(args.key_file).read_text(encoding="utf-8"))
                print(json.dumps({"status": "saved", "names": args.name, **info}))
            elif args.action == "local":
                engine.generate_local_certificates(args.name)
                print(json.dumps({"status": "generated", "names": args.name}))
            else:
                print(json.dumps({"status": "removed", "removed": [n for n in args.name if engine.remove_management_certificate(n)]}))
            print("Deploy the change with `apply-config` (hosts already installed) or `deploy` (new hosts).", file=sys.stderr)
        return EXIT_OK
    if args.command == "deploy":
        state = engine.state()
        pending = engine.pending_nodes(state)
        results = engine.check_dns()
        problems = engine.deploy_blockers(pending)
        waiting = management.unready(state, engine.work, results)
        print("Hosts to install, in order: " + (", ".join(pending) or "none"))
        for _key, reason in waiting:
            print(f"DNS not ready (1.1.1.1): {reason}  -> a local certificate will be generated for it")
        for problem in problems:
            print(f"Blocked: {problem}")
        if args.plan_only or problems:
            return EXIT_OK if not problems else EXIT_VALIDATION
        locations: dict[str, tuple[str | None, str | None]] = {}
        for item in args.location:
            node, _, place = item.partition("=")
            country, _, continent = place.partition(":")
            if not (node and country and continent):
                raise ValidationError(f"--location {item!r}: write NODE=CC:CONTINENT, for example pop-1=DE:EU")
            locations[node] = (country.upper(), continent.upper())
        _confirm(args, f"Install {len(pending)} host(s)")
        failed = engine.deploy(locations)
        if failed is not None:
            return outcome(failed)
        print(json.dumps({"ok": True, "installed": pending}))
        return EXIT_OK

    if args.command == "alert-webhook":
        if args.url is not None:
            engine.set_alert_webhook(args.url)
        print(json.dumps({"alert_webhook_url": engine.alert_webhook()}))
        return EXIT_OK
    if args.command == "inventory":
        from . import inventory as inv_mod
        from .terminal import inventory_table

        state = engine.state()
        names = args.node or sorted(state["nodes"])
        unknown = [n for n in names if n not in state["nodes"]]
        if unknown:
            raise ValidationError(f"Unknown node: {', '.join(unknown)}")
        if args.refresh:
            engine.collect_inventory(names)
        recorded = {n: v for n, v in engine.inventory().items() if n in names}
        if args.json:
            print(json.dumps({n: {**recorded.get(n, {}), "findings": inv_mod.findings(state["nodes"][n], recorded.get(n, {}))} for n in names}, indent=2, sort_keys=True))
        else:
            print(inventory_table({n: state["nodes"][n] for n in names}, recorded))
            found = [f"{n}: {f['text']}" for n in names for f in inv_mod.findings(state["nodes"][n], recorded.get(n, {}))] + [f["text"] for f in inv_mod.fleet_findings(recorded)]
            print("\nNeeds attention:" + ("".join("\n  - " + f for f in found) if found else " nothing"))
            if len(recorded) < len(names):
                print(f"\n{len(names) - len(recorded)} host(s) have not been read. Run with --refresh.")
        return EXIT_OK
    if args.command == "watch":
        import time as time_mod

        from .terminal import watch_table

        state = engine.state()
        interval = max(5, args.interval)
        try:
            while True:
                probes = engine.probe_nodes()
                if args.json:
                    from .monitor import summarize

                    print(json.dumps({"at": time_mod.strftime("%Y-%m-%dT%H:%M:%SZ", time_mod.gmtime()), "nodes": {n: summarize(p) for n, p in probes.items()}}, sort_keys=True), flush=True)
                else:
                    if sys.stdout.isatty() and not args.once:
                        sys.stdout.write("\x1b[2J\x1b[H")
                    print(f"CDNFoundry fleet, {time_mod.strftime('%H:%M:%S')}" + ("" if args.once else f", every {interval} s, Ctrl+C to leave") + "\n")
                    print(watch_table(state["nodes"], probes), flush=True)
                if args.once:
                    return EXIT_OK if all(p.get("ok") for p in probes.values()) else EXIT_EXTERNAL
                time_mod.sleep(interval)
        except KeyboardInterrupt:
            return EXIT_OK

    if args.command == "public-key":
        print(engine.public_key())
    elif args.command == "node-ssh":
        marker = engine.set_node_ssh(args.node, args.host, args.ssh_port, args.ssh_user)
        print(json.dumps({"status": "stored", "node": args.node, "host_key_pinned": marker}))
    elif args.command == "trust-release-key":
        engine.trust_release_key(Path(args.key_file).read_text(encoding="utf-8"))
        print(json.dumps({"status": "trusted"}))
    elif args.command == "set-release":
        manifest = engine.set_release(json.loads(Path(args.manifest).read_text(encoding="utf-8")) if args.manifest else None, key_pem=Path(args.key_file).read_text(encoding="utf-8") if args.key_file else None)
        print(json.dumps({"status": "release-set", "release": manifest["release"], "profile": manifest["profile"]}))
    elif args.command == "bootstrap":
        plan = engine.bootstrap_plan(args.node)
        print("\n".join(plan.describe()))
        if args.plan_only:
            return EXIT_OK
        _confirm(args, f"Install and start {args.node}")
        return outcome(engine.bootstrap(args.node, country=args.country, continent=args.continent))
    elif args.command == "upgrade":
        document = json.loads(Path(args.manifest).read_text(encoding="utf-8")) if args.manifest else engine.automatic_release()
        key = Path(args.key_file).read_text(encoding="utf-8") if args.key_file else None
        plan, manifest, diff = engine.upgrade_plan(document, key_pem=key)
        print(f"Profile: {manifest['profile']}; irreversible migration: {'yes' if diff.migrations else 'no'}")
        for name, (old, new) in diff.images.items():
            print(f"  image {name}: {old or 'new'} -> {new or 'removed'}")
        for path in diff.configs:
            print(f"  config {path} changes")
        print("\n".join(plan.describe()))
        if diff.empty:
            print("Nothing changes between the running release and the target.")
        if args.plan_only or not plan.steps:
            return EXIT_OK
        _confirm(args, f"Upgrade the fleet to {manifest['release']}")
        return outcome(engine.upgrade(document, key_pem=key))
    elif args.command == "apply-config":
        plan, _ = engine.config_plan()
        print("\n".join(plan.describe()))
        if args.plan_only or not plan.steps:
            return EXIT_OK
        _confirm(args, "Deploy the changed configs")
        return outcome(engine.apply_config())
    elif args.command == "rollback":
        _confirm(args, "Return " + (", ".join(args.node) if args.node else "the whole fleet") + " to the previous release")
        rolled = engine.rollback(args.node)
        print(json.dumps({"status": "rolled-back", "nodes": rolled}))
    elif args.command == "security":
        available = json.loads(Path(args.available_manifest).read_text(encoding="utf-8"))["manifest"] if args.available_manifest else None
        from .security import classify

        rows = classify(engine.release_manifest(), Path(args.repo_root), available)
        if args.json:
            print(json.dumps(rows, indent=2))
        else:
            for r in rows:
                print(f"{r['severity']:9} {r['id']}  {r['image']}  {r['detail']}")
            print(f"{len(rows)} finding(s)")
    elif args.command == "backup-now":
        print(ControlActions(engine).backup_now())
    elif args.command == "restore":
        control = ControlActions(engine)
        plan = control.restore_plan(args.node, args.snapshot, args.overwrite_live)
        print(f"Restore snapshot {plan['snapshot']} into {plan['node']}; this replaces {plan['replaces']}.")
        print(control.restore(args.node, args.snapshot, args.confirm, overwrite_live=args.overwrite_live, operation=args.operation))
    elif args.command == "vault-backup":
        from .vault_backup import backup_vault, restic_env

        state = store.load()
        feature = state["features"]["backups"]
        if feature["mode"] == "disabled" or not feature.get("repository"):
            raise ValidationError("Configure backups first: the vault goes to the same restic repository")
        env = restic_env(feature["repository"], store.read_secret("backup-password"), store.read_secret("backup-access-key"), store.read_secret("backup-secret-key"), feature.get("region"))
        print(json.dumps({"status": "backed-up", "snapshot": backup_vault(Path(args.vault), env)}))
    return EXIT_OK


def run(args: argparse.Namespace) -> int:
    """Run one command against the vault, or against --state-dir when given."""
    if args.command == "web" and args.host != "127.0.0.1":
        raise ValidationError("The fleet web binds to 127.0.0.1 only")
    if args.command != "web" and (args.state_dir or args.dry_run or (args.command == "doctor" and not Path(args.vault).exists())):
        args.state_dir = args.state_dir or "/nonexistent-dry-run-state"
        return execute(args)
    vault_path = Path(args.vault)
    if args.command == "web":
        import os

        from .web import make_server, require_loopback
        from .web_setup import WebGate

        require_loopback(args.host)
        gate = WebGate(vault_path, Path(args.repo_root), Path(args.output_dir), args.monitor_interval)
        if vault_path.exists() and (os.environ.get(PASSPHRASE_ENV) or os.environ.get(PASSPHRASE_FILE_ENV)):
            gate.open(_passphrase(confirm=False))
        server = make_server(gate, args.host, args.port)
        print(f"Fleet web on http://{args.host}:{args.port}/ (Ctrl+C to stop)")
        try:
            server.serve_forever()
        finally:
            server.server_close()
        return EXIT_OK
    if args.command == "vault-restore":
        return _vault_restore(args, vault_path)
    vault = Vault(vault_path, "")
    creating = not vault.exists()
    vault = Vault(vault_path, _passphrase(confirm=creating))
    if creating:
        if args.command not in {"setup", "init"}:
            raise StateError(f"No fleet vault at {vault_path}; run setup first")
        vault.create()
    with vault.session() as work:
        args.state_dir = str(work)
        code = execute(args)
        # A failed engine action keeps its journal and state so the same command can resume.
        if code not in (EXIT_OK, EXIT_EXTERNAL):
            raise _Unsealed(code)
    vault.record("command", args.command if code == EXIT_OK else f"{args.command} (failed)")
    return code


class _Unsealed(Exception):
    def __init__(self, code: int) -> None:
        self.code = code


def main(argv: list[str] | None = None) -> int:
    try:
        effective = list(sys.argv[1:] if argv is None else argv)
        if not effective:
            if sys.stdin.isatty():
                choice = _prompt_choice(
                    "CDNFoundry Fleet: what do you want to do?",
                    [
                        ("web", "Open Fleet web in the browser (recommended; sets everything up there)"),
                        ("setup", "Run the setup wizard in this terminal"),
                        ("help", "Show the commands"),
                    ],
                    default="web",
                )
                if choice == "help":
                    parser().print_help()
                    return EXIT_OK
                effective = [choice]
            else:
                parser().print_help()
                return EXIT_OK
        return run(parser().parse_args(effective))
    except _Unsealed as exc:
        return exc.code
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
