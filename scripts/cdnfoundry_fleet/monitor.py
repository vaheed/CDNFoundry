"""The live view of the fleet: a bounded, read-only probe of every host on a timer.

Samples live in memory only. The monitor skips a round while an action holds the write lock, so it never adds
SSH load during a deployment, and it never writes the vault. One round is at most one short command per host.
"""
from __future__ import annotations

import threading
import time
from collections import deque
from typing import Any, Callable

from . import inventory as inv

HISTORY = 60
MIN_INTERVAL = 15


def summarize(sample: dict[str, Any]) -> dict[str, Any]:
    """The few numbers the screens show for one probe result."""
    if not sample.get("ok"):
        return {"state": "offline", "error": sample.get("error", "")}
    i = sample["inventory"]
    containers = i.get("containers", [])
    disk = inv.worst_disk(i)
    return {
        "state": "degraded" if any(c["state"] != "running" or c["healthy"] is False for c in containers) else "online",
        "load": inv.load_per_core(i), "memory": i.get("mem_used_pct"), "disk": disk["used_pct"] if disk else None, "uptime_s": i.get("uptime_s"),
        "containers": {"total": len(containers), "running": sum(1 for c in containers if c["state"] == "running"),
                       "unhealthy": sum(1 for c in containers if c["state"] != "running" or c["healthy"] is False)},
        "cores": i.get("cpu_cores"), "memory_mb": i.get("mem_total_mb"),
    }


class LiveHealth:
    def __init__(self, probe: Callable[[], dict[str, dict[str, Any]]], busy: Callable[[], bool], *, interval: int = 60) -> None:
        self.probe, self.busy = probe, busy
        self.interval = max(MIN_INTERVAL, interval) if interval else 0
        self.latest: dict[str, dict[str, Any]] = {}
        self.history: dict[str, deque[dict[str, Any]]] = {}
        self.inventory: dict[str, dict[str, Any]] = {}
        self.updated: float | None = None
        self.error = ""
        self._stop = threading.Event()
        self._round = threading.Lock()
        self._thread: threading.Thread | None = None

    def start(self) -> None:
        if self.interval and self._thread is None:
            self._thread = threading.Thread(target=self._loop, daemon=True, name="fleet-live-health")
            self._thread.start()

    def stop(self) -> None:
        self._stop.set()

    def _loop(self) -> None:
        while not self._stop.is_set():
            if not self.busy():
                self.refresh()
            self._stop.wait(self.interval)

    def refresh(self) -> bool:
        """Probe every host now. Returns False when a round is already running."""
        if not self._round.acquire(blocking=False):
            return False
        try:
            try:
                results = self.probe()
                self.error = ""
            except Exception as exc:  # the vault may be locked or busy; the next round tries again
                self.error = str(exc)[:200]
                return True
            now = time.time()
            for name, sample in results.items():
                summary = summarize(sample)
                self.latest[name] = {**summary, "at": now}
                if sample.get("ok"):
                    self.inventory[name] = sample["inventory"]
                self.history.setdefault(name, deque(maxlen=HISTORY)).append({"t": round(now), "state": summary["state"], "load": summary.get("load"), "memory": summary.get("memory"), "disk": summary.get("disk")})
            for gone in set(self.latest) - set(results):
                self.latest.pop(gone, None)
                self.history.pop(gone, None)
            self.updated = now
            return True
        finally:
            self._round.release()

    def snapshot(self) -> dict[str, Any]:
        return {"updated": self.updated, "interval": self.interval, "error": self.error, "nodes": {n: {**v, "history": list(self.history.get(n, []))} for n, v in self.latest.items()}}
