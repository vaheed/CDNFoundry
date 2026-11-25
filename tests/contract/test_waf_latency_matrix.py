"""The WAF latency matrix and the benchmark options it drives, tested without Docker."""

import pathlib
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tests" / "e2e"))

import edge_cell_benchmark as benchmark  # noqa: E402
import waf_latency_matrix as matrix  # noqa: E402


class LatencyMatrixTest(unittest.TestCase):
    ARGS = type("Args", (), {"image": "i", "cpus": 1.0, "connections": 32, "duration": 20, "repeat": 3, "warmup": 5, "mmdb_volume": None})()

    def test_matrix_covers_every_profile_body_rule_count_and_detection(self) -> None:
        names = {case["name"] for case in matrix.matrix()}
        for waf in ("off", "monitor", "balanced", "strict"):
            for size in (0, 4096, 65536, 1048576):
                self.assertIn(f"{waf}-body-{size}", names)
        for name in ("balanced-log-only-10", "balanced-custom-rules-100", "balanced-custom-rules-1000", "balanced-rate-limit-rules-20",
                     "off-credential-check", "off-sensitive-data", "off-ai-protection"):
            self.assertIn(name, names)
        self.assertEqual(len(names), len(matrix.matrix()))

    def test_post_bodies_count_the_form_prefix_so_one_mib_is_exactly_one_mib(self) -> None:
        case = next(case for case in matrix.matrix() if case["name"] == "balanced-body-1048576")
        arguments = matrix.command(case, self.ARGS)
        self.assertEqual(int(arguments[arguments.index("--body-bytes") + 1]) + len("field="), 1048576)
        self.assertIn("--no-cache", arguments)
        self.assertIn("POST", arguments)

    def test_a_zero_body_is_a_cached_get(self) -> None:
        arguments = matrix.command(next(case for case in matrix.matrix() if case["name"] == "strict-body-0"), self.ARGS)
        self.assertNotIn("--no-cache", arguments)
        self.assertNotIn("--body-bytes", arguments)

    def test_benchmark_builds_the_requested_rule_counts(self) -> None:
        args = type("A", (), {"security_rules": 100, "rate_limit_rules": 20, "detection": "sensitive_data", "waf": "balanced", "log_only_rules": 10})()
        extra = benchmark.extra_security(args)
        self.assertEqual(len(extra["rules"]), 120)
        self.assertEqual(sum(1 for rule in extra["rules"] if "rate_limit" in rule), 20)
        self.assertEqual(len({rule["id"] for rule in extra["rules"]}), 120)
        self.assertIn("sensitive_data", extra)
        profile = benchmark.waf_profile(args)
        self.assertEqual(len(profile["log_only_rules"]), 10)
        self.assertFalse(benchmark.WAF_PROFILES["monitor"]["blocking"])
        self.assertEqual(benchmark.WAF_PROFILES["balanced"]["log_only_rules"], [], "the shared profile must not be mutated")

    def test_summary_takes_the_median_and_keeps_every_run(self) -> None:
        runs = [{"rps": 100, "p50_ms": 1, "p95_ms": 2, "p99_ms": 3, "cell_cpu_us_per_request": value, "cell_cpu_cores": 1, "cell_memory_peak_mib": 50,
                 "errors": 0, "status_errors": 0, "cell_throttled_periods": 0, "requests": 1000} for value in (3000, 2000, 9000)]
        summary = matrix.summarise(runs)
        self.assertEqual(summary["cpu_us_per_request"], 3000)
        self.assertEqual(summary["cpu_us_per_request_runs"], [3000, 2000, 9000])
        self.assertEqual(summary["request_count"], 3000)


if __name__ == "__main__":
    unittest.main()
