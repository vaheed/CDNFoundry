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
        return R(1, "syntax error") if self.bad and self.bad in command else R(0)


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
