"""The WAF baseline harness, tested against a stub cell and a stub origin: no Docker, no real WAF.

These tests prove the harness measures what it claims to: the corpora are versioned and deterministic, requests are framed as stated,
each request is joined to its own log row, decisions and rates are counted from denominators, and a regression is detected. The real
cell run is ``tests/e2e/waf_baseline.py run``.
"""

import http.server
import json
import pathlib
import sys
import tempfile
import threading
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tests" / "e2e"))

import waf_baseline as baseline  # noqa: E402
import waf_corpus as corpus  # noqa: E402


class StubCell(http.server.BaseHTTPRequestHandler):
    """Answers 403 to anything containing a script tag, like a blocking WAF, and records what it received like the cell's access log."""

    protocol_version = "HTTP/1.1"
    rows: list[dict] = []
    origin: list[dict] = []
    received: list[dict] = []

    def handle_any(self) -> None:
        if "chunked" in (self.headers.get("Transfer-Encoding") or "").lower():
            data = b""
            while True:
                size = int(self.rfile.readline().strip() or b"0", 16)
                if size == 0:
                    self.rfile.readline()
                    break
                data += self.rfile.read(size)
                self.rfile.readline()
        else:
            data = self.rfile.read(int(self.headers.get("Content-Length") or 0))
        client = self.headers.get("X-Forwarded-For", "")
        blocked = b"<script" in (self.path.encode() + data).lower()
        type(self).received.append({"host": self.headers.get("Host"), "client": client, "body": data, "chunked": "chunked" in (self.headers.get("Transfer-Encoding") or "").lower(),
                                    "length_header": self.headers.get("Content-Length"), "connection": self.headers.get("Connection")})
        type(self).rows.append({"hostname": self.headers.get("Host"), "client_ip": client, "waf_action": "block" if blocked else "pass",
                                "attack_score": 1 if blocked else 99, "waf_score": 5 if blocked else 0})
        if not blocked:
            type(self).origin.append({"c": client})
        self.send_response(403 if blocked else 200)
        self.send_header("Content-Length", "0")
        self.end_headers()

    do_GET = do_POST = do_PUT = handle_any

    def log_message(self, *args) -> None:
        pass


class BaselineHarnessTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), StubCell)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        baseline.CELL_PORT = cls.server.server_address[1]

    @classmethod
    def tearDownClass(cls) -> None:
        cls.server.shutdown()
        baseline.CELL_PORT = 8080

    def setUp(self) -> None:
        StubCell.rows, StubCell.origin, StubCell.received = [], [], []

    # corpora

    def test_clean_corpus_is_large_versioned_and_deterministic(self) -> None:
        first = corpus.clean_corpus()
        self.assertGreaterEqual(len(first), corpus.MINIMUM_CLEAN_REQUESTS)
        self.assertEqual(corpus.corpus_digest(first), corpus.corpus_digest(corpus.clean_corpus()))
        self.assertNotEqual(corpus.corpus_digest(first), corpus.corpus_digest(corpus.clean_corpus(seed=1)))
        counts = corpus.clean_class_counts(first)
        self.assertEqual(set(counts), {"browser_page_load", "form_post", "json_api", "file_upload", "mobile_api", "webhook", "crawler"})
        self.assertTrue(all(count >= 500 for count in counts.values()), counts)
        self.assertEqual(len({request.identifier for request in first}), len(first), "identifiers must be unique")

    def test_clean_corpus_uses_the_framings_it_claims(self) -> None:
        requests = corpus.clean_corpus(scale=0.2)
        self.assertTrue(any(request.transport == "chunked" for request in requests))
        self.assertTrue(any(request.method == "POST" and b"multipart/form-data" in dict(request.headers).get("Content-Type", "").encode() for request in requests))
        self.assertTrue(any(request.method == "HEAD" for request in requests))
        self.assertTrue(all(request.corpus == "clean" for request in requests))

    def test_evasion_corpus_states_valid_expectations_and_has_the_chunked_case(self) -> None:
        cases, stats = corpus.evasion_corpus()
        identifiers = [case.identifier for case in cases]
        self.assertEqual(len(identifiers), len(set(identifiers)))
        self.assertIn("evasion:xss-chunked", identifiers)
        self.assertEqual(stats["cases"], len(cases))
        transports = {case.transport for case in cases}
        self.assertTrue({"http1", "chunked", "h2", "h2c"} <= transports, transports)
        for case in cases:
            for profile, expected in case.expect.items():
                self.assertIn(profile, baseline.EXPECTATION_HOSTS)
                self.assertIn(expected, ("block", "detect", "pass", "refuse"))
        sizes = {case.identifier: len(case.body) for case in cases}
        self.assertGreater(sizes["evasion:body-over-inspection-limit"], 1048576)
        self.assertLess(sizes["evasion:body-under-inspection-limit"], 1048576)

    def test_chunk_framing(self) -> None:
        self.assertEqual(corpus.chunk_body(b"abcdef"), b"6\r\nabcdef\r\n0\r\n\r\n")
        self.assertEqual(corpus.chunk_body(b"abcdef", 4), b"4\r\nabcd\r\n2\r\nef\r\n0\r\n\r\n")
        self.assertEqual(corpus.chunk_body(b""), b"0\r\n\r\n")

    def test_request_framing_sets_host_client_and_close(self) -> None:
        request = corpus.CorpusRequest("t", "clean", "x", method="POST", path="/p?a=1", headers=[("Host", "evil"), ("X-Forwarded-For", "9.9.9.9"), ("Content-Type", "text/plain")], body=b"hi")
        wire = corpus.with_host_and_client(request, "a.test", "10.0.0.1").decode()
        self.assertIn("Host: a.test\r\n", wire)
        self.assertNotIn("evil", wire)
        self.assertNotIn("9.9.9.9", wire)
        self.assertIn("X-Forwarded-For: 10.0.0.1\r\n", wire)
        self.assertIn("Connection: close\r\n", wire)
        self.assertIn("Content-Length: 2\r\n", wire)
        chunked = corpus.CorpusRequest("t", "clean", "x", method="POST", headers=[("Content-Length", "99")], body=b"hi", transport="chunked")
        wire = corpus.with_host_and_client(chunked, "a.test", "10.0.0.1").decode()
        self.assertNotIn("Content-Length", wire)
        self.assertIn("Transfer-Encoding: chunked\r\n", wire)
        self.assertTrue(wire.endswith("2\r\nhi\r\n0\r\n\r\n"))

    def test_raw_crs_request_gets_our_host_and_client(self) -> None:
        raw = b"GET /x HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-For: 1.2.3.4\r\nConnection: keep-alive\r\nUser-Agent: t\r\n\r\n"
        wire = corpus.with_host_and_client(corpus.CorpusRequest("t", "crs", "x", transport="raw", raw=raw), "a.test", "10.0.0.2")
        text = wire.decode()
        self.assertEqual(text.count("Host:"), 1)
        self.assertIn("Host: a.test", text)
        self.assertNotIn("1.2.3.4", text)
        self.assertEqual(text.count("Connection:"), 1)
        self.assertIn("Connection: close", text)

    def test_crs_stages_are_counted_with_paranoia_floors_and_skips(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            crs = pathlib.Path(directory)
            (crs / "rules").mkdir()
            (crs / "rules" / "REQUEST-941.conf").write_text(
                "SecRule ARGS \"@rx a\" \\\n    \"id:941100,\\\n    phase:2,\\\n    tag:'paranoia-level/1'\"\n\n"
                "SecRule ARGS \"@rx b\" \\\n    \"id:941200,\\\n    phase:2,\\\n    tag:'paranoia-level/3'\"\n")
            tests = crs / "tests" / "regression" / "tests" / "REQUEST-941"
            tests.mkdir(parents=True)
            (tests / "941100.yaml").write_text(
                "rule_id: 941100\ntests:\n"
                "  - test_id: 1\n    stages:\n      - input: {method: GET, uri: '/?q=<script>', headers: {Host: localhost}}\n        output: {log: {expect_ids: [941100]}}\n"
                "  - test_id: 2\n    stages:\n      - input: {method: POST, uri: /, data: 'q=x', headers: {Content-Type: text/plain}}\n        output: {log: {expect_ids: [941200]}}\n"
                "      - input: {method: GET, uri: /ok}\n        output: {log: {no_expect_ids: [941100]}}\n"
                "  - test_id: 3\n    stages:\n      - input: {method: GET, uri: /r}\n        output: {log: {expect_ids: [951100]}}\n"
                "  - test_id: 4\n    stages:\n      - input: {method: GET, uri: /e}\n        output: {expect_error: true}\n")
            requests, stats = corpus.crs_regression_requests(crs)
        self.assertEqual(stats["stages"], 5)
        self.assertEqual(stats["replayed"], 3)
        self.assertEqual(sum(value for key, value in stats.items() if key.startswith("skipped")), 2)
        by_id = {request.identifier: request for request in requests}
        self.assertEqual(by_id["crs:941100-1-1"].paranoia_floor, 1)
        self.assertEqual(by_id["crs:941100-2-1"].paranoia_floor, 3)
        self.assertEqual(by_id["crs:941100-2-2"].category, "negative")
        self.assertEqual(by_id["crs:941100-2-1"].body, b"q=x")

    # measurement

    def test_wilson_interval_brackets_the_rate_and_widens_with_small_samples(self) -> None:
        low, high = baseline.wilson(10, 100)
        self.assertLess(low, 0.1)
        self.assertGreater(high, 0.1)
        small = baseline.wilson(1, 10)
        self.assertGreater(small[1] - small[0], high - low)
        self.assertEqual(baseline.wilson(0, 0), [0.0, 1.0])
        self.assertEqual(baseline.wilson(0, 10000)[0], 0.0)

    def test_decisions(self) -> None:
        self.assertEqual(baseline.decide(403, {"waf_action": "block"}, False), "blocked")
        self.assertEqual(baseline.decide(200, {"waf_action": "pass", "attack_score": 99}, True), "passed")
        self.assertEqual(baseline.decide(200, {"waf_action": "monitor", "waf_score": 8, "attack_score": 60}, True), "logged")
        self.assertEqual(baseline.decide(200, {"waf_action": "pass", "attack_score": 60}, True), "logged")
        self.assertEqual(baseline.decide(413, {"waf_action": "inspect", "security_reason": "body_too_large"}, False), "refused")
        self.assertEqual(baseline.decide(0, None, False), "error")
        self.assertEqual(baseline.decide(200, None, True), "unlogged")

    def test_a_block_that_still_reached_the_origin_is_not_a_block(self) -> None:
        self.assertEqual(baseline.decide(403, {"waf_action": "block"}, True), "passed")

    def test_client_addresses_are_unique_and_cover_both_families(self) -> None:
        addresses = baseline.ClientAddresses()
        drawn = [addresses.next() for _ in range(5000)]
        self.assertEqual(len(set(drawn)), 5000)
        self.assertTrue(any(":" in address for address in drawn) and any("." in address for address in drawn))

    # replay against the stub cell

    def test_replay_joins_each_request_to_its_own_row_and_counts_blocks(self) -> None:
        requests = [
            corpus.CorpusRequest("a", "evasion", "xss", path="/?q=%3Cscript%3E".replace("%3C", "<"), headers=[("User-Agent", "t")]),
            corpus.CorpusRequest("b", "clean", "page", path="/about", headers=[("User-Agent", "t")]),
            corpus.CorpusRequest("c", "evasion", "xss", method="POST", body=b"q=<script>", headers=[("Content-Type", "text/plain")], transport="chunked"),
            corpus.CorpusRequest("d", "clean", "form", method="POST", body=b"name=alice", headers=[("Content-Type", "text/plain")], transport="chunked"),
        ]
        results = baseline.replay("127.0.0.1", "stub.test", requests, baseline.ClientAddresses(), 4, "now",
                                  cell_log=lambda _: StubCell.rows, origin_log=lambda _: StubCell.origin, settle=0)
        decisions = {item["id"]: item["decision"] for item in results}
        self.assertEqual(decisions, {"a": "blocked", "b": "passed", "c": "blocked", "d": "passed"})
        self.assertEqual(len({item["client"] for item in results}), 4)
        chunked = [row for row in StubCell.received if row["chunked"]]
        self.assertEqual(len(chunked), 2)
        self.assertTrue(all(row["length_header"] is None and row["connection"] == "close" and row["host"] == "stub.test" for row in chunked))
        self.assertEqual(sorted(row["body"] for row in chunked), [b"name=alice", b"q=<script>"])

    def test_a_request_with_no_log_row_is_counted_as_unanswered_not_passed(self) -> None:
        requests = [corpus.CorpusRequest("a", "clean", "page", path="/about", headers=[("User-Agent", "t")])]
        results = baseline.replay("127.0.0.1", "stub.test", requests, baseline.ClientAddresses(), 1, "now",
                                  cell_log=lambda _: [], origin_log=lambda _: [], settle=0)
        self.assertEqual(results[0]["decision"], "unlogged")
        self.assertEqual(baseline.rates(results)["unanswered"], 1)

    def test_harness_failure_to_reach_the_cell_is_an_error_not_a_pass(self) -> None:
        saved = baseline.CELL_PORT
        baseline.CELL_PORT = 1  # nothing listens here
        try:
            results = baseline.replay("127.0.0.1", "stub.test", [corpus.CorpusRequest("a", "clean", "page")], baseline.ClientAddresses(), 1, "now",
                                      cell_log=lambda _: [], origin_log=lambda _: [], settle=0)
        finally:
            baseline.CELL_PORT = saved
        self.assertEqual(results[0]["decision"], "error")

    def test_rates_report_denominators_and_intervals(self) -> None:
        results = [{"decision": "blocked"}] * 3 + [{"decision": "logged"}] + [{"decision": "passed"}] * 6
        summary = baseline.rates(results)
        self.assertEqual(summary["requests"], 10)
        self.assertEqual(summary["detection_rate"], 0.4)
        self.assertEqual(summary["block_rate"], 0.3)
        self.assertEqual(len(summary["detection_rate_ci95"]), 2)

    # comparison and expectations

    def test_compare_flags_lost_detection_and_new_false_positives_only(self) -> None:
        def record(detection: float, false_positives: float, unanswered: int = 0) -> dict:
            return {"hosts": {"balanced-pl1": {"crs_attacks_in_scope": {"detection_rate": detection}, "clean_false_positive_rate": false_positives,
                                               "clean": {"unanswered": unanswered}}}}
        self.assertEqual(baseline.compare(record(0.8, 0.001), record(0.8, 0.001), 0.005), [])
        self.assertEqual(baseline.compare(record(0.8, 0.001), record(0.85, 0.0), 0.005), [])
        self.assertEqual(len(baseline.compare(record(0.8, 0.001), record(0.7, 0.001), 0.005)), 1)
        self.assertEqual(len(baseline.compare(record(0.8, 0.001), record(0.8, 0.02), 0.005)), 1)
        self.assertEqual(len(baseline.compare(record(0.8, 0.001), record(0.8, 0.001, 3), 0.005)), 1)

    def test_expectations_are_checked_on_the_named_hosts(self) -> None:
        case = corpus.CorpusRequest("evasion:x", "evasion", "xss", expect={"balanced": "block", "strict": "pass"})
        outcome = baseline.check_expectations([case], {"balanced-pl1": [{"id": "evasion:x", "decision": "blocked", "status": 403}],
                                                       "strict-pl2": [{"id": "evasion:x", "decision": "blocked", "status": 403}]})[0]
        self.assertTrue(outcome["expectations"]["balanced"]["met"])
        self.assertFalse(outcome["expectations"]["strict"]["met"])

    def test_host_matrix_covers_every_profile_and_paranoia_level(self) -> None:
        names = [item["name"] for item in baseline.host_matrix()]
        self.assertEqual(names[0], "off")
        for profile in ("monitor", "balanced", "strict"):
            for level in (1, 2, 3, 4):
                self.assertIn(f"{profile}-pl{level}", names)
        self.assertEqual(len({item["hostname"] for item in baseline.host_matrix()}), len(names))
        self.assertEqual(len({item["domain_id"] for item in baseline.host_matrix()}), len(names))

    def test_snapshot_trusts_forwarded_addresses_and_lifts_domain_limits_only_for_the_test_cell(self) -> None:
        item = baseline.host_matrix(["balanced-pl3"])[0]
        host = baseline.snapshot_host(item, "192.168.251.2")
        self.assertEqual(host["waf"]["paranoia_level"], 3)
        self.assertTrue(host["waf"]["blocking"])
        self.assertEqual(baseline.snapshot_host(baseline.host_matrix(["off"])[0], "192.168.251.2")["waf"]["paranoia_level"], 0)
        self.assertEqual(json.loads(json.dumps(host))["security"]["trusted_proxy_cidrs"], ["0.0.0.0/0", "::/0"])


if __name__ == "__main__":
    unittest.main()
