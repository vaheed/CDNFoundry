from __future__ import annotations

import sys
from dataclasses import dataclass
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet.common import ValidationError  # noqa: E402
from cdnfoundry_fleet.engine import ControlActions, FleetEngine  # noqa: E402
from cdnfoundry_fleet.ssh import Target, save_target  # noqa: E402
from cdnfoundry_fleet.state import FleetState  # noqa: E402


@dataclass
class R:
    code: int = 0
    stdout: str = ""
    stderr: str = ""

    @property
    def ok(self) -> bool:
        return self.code == 0


class T:
    def __init__(self, users: int = 0) -> None:
        self.users, self.ran = users, []

    def run(self, target, command, **_):
        self.ran.append(command)
        if "tinker" in command:
            return R(0, f"{self.users}\n")
        return R(0, "ok")


def engine(tmp_path: Path, transport: T, backups: str = "control") -> FleetEngine:
    store = FleetState(tmp_path / "state")
    state = store.init({"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v2.0.0"})
    state = store.add_node(state, {"name": "control-1", "role": "control", "public_ipv4": "203.0.113.10"})
    store.configure_feature(state, "backups", {"mode": backups, "repository": "s3:https://x/y"})
    save_target(tmp_path / "state", Target("control-1", "203.0.113.10"))
    return FleetEngine(tmp_path / "state", tmp_path, tmp_path / "out", transport=transport)


SNAP = "a" * 64


def test_restore_needs_the_typed_confirmation_and_runs_nothing_without_it(tmp_path: Path) -> None:
    t = T()
    c = ControlActions(engine(tmp_path, t))
    with pytest.raises(ValidationError, match=f"RESTORE {SNAP}"):
        c.restore("control-1", SNAP, "yes")
    assert not any("backups:restore" in cmd for cmd in t.ran)


def test_restore_into_an_empty_node_runs_the_one_off_executor(tmp_path: Path) -> None:
    t = T()
    c = ControlActions(engine(tmp_path, t))
    plan = c.restore_plan("control-1", SNAP, False)
    assert "empty" in plan["replaces"]
    c.restore("control-1", SNAP, f"RESTORE {SNAP}")
    cmd = next(x for x in t.ran if "backups:restore" in x)
    assert "BACKUP_RESTORE_ALLOWED=true" in cmd and f"--snapshot={SNAP}" in cmd and " run --rm" in cmd


def test_a_live_control_node_is_refused_by_default_and_needs_the_operation(tmp_path: Path) -> None:
    t = T(users=3)
    c = ControlActions(engine(tmp_path, t))
    with pytest.raises(ValidationError, match="3 user"):
        c.restore_plan("control-1", SNAP, False)
    assert "3 user" in c.restore_plan("control-1", SNAP, True)["replaces"]
    with pytest.raises(ValidationError, match="operation"):
        c.restore("control-1", SNAP, f"RESTORE {SNAP}", overwrite_live=True)
    op = "11111111-1111-4111-8111-111111111111"
    c.restore("control-1", SNAP, f"RESTORE {SNAP}", overwrite_live=True, operation=op)
    assert any(op in x for x in t.ran)


def test_backup_now_requires_configured_backups(tmp_path: Path) -> None:
    with pytest.raises(ValidationError, match="disabled"):
        ControlActions(engine(tmp_path, T(), backups="disabled")).backup_now()
    t = T()
    ControlActions(engine(tmp_path / "b", t)).backup_now()
    assert any("cdnf:backups:create --wait" in x for x in t.ran)


def test_restore_rejects_a_non_control_node(tmp_path: Path) -> None:
    e = engine(tmp_path, T())
    s = e.store.load()
    e.store.add_node(s, {"name": "pop-1", "role": "edge", "public_ipv4": "203.0.113.11"})
    with pytest.raises(ValidationError, match="control node"):
        ControlActions(e).restore_plan("pop-1", SNAP, False)
