"""Local fleet web: the Fleet CLI's engine behind server-rendered pages.

Binds to 127.0.0.1 only. It holds no state of its own: every request unlocks the vault, reads or changes fleet state
through the same engine the CLI uses, and seals it again. It never edits CDN state (domains, DNS, pools, TLS, users).
Long actions run in one background job; the job page refreshes itself and needs no JavaScript.
"""
from __future__ import annotations

import getpass
import html
import ipaddress
import http.server
import json
import re
import secrets
import socketserver
import threading
import time
import urllib.parse
from pathlib import Path
from typing import Any, Callable

from . import manifest as mf
from .checks import Check
from .common import FleetError, ValidationError
from .engine import FleetEngine, StandbySetup, ha_action, ControlActions
from . import management as mg
from .render import Renderer
from .security import classify
from .ssh import Target
from . import inventory as inv
from . import web_inventory as wv
from .monitor import LiveHealth
from .progress import Tracker
from .state import FleetState
from .vault import Vault

ASSETS = Path(__file__).parent / "web_assets"
LOOPBACK = "127.0.0.1"
PAGES = [("/", "Overview"), ("/deploy", "Deploy"), ("/dns", "DNS names"), ("/tls", "Certificates"), ("/nodes", "Nodes"), ("/inventory", "Inventory"), ("/security", "Security"), ("/upgrade", "Upgrade"), ("/config", "Config"),
         ("/rollback", "Rollback"), ("/backups", "Backups"), ("/ha", "HA"), ("/secrets", "Secrets"), ("/history", "History")]
NAV_GROUPS = [
    ("Set up", ["/", "/deploy", "/dns", "/tls", "/nodes"]),
    ("Operate", ["/inventory", "/upgrade", "/config", "/backups", "/ha", "/security"]),
    ("Protect", ["/rollback", "/secrets", "/history"]),
]
MAX_KEPT_JOBS = 20
HISTORY_LOG_LINES = 200
ROTATABLE = ["metrics-token", "telemetry-token", "edge-proxy-secret", "log-auth-token"]

HELP: dict[str, tuple[str, str]] = {
    "/": ("The state of your fleet at a glance.", "Follow the roadmap from top to bottom. Run the check any time to see which host needs attention."),
    "/deploy": ("Get your hosts installed in two steps.", "Step 1: connect the hosts, set the release, and enter locations. Step 2: review exactly what will happen, then confirm. Nothing reaches a server before you confirm."),
    "/dns": ("The DNS names you must create before the install.", "Create these records at the DNS provider of your operator domain, and the glue records at the registrar of your platform domain. Certificates for the control, telemetry, and Grafana names are requested when the control node starts, so the names must already point to it."),
    "/tls": ("Certificates for the control, Grafana, and telemetry names.", "By default Caddy asks Let's Encrypt for them when the control node starts. That fails if the names do not point at the host or ports 80 and 443 are closed. Upload your own certificate to skip that."),
    "/nodes": ("Your hosts and how Fleet logs in to them.", "Add a host here, or open one to change its SSH login or install it alone. Every host must accept the Fleet SSH key shown on the Deploy page."),
    "/inventory": ("The hardware and system of every host.", "Fleet reads each host over SSH: processor, memory, disks, kernel, Docker, time sync, and containers. Findings list what needs attention. Reading is read-only."),
    "/security": ("Known vulnerabilities in the running release.", "Read this after an upgrade. A fix arrives by upgrading to a newer signed release."),
    "/upgrade": ("Move the whole fleet to a new signed release.", "Trust your CI signing key once, paste the signed release manifest, read the plan, then upgrade. Hosts update one at a time and roll back if a check fails."),
    "/config": ("Push changed configuration to hosts.", "Use after you change hosts or settings. Fleet shows which bundles changed and swaps them in one host at a time."),
    "/rollback": ("Return the whole fleet to the previous release.", "Use only if an upgrade went wrong. It is blocked when the release holds an irreversible database change; restore from backup then."),
    "/backups": ("Back up the fleet and restore a control node.", "Back up before every upgrade. Restoring needs a snapshot ID and typing RESTORE with it."),
    "/ha": ("Add and manage the standby control node.", "A standby lets the control plane survive the loss of one host. First point the DNS names and addresses, prepare the host, then deploy the standby. Add it after the first deploy works."),
    "/secrets": ("Rotate fleet secrets.", "Rotate one secret at a time. Hosts that use it are updated for you."),
    "/history": ("Everything Fleet has done, who did it, and the result.", "Use it to find out what changed before a problem started."),
}


def error_help(message: str) -> str:
    if "No SSH access stored" in message:
        return '<p>Fleet has no SSH login saved for that host. Open <a href="/deploy">Deploy</a> and save a login (user and SSH port), then add the Fleet key to the host.</p>'
    if "permission denied" in message.lower():
        return '<p>The host did not accept the Fleet key. The <a href="/deploy#connect">Deploy page</a> shows the key and the exact command to add it.</p>'
    return ""


def explain_ssh(message: str, node: str, login: str) -> tuple[str, str]:
    """Turn ssh's own words into what is wrong and what to do about it."""
    low = message.lower()
    if "permission denied" in low or "publickey" in low:
        return ("The host did not accept the Fleet key.", f"The key is not in the authorized keys of {login}. Add it with the command in step 1, then test again. If root login is disabled on that host, log in as another user with passwordless sudo and set that user on the host's page.")
    if "refused" in low:
        return ("Nothing is listening for SSH at that address and port.", "Check the port on the host's page, that sshd is running, and that the host firewall allows it.")
    if "timed out" in low or "timeout" in low:
        return ("The host did not answer in time.", "Check the IPv4 address, the cloud firewall or security group, and that the host is powered on.")
    if "could not resolve" in low or "no route" in low or "unreachable" in low:
        return ("This computer cannot reach that address.", "Check the IPv4 address on the Nodes page and your own network or VPN.")
    if "could not read a host key" in low:
        return ("Fleet could not read the host's SSH identity.", "Check the IPv4 address and port, and that sshd is running on the host.")
    if "host key" in low or "identification" in low:
        return ("The host key does not match the one Fleet saved.", "The server may have been reinstalled. If you expected that, a host key change must be reviewed before you continue; do not ignore it if you did not.")
    if "no ssh access stored" in low:
        return ("Fleet has no SSH login saved for this host yet.", "Open Deploy and save a login (user and SSH port) for it, or set it on the host's page.")
    return ("The connection failed.", message[-200:])


def require_loopback(host: str) -> str:
    if host != LOOPBACK:
        raise ValidationError(f"The fleet web binds to {LOOPBACK} only, not {host!r}")
    return host


def esc(value: Any) -> str:
    return html.escape(str(value), quote=True)


def state_word(kind: str, label: str) -> str:
    return f'<span class="state {kind}">{esc(label)}</span>'


def table(headers: list[str], rows: list[list[str]], numeric: set[int] | None = None, label: str = "Table") -> str:
    numeric = numeric or set()
    head = "".join(f'<th{" class=num" if i in numeric else ""}>{esc(h)}</th>' for i, h in enumerate(headers))
    body = "".join("<tr>" + "".join(f'<td{" class=num" if i in numeric else ""}>{c}</td>' for i, c in enumerate(r)) + "</tr>" for r in rows)
    # A table that scrolls sideways on a phone must be reachable by keyboard.
    return f'<div class="tablewrap" role="region" tabindex="0" aria-label="{esc(label)}"><table><thead><tr>{head}</tr></thead><tbody>{body}</tbody></table></div>'


TOPOLOGY_DOT = {"online": "var(--success-dot)", "degraded": "var(--warning-dot)", "offline": "var(--danger-dot)", "unknown": "var(--subtle)"}


def topology_svg(state: dict[str, Any], results: dict[str, bool], live: dict[str, str] | None = None, detail: dict[str, str] | None = None) -> str:
    """Hosts by role, each a link to its page, with a dot for its live state (or the last check when nothing has been probed)."""
    nodes = state["nodes"]
    live, detail = live or {}, detail or {}
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
            kind = live.get(name) or ("unknown" if name not in results else ("online" if results[name] else "offline"))
            word = wv.STATE_LABELS[kind][1]
            parts.append(f'<a href="/nodes/{esc(name)}" aria-label="{esc(name)}, {esc(word)}"><title>{esc(name)}: {esc(word)}. {esc(detail.get(name, ""))}</title>'
                         f'<rect class="box" x="{x}" y="{y}" width="170" height="36" rx="6"/><circle cx="{x + 14}" cy="{y + 18}" r="5" fill="{TOPOLOGY_DOT[kind]}" data-live-node="{esc(name)}" data-live-field="dot"/>'
                         f'<text x="{x + 28}" y="{y + 22}">{esc(name[:20])}</text></a>')
        height = max(height, 40 + len(names) * 46)
    control = next((p for n, p in placed.items() if nodes[n]["role"] == "control"), None)
    for name, (x, y) in placed.items():
        if control and nodes[name]["role"] in {"edge", "dns", "dns-edge"}:
            parts.insert(0, f'<line x1="{control[0] + 170}" y1="{control[1] + 18}" x2="{x}" y2="{y + 18}"/>')
    label = "Fleet topology: " + (", ".join(f"{n} ({v['role']})" for n, v in sorted(nodes.items())) or "no nodes")
    return f'<svg role="group" aria-label="{esc(label)}" viewBox="0 0 770 {height}" width="770" height="{height}">{"".join(parts)}</svg>'


class Job(Tracker):
    def __init__(self, job_id: str, title: str) -> None:
        super().__init__()
        self.id, self.title = job_id, title


class FleetWeb:
    def __init__(self, vault: Vault, repo_root: Path, output_dir: Path | None = None, *, transport_factory: Callable[[Path], Any] | None = None) -> None:
        self.vault, self.repo_root = vault, repo_root
        self.output_dir = output_dir or vault.path.parent / "bundles"
        self.transport_factory = transport_factory
        self.csrf = secrets.token_urlsafe(24)
        self.last_checks: list[Check] = []
        self.jobs: dict[str, Job] = {}
        self.seen_jobs: set[str] = set()
        self.write_lock = threading.Lock()
        self.live = LiveHealth(lambda: self.read(lambda e: e.probe_nodes()), self.write_lock.locked, interval=0)
        self.operator = getpass.getuser()
        self.pending_manifest: dict[str, str] = {}
        self.pending_deploy: dict[str, dict[str, Any]] = {}
        self.connections: dict[str, tuple[bool, str]] = {}
        self.dns_results: dict[str, tuple[bool, str]] = {}
        self.ha_plan: dict[str, str] = {}
        self.ha_results: dict[str, tuple[bool, str]] = {}

    # -- engine access -----------------------------------------------------------------------------------
    def _engine(self, work: Path, log: Callable[[str], None] = lambda _m: None, progress: Tracker | None = None) -> FleetEngine:
        transport = self.transport_factory(work) if self.transport_factory else None
        return FleetEngine(work, self.repo_root, self.output_dir, transport=transport, say=log, progress=progress)

    def read(self, fn: Callable[[FleetEngine], Any]) -> Any:
        with self.vault.read_session() as work:
            return fn(self._engine(work))

    def run_job(self, title: str, fn: Callable[[FleetEngine], Any], kind: str) -> Job:
        if not self.write_lock.acquire(blocking=False):
            raise ValidationError("Another action is running; wait for it to finish")
        job = Job(secrets.token_hex(6), title)
        self.jobs[job.id] = job
        for old in [j for j in self.jobs.values() if j.state != "running"][:-MAX_KEPT_JOBS]:
            del self.jobs[old.id]

        def work_fn() -> None:
            started = time.time()
            detail: dict[str, Any] = {"operator": self.operator, "title": title}
            try:
                with self.vault.session() as work:
                    engine = self._engine(work, job.line, job)
                    result = fn(engine)
                    ok = getattr(result, "ok", True)
                    if hasattr(result, "ok"):
                        detail |= {"failed": result.failed, "detail": result.detail, "rolled_back": result.rolled_back, "steps_done": len(result.done)}
                    detail["events"] = engine.events
                    text = "" if result is None or hasattr(result, "ok") else str(result)[:2000]
                    if hasattr(result, "ok") and not ok:
                        text = f"Stopped at {result.failed}: {result.detail}"[:2000]
                    job.finish("ok" if ok else "failed", text)
            except Exception as exc:  # the job page reports it; nothing is sealed
                detail["error"] = str(exc)[:1000]
                job.finish("failed", detail["error"])
            finally:
                detail["seconds"] = round(time.time() - started, 1)
                detail["log"] = job.lines[-HISTORY_LOG_LINES:]
                detail["steps"] = [{"label": st["label"], "state": st["state"], "seconds": st["seconds"]} for st in job.steps][:200]
                try:
                    self.vault.record(kind, json.dumps(detail, sort_keys=True))
                finally:
                    self.write_lock.release()

        threading.Thread(target=work_fn, daemon=True).start()
        return job

    # -- pages -------------------------------------------------------------------------------------------
    def layout(self, path: str, title: str, body: str, flash: str = "", refresh: bool = False) -> str:
        titles = dict(PAGES)
        nav = "".join(
            f'<div class="nav-group"><span class="nav-label">{esc(label)}</span>'
            + "".join(f'<a href="{p}"{" aria-current=page" if p == path else ""}>{esc(titles[p])}</a>' for p in paths) + "</div>"
            for label, paths in NAV_GROUPS)
        notice = f'<p class="flash" role="status">{esc(flash)}</p>' if flash else ""
        notice = self._job_banner(path) + notice
        meta = '<noscript><meta http-equiv="refresh" content="2"></noscript>' if refresh else ""
        what, how = HELP.get(path, ("", ""))
        lede = f'<p class="lede">{esc(what)}</p>' if what else ""
        guide = f'<details class="help"><summary>About this page</summary><p>{esc(how)}</p></details>' if how else ""
        return (
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            f'{meta}<title>{esc(title)} - CDNFoundry Fleet</title><link rel="stylesheet" href="/assets/fleet.css"><script src="/assets/fleet.js" defer></script></head>'
            f'<body><div class="shell"><aside class="side"><div class="brand">CDNFoundry Fleet</div><nav aria-label="Fleet">{nav}</nav></aside>'
            f'<main><header class="page-head"><h1>{esc(title)}</h1>{lede}</header>{notice}{guide}{body}</main></div></body></html>'
        )

    # -- live view and inventory --------------------------------------------------------------------------
    def start_monitor(self, interval: int) -> None:
        """Probe the hosts on a timer so the screens stay current. 0 keeps probing manual."""
        self.live.stop()
        self.live = LiveHealth(lambda: self.read(lambda e: e.probe_nodes()), self.write_lock.locked, interval=interval)
        self.live.start()

    def hardware(self) -> dict[str, dict[str, Any]]:
        """The newest inventory of each host: what the live view saw, else what the last recorded read found."""
        stored = self.read(lambda e: e.inventory())
        return {**stored, **self.live.inventory}

    def live_json(self) -> str:
        snap = self.live.snapshot()
        running = next((j for j in self.jobs.values() if j.state == "running"), None)
        snap["job"] = {"id": running.id, "title": running.title, **running.snapshot()} if running else None
        return json.dumps(snap)

    def logs_text(self, node: str, container: str) -> str:
        return self.read(lambda e: e.container_log(node, container))

    def _topology(self, state: dict[str, Any]) -> str:
        results = self.results()
        live = {n: wv.live_state(self.live.latest.get(n), results.get(n)) for n in state["nodes"]}
        return topology_svg(state, results, live, self.live_detail(state))

    def live_detail(self, state: dict[str, Any]) -> dict[str, str]:
        hw = self.live.inventory
        return {n: " · ".join(x for x in (state["nodes"][n]["role"], state["nodes"][n]["public_ipv4"], f"{hw[n]['cpu_cores']} cores" if n in hw and hw[n].get("cpu_cores") else "", wv.memory(hw[n].get("mem_total_mb")) if n in hw and hw[n].get("mem_total_mb") else "") if x) for n in state["nodes"]}

    def _node_hardware(self, name: str, node: dict[str, Any]) -> str:
        i = self.hardware().get(name, {})
        live = self.live.latest.get(name)
        actions = ('<div class="actions">' + self.action_form(f"/nodes/{esc(name)}/check", "Check this host", primary=True, inline=True)
                   + self.action_form(f"/nodes/{esc(name)}/inventory", "Read hardware again", inline=True) + "</div>")
        if not i:
            note = (f'<p class="empty">{esc(live["error"])}</p>' if live and live["state"] == "offline" else '<p class="empty">Fleet has not read this host yet. Read the hardware to see its processor, memory, disks, and containers.</p>')
            return "<h2>Hardware and system</h2>" + note + actions
        history = self.live.history.get(name, [])
        trend = (f'<div class="trends"><div><span>Memory in use</span>{wv.sparkline([h["memory"] for h in history], "Memory in use")}</div>'
                 f'<div><span>Fullest disk</span>{wv.sparkline([h["disk"] for h in history], "Fullest disk")}</div>'
                 f'<div><span>Load per core</span>{wv.sparkline([h["load"] for h in history], "Load per core", 2.0)}</div></div>') if history else ""
        problems = inv.findings(node, i)
        return ("<h2>Hardware and system</h2>" + wv.hardware_card(i) + trend + "<h3>Needs attention</h3>" + wv.findings_list(problems, "Nothing needs attention on this host.")
                + wv.disks_and_nics(i, table) + "<h3>Containers</h3>" + wv.containers_table(name, i, table) + actions)

    def _inventory_page(self, nodes: dict[str, Any]) -> str:
        hardware = self.hardware()
        if not nodes:
            return '<p class="empty">No nodes yet. Add the control node on the Nodes page.</p>'
        results = self.results()
        data = [{"name": n, "role": nodes[n]["role"], "inventory": hardware.get(n), "live": self.live.latest.get(n), "check": results.get(n)} for n in sorted(nodes)]
        online = sum(1 for d in data if wv.live_state(d["live"], d["check"]) in {"online", "degraded"})
        found = [{"level": f["level"], "text": f"{n}: {f['text']}"} for n in sorted(nodes) for f in inv.findings(nodes[n], hardware.get(n, {}))] + inv.fleet_findings(hardware)
        head = (wv.summary_strip(inv.totals(hardware), online, len(nodes)) if hardware else "")
        refresh = '<div class="actions">' + self.action_form("/inventory/refresh", "Read hardware of every host", primary=not hardware, inline=True) + "</div>"
        if not hardware:
            return '<p class="empty">Fleet has not read any host yet. Reading is read-only and takes a few seconds per host.</p>' + refresh
        return head + "<h2>Needs attention</h2>" + wv.findings_list(found, "Nothing needs attention across the fleet.") + "<h2>Hosts</h2>" + wv.inventory_table(data, table) + refresh

    def _logs_page(self, path: str, nodes: dict[str, Any], query: dict[str, list[str]]) -> tuple[int, str]:
        name = path.split("/")[2]
        if name not in nodes:
            return 404, self.layout(path, "Not found", '<p class="empty">There is no such node.</p>')
        known = (self.hardware().get(name) or {}).get("containers", [])
        container = (query.get("c") or [""])[0]
        back = f'<p><a href="/nodes/{esc(name)}">Back to {esc(name)}</a></p>'
        if not container:
            links = "".join(f'<li><a href="/nodes/{esc(name)}/logs?c={esc(c["name"])}">{esc(c["name"])}</a> {esc(c["status"])}</li>' for c in known)
            return 200, self.layout(path, f"Logs of {name}", (f"<ul>{links}</ul>" if links else '<p class="empty">Fleet has not seen any container on this host. Read the hardware first.</p>') + back)
        try:
            text = self.logs_text(name, container)
        except (FleetError, KeyError) as exc:
            return 200, self.layout(path, f"Logs of {container}", f'<p class="empty" role="alert">{esc(exc)}</p>' + back)
        tools = ('<div class="log-tools"><label class="inline-field">Filter the log<input type="search" data-log-filter autocomplete="off"></label>'
                 '<label class="check"><input type="checkbox" data-log-poll>Follow (every 5 seconds)</label></div>')
        return 200, self.layout(path, f"Logs of {container}", f'<div class="job" data-state="logs" data-logs-url="/api/logs?node={esc(name)}&amp;c={esc(container)}">{tools}'
                                f'<pre class="log" id="job-log" tabindex="0" aria-label="Container log">{esc(text) or "The container has written nothing yet."}</pre></div>'
                                f'<p class="hint">Showing the last 300 lines. Values that follow names like token, secret, or password are hidden.</p>' + back)

    def _job_banner(self, path: str) -> str:
        """Every page says when an action is running or finished while the operator was away, with a way back to it."""
        for job in sorted(self.jobs.values(), key=lambda j: j.started, reverse=True):
            if path == f"/jobs/{job.id}":
                self.seen_jobs.add(job.id)
                continue
            if path == "/deploy" and job.title.startswith(("Deploy", "Continue")):
                continue
            if job.state == "running":
                done, total = job.counts()
                progress = f", {done} of {total} steps" if total else ""
                return (f'<p class="banner" role="status" data-testid="running-banner">{state_word("warn", "Running")} <b>{esc(job.title)}</b>{progress}. '
                        f'<a href="/jobs/{esc(job.id)}">Open the live view</a></p>')
            if job.id not in self.seen_jobs and time.time() - (job.finished or 0) < 86400:
                word = state_word("ok", "Finished") if job.state == "ok" else state_word("bad", "Failed")
                return (f'<p class="banner" role="status" data-testid="finished-banner">{word} <b>{esc(job.title)}</b> while you were away. '
                        f'<a href="/jobs/{esc(job.id)}">Open the result</a></p>')
        return ""

    STATE_WORDS = {"pending": "Waiting", "running": "Running", "ok": "Done", "failed": "Failed", "skipped": "Already done"}

    def _task_list(self, steps: list[dict[str, Any]]) -> str:
        """Every step of the action, grouped by host, ticked as it finishes."""
        groups: dict[str, list[dict[str, Any]]] = {}
        for st in steps:
            groups.setdefault(st["node"] or "Steps", []).append(st)

        def item(st: dict[str, Any]) -> str:
            name = st["label"].split(": ", 1)[-1] if st["node"] else st["label"]
            took = f'{st["seconds"]}s' if st.get("seconds") else ""
            return (f'<li data-step="{esc(st["id"])}" data-state="{st["state"]}"><span class="mark" aria-hidden="true"></span>'
                    f'<span class="task-name">{esc(name)}</span><span class="sr">{self.STATE_WORDS[st["state"]]}</span><span class="took">{took}</span></li>')

        return "".join(f'<section class="task-group" data-node="{esc(n)}"><h3>{esc(n)}</h3><ol class="tasks">{"".join(item(st) for st in group)}</ol></section>' for n, group in groups.items())

    def _job_view(self, job: Job) -> str:
        steps, lines, after = job.view()
        snap = job.snapshot()
        running = job.state == "running"
        badge = {"running": state_word("warn", "Running"), "ok": state_word("ok", "Finished"), "failed": state_word("bad", "Failed")}[job.state]
        total, done = len(steps), snap["done"]
        meter = (f'<progress max="{total}" value="{done}" aria-label="Steps done"></progress>' if total
                 else '<progress aria-label="Working, the number of steps is not known"></progress>' if running else "")
        cancel = ""
        if running and total:
            cancel = (f'<form class="inline" method="post" action="/jobs/{esc(job.id)}/cancel">{self.csrf_field()}'
                      '<button data-testid="job-cancel-button">Stop after this step</button></form>')
        result = f'<p class="job-result" role="status">{esc(job.result)}</p>' if job.result else ""
        return (
            f'<div class="job" data-job="{esc(job.id)}" data-state="{job.state}" data-after="{after}" data-title="{esc(job.title)}">'
            f'<div class="job-head"><span class="job-badge" data-testid="job-state">{badge}</span>'
            f'<span class="job-count" data-testid="job-count">{f"{done} of {total} steps" if total else ""}</span>'
            f'<span class="job-time" data-since="{round(job.started)}" data-end="{round(job.finished) if job.finished else ""}">{snap["elapsed"]}s</span>{cancel}</div>'
            f'{meter}{result}'
            + (f'<div class="tasks-wrap" data-testid="job-tasks">{self._task_list(steps)}</div>' if total or running else "")
            + '<div class="log-tools"><label class="inline-field">Filter the log<input type="search" data-log-filter autocomplete="off"></label>'
              '<label class="check"><input type="checkbox" data-log-follow checked>Follow the end</label>'
              f'<a class="btn" href="/jobs/{esc(job.id)}/log.txt" download="fleet-{esc(job.id)}.log">Download log</a></div>'
            f'<pre class="log" id="job-log" tabindex="0" aria-label="Action log" data-testid="job-log">{esc(chr(10).join(lines) or "Waiting for the first step...")}</pre>'
            '<p><a href="/">Back to overview</a> <a href="/history">History</a></p></div>'
        )

    def job_events(self, job_id: str, after: int) -> tuple[list[dict[str, Any]], str] | None:
        """For the event stream: the next events of a job and its state, or None when the job is unknown."""
        job = self.jobs.get(job_id)
        if job is None:
            return None
        return job.wait(after), job.state

    def cancel_job(self, job_id: str) -> None:
        job = self.jobs.get(job_id)
        if job is None or job.state != "running":
            raise ValidationError("That action is not running")
        job.request_cancel()

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

    def local_ca(self) -> str | None:
        return self.read(lambda e: e.local_ca())

    def installed(self) -> set[str]:
        """Hosts Fleet has installed, from the sealed state (shared with the CLI)."""
        return self.read(lambda e: e.installed())

    def _dns_section(self, state: dict[str, Any]) -> str:
        records, glue = mg.dns_records(state)
        own = set(self.read(lambda e: e.management_certificates()))
        operator, platform = state["global"]["operator_domain"], state["global"]["platform_domain"]

        def rows(items: list[dict[str, Any]]) -> list[list[str]]:
            out = []
            for r in items:
                ok, why = self.dns_results.get(r["name"], (None, ""))
                status = state_word("", "Not checked") if ok is None else (state_word("ok", "Correct") if ok else state_word("bad", "Not yet") + f"<br><span class=hint>{esc(why)}</span>")
                if r.get("key") and r["key"] in own:
                    status += "<br>" + state_word("ok", "Own certificate: this name need not be correct to deploy")
                kinds = " and ".join(("A " + a) if ":" not in a else ("AAAA " + a) for a in r["ips"])
                out.append([f"<b>{esc(r['name'])}</b>", esc(kinds), f"{esc(r['node'])}", esc(r["use"]), status])
            return out
        head = ["Name", "Record", "Host", "What it is for", "Status"]
        body = (f"<h3>1. At the DNS provider of <b>{esc(operator)}</b></h3><p>Create these records. They must point to the hosts below before the control node starts, or its certificates cannot be issued.</p>"
                + (table(head, rows(records), label="Operator domain records") if records else '<p class="empty">Add a control node to see these names.</p>')
                + f"<h3>2. At the registrar of <b>{esc(platform)}</b> (nameserver glue)</h3>"
                "<p>Create these nameserver hosts with their addresses (glue), then make them the nameservers of the platform domain. Customer domains are delegated to the names the admin panel assigns later.</p>"
                + (table(head, rows(glue), label="Platform nameserver glue") if glue else '<p class="empty">Add a DNS or combined DNS + edge node to see these names.</p>'))
        if len({g["node"] for g in glue}) < 2 and glue:
            body += '<p class="hint">You have one DNS host, so ns1 and ns2 use the same address. Add a second DNS host for resilience.</p>'
        body += ("<h3>Check</h3><p>Fleet asks the public resolver 1.1.1.1, the way the certificate authority will see your names, and compares the answer. A new record can take a few minutes to appear.</p>"
                 + self.action_form("/dns/check", "Check DNS now", primary=True))
        return body

    def _ha_setup(self, state: dict[str, Any]) -> str:
        """Adding a standby control node, as a road: point the addresses, prepare the host, then deploy it."""
        nodes = state["nodes"]
        controls = [n for n in sorted(nodes) if nodes[n]["role"] == "control"]
        if len(controls) != 1:
            return '<p class="empty">Add exactly one control node and deploy it first. A standby needs a working active control node.</p>'
        if controls[0] not in self.read(lambda e: e.installed()):
            return f'<p class="empty">Deploy <b>{esc(controls[0])}</b> first (see <a href="/deploy">Deploy</a>). The standby copies it, so it must already be running.</p>'
        active = nodes[controls[0]]
        plan = self.ha_plan
        name = plan.get("name") or self.read(lambda e: StandbySetup(e).next_name(state))
        form = (f'<label>Standby name<input name="name" value="{esc(name)}" required pattern="[a-z0-9][a-z0-9-]*" autocomplete="off"></label>'
                f'<label>Standby public IPv4<input name="host" value="{esc(plan.get("host", ""))}" required autocomplete="off" placeholder="203.0.113.11"></label>'
                f'<label>SSH port<input name="ssh_port" type="number" min="1" max="65535" value="{esc(plan.get("ssh_port", "22"))}" required></label>'
                f'<label>Standby private address<input name="replication_address" value="{esc(plan.get("replication_address", ""))}" autocomplete="off"><span class="hint">Optional. Fleet reads it from the host when there is exactly one private address.</span></label>'
                f'<label>Active private address<input name="active_replication_address" value="{esc(plan.get("active_replication_address", ""))}" autocomplete="off"><span class="hint">Optional, same rule.</span></label>')
        out = ["<p>A standby is a second control node that copies the first and takes over when you promote it. DNS and HTTP keep serving while control is down; the standby restores the panel and API.</p>",
               "<h2>Step 1: point the addresses</h2>",
               f"<p>Create or change these records <b>before</b> you add the standby. The two client names point at the <b>active</b> node ({esc(controls[0])}), not the standby. "
               "Set their TTL to <b>60 seconds</b> now so that moving them after a failure is quick. The standby gets its own name.</p>",
               self.action_form("/ha/check", "Save and check DNS with 1.1.1.1", primary=not plan, fields=form)]
        if plan:
            records = mg.ha_records(state, plan["name"], plan["host"])
            rows = []
            for r in records:
                ok, why = self.ha_results.get(r["name"], (None, ""))
                status = state_word("", "Not checked") if ok is None else (state_word("ok", "Correct") if ok else state_word("bad", "Not yet") + f"<br><span class=hint>{esc(why)}</span>")
                rows.append([f"<b>{esc(r['name'])}</b>", esc(" and ".join(("AAAA " if ":" in a else "A ") + a for a in r["ips"])), f"{r['ttl']} s", esc(r["use"]), status])
            out.append(table(["Name", "Record", "TTL", "What it is for", "Status"], rows, label="HA records"))
        out += ["<h2>Step 2: prepare the standby host</h2><ol>",
                f"<li>A server with a fixed public IPv4 address. Fleet installs Docker and everything else.</li>",
                "<li>A <b>private network or VPN</b> between the two control nodes. Database and cache replication use it and Fleet refuses public addresses. Never replicate across the internet.</li>",
                "<li>Allow TCP <b>5432</b>, <b>5433</b>, and <b>6379</b> between the two private addresses only.</li>",
                "<li>Add the Fleet SSH key to the standby (the command is on the <a href=\"/deploy#connect\">Deploy page</a>).</li>",
                "<li>The standby serves the same control certificate as the active node. Fleet copies it: your own or local certificate from the "
                "<a href=\"/tls\">Certificates</a> page, or the one Caddy obtained. It must be valid for at least 14 days.</li></ol>"]
        ready = bool(plan) and all(self.ha_results.get(r["name"], (False, ""))[0] for r in mg.ha_records(state, plan["name"], plan["host"]))
        out.append("<h2>Step 3: deploy the standby</h2>")
        if not plan:
            out.append('<p class="empty">Complete step 1 first.</p>')
        else:
            if not ready:
                out.append('<p class="empty">1.1.1.1 does not yet see every record above. Fix them and check again. Without DNS you can still continue, but a failover will be slow and manual.</p>')
            hidden = "".join(f'<input type="hidden" name="{k}" value="{esc(plan.get(k, ""))}">' for k in ("name", "host", "ssh_port", "replication_address", "active_replication_address"))
            skip = "" if ready else '<label><span><input type="checkbox" name="skip_dns" value="1"> Continue without correct DNS</span></label>'
            out.append(f"<p>Fleet installs the prerequisites on <b>{esc(plan['host'])}</b>, copies the secrets and the control certificate, sets up replication, and starts the standby. "
                       "Edges learn both control addresses from signed configuration.</p>"
                       + self.action_form("/ha/add-standby", f"Deploy standby {plan['name']}", primary=ready, confirm="ADD STANDBY", fields=hidden + skip))
        return "".join(out)

    def _tls_section(self, state: dict[str, Any]) -> str:
        own = self.read(lambda e: e.management_certificates())
        email = state["global"].get("acme_email") or "not set"
        rows = []
        for name in mg.MANAGEMENT_NAMES:
            host = mg.hostname(state, name)
            if name in own and "error" in own[name]:
                what = state_word("bad", "Own certificate is invalid") + f"<br><span class=hint>{esc(own[name]['error'])}</span>"
            elif name in own:
                c = own[name]
                label = "Local certificate (browsers warn)" if c.get("kind") == "local" else "Own certificate"
                what = state_word("ok" if c["days_left"] > mg.EXPIRY_WARNING_DAYS and c.get("kind") != "local" else "warn", label) + f" expires {esc(c['not_after'])} ({c['days_left']} days)"
            else:
                what = state_word("", "Automatic (Let's Encrypt)") + "<br><span class=hint>Needs the name to point at the host and ports 80 and 443 open.</span>"
            remove = self.action_form("/tls/remove", "Use automatic", fields=f'<input type="hidden" name="name" value="{name}">') if name in own else ""
            rows.append([f"<b>{esc(host)}</b>", what, remove])
        boxes = "".join(f'<label><span><input type="checkbox" name="name:{n}" value="1" checked> {esc(mg.hostname(state, n))}</span></label>' for n in mg.MANAGEMENT_NAMES)
        fields = (boxes + '<label>Certificate chain (PEM, your certificate first)<textarea name="certificate" rows="6" required spellcheck="false" placeholder="-----BEGIN CERTIFICATE-----"></textarea></label>'
                  '<label>Private key (PEM, no password)<textarea name="key" rows="6" required spellcheck="false" placeholder="-----BEGIN PRIVATE KEY-----"></textarea></label>')
        return (f"<p>Let's Encrypt contact email: <b>{esc(email)}</b>. Automatic certificates are requested by Caddy when the node starts.</p>"
                + table(["Name", "Certificate", ""], rows, label="Management certificates")
                + "<h2>Use your own certificate</h2><p>Choose the names it covers. One certificate can cover several names (a SAN or wildcard certificate). "
                  "Fleet checks that the key matches, every chosen name is covered, and it has not expired. The key is kept in the encrypted vault and is never shown again. "
                  "If hosts are already installed, deploy the change from the <a href=\"/config\">Config</a> page.</p>"
                + self.action_form("/tls/set", "Save certificate", primary=True, fields=fields)
                + "<h2>No certificate yet? Generate a local one</h2><p>Fleet makes its own certificate authority and signs a certificate for the chosen names, so the control panel and Grafana "
                  "open over HTTPS even when Let's Encrypt cannot reach them. Deploy does this by itself for names that are not ready. Browsers show a warning until you trust the local CA"
                  + (' (<a href="/tls/ca.crt">download the local CA</a>)' if self.read(lambda e: e.local_ca()) else "") + ", or you replace it with a real certificate later.</p>"
                + self.action_form("/tls/local", "Generate local certificate", fields=boxes))

    def _dns_ok(self, state: dict[str, Any]) -> bool:
        records, glue = mg.dns_records(state)
        names = [r["name"] for r in records]
        own = set(self.read(lambda e: e.management_certificates()))
        return bool(names) and all(self.dns_results.get(r["name"], (False, ""))[0] or r["key"] in own for r in records)

    def _roadmap(self, state: dict[str, Any], current: dict[str, Any] | None) -> str:
        nodes, done = state["nodes"], self.installed()
        have = self.read(lambda e: e.targets(state))
        connected = bool(nodes) and all(self.connections.get(n, (False, ""))[0] for n in nodes)
        checked = bool(self.last_checks) and all(c.ok for c in self.last_checks)
        items = [
            ("Plan created", True, "/nodes", "Your hosts and domains are saved in the encrypted vault."),
            ("SSH login saved for every host", all(n in have for n in nodes), "/deploy#connect", "Fleet needs a user, address, and port for each host."),
            ("Hosts accept the Fleet key", connected, "/deploy#connect", "Add the key to each host, then run the connection test."),
            ("DNS names point to your hosts (or you supply your own certificates)", self._dns_ok(state), "/dns", "Caddy gets its certificates only if 1.1.1.1 sees the names pointing at the hosts."),
            ("Signed release set", current is not None, "/deploy#release", "Fleet installs only signed releases."),
            ("Hosts installed", bool(nodes) and all(n in done for n in nodes), "/deploy#review", "Review the plan, then confirm."),
            ("Health check passed", checked, "/", "Run the check on the Overview page."),
        ]
        first_open = next((i for i, it in enumerate(items) if not it[1]), None)
        rows = []
        for i, (label, ok, link, why) in enumerate(items):
            mark = state_word("ok", "Done") if ok else (state_word("warn", "Next") if i == first_open else state_word("", "Later"))
            rows.append(f'<li>{mark} <a href="{link}">{esc(label)}</a><span class="hint"> {esc(why)}</span></li>')
        return '<h2>Your road to a running CDN</h2><ol class="roadmap">' + "".join(rows) + "</ol>"

    def _key_help(self, state: dict[str, Any], key: str, have: dict[str, Any]) -> str:
        first = next(iter(sorted(state["nodes"])), None)
        t = have.get(first)
        sample = (f"-p {t.port} {t.user}@{t.host}" if t and t.port != 22 else f"{t.user}@{t.host}") if t else "root@SERVER_IP"
        command = f"mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo '{key}' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
        return (
            "<p>Fleet logs in to every host with one SSH key, never a password. Put this public key on each host once.</p>"
            f'<pre class="log" tabindex="0" aria-label="Fleet SSH public key">{esc(key)}</pre>'
            '<details class="help"><summary>How do I add the key to a host?</summary><ol>'
            "<li>Open a terminal on the computer where you run Fleet, or the web console of your hosting provider.</li>"
            f"<li>Log in to the host. From your computer: <code>ssh {esc(sample)}</code>. Use the password or key your provider gave you.</li>"
            f"<li>On the host, paste and run this single command:</li></ol><pre class=\"log\" tabindex=\"0\" aria-label=\"Command to run on the host\">{esc(command)}</pre>"
            "<ol start=\"4\"><li>Repeat on every host, then press <b>Test connections</b> below.</li></ol>"
            "<p>Short version from your own computer, per host: <code>ssh root@IP \"&lt;command above&gt;\"</code>. "
            "If the provider disabled root login, use a user with passwordless sudo and set that user on the host's page. "
            "The key gives full control of the host, so keep the Fleet vault passphrase safe.</p></details>"
        )

    def _resume_panel(self, order: list[str], done: set[str]) -> str:
        """Leaving the page never stops an install. Coming back shows it running, or offers to continue where it stopped."""
        running = next((j for j in self.jobs.values() if j.state == "running" and j.title.startswith(("Deploy", "Continue"))), None)
        if running:
            d, t = running.counts()
            return (f'<div class="resume" data-testid="deploy-running"><h2>Installation in progress</h2><p>{d} of {t} steps are done. You can leave this page; the installation keeps going.</p>'
                    f'<a class="btn primary" href="/jobs/{esc(running.id)}">Open the live view</a></div>')
        session, steps_done = self.read(lambda e: (e.deploy_session(), e.steps_done_in_journal()))
        left = [n for n in order if n not in done]
        if not session or not left:
            return ""
        hosts = ", ".join(left[:6]) + ("..." if len(left) > 6 else "")
        return ('<div class="resume" data-testid="deploy-resume"><h2>Continue the installation</h2>'
                f'<p>An earlier installation did not finish. {len(done)} host(s) are installed; {len(left)} remain ({esc(hosts)}). '
                f'{steps_done} step(s) of the current host are already done and are skipped. Continue picks up at the first step that is not done.</p>'
                + self.action_form("/deploy/continue", f"Continue installing {len(left)} host(s)", primary=True) + "</div>")

    def _deploy_page(self, state: dict[str, Any], current: dict[str, Any] | None) -> str:
        """The road to a first install. Steps 1 and 2 change nothing on a host except the connection test, which only runs `true`."""
        nodes, done = state["nodes"], self.installed()
        order = [n for group in Renderer.start_order(state) for n in group["nodes"]]
        order = [n for i, n in enumerate(order) if n not in order[:i]]
        pending = [n for n in order if n not in done]
        have = self.read(lambda e: e.targets(state))
        out = [self._resume_panel(order, done), self._roadmap(state, current), '<h2 id="connect">Step 1 of 2: prepare</h2><h3>a. Connect your hosts</h3>', self._key_help(state, self.fleet_public_key(), have)]
        rows = []
        for n in order:
            login = f"{have[n].user}@{have[n].host}:{have[n].port}" if n in have else None
            ok, why = self.connections.get(n, (None, ""))
            if login is None:
                conn = state_word("bad", "No SSH login saved")
            elif ok is None:
                conn = state_word("", "Not tested")
            elif ok:
                conn = state_word("ok", "Connected")
            else:
                title, advice = explain_ssh(why, n, login)
                conn = state_word("bad", "Failed") + f"<br><b>{esc(title)}</b><br><span class=hint>{esc(advice)}</span>"
            rows.append([f'<a href="/nodes/{esc(n)}">{esc(n)}</a>', esc(nodes[n]["role"]), esc(login or nodes[n]["public_ipv4"]),
                         state_word("ok", "Installed") if n in done else state_word("warn", "Not installed"), conn])
        out.append(table(["Host", "Role", "Login", "Install", "Connection"], rows, label="Hosts"))
        if any(n not in have for n in order):
            out.append(self.action_form("/deploy/ssh-defaults", "Save this login for hosts without one", primary=True, fields=(
                '<label>SSH user<input name="ssh_user" value="root" required autocomplete="off"></label>'
                '<label>SSH port<input name="ssh_port" type="number" min="1" max="65535" value="22" required><span class="hint">Change it if your servers do not use 22. You can set a different port per host on its page.</span></label>')))
        out.append(self.action_form("/deploy/test", "Test connections", primary=all(n in have for n in order)))
        out.append('<h3 id="dns">b. DNS names and certificates</h3><p>Caddy gets Let\'s Encrypt certificates for <b>control</b>, <b>grafana</b>, and <b>telemetry</b> when the control node starts. '
                   'That works only if 1.1.1.1 sees those names pointing at the host. See <a href="/dns">DNS names</a> for the exact records and a check, or '
                   'put your own certificates on the <a href="/tls">Certificates</a> page to skip it.</p>')
        out.append('<h3 id="release">c. Release</h3>')
        if current is None:
            target = self.read(lambda e: e.target_release())
            out.append(f"<p>Fleet installs release <b>{esc(target)}</b> (the release you chose at setup). It looks up the published images and signs the release for you. Nothing to paste.</p>"
                       + self.action_form("/upgrade/first", f"Use release {target}", primary=True)
                       + self._manual_release('/upgrade/first', "/deploy"))
            return "".join(out) + '<p class="empty">Step 2 opens when the release is set.</p>'
        out.append(f"<p>{state_word('ok', 'Done')} Release to install: <b>{esc(current['release'])}</b></p>")
        out.append('<h3 id="review">d. Review and confirm</h3>')
        if not pending:
            return "".join(out) + '<p class="attention">Every host is installed. Use <a href="/config">Config</a> to deploy changed configuration or <a href="/upgrade">Upgrade</a> for a new release.</p>'
        fields = ""
        for n in pending:
            if nodes[n]["role"] in {"edge", "dns-edge"} and "EDGE_ID" not in nodes[n].get("extra_env", {}):
                fields += (f'<fieldset><legend>{esc(n)} location (places it in Geo-DNS)</legend>'
                           f'<label>Country code<input name="country:{esc(n)}" maxlength="2" required pattern="[A-Za-z]{{2}}" placeholder="DE" autocomplete="off"></label>'
                           f'<label>Continent code<input name="continent:{esc(n)}" maxlength="2" required pattern="[A-Za-z]{{2}}" placeholder="EU" autocomplete="off"></label></fieldset>')
        ready = all(self.connections.get(n, (False, ""))[0] for n in pending)
        if not ready:
            out.append('<p class="empty">Run <b>Test connections</b> above and get every host to Connected. Then you can review.</p>')
        out.append(self.action_form("/deploy/review", f"Step 2: review the deployment of {len(pending)} host(s)", primary=ready, fields=fields))
        return "".join(out)

    def csrf_field(self) -> str:
        return f'<input type="hidden" name="csrf" value="{esc(self.csrf)}">'

    def action_form(self, action: str, label: str, *, primary: bool = False, fields: str = "", confirm: str | None = None, inline: bool = False) -> str:
        typed = (f'<label>Type <b>{esc(confirm)}</b> to confirm<input name="confirm" required autocomplete="off" pattern="{esc(confirm)}"></label>' if confirm else "")
        return (f'<form class="{"inline" if inline else "stack"}" method="post" action="{action}">{self.csrf_field()}{fields}{typed}'
                f'<button{" class=primary" if primary else ""}>{esc(label)}</button></form>')

    def page(self, path: str, query: dict[str, list[str]]) -> tuple[int, str]:
        flash = (query.get("msg") or [""])[0]
        if path.startswith("/jobs/"):
            job = self.jobs.get(path.split("/")[2])
            if job is None:
                return 404, self.layout(path, "Not found", '<p class="empty">That action is not known. Fleet keeps the latest actions only; older ones are in <a href="/history">History</a>.</p>')
            return 200, self.layout(path, job.title, self._job_view(job), refresh=job.state == "running")
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
            hardware = self.hardware()
            attention = [f"{c.node}: {c.name}" + (f" ({c.detail})" if c.detail else "") for c in self.last_checks if not c.ok]
            attention += [f"{n}: unreachable ({v.get('error', '')})" for n, v in sorted(self.live.latest.items()) if v["state"] == "offline"]
            attention += [f"{n}: {v['containers']['unhealthy']} container(s) not healthy" for n, v in sorted(self.live.latest.items()) if v["state"] == "degraded"]
            found = [(n, f) for n in sorted(nodes) for f in inv.findings(nodes[n], hardware.get(n, {}))]
            attention += [f"{n}: {f['text']}" for n, f in found]
            attention += [f["text"] for f in inv.fleet_findings(hardware)]
            if not self.last_checks and not self.live.updated and not hardware:
                needs = '<p class="empty">Nothing has been checked in this session. Run the check, or probe the hosts, to see the state of every node.</p>'
            elif attention:
                needs = '<ul class="attention">' + "".join(f"<li>{state_word('bad', a)}</li>" for a in attention) + "</ul>"
            else:
                needs = '<p class="attention">Nothing needs attention.</p>'
            live_line = (f'<p class="live-line" data-live-status data-updated="{round(self.live.updated) if self.live.updated else ""}" data-interval="{self.live.interval}">'
                         + (f"Probed {max(0, round(time.time() - self.live.updated))} seconds ago" + (f", every {self.live.interval} seconds." if self.live.interval else ".") if self.live.updated else
                            ("Probing every " + str(self.live.interval) + " seconds. The first reading is on its way." if self.live.interval else "Live probing is off. Probe now reads every host once."))
                         + "</p>")
            probe = self.action_form("/live/refresh", "Probe now", inline=True)
            recent = [wv.history_entry(h) for h in self.vault.history(8)]
            body = (self._roadmap(state, current) + kpis + '<h2>Topology</h2><div class="topology" role="region" tabindex="0" aria-label="Topology diagram">' + self._topology(state) + "</div>" + '<div class="live-row">' + live_line + probe + "</div>"
                    + "<h2>Needs attention</h2>" + needs + '<div class="actions">' + self.action_form("/check", "Run check of the whole stack", primary=True, inline=True) + "</div>"
                    + '<h2>Recent activity</h2>' + wv.activity(recent) + '<p><a href="/history">All history</a></p>')
            return 200, self.layout(path, "Overview", body, flash)
        if path == "/tls":
            return 200, self.layout(path, "Certificates", self._tls_section(state), flash)
        if path == "/dns":
            return 200, self.layout(path, "DNS names", self._dns_section(state), flash)
        if path == "/deploy":
            return 200, self.layout(path, "Deploy", self._deploy_page(state, current), flash)
        if path == "/nodes":
            hardware = self.hardware()
            rows = []
            for n in (nodes[k] for k in sorted(nodes)):
                i, disk = hardware.get(n["name"], {}), inv.worst_disk(hardware.get(n["name"], {}))
                rows.append([esc(n["name"]), esc(n["role"]), esc(n["hostname"]), esc(n["public_ipv4"]), esc(n["release"]),
                             wv.state_cell(n["name"], self.live.latest.get(n["name"]), self.results().get(n["name"])),
                             esc(i.get("cpu_cores") or "-"), wv.gauge(n["name"], "memory", i.get("mem_used_pct")), wv.gauge(n["name"], "disk", disk["used_pct"] if disk else None),
                             f'<a href="/nodes/{esc(n["name"])}">Open</a>'])
            listing = (table(["Name", "Role", "Host", "IPv4", "Release", "State", "Cores", "Memory", "Disk", ""], rows, numeric={6}, label="Nodes") if rows else '<p class="empty">No nodes yet.</p>')
            fields = (
                '<label>Name<input name="name" required pattern="[a-z0-9-]+" autocomplete="off"></label>'
                '<label>Role<select name="role"><option>control</option><option>dns</option><option>edge</option><option>dns-edge</option><option>monitoring</option></select></label>'
                '<label>Public IPv4<input name="public_ipv4" required autocomplete="off"></label>'
                '<label>SSH port<input name="ssh_port" type="number" min="1" max="65535" value="22" required></label>'
                '<label>SSH user<input name="ssh_user" value="root" required autocomplete="off"></label>'
            )
            key_block = ("<h2>Fleet SSH public key</h2><p>One key reaches every node. Add this line to <b>/root/.ssh/authorized_keys</b> on each host "
                         "<b>before</b> you add it here. Fleet logs in with key authentication only.</p>"
                         f'<pre class="log" tabindex="0" aria-label="Fleet SSH public key">{esc(self.fleet_public_key())}</pre>')
            return 200, self.layout(path, "Nodes", listing + key_block + "<h2>Add a node</h2>" + self.action_form("/nodes/add", "Add node and show plan", primary=True, fields=fields), flash)
        if path.startswith("/nodes/") and path.endswith("/logs"):
            return self._logs_page(path, nodes, query)
        if path.startswith("/nodes/"):
            name = path.split("/", 2)[2]
            if name not in nodes:
                return 404, self.layout(path, "Not found", '<p class="empty">There is no such node.</p>')
            node = nodes[name]
            have = self.read(lambda e: e.targets(state))
            login = have.get(name)
            ssh_form = self.action_form(f"/nodes/{esc(name)}/ssh", "Save SSH login", fields=(
                f'<label>SSH user<input name="ssh_user" value="{esc(login.user if login else "root")}" required autocomplete="off"><span class="hint">root, or a user with passwordless sudo.</span></label>'
                f'<label>Address<input name="ssh_host" value="{esc(login.host if login else node["public_ipv4"])}" required autocomplete="off"></label>'
                f'<label>SSH port<input name="ssh_port" type="number" min="1" max="65535" value="{login.port if login else 22}" required></label>'))
            facts = table(["Role", "Host", "IPv4", "Release"], label="Node facts", rows=[[esc(node["role"]), esc(node["hostname"]), esc(node["public_ipv4"]), esc(node["release"])]])
            if login is None:
                return 200, self.layout(path, name, facts + "<h2>SSH login</h2><p>Fleet has no login saved for this host. Save one, then add the Fleet key on the <a href=\"/deploy#connect\">Deploy page</a>.</p>" + ssh_form, flash)
            plan = self.read(lambda e: e.bootstrap_plan(name))
            enroll = any(s.kind == "enroll" for s in plan.steps)
            geo = ('<label>Country code<input name="country" value="US" maxlength="2" required><span class="hint">Places this PoP in Geo-DNS.</span></label>'
                   '<label>Continent code<input name="continent" value="NA" maxlength="2" required></label>') if enroll else ""
            body = (facts + self._node_hardware(name, node) + "<h2>SSH login</h2>" + f"<p>Fleet logs in as <b>{esc(login.user)}@{esc(login.host)}:{login.port}</b>. The Fleet key must be on this host (see the <a href=\"/deploy#connect\">Deploy page</a>).</p>" + ssh_form
                    + "<h2>Install only this host</h2><p>Most people use the <a href=\"/deploy\">Deploy page</a> to install everything in order.</p><ol>" + "".join(f"<li>{esc(s.label())}</li>" for s in plan.steps) + "</ol>"
                    + self.action_form(f"/nodes/{esc(name)}/bootstrap", "Install and start this node", primary=True, fields=geo))
            return 200, self.layout(path, name, body, flash)
        if path == "/inventory":
            return 200, self.layout(path, "Inventory", self._inventory_page(nodes), flash)
        if path == "/security":
            rows = classify(current, self.repo_root)
            if current is None:
                body = '<p class="empty">No release is recorded yet. Upgrade to a signed manifest first; this page then lists the known vulnerabilities in its images.</p>'
            elif not rows:
                body = f'<p class="attention">Release {esc(current["release"])} has no known vulnerabilities in its scan.</p>'
            else:
                kind = {"fixed": "ok", "classified": "warn", "no-fix": "warn", "fix-upstream": "bad", "expired": "bad"}
                body = table(["Severity", "Finding", "Image", "State"], label="Vulnerabilities", rows=[[esc(r["severity"]), esc(r["id"]), esc(r["image"]), state_word(kind[r["state"]], r["detail"])] for r in rows])
            return 200, self.layout(path, "Security", body, flash)
        if path == "/upgrade":
            target = self.read(lambda e: e.target_release())
            if current is None:
                head = f"<p>No release is recorded yet. Fleet will install <b>{esc(target)}</b>.</p>"
                form_html = self.action_form("/upgrade/first", f"Use release {target} for the first install", primary=True)
            elif current["release"] == target:
                head = f"<p>Running release: <b>{esc(current['release'])}</b>. Your checkout is on the same version.</p>"
                form_html = '<p class="hint">To upgrade, check out the newer release first: <code>git fetch --tags && git checkout vX.Y.Z</code>, then reload this page.</p>'
            else:
                head = f"<p>Running release: <b>{esc(current['release'])}</b>. Your checkout is on <b>{esc(target)}</b>.</p>"
                form_html = self.action_form("/upgrade/plan", f"Show the plan for {target}", primary=True)
            return 200, self.layout(path, "Upgrade", head + form_html + self._manual_release("/upgrade/plan" if current else "/upgrade/first", "/upgrade"), flash)
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
                return 200, self.layout(path, "HA", self._ha_setup(state), flash)
            else:
                info = table(["Active", "Standby"], label="Control nodes", rows=[[esc(section.get("active", "?")), esc(section.get("standby", "?"))]])
                actions_html = "".join(self.action_form(f"/ha/{a}", label, confirm=a.upper()) for a, label in
                                       [("switchover", "Planned switchover"), ("demote", "Demote the active node"), ("promote", "Promote the standby"),
                                        ("rejoin", "Rejoin the former active node"), ("remove-standby", "Remove the standby"), ("refresh-ingress", "Refresh the standby's control certificate")])
            return 200, self.layout(path, "HA", info + actions_html, flash)
        if path == "/secrets":
            options = "".join(f"<option>{esc(n)}</option>" for n in ROTATABLE)
            body = ("<p>Rotates a fleet secret, then deploys it to the nodes that use it. Application keys, the backup password, and the database passwords rotate only through the "
                    "guided CLI procedures.</p>"
                    + self.action_form("/secrets/rotate", "Rotate and deploy", primary=True, fields=f'<label>Secret<select name="secret">{options}</select></label>', confirm="ROTATE"))
            return 200, self.layout(path, "Secrets", body, flash)
        if path == "/history":
            entries = [wv.history_entry(h) for h in self.vault.history()]
            return 200, self.layout(path, "History", wv.history_details(entries) if entries else '<p class="empty">No actions recorded yet.</p>', flash)
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

        if path == "/live/refresh":
            threading.Thread(target=self.live.refresh, daemon=True).start()
            return "/", "Probing every host now. The numbers update in a few seconds."
        if path == "/inventory/refresh":
            return job("Read the hardware of every host", lambda e: f"{len(e.collect_inventory())} host(s) read", "inventory")
        if path.startswith("/nodes/") and path.endswith("/inventory"):
            node = path.split("/")[2]
            return job(f"Read the hardware of {node}", lambda e: f"{len(e.collect_inventory([node]))} host(s) read", "inventory")
        if path.startswith("/nodes/") and path.endswith("/check"):
            node = path.split("/")[2]

            def check_one(e: FleetEngine) -> str:
                found = e.doctor([node])
                self.last_checks = [c for c in self.last_checks if c.node != node] + found
                bad = sum(1 for c in found if not c.ok)
                return f"{len(found)} checks, {bad} failing"
            return job(f"Check {node}", check_one, "check")
        if path.startswith("/jobs/") and path.endswith("/cancel"):
            job_id = path.split("/")[2]
            self.cancel_job(job_id)
            return f"/jobs/{job_id}", ""
        if path == "/check":
            def check(e: FleetEngine) -> str:
                self.last_checks = e.doctor()
                bad = sum(1 for c in self.last_checks if not c.ok)
                return f"{len(self.last_checks)} checks, {bad} failing"
            return job("Check of the whole stack", check, "check")
        if path == "/tls/set":
            names = [n for n in mg.MANAGEMENT_NAMES if form.get(f"name:{n}")]
            if not names:
                raise ValidationError("Choose at least one name")
            return self._sync("management-tls", lambda e: e.set_management_certificate(names, form["certificate"], form["key"]), "/tls",
                              f"Certificate saved for {', '.join(names)}. It is used the next time the bundle is deployed.")
        if path == "/tls/local":
            names = [n for n in mg.MANAGEMENT_NAMES if form.get(f"name:{n}")]
            if not names:
                raise ValidationError("Choose at least one name")
            return self._sync("management-tls", lambda e: e.generate_local_certificates(names), "/tls", f"Local certificate generated for {', '.join(names)}. Download the local CA to trust it.")
        if path == "/tls/remove":
            return self._sync("management-tls", lambda e: e.remove_management_certificate(form["name"]), "/tls", f"{form['name']} will use an automatic certificate again.")
        if path == "/dns/check":
            def check_dns(e: FleetEngine) -> str:
                self.dns_results = e.check_dns()
                for name, (ok, why) in sorted(self.dns_results.items()):
                    e.say(f"{name}: {'ok' if ok else 'NOT READY'}: {why}")
                bad = [n for n, (ok, _) in self.dns_results.items() if not ok]
                return "Every name points to the right host." if not bad else f"{len(bad)} name(s) are not correct yet: {', '.join(sorted(bad))}"
            return job("Check DNS names with 1.1.1.1", check_dns, "dns-check")
        if path == "/deploy/ssh-defaults":
            user, port = form.get("ssh_user", "root").strip() or "root", int(form.get("ssh_port", "22"))
            return self._sync("ssh-defaults", lambda e: e.ensure_targets(user=user, port=port), "/deploy", f"Saved {user} on port {port} for the hosts that had no login. Add the Fleet key to them, then test.")
        if path == "/deploy/test":
            def test(e: FleetEngine) -> str:
                state = e.state()
                for name in sorted(state["nodes"]):
                    try:
                        e.test_connection(name)
                        self.connections[name] = (True, "")
                        e.say(f"{name}: connected")
                    except FleetError as exc:
                        self.connections[name] = (False, str(exc))
                        e.say(f"{name}: {explain_ssh(str(exc), name, name)[0]}")
                bad = [n for n in state["nodes"] if not self.connections[n][0]]
                return "All hosts are connected. Go back to Deploy to continue." if not bad else f"{len(bad)} host(s) need attention: {', '.join(sorted(bad))}. The Deploy page says what to fix."
            return job("Test connections", test, "ssh-test")
        if path.startswith("/nodes/") and path.endswith("/ssh"):
            from .ssh import Target, save_target

            name = path.split("/")[2]
            target = Target(name, form["ssh_host"].strip(), int(form["ssh_port"]), form["ssh_user"].strip())
            self.connections.pop(name, None)
            return self._sync("node-ssh", lambda e: save_target(e.work, target), f"/nodes/{name}", f"SSH login for {name} saved. Test it on the Deploy page.")
        if path == "/deploy/review":
            state, current = self.read(lambda e: (e.state(), e.release_manifest()))
            if current is None:
                raise ValidationError("Set a signed release first")
            done = self.installed()
            order = [n for group in Renderer.start_order(state) for n in group["nodes"]]
            pending = [n for i, n in enumerate(order) if n not in order[:i] and n not in done]
            if not pending:
                raise ValidationError("Every host is already installed")
            bad = [n for n in pending if not self.connections.get(n, (False, ""))[0]]
            if bad:
                raise ValidationError(f"Test the connection to {', '.join(bad)} first. Fleet must be able to log in to a host before it can install it.")
            waiting = self.read(lambda e: mg.unready(e.state(), e.work, self.dns_results))
            geo = {n: (form.get(f"country:{n}", "").upper(), form.get(f"continent:{n}", "").upper()) for n in pending}
            plans = self.read(lambda e: {n: e.bootstrap_plan(n) for n in pending})
            token = secrets.token_urlsafe(12)
            self.pending_deploy[token] = {"nodes": pending, "geo": geo, "release": current["release"]}
            sections = "".join(f"<h3>{i}. {esc(n)} ({esc(state['nodes'][n]['role'])}, {esc(state['nodes'][n]['public_ipv4'])})</h3><ol>"
                               + "".join(f"<li>{esc(st.label())}</li>" for st in plans[n].steps) + "</ol>" for i, n in enumerate(pending, 1))
            local_note = ("<h3>Certificates</h3><p>1.1.1.1 does not yet see these names pointing at their host" + ("" if self.dns_results else " (DNS has not been checked)")
                           + ", so Let's Encrypt could not issue for them. Fleet checks again just before deploying; any name still not ready gets a <b>local certificate</b> so the page still opens over HTTPS "
                           "(browsers warn until you trust the local CA on the <a href=\"/tls\">Certificates</a> page):</p><ul>"
                           + "".join(f"<li>{esc(reason)}</li>" for _k, reason in waiting) + "</ul>") if waiting else ""
            body = (f"<h2>Step 2 of 2: confirm</h2><p>Fleet will install release <b>{esc(current['release'])}</b> on {len(pending)} host(s), in this order, "
                    "and stop at the first failure. Nothing has been sent to any host yet.</p>" + local_note + sections
                    + self.action_form("/deploy/run", "Deploy to servers", primary=True, confirm="DEPLOY", fields=f'<input type="hidden" name="token" value="{esc(token)}">')
                    + '<p><a href="/deploy">Go back and change something</a></p>')
            raise _Render(self.layout("/deploy", "Review and confirm", body))
        if path == "/deploy/run":
            need("DEPLOY")
            plan = self.pending_deploy.pop(form.get("token", ""), None)
            if plan is None:
                raise ValidationError("The review has expired; review the deployment again")

            def deploy(e: FleetEngine) -> Any:
                geo = {n: tuple(v) for n, v in plan["geo"].items()}
                e.remember_deploy(geo, plan["release"])
                outcome = e.deploy(geo)
                if outcome is None:
                    e.forget_deploy()
                return outcome if outcome is not None else f"Installed {', '.join(plan['nodes'])}"
            return job("Deploy the fleet", deploy, "deploy")
        if path == "/deploy/continue":
            def resume(e: FleetEngine) -> Any:
                session = e.deploy_session()
                if session is None:
                    raise ValidationError("There is no interrupted installation to continue")
                outcome = e.deploy({n: tuple(v) for n, v in session["geo"].items()})
                if outcome is None:
                    e.forget_deploy()
                return outcome if outcome is not None else "Installation finished"
            return job("Continue the installation", resume, "deploy")
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
            def install(e: FleetEngine) -> Any:
                return e.bootstrap(name, country=form.get("country"), continent=form.get("continent"))
            return job(f"Install {name}", install, "bootstrap")
        if path == "/upgrade/trust":
            return self._sync("trust-release-key", lambda e: e.trust_release_key(form["key"]), "/deploy" if form.get("next") == "/deploy" else "/upgrade", "Release key trusted")
        if path == "/upgrade/plan":
            document = self._release_document(form)
            plan, manifest, diff = self.read(lambda e: e.upgrade_plan(document))
            token = secrets.token_urlsafe(12)
            self.pending_manifest[token] = json.dumps(document)
            lines = [f"image {n}: {o or 'new'} -> {w or 'removed'}" for n, (o, w) in diff.images.items()] + [f"config {c} changes" for c in diff.configs]
            body = (f"<p>Release <b>{esc(manifest['release'])}</b>, profile <b>{esc(manifest['profile'])}</b>; irreversible migration: <b>{'yes' if diff.migrations else 'no'}</b>.</p>"
                    "<h2>Changes</h2>" + ("<ul>" + "".join(f"<li>{esc(l)}</li>" for l in lines) + "</ul>" if lines else '<p class="empty">Nothing changes between the running release and the target.</p>')
                    + "<h2>Plan</h2><ol>" + "".join(f"<li>{esc(s.label())}</li>" for s in plan.steps) + "</ol>"
                    + "".join(f"<p class=hint>Unchanged, not restarted: {esc(n)}</p>" for n in plan.skipped)
                    + (self.action_form("/upgrade/apply", "Upgrade the fleet", primary=True, fields=f'<input type="hidden" name="token" value="{esc(token)}">') if plan.steps else ""))
            raise _Render(self.layout("/upgrade", "Upgrade plan", body))
        if path == "/upgrade/first":
            document = self._release_document(form)
            return self._sync("set-release", lambda e: e.set_release(document), "/deploy", f"Release {document['manifest']['release']} set. Review the deployment below.")
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
        if path == "/ha/check":
            plan = {k: form.get(k, "").strip() for k in ("name", "host", "ssh_port", "replication_address", "active_replication_address")}
            if not re.fullmatch(r"[a-z0-9][a-z0-9-]{0,40}", plan["name"]):
                raise ValidationError("The standby name may use lowercase letters, digits, and dashes only")
            try:
                ipaddress.ip_address(plan["host"])
            except ValueError as exc:
                raise ValidationError("Enter the standby's public IPv4 address, for example 203.0.113.11") from exc
            if not (plan["ssh_port"].isdigit() and 1 <= int(plan["ssh_port"]) <= 65535):
                raise ValidationError("The SSH port must be a number from 1 to 65535")
            self.ha_plan = plan
            state = self.read(lambda e: e.state())
            records = mg.ha_records(state, plan["name"], plan["host"])

            def check_ha(e: FleetEngine) -> str:
                for r in records:
                    self.ha_results[r["name"]] = mg.check_name(r["name"], r["ips"])
                    e.say(f"{r['name']}: {'ok' if self.ha_results[r['name']][0] else 'NOT READY'}: {self.ha_results[r['name']][1]}")
                bad = [r["name"] for r in records if not self.ha_results[r["name"]][0]]
                return "Every HA name points to the right address. Continue with step 3 on the HA page." if not bad else f"Not correct yet: {', '.join(bad)}"
            return job("Check HA DNS names with 1.1.1.1", check_ha, "ha-dns-check")
        if path.startswith("/ha/"):
            action = path.split("/", 2)[2]
            if action == "add-standby":
                need("ADD STANDBY")
                plan = {k: form.get(k, "").strip() for k in ("name", "host", "ssh_port", "replication_address", "active_replication_address")}
                if not plan["host"]:
                    raise ValidationError("Enter the standby's public address in step 1")
                if not form.get("skip_dns"):
                    state = self.read(lambda e: e.state())
                    bad = [r["name"] for r in mg.ha_records(state, plan["name"], plan["host"]) if not self.ha_results.get(r["name"], (False, ""))[0]]
                    if bad:
                        raise ValidationError(f"Point these names first (1.1.1.1 does not see them yet): {', '.join(bad)}. Run the DNS check in step 1, or choose to continue without DNS.")
                options = {"host": plan["host"], "name": plan["name"] or None, "ssh_port": int(plan["ssh_port"] or 22),
                           "replication_address": plan["replication_address"] or None, "active_replication_address": plan["active_replication_address"] or None}
                return job("Add standby", lambda e: json.dumps(ha_action(e, "add-standby", **options)), "ha")
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

    def _manual_release(self, action: str, back: str) -> str:
        """For operators who sign releases themselves: pin their key, then paste the signed manifest. Hidden by default."""
        return ('<details class="help"><summary>I sign releases myself</summary>'
                "<p>Paste your release public key (the whole block, with its BEGIN and END lines) to pin it, then paste the signed manifest. Fleet then accepts only releases signed with your key.</p>"
                + self.action_form("/upgrade/trust", "Pin this key", fields=f'<label>Release public key (PEM)<textarea name="key" rows="4" required placeholder="-----BEGIN PUBLIC KEY-----"></textarea></label><input type="hidden" name="next" value="{back}">')
                + self.action_form(action, "Use this manifest", fields='<label>Signed release manifest (JSON)<textarea name="manifest" rows="6" required></textarea></label>')
                + "</details>")

    def _release_document(self, form: dict[str, str]) -> dict[str, Any]:
        """The pasted manifest, or the one Fleet builds and signs itself for the release the checkout is on."""
        if form.get("manifest", "").strip():
            return json.loads(form["manifest"])
        if not self.write_lock.acquire(blocking=False):
            raise ValidationError("Another action is running; wait for it to finish")
        try:
            with self.vault.session() as work:
                return self._engine(work).automatic_release()
        finally:
            self.write_lock.release()

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
            self.send_header("Content-Security-Policy", "default-src 'none'; script-src 'self'; connect-src 'self'; style-src 'self'; form-action 'self'; img-src 'self'; frame-ancestors 'none'")
            self.end_headers()
            self.wfile.write(data)

        def _stream(self, inner: Any, job_id: str, query: dict[str, list[str]]) -> None:
            """Server-sent events: every event of one action, from the number the page already shows."""
            try:
                after = max(0, int((query.get("after") or ["0"])[0]))
            except ValueError:
                after = 0
            self.send_response(200)
            self.send_header("Content-Type", "text/event-stream")
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Accel-Buffering", "no")
            self.end_headers()
            try:
                while True:
                    found = inner.job_events(job_id, after)
                    if found is None:
                        return
                    events, state = found
                    if not events:
                        if state != "running":
                            return
                        self.wfile.write(b": keepalive\n\n")
                    for event in events:
                        self.wfile.write(f"id: {event['n']}\ndata: {json.dumps(event)}\n\n".encode())
                        after = event["n"] + 1
                    self.wfile.flush()
                    if events and events[-1]["type"] == "done":
                        return
            except (BrokenPipeError, ConnectionResetError):
                return

        def do_GET(self) -> None:
            if not self._host_ok():
                return self._send(403, "Forbidden", "text/plain")
            url = urllib.parse.urlparse(self.path)
            if url.path == "/assets/fleet.css":
                return self._send(200, (ASSETS / "fleet.css").read_text(), "text/css")
            if url.path == "/assets/fleet.js":
                return self._send(200, (ASSETS / "fleet.js").read_text(), "text/javascript")
            parts = url.path.strip("/").split("/")
            inner = getattr(web, "web", web)
            if url.path == "/api/live" and hasattr(inner, "live_json"):
                return self._send(200, inner.live_json(), "application/json")
            if url.path == "/api/logs" and hasattr(inner, "logs_text"):
                query = urllib.parse.parse_qs(url.query)
                try:
                    return self._send(200, inner.logs_text((query.get("node") or [""])[0], (query.get("c") or [""])[0]), "text/plain; charset=utf-8")
                except FleetError as exc:
                    return self._send(400, str(exc), "text/plain")
            if url.path.startswith("/api/"):
                return self._send(404, "Not available", "text/plain")
            if len(parts) == 3 and parts[0] == "jobs" and parts[2] in {"events", "log.txt"} and hasattr(inner, "jobs"):
                job = inner.jobs.get(parts[1])
                if job is None:
                    return self._send(404, "Unknown action", "text/plain")
                if parts[2] == "log.txt":
                    return self._send(200, "\n".join(job.lines) + "\n", "text/plain; charset=utf-8")
                return self._stream(inner, parts[1], urllib.parse.parse_qs(url.query))
            if url.path == "/tls/ca.crt":
                inner = getattr(web, "web", web)
                pem = inner.local_ca() if inner is not None and hasattr(inner, "local_ca") else None
                return self._send(200, pem, "application/x-pem-file") if pem else self._send(404, "No local CA has been generated", "text/plain")
            try:
                code, body = web.page(url.path, urllib.parse.parse_qs(url.query))
            except FleetError as exc:
                code, body = 500, web.layout(url.path, "Something needs attention", f'<p class="empty" role="alert">{esc(exc)}</p>{error_help(str(exc))}')
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
                return self._send(400, web.layout("", "Action failed", f'<p class="empty" role="alert">{esc(exc)}</p>{error_help(str(exc))}<p><a href="/deploy">Back to Deploy</a></p>'))
            self.send_response(303)
            self.send_header("Location", target + (("?" + urllib.parse.urlencode({"msg": msg})) if msg else ""))
            self.end_headers()

    class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
        daemon_threads = True

    return Server((host, port), Handler)
