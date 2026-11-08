"""Structured progress for long Fleet actions, shared by Fleet web and the CLI.

An action announces its plan once, then marks each step running, ok, failed, or skipped. The
tracker keeps the events in order so a reader (the job page stream, a terminal bar) can join late
and still see everything. It holds only what is already printed in the action log.
"""
from __future__ import annotations

import contextlib
import signal
import threading
import time
from typing import Any, Callable, Iterator

MAX_LINES = 5000
MAX_LINE_CHARS = 600
STEP_STATES = {"pending", "running", "ok", "failed", "skipped"}


class Tracker:
    def __init__(self) -> None:
        self.steps: list[dict[str, Any]] = []
        self.lines: list[str] = []
        self.events: list[dict[str, Any]] = []
        self.state = "running"
        self.result = ""
        self.cancel_requested = False
        self.started = time.time()
        self.finished: float | None = None
        self.listeners: list[Callable[[dict[str, Any]], None]] = []
        self._cond = threading.Condition()

    # -- writers (the action thread) ----------------------------------------------------------------------
    def _emit(self, event: dict[str, Any]) -> None:
        with self._cond:
            event["n"] = len(self.events)
            event["t"] = round(time.time() - self.started, 1)
            self.events.append(event)
            self._cond.notify_all()
        for listener in list(self.listeners):
            listener(event)

    def plan(self, steps: list[dict[str, str]]) -> None:
        """Announce steps. Steps already known keep their state, so a multi-host action can plan up front and each host can plan again."""
        with self._cond:
            known = {s["id"] for s in self.steps}
            added = [{"id": s["id"], "node": s.get("node", ""), "kind": s.get("kind", ""), "label": s.get("label", s["id"]), "state": "pending", "at": None, "seconds": None} for s in steps if s["id"] not in known]
            if not added:
                return
            self.steps += added
            self._emit({"type": "plan", "steps": [dict(s) for s in added]})

    def step(self, step_id: str, state: str, detail: str = "") -> None:
        if state not in STEP_STATES:
            raise ValueError(f"Unknown step state {state}")
        with self._cond:
            for s in self.steps:
                if s["id"] == step_id:
                    if state == "running":
                        s["at"] = time.time()
                    elif s["at"] is not None:
                        s["seconds"] = round(time.time() - s["at"], 1)
                    s["state"] = state
                    break
            self._emit({"type": "step", "id": step_id, "state": state, "detail": detail[:MAX_LINE_CHARS],
                        "seconds": next((s["seconds"] for s in self.steps if s["id"] == step_id), None)})

    def line(self, text: str) -> None:
        text = str(text)[:MAX_LINE_CHARS]
        with self._cond:
            if len(self.lines) >= MAX_LINES:
                del self.lines[: MAX_LINES // 10]
            self.lines.append(text)
            self._emit({"type": "line", "text": text})

    def finish(self, state: str, result: str = "") -> None:
        with self._cond:
            self.state, self.result, self.finished = state, result, time.time()
            self._emit({"type": "done", "state": state, "result": result})

    # -- readers ------------------------------------------------------------------------------------------
    def wait(self, after: int, timeout: float = 15.0) -> list[dict[str, Any]]:
        """Events with number >= `after`, waiting up to `timeout` seconds for the next one."""
        with self._cond:
            if len(self.events) <= after and self.state == "running":
                self._cond.wait(timeout)
            return list(self.events[after:])

    def view(self) -> tuple[list[dict[str, Any]], list[str], int]:
        """Steps, log lines, and the event number to continue from, taken together so a stream never repeats or skips."""
        with self._cond:
            return [dict(s) for s in self.steps], list(self.lines), len(self.events)

    def request_cancel(self) -> None:
        with self._cond:
            self.cancel_requested = True
        self.line("Stop requested. Fleet finishes the step in progress and stops before the next one.")

    def counts(self) -> tuple[int, int]:
        done = sum(1 for s in self.steps if s["state"] in {"ok", "skipped", "failed"})
        return done, len(self.steps)

    def percent(self) -> int | None:
        done, total = self.counts()
        return round(100 * done / total) if total else None

    def elapsed(self) -> float:
        return round((self.finished or time.time()) - self.started, 1)

    def current(self) -> dict[str, Any] | None:
        return next((s for s in self.steps if s["state"] == "running"), None)

    def snapshot(self) -> dict[str, Any]:
        done, total = self.counts()
        current = self.current()
        return {"state": self.state, "result": self.result, "done": done, "total": total, "percent": self.percent(),
                "current": current["label"] if current else "", "elapsed": self.elapsed(), "events": len(self.events), "cancelling": self.cancel_requested}


class Stopped(Exception):
    """The operator asked a running action to stop at the next step boundary."""


def plan_steps(plan: Any) -> list[dict[str, str]]:
    return [{"id": s.id, "node": s.node, "kind": s.kind, "label": s.label()} for s in plan.steps]


class TerminalBar:
    """One status line on a terminal, plain lines everywhere else. Reads the same events as Fleet web."""

    WIDTH = 24

    def __init__(self, stream: Any, tty: bool) -> None:
        self.stream, self.tty = stream, tty
        self.total = 0
        self.done = 0
        self.label = ""
        self._drawn = False

    def __call__(self, event: dict[str, Any]) -> None:
        kind = event["type"]
        if kind == "plan":
            self.total += len(event["steps"])
        elif kind == "step":
            if event["state"] == "running":
                self.label = event["id"]
            elif event["state"] in {"ok", "skipped", "failed"}:
                self.done += 1
            if not self.tty:
                self.stream.write(f"[{self.done}/{self.total}] {event['id']} {event['state']}\n")
                self.stream.flush()
                return
        elif kind == "line" and self.tty:
            self._clear()
            self.stream.write(event["text"] + "\n")
        if self.tty and self.total:
            self._draw()
        elif kind == "line":
            self.stream.write(event["text"] + "\n")
        self.stream.flush()

    def _bar(self) -> str:
        filled = round(self.WIDTH * self.done / self.total) if self.total else 0
        return "[" + "#" * filled + "-" * (self.WIDTH - filled) + f"] {self.done}/{self.total}"

    def _draw(self) -> None:
        self.stream.write("\r\x1b[K" + f"{self._bar()}  {self.label}"[:110])
        self._drawn = True

    def _clear(self) -> None:
        if self._drawn:
            self.stream.write("\r\x1b[K")

    def close(self) -> None:
        """Leave the finished bar on its own line, so nothing printed next lands on it."""
        if self.tty and self._drawn:
            self.stream.write("\r\x1b[K" + self._bar() + "\n")
            self.stream.flush()
            self._drawn = False


class BarWriter:
    """Stands in for standard output while a bar is drawn: ordinary prints first settle the bar onto its own line."""

    def __init__(self, bar: TerminalBar, real: Any) -> None:
        self.bar, self.real = bar, real

    def write(self, text: str) -> int:
        if text:
            self.bar.close()
        return self.real.write(text)

    def __getattr__(self, name: str) -> Any:
        return getattr(self.real, name)


@contextlib.contextmanager
def graceful_stop(tracker: Tracker) -> Iterator[None]:
    """First Ctrl+C asks the action to stop before its next step; the second one interrupts at once."""
    def handler(_signum: int, _frame: Any) -> None:
        if not tracker.steps or tracker.cancel_requested:
            raise KeyboardInterrupt
        tracker.request_cancel()

    if threading.current_thread() is not threading.main_thread():
        yield
        return
    previous = signal.signal(signal.SIGINT, handler)
    try:
        yield
    finally:
        signal.signal(signal.SIGINT, previous)
