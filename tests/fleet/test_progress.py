from __future__ import annotations

import io
import sys
import threading
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet.progress import TerminalBar, Tracker  # noqa: E402

STEPS = [{"id": "a:pin", "node": "a", "kind": "pin", "label": "a: pin"}, {"id": "a:gate", "node": "a", "kind": "gate", "label": "a: gate"}]


def test_steps_tick_in_order_and_percent_follows():
    t = Tracker()
    t.plan(STEPS)
    assert t.percent() == 0
    t.step("a:pin", "running")
    t.step("a:pin", "ok")
    assert t.counts() == (1, 2) and t.percent() == 50
    t.step("a:gate", "failed", "unhealthy")
    assert t.counts() == (2, 2)
    assert [e["type"] for e in t.events] == ["plan", "step", "step", "step"]
    assert [e["n"] for e in t.events] == [0, 1, 2, 3]


def test_planning_again_keeps_state_and_adds_only_new_steps():
    t = Tracker()
    t.plan(STEPS)
    t.step("a:pin", "ok")
    t.plan(STEPS + [{"id": "b:pin", "node": "b", "kind": "pin", "label": "b: pin"}])
    assert [s["id"] for s in t.steps] == ["a:pin", "a:gate", "b:pin"]
    assert t.steps[0]["state"] == "ok"
    before = len(t.events)
    t.plan(STEPS)
    assert len(t.events) == before


def test_a_reader_that_joins_late_gets_only_what_it_missed():
    t = Tracker()
    t.line("one")
    steps, lines, after = t.view()
    t.line("two")
    assert lines == ["one"] and [e["text"] for e in t.wait(after)] == ["two"]


def test_wait_wakes_when_an_event_arrives():
    t = Tracker()
    threading.Timer(0.05, lambda: t.line("hello")).start()
    assert [e["text"] for e in t.wait(0, timeout=5)] == ["hello"]


def test_wait_returns_at_once_after_the_action_finished():
    t = Tracker()
    t.finish("ok")
    assert t.wait(5, timeout=5) == []


def test_log_is_bounded():
    t = Tracker()
    for i in range(6000):
        t.line(f"line {i}")
    assert len(t.lines) <= 5000 and t.lines[-1] == "line 5999"


def test_stop_request_is_logged_and_flagged():
    t = Tracker()
    t.request_cancel()
    assert t.cancel_requested and "Stop requested" in t.lines[-1]


def test_terminal_bar_draws_one_line_on_a_tty_and_plain_lines_otherwise():
    out = io.StringIO()
    bar = TerminalBar(out, tty=True)
    t = Tracker()
    t.listeners.append(bar)
    t.plan(STEPS)
    t.step("a:pin", "running")
    t.step("a:pin", "ok")
    bar.close()
    assert "1/2" in out.getvalue() and "\r" in out.getvalue()
    plain = io.StringIO()
    t2 = Tracker()
    t2.listeners.append(TerminalBar(plain, tty=False))
    t2.plan(STEPS)
    t2.step("a:pin", "ok")
    t2.line("hello")
    assert "\r" not in plain.getvalue() and "[1/2] a:pin ok" in plain.getvalue() and "hello" in plain.getvalue()
