"""First-run and unlock pages for the local fleet web.

The fleet web used to need a vault made in a terminal first. This gate lets the browser do everything: it shows a setup
form when no vault exists (passphrase, domains, release, topology, nodes) and an unlock form when one does. Once the vault is
open it hands every request to FleetWeb. Nothing here edits CDN state.
"""
from __future__ import annotations

import contextlib
import io
import json
import re
import secrets
import tempfile
import threading
from pathlib import Path
from typing import Any

from . import __version__
from .common import FleetError, StateError, ValidationError
from .ssh import Target, save_target
from .state import ROLES
from .vault import MIN_PASSPHRASE, Vault
from .web import FleetWeb, esc

PRESETS = [
    ("control-monitoring", "Control and monitoring on one host (recommended)"),
    ("control-only", "Control plane only, no monitoring"),
    ("dedicated-monitoring", "Control plane plus a dedicated monitoring host"),
    ("custom", "Custom"),
]
NODE_NAME = re.compile(r"^[a-z0-9][a-z0-9-]{0,40}$")
EXTRA_ROLES = sorted(ROLES - {"control"})


def parse_extra_nodes(text: str, operator_domain: str) -> list[dict[str, Any]]:
    """One node per line: `name role ipv4 [region] [port=2222] [user=ubuntu]`, separated by spaces or commas."""
    nodes: list[dict[str, Any]] = []
    for number, raw in enumerate(text.splitlines(), 1):
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        parts = [p for p in re.split(r"[\s,]+", line) if p]
        options = {p.split("=", 1)[0]: p.split("=", 1)[1] for p in parts if "=" in p}
        parts = [p for p in parts if "=" not in p]
        if len(parts) not in (3, 4) or set(options) - {"port", "user"}:
            raise ValidationError(f"Node line {number}: write `name role ipv4 [region] [port=2222] [user=ubuntu]`, for example `pop-1 dns-edge 203.0.113.10 EU port=2222`")
        if "port" in options and not (options["port"].isdigit() and 1 <= int(options["port"]) <= 65535):
            raise ValidationError(f"Node line {number}: the SSH port must be a number from 1 to 65535")
        name, role, ipv4 = parts[:3]
        if not NODE_NAME.match(name):
            raise ValidationError(f"Node line {number}: the name may use lowercase letters, digits, and dashes only")
        if role not in EXTRA_ROLES:
            raise ValidationError(f"Node line {number}: the role must be one of {', '.join(EXTRA_ROLES)}")
        nodes.append({"name": name, "role": role, "region": parts[3] if len(parts) == 4 else "global", "location": "primary",
                      "hostname": f"{name}.{operator_domain}", "public_ipv4": ipv4, "bind_ipv4": "0.0.0.0",
                      "_ssh_port": int(options["port"]) if "port" in options else None, "_ssh_user": options.get("user")})
    return nodes


class WebGate:
    """Same surface as FleetWeb (page, post, layout, csrf) so the HTTP handler does not care which one answers."""

    def __init__(self, vault_path: Path, repo_root: Path, output_dir: Path | None = None, monitor_interval: int = 0) -> None:
        self.monitor_interval = monitor_interval
        self.vault_path, self.repo_root = vault_path, repo_root
        self.output_dir = output_dir or vault_path.parent / "bundles"
        self.csrf = secrets.token_urlsafe(24)
        self.web: FleetWeb | None = None
        self.lock = threading.Lock()

    def open(self, passphrase: str) -> None:
        vault = Vault(self.vault_path, passphrase)
        vault.unlock()
        self.web = FleetWeb(vault, self.repo_root, self.output_dir)
        self.csrf = self.web.csrf
        if self.monitor_interval:
            self.web.start_monitor(self.monitor_interval)

    # -- handler surface ---------------------------------------------------------------------------------
    def layout(self, path: str, title: str, body: str, flash: str = "", refresh: bool = False) -> str:
        if self.web:
            return self.web.layout(path, title, body, flash, refresh)
        notice = f'<p class="flash" role="status">{esc(flash)}</p>' if flash else ""
        return (
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            f'<title>{esc(title)} - CDNFoundry Fleet</title><link rel="stylesheet" href="/assets/fleet.css"></head>'
            f'<body><div class="shell"><aside class="side"><div class="brand">CDNFoundry Fleet</div></aside>'
            f"<main><h1>{esc(title)}</h1>{notice}{body}</main></div></body></html>"
        )

    def page(self, path: str, query: dict[str, list[str]]) -> tuple[int, str]:
        if self.web:
            return self.web.page(path, query)
        flash = (query.get("msg") or [""])[0]
        if self.vault_path.exists():
            return 200, self.layout(path, "Unlock", self._unlock_form(), flash)
        return 200, self.layout(path, "Set up your fleet", self._setup_form(), flash)

    def post(self, path: str, form: dict[str, str]) -> tuple[str, str]:
        if self.web:
            return self.web.post(path, form)
        if not secrets.compare_digest(form.get("csrf", ""), self.csrf):
            raise ValidationError("The form has expired; reload the page")
        with self.lock:
            if self.web:
                return "/", ""
            if path == "/unlock" and self.vault_path.exists():
                try:
                    self.open(form.get("passphrase", ""))
                except FleetError as exc:
                    raise ValidationError("That passphrase did not unlock the vault") from exc
                return "/", ""
            if path == "/setup" and not self.vault_path.exists():
                return self._create(form)
        raise ValidationError("That action is not available yet")

    # -- forms -------------------------------------------------------------------------------------------
    def _field(self, label: str, name: str, *, hint: str = "", **attrs: str) -> str:
        extra = "".join(f' {k.rstrip("_")}="{esc(v)}"' for k, v in attrs.items())
        return f'<label>{esc(label)}<input name="{name}"{extra}>{f"<span class=hint>{esc(hint)}</span>" if hint else ""}</label>'

    def _unlock_form(self) -> str:
        return (f'<p>Enter the Fleet vault passphrase to continue.</p><form class="stack" method="post" action="/unlock"><input type="hidden" name="csrf" value="{esc(self.csrf)}">'
                + self._field("Passphrase", "passphrase", type="password", required="", autocomplete="current-password", autofocus="")
                + '<button class="primary">Unlock</button></form>')

    def _setup_form(self) -> str:
        presets = "".join(f'<option value="{v}">{esc(t)}</option>' for v, t in PRESETS)
        return (
            "<p>This creates your encrypted Fleet vault, plans your hosts, and prepares their install bundles. Nothing is installed on a "
            "host until you choose to on the Nodes page.</p>"
            f'<form class="stack" method="post" action="/setup"><input type="hidden" name="csrf" value="{esc(self.csrf)}">'
            "<h2>Vault</h2>"
            + self._field("Passphrase", "passphrase", type="password", required="", minlength=str(MIN_PASSPHRASE), autocomplete="new-password",
                          hint=f"At least {MIN_PASSPHRASE} characters. It cannot be recovered; store it safely.")
            + self._field("Repeat passphrase", "passphrase2", type="password", required="", autocomplete="new-password")
            + "<h2>Your platform</h2>"
            + self._field("Operator domain", "operator_domain", required="", placeholder="cdn.example.com", hint="Where the control panel and API live.")
            + self._field("Platform domain", "platform_domain", required="", placeholder="example.net", hint="Holds the platform nameserver names.")
            + self._field("Release", "release", required="", value=f"v{__version__}", hint="Exact release tag or commit to deploy.")
            + self._field("Admin email for Let's Encrypt", "acme_email", type="email", required="", autocomplete="email", hint="Let's Encrypt sends expiry and problem notices here. Used for the free certificates of the control panel and Grafana.")
            + f'<label>Layout<select name="preset">{presets}</select></label>'
            + "<h2>Control host</h2>"
            + self._field("Name", "control_name", required="", value="control-1", pattern="[a-z0-9][a-z0-9-]*")
            + self._field("Public IPv4", "control_ipv4", required="", placeholder="203.0.113.5")
            + self._field("Host name", "control_hostname", hint="Leave empty to use name.operator-domain.")
            + "<h2>Other hosts</h2>"
            + '<label>One host per line: name role ipv4 [region]<textarea name="nodes" rows="5" spellcheck="false" '
              'placeholder="pop-1 dns-edge 203.0.113.10 EU&#10;pop-2 dns-edge 203.0.113.11 AS"></textarea>'
              f'<span class="hint">Roles: {esc(", ".join(EXTRA_ROLES))}. A host with its own SSH port or user: add port=2222 user=ubuntu. You can change any login later.</span></label>'
            + "<h2>SSH</h2>"
            + self._field("SSH user for all hosts", "ssh_user", required="", value="root")
            + self._field("SSH port for all hosts", "ssh_port", type="number", min_="1", max_="65535", required="", value="22")
            + '<button class="primary">Create fleet</button></form>'
        ).replace("min_=", "min=").replace("max_=", "max=")

    # -- creation ----------------------------------------------------------------------------------------
    def _create(self, form: dict[str, str]) -> tuple[str, str]:
        from . import cli

        passphrase = form.get("passphrase", "")
        if passphrase != form.get("passphrase2", ""):
            raise ValidationError("The passphrases do not match")
        operator = form.get("operator_domain", "").strip().lower()
        if not operator:
            raise ValidationError("Enter the operator domain")
        control = form.get("control_name", "control-1").strip()
        if not NODE_NAME.match(control):
            raise ValidationError("The control host name may use lowercase letters, digits, and dashes only")
        ssh_user, ssh_port = form.get("ssh_user", "root").strip() or "root", int(form.get("ssh_port", "22"))
        nodes = [{"name": control, "role": "control", "region": "global", "location": "primary",
                  "hostname": form.get("control_hostname", "").strip() or f"{control}.{operator}",
                  "public_ipv4": form.get("control_ipv4", "").strip(), "bind_ipv4": "0.0.0.0"}]
        nodes += parse_extra_nodes(form.get("nodes", ""), operator)
        logins = {n["name"]: (n.pop("_ssh_user", None) or ssh_user, n.pop("_ssh_port", None) or ssh_port) for n in nodes}
        if len({n["name"] for n in nodes}) != len(nodes):
            raise ValidationError("Every host needs a different name")
        preset = form.get("preset", "control-monitoring")
        if preset not in {v for v, _ in PRESETS}:
            raise ValidationError("Choose one of the listed layouts")
        email = form.get("acme_email", "").strip()
        if not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", email):
            raise ValidationError("Enter a valid admin email for Let's Encrypt, for example admin@example.com")
        config = {"global": {"operator_domain": operator, "platform_domain": form.get("platform_domain", "").strip().lower(),
                             "release": form.get("release", "").strip(), "acme_email": form.get("acme_email", "").strip()},
                  "preset": preset, "nodes": nodes}
        vault = Vault(self.vault_path, passphrase)
        vault.create()  # also checks the passphrase length
        try:
            with tempfile.TemporaryDirectory(prefix="cdnf-setup-") as scratch:
                config_file = Path(scratch) / "setup.json"
                config_file.write_text(json.dumps(config), encoding="utf-8")
                config_file.chmod(0o600)
                args = cli.parser().parse_args(["setup", "--non-interactive", "--config", str(config_file),
                                                "--output-dir", str(self.output_dir), "--repo-root", str(self.repo_root)])
                with vault.session() as work:
                    args.state_dir = str(work)
                    with contextlib.redirect_stdout(io.StringIO()):
                        code = cli.execute(args)
                    if code != cli.EXIT_OK:
                        raise StateError("Setup did not finish")
                    for node in nodes:
                        save_target(work, Target(node["name"], node["public_ipv4"], logins[node["name"]][1], logins[node["name"]][0]))
                    from .engine import FleetEngine

                    FleetEngine(work, self.repo_root, self.output_dir).public_key()
            vault.record("setup", json.dumps({"operator": "web", "title": "First-time setup", "nodes": [n["name"] for n in nodes]}))
        except BaseException:
            # A half-made vault would block the next try; the form can be submitted again.
            self.vault_path.unlink(missing_ok=True)
            raise
        self.open(passphrase)
        return "/deploy", "Your fleet is created. Add the SSH key to each host, set the signed release, then review and deploy."
