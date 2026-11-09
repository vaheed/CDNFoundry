from __future__ import annotations

import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet import actions as a  # noqa: E402
from cdnfoundry_fleet.ssh import SshError, Target  # noqa: E402

D1, D2 = "sha256:" + "1" * 64, "sha256:" + "2" * 64


def manifest(version: str, edge_digest: str) -> dict[str, Any]:
    return {
        "release": version, "profile": "full", "ci_passed": True, "irreversible_migration": False,
        "images": {"openresty": edge_digest, "pdns": D1, "app": D1}, "configs": {}, "env_images": {},
        "roles": {"edge": {"images": ["openresty"]}, "dns": {"images": ["pdns"]}, "control": {"images": ["app"]}},
    }


NODES = {
    "dns-1": {"role": "dns"}, "dns-2": {"role": "dns"}, "pop-1": {"role": "edge"}, "pop-2": {"role": "edge"},
    "control-1": {"role": "control"}, "control-2": {"role": "control"},
}


@dataclass
class R:
    code: int = 0
    stdout: str = ""
    stderr: str = ""

    @property
    def ok(self) -> bool:
        return self.code == 0


class FakeTransport:
    def __init__(self) -> None:
        self.calls: list[tuple[str, str]] = []
        self.drop_on: str | None = None
        self.fail_activate: set[str] = set()
        self.pulled_digest: dict[str, str] = {}

    def run(self, target: Target, command: str, *, stdin=None, check=False, timeout=None) -> R:
        self.calls.append((target.name, command))
        if self.drop_on and self.drop_on in command:
            self.drop_on = None
            raise SshError("connection dropped")
        if "docker image inspect" in command:
            image = command.rsplit(" ", 1)[-1]
            return R(0, f"{image.split('@')[0]}@{self.pulled_digest.get(target.name, image.split('@')[1])}\n")
        if "&& ./start.sh" in command and "failed" not in command and target.name in self.fail_activate:
            return R(1, "", "boom")
        return R()

    def upload_tree(self, target: Target, local: Path, remote: str, **_) -> None:
        self.calls.append((target.name, f"upload {remote}"))


def engine(tmp_path: Path, t: FakeTransport, gate=lambda n: (True, ""), **kw) -> a.Engine:
    targets = {n: Target(n, "127.0.0.1") for n in NODES}
    for n in NODES:
        (tmp_path / "bundles" / n).mkdir(parents=True, exist_ok=True)
        (tmp_path / "bundles" / n / "start.sh").write_text("x")
    return a.Engine(t, targets, NODES, tmp_path / "bundles", a.Journal(tmp_path / "journal.json"), gate=gate, provision=lambda _n: (True, ""), **kw)


def test_plan_orders_dns_then_pops_then_control_and_skips_unchanged_roles() -> None:
    plan = a.plan_upgrade(NODES, manifest("2.0.0", D1), manifest("2.0.1", D2))
    assert [s.node for s in plan.steps if s.kind == "gate"] == ["pop-1", "pop-2"]
    assert set(plan.skipped) == {"dns-1", "dns-2", "control-1", "control-2"}


def test_standby_is_upgraded_before_the_active_control_node_with_a_switchover() -> None:
    new = manifest("2.0.1", D2)
    new["images"]["app"] = D2
    plan = a.plan_upgrade({k: v for k, v in NODES.items() if v["role"] == "control"}, manifest("2.0.0", D1), new, ha_standby="control-2")
    assert [s.node for s in plan.steps if s.kind == "gate"] == ["control-2", "control-1"]
    assert [s.node for s in plan.steps if s.kind == "switchover"] == ["control-1"]


def test_apply_runs_every_step_and_prepulls_before_activating(tmp_path: Path) -> None:
    t = FakeTransport()
    plan = a.plan_upgrade(NODES, manifest("2.0.0", D1), manifest("2.0.1", D2))
    out = engine(tmp_path, t).apply(plan, manifest("2.0.1", D2))
    assert out.ok
    pop1 = [c for n, c in t.calls if n == "pop-1"]
    assert pop1.index(next(c for c in pop1 if "docker pull" in c)) < pop1.index(next(c for c in pop1 if "&& ./start.sh" in c))
    assert not any(c.startswith("docker pull") for n, c in t.calls if n == "dns-1")


def test_digest_mismatch_after_pull_stops_before_anything_restarts(tmp_path: Path) -> None:
    t = FakeTransport()
    t.pulled_digest["pop-1"] = D1
    new = manifest("2.0.1", D2)
    out = engine(tmp_path, t).apply(a.plan_upgrade(NODES, manifest("2.0.0", D1), new), new)
    assert not out.ok and "pins" in out.detail and out.rolled_back == []
    assert not any("&& ./start.sh" in c for _, c in t.calls)


def test_dropped_connection_resumes_where_it_stopped(tmp_path: Path) -> None:
    new = manifest("2.0.1", D2)
    plan = a.plan_upgrade(NODES, manifest("2.0.0", D1), new)
    t = FakeTransport()
    t.drop_on = "&& ./start.sh"
    first = engine(tmp_path, t).apply(plan, new)
    assert not first.ok and "run the action again" in first.detail
    uploads_before = sum(1 for _, c in t.calls if c.startswith("upload"))
    second = engine(tmp_path, t).apply(plan, new)
    assert second.ok
    assert uploads_before == 1
    # pop-1 is not uploaded again; only pop-2 adds an upload after the resume.
    assert sum(1 for _, c in t.calls if c.startswith("upload")) == 2


def test_broken_canary_rolls_back_and_leaves_the_other_nodes_alone(tmp_path: Path) -> None:
    t = FakeTransport()
    t.fail_activate.add("pop-1")
    new = manifest("2.0.1", D2)
    out = engine(tmp_path, t).apply(a.plan_upgrade(NODES, manifest("2.0.0", D1), new), new)
    assert not out.ok and out.failed == "pop-1:activate"
    assert out.rolled_back == ["pop-1"]
    # pop-2 only had its images pulled ahead of time; it was never uploaded to, restarted, or rolled back
    assert not any(n == "pop-2" and (c.startswith("upload") or "start.sh" in c) for n, c in t.calls)


def test_failed_health_gate_rolls_back_that_node(tmp_path: Path) -> None:
    t = FakeTransport()
    new = manifest("2.0.1", D2)
    out = engine(tmp_path, t, gate=lambda n: (n != "pop-1", "dns not answering")).apply(a.plan_upgrade(NODES, manifest("2.0.0", D1), new), new)
    assert out.failed == "pop-1:gate" and out.detail == "dns not answering" and out.rolled_back == ["pop-1"]


def test_gate_failure_on_a_later_node_rolls_back_all_when_mixed_versions_are_not_allowed(tmp_path: Path) -> None:
    t = FakeTransport()
    new = manifest("2.0.1", D2)
    out = engine(tmp_path, t, gate=lambda n: (n != "pop-2", "bad"), mixed_versions_ok=False).apply(a.plan_upgrade(NODES, manifest("2.0.0", D1), new), new)
    assert out.rolled_back == ["pop-2", "pop-1"]


def test_gate_failure_rolls_back_only_the_failed_node_when_mixed_versions_are_allowed(tmp_path: Path) -> None:
    new = manifest("2.0.1", D2)
    out = engine(tmp_path, FakeTransport(), gate=lambda n: (n != "pop-2", "bad")).apply(a.plan_upgrade(NODES, manifest("2.0.0", D1), new), new)
    assert out.rolled_back == ["pop-2"]


def test_config_rejected_by_its_validator_is_never_activated(tmp_path: Path) -> None:
    class Rejecting(FakeTransport):
        def run(self, target, command, **kw):
            if "openresty -t" in command:
                self.calls.append((target.name, command))
                return R(1, "bad directive")
            return super().run(target, command, **kw)

    t = Rejecting()
    new = manifest("2.0.1", D2)
    eng = engine(tmp_path, t, services={"pop-1": {"cell-01"}, "pop-2": {"cell-01"}})
    out = eng.apply(a.plan_upgrade(NODES, manifest("2.0.0", D1), new), new)
    assert out.failed == "pop-1:validate" and "openresty rejected" in out.detail
    assert not any("&& ./start.sh" in c and "failed" not in c for _, c in t.calls)


def test_a_resumed_run_uploads_again_when_the_rendered_bundle_changed(tmp_path: Path) -> None:
    new = manifest("2.0.1", D2)
    plan = a.plan_upgrade(NODES, manifest("2.0.0", D1), new)
    t = FakeTransport()
    t.drop_on = "&& ./start.sh"
    assert not engine(tmp_path, t).apply(plan, new).ok
    uploads = lambda: [n for n, c in t.calls if c.startswith("upload")]  # noqa: E731
    assert uploads() == ["pop-1"]
    resumed = engine(tmp_path, t)  # building the engine writes the stub bundles; change one afterwards
    (tmp_path / "bundles" / "pop-1" / "start.sh").write_text("a different, re-rendered bundle")
    assert resumed.apply(plan, new).ok
    assert uploads().count("pop-1") == 2  # the changed bundle went up again instead of resuming past the upload


class Rendezvous(FakeTransport):
    """Every image pull waits for `parties` pulls to be in flight at once, so one that runs alone times out and fails."""

    def __init__(self, parties: int) -> None:
        super().__init__()
        import threading

        self.barrier = threading.Barrier(parties, timeout=5)
        self.lock = threading.Lock()
        self.fail_pull: set[str] = set()

    def run(self, target, command, **kw):
        if command.startswith("docker pull"):
            if target.name in self.fail_pull:
                with self.lock:
                    self.calls.append((target.name, command))
                return R(1, "", "pull denied")
            try:
                self.barrier.wait()
            except Exception:
                return R(1, "", "pulls did not overlap")
        with self.lock:
            return super().run(target, command, **kw)


def pull_plan() -> tuple[a.Plan, dict[str, Any]]:
    new = manifest("2.0.1", D2)
    return a.plan_upgrade({k: v for k, v in NODES.items() if v["role"] == "edge"}, manifest("2.0.0", D1), new), new


def test_every_host_pulls_its_images_at_the_same_time_before_any_host_changes(tmp_path: Path) -> None:
    t = Rendezvous(2)  # pop-1 and pop-2 must be pulling together, or the barrier fails the pull
    plan, new = pull_plan()
    out = engine(tmp_path, t).apply(plan, new)
    assert out.ok, out.detail
    commands = [c for _n, c in t.calls]
    assert max(i for i, c in enumerate(commands) if c.startswith("docker pull")) < min(i for i, c in enumerate(commands) if "&& ./start.sh" in c)


def test_one_failed_pull_changes_no_host_and_the_rerun_pulls_only_what_is_missing(tmp_path: Path) -> None:
    t = Rendezvous(1)
    t.fail_pull = {"pop-2"}
    plan, new = pull_plan()
    out = engine(tmp_path, t).apply(plan, new)
    assert not out.ok and out.failed == "pop-2:prepull" and "could not pull" in out.detail and "pull denied" in out.detail and out.rolled_back == []
    assert not any("&& ./start.sh" in c or c.startswith("upload") for _n, c in t.calls)
    t.fail_pull, before = set(), len([1 for n, c in t.calls if n == "pop-1" and c.startswith("docker pull")])
    assert engine(tmp_path, t).apply(plan, new).ok
    assert len([1 for n, c in t.calls if n == "pop-1" and c.startswith("docker pull")]) == before  # pop-1 was already done


def test_a_hosts_images_are_pulled_in_parallel_too(tmp_path: Path) -> None:
    t = Rendezvous(3)
    new = manifest("2.0.1", D2)
    new["images"].update({"vector": D2, "agent": D2})
    new["roles"]["edge"]["images"] = ["openresty", "vector", "agent"]
    nodes = {"pop-1": {"role": "edge"}}
    (tmp_path / "bundles" / "pop-1").mkdir(parents=True)
    (tmp_path / "bundles" / "pop-1" / "start.sh").write_text("x")
    eng = a.Engine(t, {"pop-1": Target("pop-1", "127.0.0.1")}, nodes, tmp_path / "bundles", a.Journal(tmp_path / "j.json"), gate=lambda n: (True, ""), provision=lambda n: (True, ""))
    out = eng.apply(a.plan_upgrade(nodes, manifest("2.0.0", D1), new), new)
    assert out.ok, out.detail
    assert len([c for _n, c in t.calls if c.startswith("docker pull")]) == 3


def test_parallel_preparation_reports_each_step_once_and_logs_each_image(tmp_path: Path) -> None:
    from cdnfoundry_fleet.progress import Tracker

    t, lines, track = Rendezvous(2), [], Tracker()
    plan, new = pull_plan()
    eng = engine(tmp_path, t)
    eng.progress = track
    assert eng.apply(plan, new, log=lines.append).ok
    states = [e["state"] for e in track.events if e["type"] == "step" and e["id"] == "pop-1:prepull"]
    assert states == ["running", "ok"]
    assert any("pop-1: pulling openresty" in line for line in lines) and any("pop-2: openresty ready" in line for line in lines)
    assert any("Preparing 2 hosts at the same time" in line for line in lines)


def test_a_stop_request_before_preparation_changes_nothing(tmp_path: Path) -> None:
    from cdnfoundry_fleet.progress import Tracker

    t, track = Rendezvous(1), Tracker()
    track.cancel_requested = True
    plan, new = pull_plan()
    eng = engine(tmp_path, t)
    eng.progress = track
    out = eng.apply(plan, new)
    assert not out.ok and "Stopped by the operator" in out.detail and t.calls == []


class Streaming(FakeTransport):
    """A transport that can stream: start.sh prints progress lines, and a quiet period is reported."""

    def run_stream(self, target, command, on_line, *, timeout=None, on_quiet=None, heartbeat=None):
        self.calls.append((target.name, command))
        for line in ("Container pdns-db  Healthy", "", "Container web  Starting"):
            on_line(line)
        if on_quiet:
            on_quiet(45)
        return R(0, "Container pdns-db  Healthy\n\nContainer web  Starting")


def test_activation_output_reaches_the_log_line_by_line_with_quiet_periods_reported(tmp_path: Path) -> None:
    lines: list[str] = []
    t = Streaming()
    new = manifest("2.0.1", D2)
    plan = a.plan_upgrade({"pop-1": {"role": "edge"}}, manifest("2.0.0", D1), new)
    eng = engine(tmp_path, t)
    out = eng.apply(plan, new, log=lines.append)
    assert out.ok
    assert "pop-1: Container pdns-db  Healthy" in lines and "pop-1: Container web  Starting" in lines
    assert "pop-1: still working, no output for 45s in this step" in lines
    assert not any(line == "pop-1: " for line in lines), "blank host lines are not logged"


def test_a_failed_activation_reports_the_tail_of_the_hosts_output(tmp_path: Path) -> None:
    class Failing(Streaming):
        def run_stream(self, target, command, on_line, **kw):
            on_line("waiting for web to become healthy")
            return R(1, "waiting for web to become healthy\ncontainer web is unhealthy", "")

    new = manifest("2.0.1", D2)
    plan = a.plan_upgrade({"pop-1": {"role": "edge"}}, manifest("2.0.0", D1), new)
    out = engine(tmp_path, Failing()).apply(plan, new)
    assert not out.ok and out.failed == "pop-1:activate" and "container web is unhealthy" in out.detail


def test_prepare_readies_all_hosts_together_and_later_runs_do_not_repeat_it(tmp_path: Path) -> None:
    t = Rendezvous(2)
    plan, new = pull_plan()
    eng = engine(tmp_path, t)
    assert eng.prepare(plan, new) is None
    pulls = len([1 for _n, c in t.calls if c.startswith("docker pull")])
    assert pulls == 2 and {"pop-1:prepull", "pop-2:prepull"} <= eng.prepared
    assert eng.apply(plan, new).ok
    assert len([1 for _n, c in t.calls if c.startswith("docker pull")]) == pulls, "apply skipped the prepared steps"


def test_prepare_reports_a_failed_pull_and_leaves_every_host_unchanged(tmp_path: Path) -> None:
    t = Rendezvous(1)
    t.fail_pull = {"pop-1"}
    plan, new = pull_plan()
    eng = engine(tmp_path, t)
    out = eng.prepare(plan, new)
    assert out is not None and not out.ok and out.failed == "pop-1:prepull"
    assert not any(c.startswith("upload") or "start.sh" in c for _n, c in t.calls)


def test_a_failed_pull_cancels_the_waiting_pulls_without_raising(tmp_path: Path) -> None:
    """With more images than workers, a failure cancels the queued pulls; reporting must skip them instead of raising CancelledError."""
    t = Rendezvous(1)
    t.fail_pull = {"pop-1"}
    plan, new = pull_plan()
    new["roles"]["edge"]["images"] = [f"image-{i}" for i in range(a.IMAGE_WORKERS * 4)]
    new["images"] = {n: "sha256:" + "a" * 64 for n in new["roles"]["edge"]["images"]}
    out = engine(tmp_path, t).apply(plan, new)
    assert not out.ok and "pull denied" in out.detail
