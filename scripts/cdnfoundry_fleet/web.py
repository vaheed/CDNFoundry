"""Local fleet web: the Fleet CLI's engine behind server-rendered pages.

Binds to 127.0.0.1 only. It holds no state of its own: every request unlocks the vault, reads or changes fleet state
through the same engine the CLI uses, and seals it again. It never edits CDN state (domains, DNS, pools, TLS, users).
Long actions run in one background job; the job page refreshes itself and needs no JavaScript.
"""
from __future__ import annotations

import getpass
import html
import http.server
import json
import secrets
import socketserver
import threading
import time
import urllib.parse
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable

from . import manifest as mf
from .checks import Check
from .common import FleetError, ValidationError
from .engine import FleetEngine, ha_action, ControlActions
from .security import classify
from .ssh import Target
from .state import FleetState
from .vault import Vault

ASSETS = Path(__file__).parent / "web_assets"
LOOPBACK = "127.0.0.1"
PAGES = [("/", "Overview"), ("/nodes", "Nodes"), ("/security", "Security"), ("/upgrade", "Upgrade"), ("/config", "Config"),
         ("/rollback", "Rollback"), ("/backups", "Backups"), ("/ha", "HA"), ("/secrets", "Secrets"), ("/history", "History")]
ROTATABLE = ["metrics-token", "telemetry-token", "edge-proxy-secret", "log-auth-token"]


def require_loopback(host: str) -> str:
    if host != LOOPBACK:
        raise ValidationError(f"The fleet web binds to {LOOPBACK} only, not {host!r}")
    return host


def esc(value: Any) -> str:
    return html.escape(str(value), quote=True)


def state_word(kind: str, label: str) -> str:
    return f'<span class="state {kind}">{esc(label)}</span>'


def table(headers: list[str], rows: list[list[str]], numeric: set[int] | None = None) -> str:
    numeric = numeric or set()
    head = "".join(f'<th{" class=num" if i in numeric else ""}>{esc(h)}</th>' for i, h in enumerate(headers))
    body = "".join("<tr>" + "".join(f'<td{" class=num" if i in numeric else ""}>{c}</td>' for i, c in enumerate(r)) + "</tr>" for r in rows)
    return f'<div class="tablewrap"><table><thead><tr>{head}</tr></thead><tbody>{body}</tbody></table></div>'


def topology_svg(state: dict[str, Any], results: dict[str, bool]) -> str:
    nodes = state["nodes"]
    columns = [("Control", ["control"]), ("DNS", ["dns", "dns-edge"]), ("Edge", ["edge"]), ("Telemetry", ["monitoring"])]
    parts: list[str] = []
    height = 80
    placed: dict[str, tuple[int, int]] = {}
    for col, (title, roles) in enumerate(columns):
        x = 10 + col * 190
        parts.append(f'<text x="{x}" y="18" font-weight="600">{esc(title)}</text>')
        names = sorted(n for n, v in nodes.items() if v["role"] in roles)
        for row, name in enumerate(names):
            y = 30 + row * 46
            placed[name] = (x, y)
            colour = "var(--subtle)" if name not in results else ("var(--success-dot)" if results[name] else "var(--danger-dot)")
            parts.append(f'<rect class="box" x="{x}" y="{y}" width="170" height="36" rx="6"/><circle cx="{x + 14}" cy="{y + 18}" r="5" fill="{colour}"/>'
                         f'<text x="{x + 28}" y="{y + 22}">{esc(name[:20])}</text>')
        height = max(height, 40 + len(names) * 46)
    control = next((p for n, p in placed.items() if nodes[n]["role"] == "control"), None)
    for name, (x, y) in placed.items():
        if control and nodes[name]["role"] in {"edge", "dns", "dns-edge"}:
            parts.insert(0, f'<line x1="{control[0] + 170}" y1="{control[1] + 18}" x2="{x}" y2="{y + 18}"/>')
    label = "Fleet topology: " + (", ".join(f"{n} ({v['role']})" for n, v in sorted(nodes.items())) or "no nodes")
    return f'<svg role="img" aria-label="{esc(label)}" viewBox="0 0 770 {height}" width="770" height="{height}">{"".join(parts)}</svg>'


@dataclass
class Job:
    id: str
    title: str
    lines: list[str] = field(default_factory=list)
    state: str = "running"
    result: str = ""


class FleetWeb:
    def __init__(self, vault: Vault, repo_root: Path, output_dir: Path | None = None, *, transport_factory: Callable[[Path], Any] | None = None) -> None:
        self.vault, self.repo_root = vault, repo_root
        self.output_dir = output_dir or vault.path.parent / "bundles"
        self.transport_factory = transport_factory
        self.csrf = secrets.token_urlsafe(24)
        self.last_checks: list[Check] = []
        self.jobs: dict[str, Job] = {}
        self.write_lock = threading.Lock()
        self.operator = getpass.getuser()
        self.pending_manifest: dict[str, str] = {}

    # -- engine access -----------------------------------------------------------------------------------
    def _engine(self, work: Path, log: Callable[[str], None] = lambda _m: None) -> FleetEngine:
        transport = self.transport_factory(work) if self.transport_factory else None
        return FleetEngine(work, self.repo_root, self.output_dir, transport=transport, say=log)

    def read(self, fn: Callable[[FleetEngine], Any]) -> Any:
        with self.vault.read_session() as work:
            return fn(self._engine(work))

    def run_job(self, title: str, fn: Callable[[FleetEngine], Any], kind: str) -> Job:
        if not self.write_lock.acquire(blocking=False):
            raise ValidationError("Another action is running; wait for it to finish")
        job = Job(secrets.token_hex(6), title)
        self.jobs[job.id] = job

        def work_fn() -> None:
            started = time.time()
            detail: dict[str, Any] = {"operator": self.operator, "title": title}
            try:
                with self.vault.session() as work:
                    engine = self._engine(work, job.lines.append)
                    result = fn(engine)
                    ok = getattr(result, "ok", True)
                    if hasattr(result, "ok"):
                        detail |= {"failed": result.failed, "detail": result.detail, "rolled_back": result.rolled_back, "steps_done": len(result.done)}
                    detail["events"] = engine.events
                    job.result = "" if result is None or hasattr(result, "ok") else str(result)[:2000]
                    job.state = "ok" if ok else "failed"
            except Exception as exc:  # the job page reports it; nothing is sealed
                job.state, job.result = "failed", str(exc)[:1000]
                detail["error"] = job.result
            finally:
                detail["seconds"] = round(time.time() - started, 1)
                try:
                    self.vault.record(kind, json.dumps(detail, sort_keys=True))
                finally:
                    self.write_lock.release()

        threading.Thread(target=work_fn, daemon=True).start()
        return job

    # -- pages -------------------------------------------------------------------------------------------
    def layout(self, path: str, title: str, body: str, flash: str = "", refresh: bool = False) -> str:
        nav = "".join(f'<a href="{p}"{" aria-current=page" if p == path else ""}>{esc(t)}</a>' for p, t in PAGES)
        notice = f'<p class="flash" role="status">{esc(flash)}</p>' if flash else ""
        meta = '<meta http-equiv="refresh" content="2">' if refresh else ""
        return (
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            f'{meta}<title>{esc(title)} - CDNFoundry Fleet</title><link rel="stylesheet" href="/assets/fleet.css"></head>'
            f'<body><div class="shell"><aside class="side"><div class="brand">CDNFoundry Fleet</div><nav aria-label="Fleet">{nav}</nav></aside>'
            f"<main><h1>{esc(title)}</h1>{notice}{body}</main></div></body></html>"
        )

    def results(self) -> dict[str, bool]:
        by_node: dict[str, bool] = {}
        for c in self.last_checks:
            by_node[c.node] = by_node.get(c.node, True) and c.ok
        return by_node

    def fleet_public_key(self) -> str:
        """The public half of the one Fleet key. A vault from before the key existed gets it created and sealed here."""
        from .ssh import FLEET_KEY

        with self.vault.read_session() as work:
            have = (work / "ssh" / FLEET_KEY).exists()
            key = self._engine(work).public_key() if have else ""
        if have:
            return key
        with self.write_lock, self.vault.session() as work:
            return self._engine(work).public_key()

    def csrf_field(self) -> str:
        return f'<input type="hidden" name="csrf" value="{esc(self.csrf)}">'

    def action_form(self, action: str, label: str, *, primary: bool = False, fields: str = "", confirm: str | None = None) -> str:
        typed = (f'<label>Type <b>{esc(confirm)}</b> to confirm<input name="confirm" required autocomplete="off" pattern="{esc(confirm)}"></label>' if confirm else "")
        return (f'<form class="stack" method="post" action="{action}">{self.csrf_field()}{fields}{typed}'
                f'<button{" class=primary" if primary else ""}>{esc(label)}</button></form>')

    def page(self, path: str, query: dict[str, list[str]]) -> tuple[int, str]:
        flash = (query.get("msg") or [""])[0]
        if path.startswith("/jobs/"):
            job = self.jobs.get(path.split("/", 2)[2])
            if job is None:
                return 404, self.layout(path, "Not found", '<p class="empty">That action is not known.</p>')
            running = job.state == "running"
            badge = {"running": state_word("warn", "Running"), "ok": state_word("ok", "Finished"), "failed": state_word("bad", "Failed")}[job.state]
            log = esc("\n".join(job.lines) or "Waiting for the first step...")
            extra = f"<p>{esc(job.result)}</p>" if job.result else ""
            return 200, self.layout(path, job.title, f"<p>{badge}</p><pre class=\"log\">{log}</pre>{extra}<p><a href=\"/\">Back to overview</a></p>", refresh=running)
        state, current, previous, history = self.read(lambda e: (e.state(), e.release_manifest(), e.release_manifest("previous"), None))
        nodes = state["nodes"]
        if path == "/":
            results = self.results()
            kpis = (
                f'<div class="kpis"><div><b>{len(nodes)}</b><span>Nodes</span></div>'
                f'<div><b>{esc(state["global"]["release"])}</b><span>Release</span></div>'
                f'<div><b>{sum(1 for ok in results.values() if ok)}/{len(results) if results else "-"}</b><span>Healthy at last check</span></div>'
                f'<div><b>{esc(state["features"]["backups"]["mode"])}</b><span>Backups</span></div></div>'
            )
            if not nodes:
                return 200, self.layout(path, "Overview", kpis + '<h2>Needs attention</h2><p class="empty">No nodes yet. Add the control node on the Nodes page.</p>', flash)
            attention = [f"{c.node}: {c.name}" + (f" ({c.detail})" if c.detail else "") for c in self.last_checks if not c.ok]
            if not self.last_checks:
                needs = '<p class="empty">Nothing has been checked in this session. Run the check to see the state of every node.</p>'
            elif attention:
                needs = '<ul class="attention">' + "".join(f"<li>{state_word('bad', a)}</li>" for a in attention) + "</ul>"
            else:
                needs = '<p class="attention">Every check passed.</p>'
            body = (kpis + '<h2>Topology</h2><div class="topology">' + topology_svg(state, results) + "</div><h2>Needs attention</h2>" + needs
                    + self.action_form("/check", "Run check of the whole stack", primary=True))
            return 200, self.layout(path, "Overview", body, flash)
        if path == "/nodes":
            rows = [[esc(n["name"]), esc(n["role"]), esc(n["hostname"]), esc(n["public_ipv4"]), esc(n["release"]),
                     (state_word("ok" if self.results().get(n["name"]) else "bad", "Healthy" if self.results().get(n["name"]) else "Needs attention")
                      if n["name"] in self.results() else state_word("", "Not checked")),
                     f'<a href="/nodes/{esc(n["name"])}">Open</a>'] for n in (nodes[k] for k in sorted(nodes))]
            listing = table(["Name", "Role", "Host", "IPv4", "Release", "State", ""], rows) if rows else '<p class="empty">No nodes yet.</p>'
            fields = (
                '<label>Name<input name="name" required pattern="[a-z0-9-]+" autocomplete="off"></label>'
                '<label>Role<select name="role"><option>control</option><option>dns</option><option>edge</option><option>dns-edge</option><option>monitoring</option></select></label>'
                '<label>Public IPv4<input name="public_ipv4" required autocomplete="off"></label>'
                '<label>SSH port<input name="ssh_port" type="number" min="1" max="65535" value="22" required></label>'
                '<label>SSH user<input name="ssh_user" value="root" required autocomplete="off"></label>'
            )
            key_block = ("<h2>Fleet SSH public key</h2><p>One key reaches every node. Add this line to <b>/root/.ssh/authorized_keys</b> on each host "
                         "<b>before</b> you add it here. Fleet logs in with key authentication only.</p>"
                         f'<pre class="log" aria-label="Fleet SSH public key">{esc(self.fleet_public_key())}</pre>')
            return 200, self.layout(path, "Nodes", listing + key_block + "<h2>Add a node</h2>" + self.action_form("/nodes/add", "Add node and show plan", primary=True, fields=fields), flash)
        if path.startswith("/nodes/"):
            name = path.split("/", 2)[2]
            if name not in nodes:
                return 404, self.layout(path, "Not found", '<p class="empty">There is no such node.</p>')
            node = nodes[name]
            plan = self.read(lambda e: e.bootstrap_plan(name))
            enroll = any(s.kind == "enroll" for s in plan.steps)
            geo = ('<label>Country code<input name="country" value="US" maxlength="2" required><span class="hint">Places this PoP in Geo-DNS.</span></label>'
                   '<label>Continent code<input name="continent" value="NA" maxlength="2" required></label>') if enroll else ""
            facts = table(["Role", "Host", "IPv4", "Release"], [[esc(node["role"]), esc(node["hostname"]), esc(node["public_ipv4"]), esc(node["release"])]])
            body = (facts + "<h2>Plan</h2><ol>" + "".join(f"<li>{esc(s.label())}</li>" for s in plan.steps) + "</ol>"
                    + self.action_form(f"/nodes/{esc(name)}/bootstrap", "Install and start this node", primary=True, fields=geo))
            return 200, self.layout(path, name, body, flash)
        if path == "/security":
            rows = classify(current, self.repo_root)
            if current is None:
                body = '<p class="empty">No release is recorded yet. Upgrade to a signed manifest first; this page then lists the known vulnerabilities in its images.</p>'
            elif not rows:
                body = f'<p class="attention">Release {esc(current["release"])} has no known vulnerabilities in its scan.</p>'
            else:
                kind = {"fixed": "ok", "classified": "warn", "no-fix": "warn", "fix-upstream": "bad", "expired": "bad"}
                body = table(["Severity", "Finding", "Image", "State"], [[esc(r["severity"]), esc(r["id"]), esc(r["image"]), state_word(kind[r["state"]], r["detail"])] for r in rows])
            return 200, self.layout(path, "Security", body, flash)
        if path == "/upgrade":
            has_key = self.read(lambda e: e._release_file("trusted.pub").exists())
            trust = "" if has_key else ('<p class="empty">No release signing key is trusted yet. Paste the public key CI signs with.</p>'
                                        + self.action_form("/upgrade/trust", "Trust this key", fields='<label>Release public key (PEM)<textarea name="key" rows="4" required></textarea></label>'))
            plan_form = self.action_form("/upgrade/plan", "Show plan", primary=True, fields='<label>Signed release manifest (JSON)<textarea name="manifest" rows="8" required></textarea></label>') if has_key else ""
            current_line = f"<p>Running release: <b>{esc(current['release'])}</b></p>" if current else "<p>No signed release is recorded yet.</p>"
            return 200, self.layout(path, "Upgrade", current_line + trust + plan_form, flash)
        if path == "/config":
            body = ("<p>Compares the bundles Fleet would render now with what each node runs, validates them on the node, and swaps them in one node at a time.</p>"
                    + self.action_form("/config/apply", "Show changes and deploy", primary=True))
            return 200, self.layout(path, "Config", body, flash)
        if path == "/rollback":
            if not previous:
                body = '<p class="empty">There is no previous release to return to.</p>'
            else:
                blocked = bool(current and current.get("irreversible_migration"))
                body = (f"<p>Running <b>{esc(current['release'] if current else '?')}</b>; previous <b>{esc(previous['release'])}</b>.</p>"
                        + ('<p class="empty">The running release holds an irreversible migration. Restore from backup instead.</p>' if blocked else
                           self.action_form("/rollback/run", "Roll the whole fleet back", confirm="ROLLBACK")))
            return 200, self.layout(path, "Rollback", body, flash)
        if path == "/backups":
            controls = "".join(f"<option>{esc(n)}</option>" for n, v in sorted(nodes.items()) if v["role"] == "control")
            fields = (f'<label>Control node<select name="node">{controls}</select></label>'
                      '<label>Snapshot ID<input name="snapshot" required pattern="[0-9a-f]{8,64}" autocomplete="off"></label>'
                      '<label>Preflight operation UUID<input name="operation" autocomplete="off"><span class="hint">Only for overwriting a live control node.</span></label>'
                      '<label><span><input type="checkbox" name="overwrite" value="1"> Allow overwriting a live control node</span></label>'
                      '<label>Type RESTORE followed by the snapshot ID<input name="confirm" required autocomplete="off" pattern="RESTORE [0-9a-f]{8,64}"></label>')
            body = (self.action_form("/backups/now", "Back up now", primary=True)
                    + self.action_form("/backups/vault", "Back up the Fleet vault to the restic repository")
                    + "<h2>Restore</h2><p>Restores into an empty control node by default. The exact confirmation is <b>RESTORE</b> followed by the snapshot ID.</p>"
                    + self.action_form("/backups/restore", "Restore", fields=fields)
                    )
            return 200, self.layout(path, "Backups", body, flash)
        if path == "/ha":
            section = state.get("ha") or {}
            if not section or section.get("mode") == "none" or not section.get("standby"):
                info = '<p class="empty">This fleet has one control node (<b>ha: none</b>). Add a standby to survive losing it.</p>'
                fields = ('<label>Standby host<input name="host" required autocomplete="off"></label><label>Name<input name="name" value="control-2" required></label>'
                          '<label>Ingress certificate path<input name="cert" required autocomplete="off"></label><label>Ingress key path<input name="key" required autocomplete="off"></label>')
                actions_html = self.action_form("/ha/add-standby", "Add standby", primary=True, fields=fields)
            else:
                info = table(["Active", "Standby"], [[esc(section.get("active", "?")), esc(section.get("standby", "?"))]])
                actions_html = "".join(self.action_form(f"/ha/{a}", label, confirm=a.upper()) for a, label in
                                       [("switchover", "Planned switchover"), ("demote", "Demote the active node"), ("promote", "Promote the standby"),
                                        ("rejoin", "Rejoin the former active node"), ("remove-standby", "Remove the standby")])
            return 200, self.layout(path, "HA", info + actions_html, flash)
        if path == "/secrets":
            options = "".join(f"<option>{esc(n)}</option>" for n in ROTATABLE)
            body = ("<p>Rotates a fleet secret, then deploys it to the nodes that use it. Application keys, the backup password, and the database passwords rotate only through the "
                    "guided CLI procedures.</p>"
                    + self.action_form("/secrets/rotate", "Rotate and deploy", primary=True, fields=f'<label>Secret<select name="secret">{options}</select></label>', confirm="ROTATE"))
            return 200, self.layout(path, "Secrets", body, flash)
        if path == "/history":
            events = self.vault.history()
            rows = []
            for h in events:
                try:
                    d = json.loads(h["detail"])
                    summary = f'{d.get("operator", "")}: {d.get("title", "")} ' + ("failed: " + str(d.get("failed") or d.get("error")) if d.get("failed") or d.get("error") else "ok")
                except ValueError:
                    summary = h["detail"]
                rows.append([esc(h["at"]), esc(h["kind"]), esc(summary)])
            body = table(["When", "Action", "Result"], rows) if rows else '<p class="empty">No actions recorded yet.</p>'
            return 200, self.layout(path, "History", body, flash)
        return 404, self.layout(path, "Not found", '<p class="empty">There is no such page.</p>')

    # -- actions -----------------------------------------------------------------------------------------
    def post(self, path: str, form: dict[str, str]) -> tuple[str, str]:
        """Returns (redirect path, flash message). Long actions redirect to their job page."""
        if not secrets.compare_digest(form.get("csrf", ""), self.csrf):
            raise ValidationError("The form has expired; reload the page")

        def need(text: str) -> None:
            if form.get("confirm") != text:
                raise ValidationError(f"Type exactly: {text}")

        def job(title: str, fn: Callable[[FleetEngine], Any], kind: str) -> tuple[str, str]:
            return f"/jobs/{self.run_job(title, fn, kind).id}", ""

        if path == "/check":
            def check(e: FleetEngine) -> str:
                self.last_checks = e.doctor()
                bad = sum(1 for c in self.last_checks if not c.ok)
                return f"{len(self.last_checks)} checks, {bad} failing"
            return job("Check of the whole stack", check, "check")
        if path == "/nodes/add":
            if not self.write_lock.acquire(blocking=False):
                raise ValidationError("Another action is running; wait for it to finish")
            try:
                with self.vault.session() as work:
                    engine = self._engine(work)
                    name = form["name"]
                    engine.store.add_node(engine.state(), {"name": name, "role": form["role"], "public_ipv4": form["public_ipv4"]})
                    engine.set_node_ssh(name, form["public_ipv4"], int(form["ssh_port"]), form["ssh_user"])
            finally:
                self.write_lock.release()
            self.vault.record("add-node", json.dumps({"operator": self.operator, "title": f"Add node {name}"}))
            return f"/nodes/{name}", f"Added {name}. Review the plan, then install."
        if path.startswith("/nodes/") and path.endswith("/bootstrap"):
            name = path.split("/")[2]
            return job(f"Install {name}", lambda e: e.bootstrap(name, country=form.get("country"), continent=form.get("continent")), "bootstrap")
        if path == "/upgrade/trust":
            return self._sync("trust-release-key", lambda e: e.trust_release_key(form["key"]), "/upgrade", "Release key trusted")
        if path == "/upgrade/plan":
            document = json.loads(form["manifest"])
            plan, manifest, diff = self.read(lambda e: e.upgrade_plan(document))
            token = secrets.token_urlsafe(12)
            self.pending_manifest[token] = form["manifest"]
            lines = [f"image {n}: {o or 'new'} -> {w or 'removed'}" for n, (o, w) in diff.images.items()] + [f"config {c} changes" for c in diff.configs]
            body = (f"<p>Release <b>{esc(manifest['release'])}</b>, profile <b>{esc(manifest['profile'])}</b>; irreversible migration: <b>{'yes' if diff.migrations else 'no'}</b>.</p>"
                    "<h2>Changes</h2>" + ("<ul>" + "".join(f"<li>{esc(l)}</li>" for l in lines) + "</ul>" if lines else '<p class="empty">Nothing changes between the running release and the target.</p>')
                    + "<h2>Plan</h2><ol>" + "".join(f"<li>{esc(s.label())}</li>" for s in plan.steps) + "</ol>"
                    + "".join(f"<p class=hint>Unchanged, not restarted: {esc(n)}</p>" for n in plan.skipped)
                    + (self.action_form("/upgrade/apply", "Upgrade the fleet", primary=True, fields=f'<input type="hidden" name="token" value="{esc(token)}">') if plan.steps else ""))
            raise _Render(self.layout("/upgrade", "Upgrade plan", body))
        if path == "/upgrade/apply":
            document = json.loads(self.pending_manifest.pop(form["token"], "null") or "null")
            if document is None:
                raise ValidationError("The plan has expired; show it again")
            return job("Rolling upgrade", lambda e: e.upgrade(document), "upgrade")
        if path == "/config/apply":
            return job("Deploy changed configs", lambda e: e.apply_config(), "config")
        if path == "/rollback/run":
            need("ROLLBACK")
            return job("Roll back the fleet", lambda e: f"returned {e.rollback()}", "rollback")
        if path == "/backups/now":
            return job("Back up now", lambda e: ControlActions(e).backup_now(), "backup")
        if path == "/backups/vault":
            return job("Back up the vault", lambda e: self._vault_backup(e), "vault-backup")
        if path == "/backups/restore":
            snapshot = form["snapshot"]
            need(f"RESTORE {snapshot}")
            return job("Restore", lambda e: ControlActions(e).restore(form["node"], snapshot, form["confirm"], overwrite_live=form.get("overwrite") == "1", operation=form.get("operation") or None), "restore")
        if path.startswith("/ha/"):
            action = path.split("/", 2)[2]
            if action == "add-standby":
                return job("Add standby", lambda e: json.dumps(ha_action(e, "add-standby", host=form["host"], name=form["name"], ingress_certificate=form["cert"], ingress_key=form["key"], public_ipv4=form["host"])), "ha")
            need(action.upper())
            return job(f"HA: {action}", lambda e: json.dumps(ha_action(e, action)), "ha")
        if path == "/secrets/rotate":
            secret = form["secret"]
            if secret not in ROTATABLE:
                raise ValidationError("That secret rotates only through the CLI")
            need("ROTATE")

            def rotate(e: FleetEngine) -> Any:
                e.store.rotate_secret(secret)
                return e.apply_config()
            return job(f"Rotate {secret}", rotate, "rotate-secret")
        raise ValidationError("Unknown action")

    def _sync(self, kind: str, fn: Callable[[FleetEngine], Any], target: str, flash: str) -> tuple[str, str]:
        if not self.write_lock.acquire(blocking=False):
            raise ValidationError("Another action is running; wait for it to finish")
        try:
            with self.vault.session() as work:
                fn(self._engine(work))
        finally:
            self.write_lock.release()
        self.vault.record(kind, json.dumps({"operator": self.operator, "title": flash}))
        return target, flash

    def _vault_backup(self, e: FleetEngine) -> str:
        from .vault_backup import backup_vault, restic_env

        feature = e.state()["features"]["backups"]
        if feature["mode"] == "disabled" or not feature.get("repository"):
            raise ValidationError("Configure backups first")
        env = restic_env(feature["repository"], e.store.read_secret("backup-password"), e.store.read_secret("backup-access-key"), e.store.read_secret("backup-secret-key"), feature.get("region"))
        return "snapshot " + backup_vault(self.vault.path, env)


class _Render(Exception):
    def __init__(self, html_text: str) -> None:
        self.html = html_text


def make_server(web: FleetWeb, host: str = LOOPBACK, port: int = 8765) -> http.server.HTTPServer:
    require_loopback(host)

    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self, *_a: Any) -> None:
            pass

        def _host_ok(self) -> bool:
            # Rejects DNS rebinding: only the loopback names this server listens on are served.
            return self.headers.get("Host", "").split(":")[0] in {LOOPBACK, "localhost"}

        def _send(self, code: int, body: str, ctype: str = "text/html; charset=utf-8") -> None:
            data = body.encode()
            self.send_response(code)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(data)))
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Frame-Options", "DENY")
            self.send_header("Content-Security-Policy", "default-src 'none'; style-src 'self'; form-action 'self'; img-src 'self'; frame-ancestors 'none'")
            self.end_headers()
            self.wfile.write(data)

        def do_GET(self) -> None:
            if not self._host_ok():
                return self._send(403, "Forbidden", "text/plain")
            url = urllib.parse.urlparse(self.path)
            if url.path == "/assets/fleet.css":
                return self._send(200, (ASSETS / "fleet.css").read_text(), "text/css")
            try:
                code, body = web.page(url.path, urllib.parse.parse_qs(url.query))
            except FleetError as exc:
                code, body = 500, web.layout(url.path, "Error", f'<p class="empty" role="alert">{esc(exc)}</p>')
            self._send(code, body)

        def do_POST(self) -> None:
            if not self._host_ok():
                return self._send(403, "Forbidden", "text/plain")
            length = int(self.headers.get("Content-Length", "0"))
            if length > 256 * 1024:
                return self._send(413, "Too large", "text/plain")
            form = {k: v[0] for k, v in urllib.parse.parse_qs(self.rfile.read(length).decode(), keep_blank_values=True).items()}
            try:
                target, msg = web.post(urllib.parse.urlparse(self.path).path, form)
            except _Render as page:
                return self._send(200, page.html)
            except (FleetError, ValueError, KeyError) as exc:
                return self._send(400, web.layout("", "Action failed", f'<p class="empty" role="alert">{esc(exc)}</p>'))
            self.send_response(303)
            self.send_header("Location", target + (("?" + urllib.parse.urlencode({"msg": msg})) if msg else ""))
            self.end_headers()

    class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
        daemon_threads = True

    return Server((host, port), Handler)
