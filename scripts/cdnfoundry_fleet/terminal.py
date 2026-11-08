"""Plain-text tables for the Fleet CLI: the inventory listing and the live watch view."""
from __future__ import annotations

from typing import Any

from . import inventory as inv
from .monitor import summarize

STATE_TEXT = {"online": "online", "degraded": "degraded", "offline": "UNREACHABLE", "unknown": "-"}


def grid(headers: list[str], rows: list[list[str]], right: set[int] | None = None) -> str:
    right = right or set()
    widths = [max(len(str(x)) for x in [h] + [r[i] for r in rows]) for i, h in enumerate(headers)]

    def line(cells: list[str]) -> str:
        return "  ".join(str(c).rjust(widths[i]) if i in right else str(c).ljust(widths[i]) for i, c in enumerate(cells)).rstrip()

    return "\n".join([line(headers), line(["-" * w for w in widths])] + [line(r) for r in rows])


def percent(value: Any) -> str:
    return "-" if value is None else f"{value}%"


def memory(mb: int | None) -> str:
    return "-" if not mb else f"{mb / 1024:.1f}G"


def inventory_table(nodes: dict[str, dict[str, Any]], inventories: dict[str, dict[str, Any]]) -> str:
    rows = []
    for name in sorted(nodes):
        i = inventories.get(name, {})
        disk = inv.worst_disk(i)
        rows.append([name, nodes[name]["role"], str(i.get("cpu_cores") or "-"), memory(i.get("mem_total_mb")), percent(i.get("mem_used_pct")), percent(disk["used_pct"] if disk else None),
                     i.get("kernel") or "-", i.get("docker") or "-", i.get("virt") or "-"])
    return grid(["HOST", "ROLE", "CORES", "MEMORY", "MEM USED", "DISK", "KERNEL", "DOCKER", "PLATFORM"], rows, right={2, 3, 4, 5})


def watch_table(nodes: dict[str, dict[str, Any]], probes: dict[str, dict[str, Any]]) -> str:
    rows = []
    for name in sorted(nodes):
        s = summarize(probes[name]) if name in probes else {"state": "unknown"}
        c = s.get("containers") or {}
        rows.append([name, nodes[name]["role"], STATE_TEXT[s["state"]], "-" if s.get("load") is None else f"{s['load']:.2f}", percent(s.get("memory")), percent(s.get("disk")),
                     f"{c['running']}/{c['total']}" if c else "-", s.get("error", "")[:60]])
    return grid(["HOST", "ROLE", "STATE", "LOAD/CORE", "MEMORY", "DISK", "CONTAINERS", "NOTE"], rows, right={3, 4, 5, 6})
