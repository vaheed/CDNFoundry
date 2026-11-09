"""Hardware, system, and container inventory of a host, read over the same SSH transport Fleet already uses.

One read-only POSIX shell script prints `key=value` lines. The parser keeps only the fields it knows, with
bounds, so a strange host can never put unexpected data into Fleet web or the sealed state. Findings are plain
hygiene checks (full disks, no time sync, mixed kernels); they are not hardware requirements of the platform.
"""
from __future__ import annotations

import re
from typing import Any

from .common import utc_now
from .ssh import Target

MARKER = "__cdnfoundry_inventory__"
DISK_WARN, DISK_BAD = 85, 95
MEMORY_WARN, SWAP_WARN, LOAD_WARN = 90, 50, 1.5
MAX_CONTAINERS = 80

SCRIPT = r"""
LC_ALL=C; export LC_ALL
echo __cdnfoundry_inventory__
echo "hostname=$(hostname 2>/dev/null)"
( . /etc/os-release 2>/dev/null; echo "os=${PRETTY_NAME:-unknown}" )
echo "kernel=$(uname -r 2>/dev/null)"
echo "arch=$(uname -m 2>/dev/null)"
echo "cpu_model=$(sed -n 's/^model name[[:space:]]*: //p;s/^Model[[:space:]]*: //p;s/^Hardware[[:space:]]*: //p' /proc/cpuinfo 2>/dev/null | head -n1)"
echo "cpu_cores=$(nproc 2>/dev/null || grep -c '^processor' /proc/cpuinfo 2>/dev/null)"
awk '/^MemTotal/{print "mem_total_kb="$2} /^MemAvailable/{print "mem_available_kb="$2} /^SwapTotal/{print "swap_total_kb="$2} /^SwapFree/{print "swap_free_kb="$2}' /proc/meminfo 2>/dev/null
echo "load=$(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null)"
echo "uptime_s=$(cut -d. -f1 /proc/uptime 2>/dev/null)"
echo "virt=$(systemd-detect-virt 2>/dev/null || echo unknown)"
echo "docker=$(docker version --format '{{.Server.Version}}' 2>/dev/null)"
echo "compose=$(docker compose version --short 2>/dev/null)"
echo "ntp_synced=$(timedatectl show -p NTPSynchronized --value 2>/dev/null)"
if ip -6 addr show scope global 2>/dev/null | grep -q inet6; then echo ipv6=yes; else echo ipv6=no; fi
for spec in "system:/" "deployment:/opt/cdnfoundry" "docker:/var/lib/docker"; do
  label=${spec%%:*}; dir=${spec#*:}
  [ -d "$dir" ] && df -Pk "$dir" 2>/dev/null | awk -v l="$label" 'NR==2{print "D|"l"|"$6"|"$2"|"$3}'
done
for d in /sys/class/net/*; do
  n=$(basename "$d"); [ "$n" = lo ] && continue
  echo "N|$n|$(cat "$d/speed" 2>/dev/null)|$(cat "$d/operstate" 2>/dev/null)"
done
docker ps -a --format 'C|{{.Names}}|{{.State}}|{{.Status}}|{{.Image}}' 2>/dev/null | head -n 80
"""


_SECRET = re.compile(r"(?i)\b([\w.-]*(?:token|secret|password|passwd|api[_-]?key|private[_-]?key|authorization)[\w.-]*)(\s*[=:]\s*|\s+)(bearer\s+)?\S+")


def redact(text: str) -> str:
    """Hide the value after anything that looks like a credential name, before a log line is shown."""
    return _SECRET.sub(lambda m: f"{m.group(1)}{m.group(2)}[hidden]", text)


def _int(value: str, limit: int = 10**13) -> int | None:
    return int(value) if re.fullmatch(r"\d{1,14}", value.strip()) and int(value) <= limit else None


def _text(value: str, limit: int = 120) -> str:
    return "".join(ch for ch in value.strip() if ch.isprintable())[:limit]


def parse(output: str) -> dict[str, Any]:
    """Turn the script output into the inventory record. Returns {} when the marker is missing."""
    if MARKER not in output:
        return {}
    flat: dict[str, str] = {}
    disks: list[dict[str, Any]] = []
    nics: list[dict[str, Any]] = []
    containers: list[dict[str, Any]] = []
    for raw in output.split(MARKER, 1)[1].splitlines():
        line = raw.rstrip("\r")
        parts = line.split("|")
        if parts[0] == "D" and len(parts) == 5:
            total, used = _int(parts[3]), _int(parts[4])
            if total:
                disks.append({"label": _text(parts[1], 20), "mount": _text(parts[2], 80), "total_gb": round(total / 1048576, 1), "used_pct": round(100 * (used or 0) / total)})
        elif parts[0] == "N" and len(parts) == 4:
            speed = _int(parts[2])
            nics.append({"name": _text(parts[1], 20), "speed_mbps": speed, "state": _text(parts[3], 20)})
        elif parts[0] == "C" and len(parts) >= 5 and len(containers) < MAX_CONTAINERS:
            status = _text(parts[3], 80)
            containers.append({"name": _text(parts[1], 64), "state": _text(parts[2], 20), "status": status, "image": _text(parts[4], 160),
                               "healthy": False if "unhealthy" in status else (True if "(healthy)" in status else None)})
        elif "=" in line and not line.startswith(("D|", "N|", "C|")):
            key, _, value = line.partition("=")
            flat[key] = value
    if not flat.get("kernel") and not flat.get("os"):
        return {}
    total_kb, available_kb = _int(flat.get("mem_total_kb", "")), _int(flat.get("mem_available_kb", ""))
    swap_total, swap_free = _int(flat.get("swap_total_kb", "")), _int(flat.get("swap_free_kb", ""))
    load = [float(x) for x in flat.get("load", "").split() if re.fullmatch(r"\d+(\.\d+)?", x)][:3]
    return {
        "collected_at": utc_now(),
        "hostname": _text(flat.get("hostname", ""), 80), "os": _text(flat.get("os", "")), "kernel": _text(flat.get("kernel", ""), 60), "arch": _text(flat.get("arch", ""), 20),
        "cpu_model": _text(flat.get("cpu_model", "")), "cpu_cores": _int(flat.get("cpu_cores", ""), 4096),
        "mem_total_mb": round(total_kb / 1024) if total_kb else None,
        "mem_used_pct": round(100 * (total_kb - available_kb) / total_kb) if total_kb and available_kb is not None else None,
        "swap_total_mb": round(swap_total / 1024) if swap_total else 0,
        "swap_used_pct": round(100 * (swap_total - (swap_free or 0)) / swap_total) if swap_total else 0,
        "load": load, "uptime_s": _int(flat.get("uptime_s", "")), "virt": _text(flat.get("virt", ""), 30) or "unknown",
        "docker": _text(flat.get("docker", ""), 30), "compose": _text(flat.get("compose", ""), 30),
        "ntp_synced": {"yes": True, "no": False}.get(flat.get("ntp_synced", "").strip()), "ipv6": flat.get("ipv6", "").strip() == "yes",
        "disks": disks, "nics": nics, "containers": containers,
    }


def collect(transport: Any, target: Target) -> dict[str, Any]:
    """The inventory of one host. Raises SshError when the host cannot be reached; returns {} for output it cannot read."""
    result = transport.run(target, SCRIPT, timeout=45)
    return parse(result.stdout) if result.ok else {}


def worst_disk(inv: dict[str, Any]) -> dict[str, Any] | None:
    return max(inv.get("disks") or [], key=lambda d: d["used_pct"], default=None)


def load_per_core(inv: dict[str, Any]) -> float | None:
    cores = inv.get("cpu_cores")
    return round(inv["load"][0] / cores, 2) if inv.get("load") and cores else None


def findings(node: dict[str, Any], inv: dict[str, Any]) -> list[dict[str, str]]:
    """Hygiene findings for one host. `level` is `bad` or `warn`; each text says what to do."""
    out: list[dict[str, str]] = []
    if not inv:
        return out
    for d in inv.get("disks", []):
        if d["used_pct"] >= DISK_BAD:
            out.append({"level": "bad", "text": f"The {d['label']} disk ({d['mount']}) is {d['used_pct']}% full. Free space before the next release."})
        elif d["used_pct"] >= DISK_WARN:
            out.append({"level": "warn", "text": f"The {d['label']} disk ({d['mount']}) is {d['used_pct']}% full."})
    if inv.get("mem_used_pct") is not None and inv["mem_used_pct"] >= MEMORY_WARN:
        out.append({"level": "warn", "text": f"Memory is {inv['mem_used_pct']}% in use."})
    if inv.get("swap_used_pct", 0) >= SWAP_WARN:
        out.append({"level": "warn", "text": f"Swap is {inv['swap_used_pct']}% in use, so the host is short of memory."})
    per_core = load_per_core(inv)
    if per_core is not None and per_core >= LOAD_WARN:
        out.append({"level": "warn", "text": f"The 1 minute load is {per_core} per core."})
    if inv.get("ntp_synced") is False:
        out.append({"level": "bad", "text": "The clock is not synchronized. Certificates, signatures, and DNS serials depend on correct time."})
    if not inv.get("docker"):
        out.append({"level": "bad", "text": "Docker is not running or not installed."})
    if node.get("public_ipv6") and not inv.get("ipv6"):
        out.append({"level": "warn", "text": "The plan has an IPv6 address for this host, but the host has no global IPv6 address."})
    down = [c["name"] for c in inv.get("containers", []) if container_down(c)]
    if down:
        out.append({"level": "bad", "text": f"{len(down)} container(s) not healthy: " + ", ".join(down[:4]) + ("..." if len(down) > 4 else "")})
    return out


def fleet_findings(inventories: dict[str, dict[str, Any]]) -> list[dict[str, str]]:
    """Differences between hosts that usually mean one of them was missed."""
    out: list[dict[str, str]] = []
    for field, label in (("kernel", "kernel"), ("docker", "Docker version"), ("os", "operating system")):
        values: dict[str, list[str]] = {}
        for name, inv in inventories.items():
            if inv and inv.get(field):
                values.setdefault(inv[field], []).append(name)
        if len(values) > 1:
            parts = "; ".join(f"{v} on {', '.join(sorted(n)[:3])}{'...' if len(n) > 3 else ''}" for v, n in sorted(values.items(), key=lambda kv: -len(kv[1])))
            out.append({"level": "warn", "text": f"Hosts run different {label}s: {parts}."})
    return out


def totals(inventories: dict[str, dict[str, Any]]) -> dict[str, int]:
    known = [i for i in inventories.values() if i]
    return {"hosts": len(known), "cores": sum(i.get("cpu_cores") or 0 for i in known), "memory_gb": round(sum(i.get("mem_total_mb") or 0 for i in known) / 1024),
            "disk_gb": round(sum((d["total_gb"] for i in known for d in i.get("disks", []) if d["label"] == "system"), 0))}


def container_down(container: dict[str, Any]) -> bool:
    """A container is down when it is not running or reports unhealthy. A one-shot job that finished with exit code 0 is done, not down."""
    if container["state"] == "exited" and str(container.get("status", "")).startswith("Exited (0)"):
        return False
    return container["state"] != "running" or container["healthy"] is False
