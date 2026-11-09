"""HTML for the hardware, live health, and activity parts of Fleet web. Pure functions: data in, markup out."""
from __future__ import annotations

import html
import json
from typing import Any

from . import inventory as inv

STATE_LABELS = {"online": ("ok", "Online"), "degraded": ("warn", "Containers need attention"), "offline": ("bad", "Unreachable"), "unknown": ("", "Not probed")}


def esc(value: Any) -> str:
    return html.escape(str(value), quote=True)


def state_word(kind: str, label: str) -> str:
    return f'<span class="state {kind}">{esc(label)}</span>'


def uptime(seconds: int | None) -> str:
    if not seconds:
        return "-"
    days, rest = divmod(int(seconds), 86400)
    hours, minutes = divmod(rest, 3600)
    return f"{days}d {hours}h" if days else f"{hours}h {minutes // 60}m"


def memory(mb: int | None) -> str:
    return "-" if not mb else (f"{mb / 1024:.1f} GB" if mb >= 1024 else f"{mb} MB")


def live_state(live: dict[str, Any] | None, check_ok: bool | None) -> str:
    if live:
        return live["state"]
    return "unknown" if check_ok is None else ("online" if check_ok else "offline")


def state_cell(node: str, live: dict[str, Any] | None, check_ok: bool | None) -> str:
    kind, label = STATE_LABELS[live_state(live, check_ok)]
    return f'<span data-live-node="{esc(node)}" data-live-field="state">{state_word(kind, label)}</span>'


def gauge(node: str, field: str, value: float | int | None, *, percent: bool = True, low: float = 70, high: float = 90) -> str:
    """A meter with its number beside it, so the value never depends on color."""
    if value is None:
        return f'<span class="gauge" data-live-node="{esc(node)}" data-live-field="{field}">-</span>'
    shown = f"{value}%" if percent else f"{value}"
    top = 100 if percent else max(4.0, float(value) * 1.5)
    label = {"memory": "Memory in use", "disk": "Fullest disk", "load": "Load per core"}.get(field, field)
    return (f'<span class="gauge" data-live-node="{esc(node)}" data-live-field="{field}"><meter min="0" max="{top}" low="{low}" high="{high}" optimum="0" value="{value}" aria-label="{esc(label)}"></meter>'
            f'<span class="gauge-n">{esc(shown)}</span></span>')


def sparkline(values: list[float | None], label: str, ceiling: float = 100.0) -> str:
    points = [v for v in values if v is not None]
    if len(points) < 2:
        return f'<span class="spark-empty">{esc(label)}: collecting</span>'
    width, height = 140, 30
    step = width / (len(values) - 1)
    coords = " ".join(f"{i * step:.1f},{height - 2 - (min(v, ceiling) / ceiling) * (height - 4):.1f}" for i, v in enumerate(values) if v is not None)
    return f'<svg class="spark" viewBox="0 0 {width} {height}" width="{width}" height="{height}" role="img" aria-label="{esc(label)}, last {len(points)} readings"><polyline points="{coords}" fill="none"/></svg>'


def findings_list(items: list[dict[str, str]], empty: str) -> str:
    if not items:
        return f'<p class="attention">{esc(empty)}</p>'
    return '<ul class="attention">' + "".join(f"<li>{state_word('bad' if i['level'] == 'bad' else 'warn', i['text'])}</li>" for i in items) + "</ul>"


def facts(rows: list[tuple[str, str]]) -> str:
    return '<dl class="facts">' + "".join(f"<div><dt>{esc(k)}</dt><dd>{esc(v) if v not in (None, '') else '-'}</dd></div>" for k, v in rows) + "</dl>"


def hardware_card(i: dict[str, Any]) -> str:
    cpu = f"{i.get('cpu_cores') or '?'} cores" + (f", {i['cpu_model']}" if i.get("cpu_model") else "")
    rows = [("Processor", cpu), ("Architecture", i.get("arch")), ("Memory", memory(i.get("mem_total_mb")) + (f", swap {memory(i['swap_total_mb'])}" if i.get("swap_total_mb") else ", no swap")),
            ("Platform", i.get("virt")), ("Operating system", i.get("os")), ("Kernel", i.get("kernel")),
            ("Docker", i.get("docker") or "not running"), ("Compose", i.get("compose")),
            ("Time sync", {True: "Synchronized", False: "Not synchronized", None: "Unknown"}[i.get("ntp_synced")]),
            ("Global IPv6", "Yes" if i.get("ipv6") else "No"), ("Uptime", uptime(i.get("uptime_s"))), ("Read", i.get("collected_at", ""))]
    return facts(rows)


def disks_and_nics(i: dict[str, Any], table: Any) -> str:
    out = ""
    if i.get("disks"):
        out += "<h3>Disks</h3>" + table(["Use", "Mount", "Size", "Used"], [[esc(d["label"].capitalize()), esc(d["mount"]), f'{d["total_gb"]} GB', gauge("", "", d["used_pct"]).replace(' data-live-node="" data-live-field=""', "")] for d in i["disks"]], numeric={2}, label="Disks")
    if i.get("nics"):
        out += "<h3>Network interfaces</h3>" + table(["Interface", "Speed", "State"], [[esc(n["name"]), f'{n["speed_mbps"]} Mb/s' if n.get("speed_mbps") and n["speed_mbps"] > 0 else "-", esc(n["state"])] for n in i["nics"]], numeric={1}, label="Network interfaces")
    return out


def containers_table(node: str, i: dict[str, Any], table: Any) -> str:
    rows = []
    for c in i.get("containers", []):
        kind = "bad" if inv.container_down(c) else "ok"
        rows.append([esc(c["name"]), state_word(kind, c["status"] or c["state"]), esc(c["image"].rsplit("/", 1)[-1]), f'<a href="/nodes/{esc(node)}/logs?c={esc(c["name"])}">Logs</a>'])
    return table(["Container", "Status", "Image", ""], rows, label="Containers") if rows else '<p class="empty">No containers were reported by this host.</p>'


def activity(entries: list[dict[str, Any]]) -> str:
    if not entries:
        return '<p class="empty">No actions recorded yet. Install or check the fleet and they appear here.</p>'
    items = "".join(
        f'<li><time datetime="{esc(e["at"])}">{esc(e["at"][:16].replace("T", " "))}</time> {state_word(e["kind_state"], e["label"])} <span>{esc(e["summary"])}</span></li>' for e in entries)
    return f'<ul class="activity">{items}</ul>'


def history_entry(raw: dict[str, str]) -> dict[str, Any]:
    """One history row with the parts the screens need. Old rows that are not JSON keep their text."""
    try:
        d = json.loads(raw["detail"])
    except ValueError:
        d = {}
    failed = bool(d.get("failed") or d.get("error"))
    title = d.get("title") or d.get("detail") or raw["detail"][:120]
    summary = f'{d.get("operator", "")}: {title}'.strip(": ") if d else raw["detail"][:160]
    if failed:
        summary += f" (failed: {d.get('failed') or d.get('error')})"
    return {"id": raw["id"], "at": raw["at"], "kind": raw["kind"], "label": raw["kind"].replace("-", " ").capitalize(), "kind_state": "bad" if failed else "ok",
            "summary": summary, "seconds": d.get("seconds"), "steps": d.get("steps") or [], "log": d.get("log") or []}


def history_details(entries: list[dict[str, Any]]) -> str:
    out = []
    for e in entries:
        took = f" · {e['seconds']} s" if e["seconds"] else ""
        steps = ""
        if e["steps"]:
            steps = '<ol class="tasks">' + "".join(f'<li data-state="{esc(s["state"])}"><span class="mark" aria-hidden="true"></span><span class="task-name">{esc(s["label"])}</span><span class="sr">{esc(s["state"])}</span><span class="took">{esc(s["seconds"] or "")}</span></li>' for s in e["steps"]) + "</ol>"
        log = f'<pre class="log" tabindex="0" aria-label="Log of this action">{esc(chr(10).join(e["log"]))}</pre>' if e["log"] else ""
        body = (steps + log) or '<p class="hint">Nothing more was recorded for this action.</p>'
        out.append(f'<details class="entry"><summary><time datetime="{esc(e["at"])}">{esc(e["at"][:16].replace("T", " "))}</time> {state_word(e["kind_state"], e["label"])} <span>{esc(e["summary"])}{esc(took)}</span></summary>{body}</details>')
    return "".join(out)


def summary_strip(totals: dict[str, int], online: int, known: int) -> str:
    cells = [(str(totals["hosts"]), "Hosts read"), (str(totals["cores"]), "CPU cores"), (f"{totals['memory_gb']} GB", "Memory"), (f"{totals['disk_gb']} GB", "System disk"), (f"{online}/{known}" if known else "-", "Online now")]
    return '<div class="kpis">' + "".join(f"<div><b>{esc(v)}</b><span>{esc(k)}</span></div>" for v, k in cells) + "</div>"


def inventory_table(rows_data: list[dict[str, Any]], table: Any) -> str:
    rows = []
    for r in rows_data:
        i = r["inventory"] or {}
        disk = inv.worst_disk(i)
        rows.append([f'<a href="/nodes/{esc(r["name"])}">{esc(r["name"])}</a>', esc(r["role"]), state_cell(r["name"], r["live"], r["check"]),
                     esc(i.get("cpu_cores") or "-"), esc(memory(i.get("mem_total_mb"))), gauge(r["name"], "memory", i.get("mem_used_pct")), gauge(r["name"], "disk", disk["used_pct"] if disk else None),
                     esc(i.get("kernel") or "-"), esc(i.get("docker") or "-"), esc(i.get("virt") or "-"), esc(uptime(i.get("uptime_s")))])
    return table(["Host", "Role", "State", "Cores", "Memory", "Memory used", "Fullest disk", "Kernel", "Docker", "Platform", "Uptime"], rows, numeric={3, 4}, label="Hardware of every host")
