"""Local fleet web: the Fleet CLI's engine behind server-rendered pages.

Binds to 127.0.0.1 only. It holds no state of its own: every request unlocks the
vault, reads or changes fleet state through the same engine the CLI uses, and
seals it again. It never edits CDN state (domains, DNS, pools, TLS, users).
"""
from __future__ import annotations

import html
import http.server
import json
import secrets
import socketserver
import threading
import urllib.parse
from pathlib import Path
from typing import Any, Callable

from . import actions, manifest as mf
from .checks import Check, run_checks
from .common import FleetError, ValidationError
from .ssh import SshTransport, Target
from .state import FleetState
from .vault import Vault

ASSETS = Path(__file__).parent / "web_assets"
LOOPBACK = "127.0.0.1"
PAGES = [("/", "Overview"), ("/nodes", "Nodes"), ("/security", "Security"), ("/upgrade", "Upgrade"), ("/history", "History")]


def require_loopback(host: str) -> str:
    if host != LOOPBACK:
        raise ValidationError(f"The fleet web binds to {LOOPBACK} only, not {host!r}")
    return host


def esc(value: Any) -> str:
    return html.escape(str(value), quote=True)


def state_word(kind: str, label: str) -> str:
    return f'<span class="state {kind}">{esc(label)}</span>'


def topology_svg(state: dict[str, Any], results: dict[str, bool]) -> str:
    nodes = state["nodes"]
    columns = [("Control", ["control"]), ("DNS", ["dns", "dns-edge"]), ("Edge", ["edge"]), ("Telemetry", ["monitoring"])]
    parts: list[str] = []
    width = 0
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
        width = max(width, 30 + max(len(names), 1) * 46)
    for name, (x, y) in placed.items():
        role = nodes[name]["role"]
        if role in {"edge", "dns", "dns-edge"}:
            control = next((p for n, p in placed.items() if nodes[n]["role"] == "control"), None)
            if control:
                parts.insert(0, f'<line x1="{control[0] + 170}" y1="{control[1] + 18}" x2="{x}" y2="{y + 18}"/>')
    label = "Fleet topology: " + ", ".join(f"{n} ({v['role']})" for n, v in sorted(nodes.items()))
    return f'<svg role="img" aria-label="{esc(label)}" viewBox="0 0 770 {max(width, 80)}" width="770" height="{max(width, 80)}">{"".join(parts)}</svg>'


class FleetWeb:
    def __init__(self, vault: Vault, repo_root: Path, *, transport_factory: Callable[[Path], Any] = SshTransport) -> None:
        self.vault, self.repo_root, self.transport_factory = vault, repo_root, transport_factory
        self.csrf = secrets.token_urlsafe(24)
        self.last_checks: list[Check] = []
        self.lock = threading.Lock()

    def snapshot(self) -> dict[str, Any]:
        with self.vault.session() as work:
            return FleetState(work).load()

    def layout(self, path: str, title: str, body: str, flash: str = "") -> str:
        nav = "".join(f'<a href="{p}"{" aria-current=page" if p == path else ""}>{esc(t)}</a>' for p, t in PAGES)
        notice = f'<p class="flash" role="status">{esc(flash)}</p>' if flash else ""
        return (
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            f"<title>{esc(title)} - CDNFoundry Fleet</title><link rel=\"stylesheet\" href=\"/assets/fleet.css\"></head>"
            f'<body><div class="shell"><aside class="side"><div class="brand">CDNFoundry Fleet</div><nav aria-label="Fleet">{nav}</nav></aside>'
            f"<main><h1>{esc(title)}</h1>{notice}{body}</main></div></body></html>"
        )

    def results(self) -> dict[str, bool]:
        by_node: dict[str, bool] = {}
        for c in self.last_checks:
            by_node[c.node] = by_node.get(c.node, True) and c.ok
        return by_node

    def page(self, path: str, query: dict[str, list[str]]) -> tuple[int, str]:
        state = self.snapshot()
        flash = (query.get("msg") or [""])[0]
        nodes = state["nodes"]
        if path == "/":
            results = self.results()
            attention = [f"{c.node}: {c.name}" + (f" ({c.detail})" if c.detail else "") for c in self.last_checks if not c.ok]
            kpis = (
                f'<div class="kpis"><div><b>{len(nodes)}</b><span>Nodes</span></div>'
                f'<div><b>{esc(state["global"]["release"])}</b><span>Release</span></div>'
                f'<div><b>{sum(1 for ok in results.values() if ok)}/{len(results) if results else "-"}</b><span>Healthy at last check</span></div>'
                f'<div><b>{esc(state["features"]["backups"]["mode"])}</b><span>Backups</span></div></div>'
            )
            if not nodes:
                body = kpis + '<h2>Needs attention</h2><p class="empty">No nodes yet. Add the control node on the Nodes page.</p>'
            else:
                if not self.last_checks:
                    needs = '<p class="empty">Nothing has been checked in this session. Run the check to see the state of every node.</p>'
                elif attention:
                    needs = '<ul class="attention">' + "".join(f"<li>{state_word('bad', a)}</li>" for a in attention) + "</ul>"
                else:
                    needs = '<p class="attention">Every check passed.</p>'
                body = (kpis + '<h2>Topology</h2><div class="topology">' + topology_svg(state, results) + "</div>"
                        '<h2>Needs attention</h2>' + needs + self._post_form("/check", "Run check of the whole stack", primary=True))
            return 200, self.layout(path, "Overview", body, flash)
        if path == "/nodes":
            rows = "".join(
                f'<tr><td>{esc(n["name"])}</td><td>{esc(n["role"])}</td><td>{esc(n["hostname"])}</td><td>{esc(n["public_ipv4"])}</td>'
                f'<td>{esc(n["release"])}</td><td>{state_word("ok" if self.results().get(n["name"]) else "bad", "Healthy") if n["name"] in self.results() else state_word("", "Not checked")}</td></tr>'
                for n in (nodes[k] for k in sorted(nodes))
            )
            table = ('<div class="tablewrap"><table><thead><tr><th>Name</th><th>Role</th><th>Host</th><th>IPv4</th><th>Release</th><th>State</th></tr></thead><tbody>'
                     + rows + "</tbody></table></div>") if nodes else '<p class="empty">No nodes yet.</p>'
            form = (
                "<h2>Add a node</h2>"
                '<form class="stack" method="post" action="/nodes/add">' + self._csrf_field() +
                '<label>Name<input name="name" required pattern="[a-z0-9-]+" autocomplete="off"></label>'
                '<label>Role<select name="role"><option>control</option><option>dns</option><option>edge</option><option>dns-edge</option><option>monitoring</option></select></label>'
                '<label>Public IPv4<input name="public_ipv4" required autocomplete="off"></label>'
                '<label>SSH port<input name="ssh_port" type="number" min="1" max="65535" value="22" required></label>'
                '<label>SSH user<input name="ssh_user" value="root" required autocomplete="off"></label>'
                '<label>SSH private key<textarea name="ssh_key" rows="4" required autocomplete="off"></textarea><span class="hint">Stored encrypted in the vault. Key authentication only.</span></label>'
                '<button class="primary">Add node</button></form>'
            )
            return 200, self.layout(path, "Nodes", table + form, flash)
        if path == "/security":
            body = ('<p class="empty">Image and vulnerability status needs a signed release manifest. '
                    'Load one on the Upgrade page; this page then lists each node\'s image digests and the Trivy classification for them.</p>')
            return 200, self.layout(path, "Security", body, flash)
        if path == "/upgrade":
            body = (
                '<form class="stack" method="post" action="/upgrade/plan">' + self._csrf_field() +
                '<label>Signed release manifest (JSON)<textarea name="manifest" rows="6" required></textarea></label>'
                '<label>Release public key (PEM)<textarea name="key" rows="4" required></textarea></label>'
                '<button class="primary">Show plan</button></form>'
            )
            return 200, self.layout(path, "Upgrade", body, flash)
        if path == "/history":
            rows = "".join(f'<tr><td>{esc(h["at"])}</td><td>{esc(h["kind"])}</td><td>{esc(h["detail"])}</td></tr>' for h in self.vault.history())
            body = ('<div class="tablewrap"><table><thead><tr><th>When</th><th>Action</th><th>Detail</th></tr></thead><tbody>' + rows + "</tbody></table></div>") if rows else '<p class="empty">No actions recorded yet.</p>'
            return 200, self.layout(path, "History", body, flash)
        return 404, self.layout(path, "Not found", '<p class="empty">There is no such page.</p>')

    def _csrf_field(self) -> str:
        return f'<input type="hidden" name="csrf" value="{esc(self.csrf)}">'

    def _post_form(self, action: str, label: str, *, primary: bool = False) -> str:
        return f'<form method="post" action="{action}">{self._csrf_field()}<button{" class=primary" if primary else ""}>{esc(label)}</button></form>'

    def post(self, path: str, form: dict[str, str]) -> tuple[str, str]:
        """Returns (redirect path, flash message)."""
        if not secrets.compare_digest(form.get("csrf", ""), self.csrf):
            raise ValidationError("The form has expired; reload the page")
        if path == "/check":
            with self.lock, self.vault.session() as work:
                state = FleetState(work).load()
                transport = self.transport_factory(work)
                targets = self._targets(state)
                self.last_checks = run_checks(transport, targets, state["global"]["release"])
            bad = sum(1 for c in self.last_checks if not c.ok)
            self.vault.record("check", f"{bad} failing")
            return "/", f"Check finished: {bad} failing" if bad else "Check finished: every check passed"
        if path == "/nodes/add":
            with self.lock, self.vault.session() as work:
                store = FleetState(work)
                state = store.load()
                name = form["name"]
                payload = {"name": name, "role": form["role"], "public_ipv4": form["public_ipv4"]}
                store.add_node(state, payload)
                SshTransport(work).store_key(Target(name, form["public_ipv4"], int(form["ssh_port"]), form["ssh_user"]), form["ssh_key"])
                ssh = work / "ssh" / "targets.json"
                targets = json.loads(ssh.read_text()) if ssh.exists() else {}
                targets[name] = {"host": form["public_ipv4"], "port": int(form["ssh_port"]), "user": form["ssh_user"]}
                ssh.write_text(json.dumps(targets))
            self.vault.record("add-node", name)
            return "/nodes", f"Added {name}"
        if path == "/upgrade/plan":
            doc = json.loads(form["manifest"])
            target = mf.verify(doc, mf.public_key_from_pem(form["key"]))
            state = self.snapshot()
            plan = actions.plan_upgrade(state["nodes"], None, target)
            return "/upgrade", " | ".join(plan.describe()[:6])
        raise ValidationError("Unknown action")

    def _targets(self, state: dict[str, Any]) -> dict[str, Target]:
        raise NotImplementedError


def _targets_from_vault(web: FleetWeb, state: dict[str, Any]) -> dict[str, Target]:
    with web.vault.session() as work:
        path = work / "ssh" / "targets.json"
        data = json.loads(path.read_text()) if path.exists() else {}
    return {n: Target(n, v["host"], v["port"], v["user"]) for n, v in data.items() if n in state["nodes"]}


FleetWeb._targets = _targets_from_vault  # type: ignore[method-assign]


def make_server(web: FleetWeb, host: str = LOOPBACK, port: int = 8765) -> http.server.HTTPServer:
    require_loopback(host)

    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self, *_a: Any) -> None:
            pass

        def _host_ok(self) -> bool:
            # Rejects DNS-rebinding: only the literal loopback name the server listens on is served.
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
            if length > 64 * 1024:
                return self._send(413, "Too large", "text/plain")
            form = {k: v[0] for k, v in urllib.parse.parse_qs(self.rfile.read(length).decode(), keep_blank_values=True).items()}
            try:
                target, msg = web.post(urllib.parse.urlparse(self.path).path, form)
            except (FleetError, ValueError, KeyError, json.JSONDecodeError) as exc:
                return self._send(400, web.layout("", "Action failed", f'<p class="empty" role="alert">{esc(exc)}</p>'))
            self.send_response(303)
            self.send_header("Location", target + "?" + urllib.parse.urlencode({"msg": msg}))
            self.end_headers()

    class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
        daemon_threads = True

    return Server((host, port), Handler)
