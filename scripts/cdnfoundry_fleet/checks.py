"""Stack checks behind `cdnfoundry-fleet doctor` and the fleet web "Run check" button."""
from __future__ import annotations

import json
from dataclasses import asdict, dataclass
from typing import Any

from .ssh import SshError, Target


@dataclass
class Check:
    node: str
    name: str
    ok: bool
    detail: str = ""


def check_node(transport: Any, target: Target, expected_release: str) -> list[Check]:
    name = target.name
    try:
        reachable = transport.run(target, "true")
    except SshError as exc:
        return [Check(name, "Host reachable", False, str(exc))]
    if not reachable.ok:
        return [Check(name, "Host reachable", False, (reachable.stderr or "ssh failed").strip()[:200])]
    checks = [Check(name, "Host reachable", True)]
    healthy, detail = _containers(transport, target)
    checks.append(Check(name, "Containers healthy", healthy, detail))
    checks.append(_cert_check(transport, target))
    release = transport.run(target, "cat /opt/cdnfoundry/RELEASE 2>/dev/null")
    running = release.stdout.strip()
    checks.append(Check(name, "Release matches", running == expected_release, f"running {running or 'unknown'}, expected {expected_release}"))
    return checks


REMOTE = "/opt/cdnfoundry"
CERT_WARN_DAYS = 30


def _containers(transport: Any, target: Target) -> tuple[bool, str]:
    r = transport.run(target, f"cd {REMOTE} && docker compose --env-file .env.prod --profile '*' ps -a --format json")
    if not r.ok:
        return False, "docker compose ps failed"
    rows = []
    for line in r.stdout.splitlines():
        line = line.strip()
        if line:
            parsed = json.loads(line)
            rows += parsed if isinstance(parsed, list) else [parsed]
    # One-shot tool services exit by design; a long-running service must be running and not unhealthy.
    bad = [x.get("Service", x.get("Name", "?")) for x in rows
           if x.get("Health") == "unhealthy" or (x.get("State") != "running" and x.get("ExitCode", 0) not in (0, None)) or x.get("State") in {"restarting", "dead"}]
    return not bad, ", ".join(bad[:5])


def node_gate(transport: Any, target: Target, node: dict[str, Any], state: dict[str, Any]) -> tuple[bool, str]:
    """After-upgrade gate: containers healthy, DNS over UDP and TCP, HTTP and HTTPS, control health."""
    ok, detail = _containers(transport, target)
    if not ok:
        return False, f"containers not healthy: {detail}"
    role = node["role"]
    zone = state["global"]["platform_domain"]
    # Probe the address the node really serves on: its bind address, or loopback when it binds the wildcard.
    v4 = node.get("bind_ipv4") or "0.0.0.0"
    families = [("127.0.0.1" if v4 == "0.0.0.0" else v4, "127.0.0.1" if v4 == "0.0.0.0" else v4)]
    v6 = node.get("bind_ipv6") or ""
    if node.get("public_ipv6") or v6:
        host6 = "::1" if v6 in ("", "::") else v6
        families.append((host6, f"[{host6}]"))
    for addr, url_host in families:
        if role in {"dns", "dns-edge"}:
            for proto in ("", "+tcp"):
                r = transport.run(target, f"dig @{addr} {proto} +time=3 +tries=1 +short SOA {zone}".replace("  ", " "))
                if not r.ok or not r.stdout.strip():
                    return False, f"DNS did not answer {proto or 'udp'} on {addr}"
        if role in {"edge", "dns-edge"}:
            for scheme in ("http", "https"):
                r = transport.run(target, f"curl -sk -o /dev/null -m 5 -w '%{{http_code}}' {scheme}://{url_host}/")
                if not r.ok or r.stdout.strip() in {"", "000"}:
                    return False, f"{scheme} not served on {addr}"
    if role == "control":
        host = f"control.{state['global']['operator_domain']}"
        r = transport.run(target, f"curl -fsk -m 8 --resolve {host}:443:127.0.0.1 https://{host}/api/health")
        if not r.ok:
            return False, "control /api/health did not answer"
    return True, ""


def dns_port_conflicts(transport: Any, target: Target, node: dict[str, Any]) -> tuple[bool, str]:
    """DNSdist publishes port 53. A host resolver already on it (Ubuntu's systemd-resolved listens on 127.0.0.x) breaks a wildcard bind."""
    if node["role"] not in {"dns", "dns-edge"}:
        return True, ""
    result = transport.run(target, "ss -H -ltnu 'sport = :53'")
    if not result.ok:
        return True, ""  # cannot tell; the start itself will report a real conflict
    owners = [line.split()[4].rsplit(":", 1)[0].strip("[]") for line in result.stdout.splitlines() if len(line.split()) >= 5]
    families = ((node.get("bind_ipv4") or "0.0.0.0", "0.0.0.0"), (node.get("bind_ipv6") or "", "::"))
    for bind, wildcard in families:
        if not bind:
            continue
        clash = sorted({o for o in owners if (":" in o) == (":" in wildcard) and (bind == wildcard or o in (bind, wildcard, "*"))})
        if clash:
            return False, (f"port 53 on {', '.join(clash)} is already in use (the host resolver?). A wildcard bind cannot share it: "
                           f"set the node's bind address to its public address with `update-node --node {node['name']} --bind-ipv4 <address>`"
                           f"{' --bind-ipv6 <address>' if node.get('bind_ipv6') else ''}")
    return True, ""


def gate_with_retry(check: Any, *, attempts: int = 18, delay: float = 10.0, sleep: Any = None) -> tuple[bool, str]:
    """Services take a while to answer after a start; the gate passes as soon as one attempt does and fails after a bounded wait."""
    import time

    sleep = sleep or time.sleep
    detail = ""
    for attempt in range(attempts):
        ok, detail = check()
        if ok:
            return True, ""
        if attempt < attempts - 1:
            sleep(delay)
    return False, detail


def _cert_check(transport: Any, target: Target) -> Check:
    days = CERT_WARN_DAYS
    r = transport.run(target, f"cd {REMOTE} && openssl x509 -checkend {days * 86400} -noout -in pki/node.crt")
    return Check(target.name, "Node certificate valid", r.ok, "" if r.ok else f"expires within {days} days or unreadable")


def control_components(transport: Any, target: Target) -> list[Check]:
    r = transport.run(target, f"cd {REMOTE} && docker compose --env-file .env.prod exec -T core php artisan cdnf:system:components")
    if not r.ok:
        return [Check(target.name, "Control plane components", False, "could not read component health")]
    try:
        data = json.loads(r.stdout.strip().splitlines()[-1])
    except (ValueError, IndexError):
        return [Check(target.name, "Control plane components", False, "unreadable component output")]
    out = [Check(target.name, f"Component {name}", c.get("status") == "healthy", "" if c.get("status") == "healthy" else str(c.get("details", ""))[:160])
           for name, c in sorted(data.get("components", {}).items())]
    out += [Check(target.name, f"Queue {name}", q.get("status") in {"healthy", "ok"}, "" if q.get("status") in {"healthy", "ok"} else str(q)[:160])
            for name, q in sorted(data.get("queues", {}).items())]
    return out


def monitoring_checks(transport: Any, target: Target) -> list[Check]:
    r = transport.run(target, "curl -fsS -m 5 'http://127.0.0.1:9090/api/v1/query?query=ALERTS%7Balertname%3D%22Watchdog%22%7D'")
    firing = r.ok and '"alertname":"Watchdog"' in r.stdout.replace(" ", "")
    return [Check(target.name, "Watchdog alert firing", firing, "" if firing else "Prometheus does not report Watchdog")]


def run_checks(transport: Any, targets: dict[str, Target], release: str, roles: dict[str, str] | None = None, monitoring_hosts: set[str] | None = None) -> list[Check]:
    out: list[Check] = []
    roles = roles or {}
    for target in targets.values():
        node_checks = check_node(transport, target, release)
        out += node_checks
        if not all(c.ok for c in node_checks if c.name == "Host reachable"):
            continue
        if roles.get(target.name) == "control":
            out += control_components(transport, target)
        if target.name in (monitoring_hosts or set()):
            out += monitoring_checks(transport, target)
    return out


def as_dicts(checks: list[Check]) -> list[dict[str, Any]]:
    return [asdict(c) for c in checks]
