from __future__ import annotations

import sys
from dataclasses import dataclass
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet.ssh import Target  # noqa: E402
from cdnfoundry_fleet.validators import validate_node_config, validators_for  # noqa: E402


@dataclass
class R:
    code: int
    stderr: str = ""
    stdout: str = ""

    @property
    def ok(self) -> bool:
        return self.code == 0


class T:
    def __init__(self, bad: str | None = None) -> None:
        self.bad, self.ran = bad, []

    def run(self, target, command, **_):
        self.ran.append(command)
        if self.bad and self.bad in command:
            return R(1, "syntax error")
        if command.startswith("dig "):
            return R(0, "", ";; ->>HEADER<<- opcode: QUERY, status: REFUSED, id: 1\n")
        return R(0)


TARGET = Target("n", "127.0.0.1")


def test_every_validated_service_is_covered() -> None:
    services = {"cell-01", "edge-control", "dnsdist", "pdns-primary", "caddy", "prometheus", "alertmanager", "vector"}
    names = {v.service for role in ("control", "edge", "dns", "monitoring") for v in validators_for(role, services)}
    assert {"openresty", "edge-control", "dnsdist", "powerdns", "caddy", "prometheus", "alertmanager", "vector"} <= names


def test_each_invalid_config_is_rejected_and_named() -> None:
    cases = {"openresty -t": "openresty", "--check-config": "dnsdist", "caddy validate": "caddy",
             "promtool": "prometheus", "amtool": "alertmanager", "vector validate": "vector", "validate.sh": "compose"}
    for needle, service in cases.items():
        role = {"openresty": "edge", "dnsdist": "dns", "caddy": "control", "prometheus": "monitoring", "alertmanager": "monitoring"}.get(service, "edge")
        ok, detail = validate_node_config(T(needle), TARGET, role, "/opt/x.incoming", {"cell-01", "dnsdist", "caddy", "prometheus", "alertmanager", "vector"})
        assert not ok and service in detail, (needle, detail)


def test_valid_config_passes_and_unknown_services_are_not_checked() -> None:
    t = T()
    assert validate_node_config(t, TARGET, "edge", "/d", {"cell-01"})[0]
    assert not any("caddy" in c for c in t.ran)


def test_validation_uses_the_install_directory_name_as_its_compose_project_and_removes_nothing() -> None:
    t = T()
    validate_node_config(t, TARGET, "edge", "/opt/cdnfoundry.next", {"cell-01"})
    assert t.ran and all("COMPOSE_PROJECT_NAME=cdnfoundry " in c or "COMPOSE_PROJECT_NAME=cdnfoundry&" in c or "COMPOSE_PROJECT_NAME=cdnfoundry" in c for c in t.ran)
    assert not any(" down" in c or "volume rm" in c for c in t.ran)


def test_the_health_gate_waits_for_a_slow_start_but_not_forever() -> None:
    from cdnfoundry_fleet.checks import gate_with_retry

    answers = iter([(False, "not yet"), (False, "not yet"), (True, "")])
    slept: list[float] = []
    assert gate_with_retry(lambda: next(answers), attempts=5, delay=7, sleep=slept.append) == (True, "")
    assert slept == [7, 7]
    slept.clear()
    assert gate_with_retry(lambda: (False, "still down"), attempts=3, delay=7, sleep=slept.append) == (False, "still down")
    assert slept == [7, 7]


def test_the_control_gate_asks_the_control_hostname_not_the_operator_domain() -> None:
    from cdnfoundry_fleet.checks import node_gate

    t = T()
    state = {"global": {"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test"}}
    ok, _ = node_gate(t, TARGET, {"role": "control", "name": "c"}, state)
    assert ok
    health = next(c for c in t.ran if "/api/health" in c)
    assert "--resolve control.ops.example.test:443:127.0.0.1 https://control.ops.example.test/api/health" in health


def _ss(*listeners: str):
    class Ss:
        def run(self, target, command, **_):
            return R(0, "", "".join(f"{p:<6}UNCONN 0 0 {a:>20}:53   0.0.0.0:*\n" for p, a in (x.split("|") for x in listeners)))

    return Ss()


def test_a_host_resolver_on_port_53_blocks_a_wildcard_bind_with_the_fix_named() -> None:
    from cdnfoundry_fleet.checks import dns_port_conflicts

    node = {"role": "dns-edge", "name": "pop-1", "bind_ipv4": "0.0.0.0", "bind_ipv6": "::"}
    ok, detail = dns_port_conflicts(_ss("udp|127.0.0.54", "tcp|127.0.0.53"), TARGET, node)
    assert not ok and "127.0.0.5" in detail and "update-node --node pop-1 --bind-ipv4" in detail


def test_binding_to_the_public_address_passes_beside_a_loopback_resolver() -> None:
    from cdnfoundry_fleet.checks import dns_port_conflicts

    node = {"role": "dns-edge", "name": "pop-1", "bind_ipv4": "93.184.216.12", "bind_ipv6": "2001:db8::12"}
    assert dns_port_conflicts(_ss("udp|127.0.0.54", "tcp|127.0.0.53"), TARGET, node) == (True, "")
    assert not dns_port_conflicts(_ss("udp|93.184.216.12"), TARGET, node)[0]
    assert dns_port_conflicts(_ss("udp|127.0.0.54"), TARGET, {"role": "edge", "name": "e", "bind_ipv4": "0.0.0.0"}) == (True, "")


def test_the_gate_probes_the_bind_addresses_and_loopback_only_for_a_wildcard_bind() -> None:
    from cdnfoundry_fleet.checks import node_gate

    state = {"global": {"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test"}}
    t = T()
    node = {"role": "dns-edge", "name": "p", "bind_ipv4": "93.184.216.12", "bind_ipv6": "2001:db8::12", "public_ipv6": "2001:db8::12"}
    assert node_gate(t, TARGET, node, state)[0]
    joined = "\n".join(t.ran)
    assert "dig @93.184.216.12" in joined.replace("  ", " ") and "dig @2001:db8::12" in joined.replace("  ", " ") and "/dev/tcp/93.184.216.12/443" in joined and "/dev/tcp/2001:db8::12/80" in joined
    assert "127.0.0.1" not in joined
    t2 = T()
    node_gate(t2, TARGET, {"role": "dns", "name": "d", "bind_ipv4": "0.0.0.0", "bind_ipv6": None}, state)
    assert any("dig @127.0.0.1" in c for c in t2.ran)


def test_the_gate_accepts_any_dns_reply_but_not_silence() -> None:
    from cdnfoundry_fleet.checks import node_gate

    state = {"global": {"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test"}}
    node = {"role": "dns", "name": "d", "bind_ipv4": "93.184.216.12", "bind_ipv6": None}
    assert node_gate(T(), TARGET, node, state)[0]  # REFUSED: the PoP has no zones yet but is serving

    class Silent(T):
        def run(self, target, command, **kw):
            return R(0) if command.startswith("dig ") else super().run(target, command, **kw)

    ok, detail = node_gate(Silent(), TARGET, node, state)
    assert not ok and "DNS did not answer udp on 93.184.216.12" in detail


def test_our_own_docker_proxy_on_port_53_is_not_a_conflict() -> None:
    from cdnfoundry_fleet.checks import dns_port_conflicts

    class Ss:
        def run(self, target, command, **_):
            return R(0, "", 'udp   UNCONN 0 0 93.184.216.12:53 0.0.0.0:* users:(("docker-proxy",pid=1,fd=7))\n')

    node = {"role": "dns-edge", "name": "pop-1", "bind_ipv4": "93.184.216.12", "bind_ipv6": ""}
    assert dns_port_conflicts(Ss(), TARGET, node) == (True, "")


def test_a_public_port_that_refuses_connections_fails_the_gate() -> None:
    from cdnfoundry_fleet.checks import node_gate

    class Closed(T):
        def run(self, target, command, **kw):
            return R(1) if "/dev/tcp/" in command and command.endswith("/443'") else super().run(target, command, **kw)

    state = {"global": {"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test"}}
    ok, detail = node_gate(Closed(), TARGET, {"role": "edge", "name": "e", "bind_ipv4": "93.184.216.12", "bind_ipv6": ""}, state)
    assert not ok and detail == "https not accepting connections on 93.184.216.12:443"
