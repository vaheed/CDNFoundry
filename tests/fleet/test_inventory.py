from __future__ import annotations

import json
import sys
from dataclasses import dataclass
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet import inventory as inv  # noqa: E402
from cdnfoundry_fleet.cli import main  # noqa: E402
from cdnfoundry_fleet.common import ValidationError  # noqa: E402
from cdnfoundry_fleet.engine import FleetEngine  # noqa: E402
from cdnfoundry_fleet.progress import Tracker  # noqa: E402
from cdnfoundry_fleet.ssh import SshError, Target, save_target  # noqa: E402
from cdnfoundry_fleet.state import FleetState  # noqa: E402
from cdnfoundry_fleet.vault import Vault  # noqa: E402
from cdnfoundry_fleet.monitor import LiveHealth, summarize  # noqa: E402
from cdnfoundry_fleet.terminal import inventory_table, watch_table  # noqa: E402

HOST = """noise before the marker
__cdnfoundry_inventory__
hostname=edge-1
os=Ubuntu 24.04.2 LTS
kernel=6.8.0-60-generic
arch=x86_64
cpu_model=Intel(R) Xeon(R) CPU E5-2697 v4 @ 2.30GHz
cpu_cores=8
mem_total_kb=16384000
mem_available_kb=4096000
swap_total_kb=2097152
swap_free_kb=2097152
load=0.52 0.40 0.30
uptime_s=864000
virt=kvm
docker=29.1.3
compose=2.40.0
ntp_synced=yes
ipv6=yes
D|system|/|102400000|51200000
D|docker|/var/lib/docker|204800000|191000000
N|eth0|10000|up
N|eth1||down
C|edge-agent|running|Up 3 days (healthy)|registry.example/edge-agent@sha256:abc
C|openresty|running|Up 3 days|openresty:1
C|vector|exited|Exited (1) 2 hours ago|vector:0.4
"""


def test_parse_reads_hardware_and_bounds_the_values():
    i = inv.parse(HOST)
    assert i["cpu_cores"] == 8 and i["mem_total_mb"] == 16000 and i["mem_used_pct"] == 75 and i["swap_used_pct"] == 0
    assert i["load"] == [0.52, 0.4, 0.3] and i["virt"] == "kvm" and i["ntp_synced"] is True and i["ipv6"] is True
    assert [d["label"] for d in i["disks"]] == ["system", "docker"] and i["disks"][1]["used_pct"] == 93
    assert [n["speed_mbps"] for n in i["nics"]] == [10000, None]
    assert [c["state"] for c in i["containers"]] == ["running", "running", "exited"] and i["containers"][0]["healthy"] is True


def test_parse_refuses_output_without_the_marker_or_a_host_identity():
    assert inv.parse("kernel=6.8\n") == {}
    assert inv.parse(inv.MARKER + "\nhostname=x\n") == {}
    assert inv.parse("") == {}


def test_parse_drops_unprintable_and_oversized_text():
    i = inv.parse(inv.MARKER + "\nkernel=6.8\x1b[31m" + "x" * 500 + "\nos=a\ncpu_cores=99999999999999999\n")
    assert "\x1b" not in i["kernel"] and len(i["kernel"]) <= 60 and i["cpu_cores"] is None


def test_findings_name_real_problems_and_stay_quiet_on_a_healthy_host():
    healthy = inv.parse(HOST.replace("191000000", "100000000").replace("C|vector|exited|Exited (1) 2 hours ago|vector:0.4\n", ""))
    assert inv.findings({"public_ipv6": "2001:db8::1"}, healthy) == []
    found = inv.findings({"public_ipv6": "2001:db8::1"}, inv.parse(HOST.replace("ntp_synced=yes", "ntp_synced=no").replace("ipv6=yes", "ipv6=no")))
    texts = " ".join(f["text"] for f in found)
    assert "docker disk" in texts and "not synchronized" in texts and "no global IPv6" in texts and "vector" in texts
    assert {f["level"] for f in found} == {"bad", "warn"}


def test_fleet_findings_report_mixed_kernels_only():
    a, b = inv.parse(HOST), inv.parse(HOST.replace("6.8.0-60", "6.5.0-10"))
    assert inv.fleet_findings({"a": a, "b": a}) == []
    [one] = inv.fleet_findings({"a": a, "b": b})
    assert "kernel" in one["text"] and "6.5.0-10" in one["text"]


def test_totals_add_up_hosts_that_answered():
    t = inv.totals({"a": inv.parse(HOST), "b": inv.parse(HOST), "c": {}})
    assert t == {"hosts": 2, "cores": 16, "memory_gb": 31, "disk_gb": 195}


def test_redact_hides_credential_values_but_keeps_the_rest():
    text = inv.redact("start ok\nAPI_TOKEN=abc123 db password: hunter2\nAuthorization: Bearer xyz\nlistening on :443")
    assert "abc123" not in text and "hunter2" not in text and "xyz" not in text
    assert "start ok" in text and "listening on :443" in text


@dataclass
class R:
    code: int = 0
    stdout: str = ""
    stderr: str = ""

    @property
    def ok(self) -> bool:
        return self.code == 0


class Transport:
    def __init__(self, output: str) -> None:
        self.output, self.commands = output, []

    def run(self, target, command, **_):
        self.commands.append(command)
        return R(0, self.output)


def test_collect_runs_one_read_only_script():
    t = Transport(HOST)
    got = inv.collect(t, type("T", (), {"name": "edge-1"})())
    assert got["cpu_cores"] == 8 and len(t.commands) == 1
    forbidden = ("rm ", "mv ", "docker rm", "docker stop", "docker restart", "systemctl", "apt", "chmod", "> /", ">>")
    assert not any(word in inv.SCRIPT for word in forbidden)


def test_summarize_marks_a_down_container_as_degraded():
    assert summarize({"ok": True, "inventory": inv.parse(HOST)})["state"] == "degraded"
    assert summarize({"ok": False, "error": "refused"}) == {"state": "offline", "error": "refused"}
    healthy = inv.parse(HOST.replace("C|vector|exited|Exited (1) 2 hours ago|vector:0.4\n", ""))
    s = summarize({"ok": True, "inventory": healthy})
    assert s["state"] == "online" and s["containers"] == {"total": 2, "running": 2, "unhealthy": 0} and s["load"] == 0.07


def test_live_health_keeps_a_bounded_history_and_skips_busy_rounds():
    results = {"a": {"ok": True, "inventory": inv.parse(HOST)}, "b": {"ok": False, "error": "down"}}
    busy = {"v": False}
    live = LiveHealth(lambda: results, lambda: busy["v"], interval=0)
    for _ in range(80):
        live.refresh()
    snap = live.snapshot()
    assert len(snap["nodes"]["a"]["history"]) == 60 and snap["nodes"]["b"]["state"] == "offline" and snap["updated"]
    results.pop("b")
    live.refresh()
    assert "b" not in live.snapshot()["nodes"]


def test_live_health_survives_a_failing_probe():
    def boom():
        raise RuntimeError("vault locked")

    live = LiveHealth(boom, lambda: False, interval=0)
    assert live.refresh() is True and "vault locked" in live.snapshot()["error"] and live.updated is None


def test_tables_render_every_host_and_the_state_words():
    nodes = {"a": {"role": "edge"}, "b": {"role": "dns"}}
    text = inventory_table(nodes, {"a": inv.parse(HOST)})
    assert "a" in text and "8" in text and "15.6G" in text and "b" in text
    watch = watch_table(nodes, {"a": {"ok": True, "inventory": inv.parse(HOST)}, "b": {"ok": False, "error": "connection refused"}})
    assert "degraded" in watch and "UNREACHABLE" in watch and "connection refused" in watch


ROOT = Path(__file__).resolve().parents[2]
PASS = "correct horse battery"


class Fleet:
    """Answers the inventory script like a host, a container log request with log text, and refuses one named host."""

    def __init__(self, down: set[str] = frozenset()) -> None:
        self.down, self.commands = set(down), []

    def run(self, target, command, **_):
        self.commands.append((target.name, command))
        if target.name in self.down:
            raise SshError("connection refused")
        if command.startswith("docker logs"):
            return R(0, "started\nAPI_TOKEN=abc123 accepted\n")
        return R(0, HOST)


@pytest.fixture()
def vault(tmp_path, monkeypatch):
    monkeypatch.setenv("CDNF_FLEET_PASSPHRASE", PASS)
    path = tmp_path / "fleet.db"
    assert main(["init", "--vault", str(path), "--non-interactive", "--operator-domain", "ops.example.test", "--platform-domain", "cdn.example.test", "--release", "v2.0.0"]) == 0
    v = Vault(path, PASS)
    with v.session() as work:
        store = FleetState(work)
        state = store.load()
        for name, role, ip in (("control-1", "control", "203.0.113.5"), ("pop-1", "edge", "203.0.113.9")):
            state = store.add_node(state, {"name": name, "role": role, "public_ipv4": ip})
            save_target(work, Target(name, "127.0.0.1", 22, "root"))
    return v


def test_engine_records_the_inventory_of_hosts_that_answer_and_reports_each_step(vault, tmp_path):
    tracker, said = Tracker(), []
    with vault.session() as work:
        engine = FleetEngine(work, ROOT, tmp_path / "b", transport=Fleet(down={"pop-1"}), say=said.append, progress=tracker)
        got = engine.collect_inventory()
    assert set(got) == {"control-1"} and [s["state"] for s in tracker.steps] == ["ok", "failed"]
    assert any("pop-1: no inventory (connection refused)" in line for line in said)
    with vault.read_session() as work:
        assert FleetEngine(work, ROOT, tmp_path / "b").inventory()["control-1"]["cpu_cores"] == 8


def test_probe_reports_an_unreachable_host_as_a_result(vault, tmp_path):
    with vault.read_session() as work:
        result = FleetEngine(work, ROOT, tmp_path / "b", transport=Fleet(down={"pop-1"})).probe_nodes()
    assert result["control-1"]["ok"] is True and result["pop-1"] == {"ok": False, "error": "connection refused"}


def test_container_logs_need_a_container_the_host_reported_and_hide_credentials(vault, tmp_path):
    with vault.session() as work:
        engine = FleetEngine(work, ROOT, tmp_path / "b", transport=Fleet())
        engine.collect_inventory()
        text = engine.container_log("control-1", "edge-agent")
        assert "started" in text and "abc123" not in text
        for bad in ("nope", "edge-agent; reboot", "../etc", "-rf"):
            with pytest.raises(ValidationError):
                engine.container_log("control-1", bad)
    assert not any("reboot" in command for _n, command in engine.transport.commands)


def test_cli_inventory_and_watch_before_any_host_was_read(vault, capsys):
    base = ["--vault", str(vault.path)]
    assert main(["inventory", *base]) == 0
    out = capsys.readouterr().out
    assert "HOST" in out and "Needs attention: nothing" in out and "2 host(s) have not been read" in out
    assert main(["inventory", *base, "--json"]) == 0
    assert json.loads(capsys.readouterr().out) == {"control-1": {"findings": []}, "pop-1": {"findings": []}}
    assert main(["inventory", *base, "--node", "ghost"]) == 3
    assert main(["watch", *base, "--once"]) == 6  # the saved logins reach no sshd that knows the Fleet key
    assert "LOAD/CORE" in capsys.readouterr().out


def test_cli_watch_once_json_reports_unreachable_hosts_with_a_failing_exit_code(vault, capsys, monkeypatch):
    from cdnfoundry_fleet import engine as engine_module

    monkeypatch.setattr(engine_module.FleetEngine, "probe_nodes", lambda self, **_k: {"control-1": {"ok": False, "error": "connection refused"}, "pop-1": {"ok": True, "inventory": inv.parse(HOST)}})
    assert main(["watch", "--vault", str(vault.path), "--once", "--json"]) == 6
    line = json.loads(capsys.readouterr().out.strip().splitlines()[-1])
    assert line["nodes"]["control-1"]["state"] == "offline" and line["nodes"]["pop-1"]["state"] == "degraded"


def test_cli_alert_webhook_shows_saves_and_refuses_plain_http(vault, capsys):
    base = ["--vault", str(vault.path)]
    assert main(["alert-webhook", *base]) == 0 and json.loads(capsys.readouterr().out)["alert_webhook_url"] == ""
    assert main(["alert-webhook", *base, "--url", "https://alerts.example.com/hook"]) == 0
    assert json.loads(capsys.readouterr().out)["alert_webhook_url"] == "https://alerts.example.com/hook"
    assert main(["alert-webhook", *base]) == 0 and "alerts.example.com" in capsys.readouterr().out
    assert main(["alert-webhook", *base, "--url", "http://alerts.example.com/hook"]) == 3
    assert main(["alert-webhook", *base, "--url", ""]) == 0 and json.loads(capsys.readouterr().out)["alert_webhook_url"] == ""
