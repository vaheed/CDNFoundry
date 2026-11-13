"""The development seed keeps its counters monotonic when a scenario ends, so no dashboard shows a reset spike."""
from __future__ import annotations

import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "scripts" / "observability"))

import seed_exporter  # noqa: E402
import seed_model as model  # noqa: E402


class ScenarioSwitchTest(unittest.TestCase):
    def test_returning_to_normal_never_lowers_a_counter(self) -> None:
        start = 1_800_000_000.0
        active: dict[str, tuple[float, float]] = {}
        seed_exporter.switch_scenario(active, "l7-flood", {"start": start, "minutes": 40}, start)
        seed_exporter.switch_scenario(active, "origin-down", {"start": start, "minutes": 40}, start)
        during = start + 20 * 60
        before = {(pop["pop"], status, cache): model.requests_total(pop, status, cache, during, model.Scenarios(dict(active)))
                  for pop in model.POPS for status in model.STATUS for cache in model.CACHE}

        seed_exporter.switch_scenario(active, "normal", {}, during)

        after = model.Scenarios(dict(active))
        for (pop_name, status, cache), value in before.items():
            pop = next(pop for pop in model.POPS if pop["pop"] == pop_name)
            self.assertGreaterEqual(model.requests_total(pop, status, cache, during + 60, after), value, (pop_name, status, cache))
        self.assertFalse(after.on("l7-flood", during + 60))

    def test_normal_keeps_a_finished_scenario_as_it_was(self) -> None:
        active = {"l7-flood": (100.0, 200.0)}
        seed_exporter.switch_scenario(active, "normal", {}, 500.0)
        self.assertEqual(active, {"l7-flood": (100.0, 200.0)})


if __name__ == "__main__":
    unittest.main()
