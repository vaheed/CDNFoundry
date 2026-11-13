#!/usr/bin/env python3
"""Static contract for Prometheus rules, Alertmanager routing, scrape coverage, label cardinality, and retention."""

from __future__ import annotations

import json
import pathlib
import re
import subprocess
import sys
import unittest

import yaml

ROOT = pathlib.Path(__file__).resolve().parents[2]
PROMETHEUS = ROOT / "docker/prometheus"
GRAFANA = ROOT / "docker/grafana"
sys.path.insert(0, str(GRAFANA))
sys.path.insert(0, str(ROOT / "scripts/observability"))

import build_slo_rules  # noqa: E402
from dashboard_lib import FOLDERS  # noqa: E402

TEAMS = {"noc", "soc", "platform"}
SEVERITIES = {"none", "info", "warning", "critical"}
DOCS = "https://vaheed.github.io/CDNFoundry/operations/runbooks#"
RULE_FILES = ["telemetry-alerts", "control-ha-alerts", "traffic-alerts", "security-alerts", "slo-rules"]
RUNBOOKS = (ROOT / "docs/operations/runbooks.md").read_text()
ANCHORS = set(re.findall(r"\{#([a-z0-9-]+)\}", RUNBOOKS)) | {
    re.sub(r"[^a-z0-9]+", "-", heading.lower()).strip("-") for heading in re.findall(r"^#{2,3} (.+?)(?: \{#.*)?$", RUNBOOKS, re.M)
}


def dashboard_uids() -> set[str]:
    return {json.loads(path.read_text())["uid"] for path in (GRAFANA / "dashboards").rglob("*.json")}


def prometheus_alerts() -> list[dict]:
    alerts = []
    for name in RULE_FILES:
        for group in yaml.safe_load((PROMETHEUS / f"{name}.yml").read_text())["groups"]:
            alerts += [rule for rule in group["rules"] if "alert" in rule]
    return alerts


def grafana_alerts() -> list[dict]:
    document = yaml.safe_load((GRAFANA / "provisioning/alerting/rules.yml").read_text())
    return [rule for group in document["groups"] for rule in group["rules"]]


def compose(*arguments: str) -> dict:
    completed = subprocess.run(["docker", "compose", *arguments, "config", "--format", "json"], cwd=ROOT, check=True, capture_output=True, text=True)
    return json.loads(completed.stdout)


class AlertContractTest(unittest.TestCase):
    def test_every_alert_carries_team_severity_summary_runbook_and_dashboard(self) -> None:
        uids = dashboard_uids()
        for rule in prometheus_alerts():
            name = rule["alert"]
            self.assertIn(rule["labels"].get("team"), TEAMS, name)
            self.assertIn(rule["labels"].get("severity"), SEVERITIES, name)
            self.assertTrue(rule["annotations"].get("summary"), name)
            runbook = rule["annotations"].get("runbook_url", "")
            self.assertTrue(runbook.startswith(DOCS), name)
            self.assertIn(runbook[len(DOCS):], ANCHORS, f"{name}: the runbook section does not exist")
            dashboard = rule["annotations"].get("dashboard_url", "")
            self.assertRegex(dashboard, r"^/d/[a-z0-9-]+", name)
            uid = dashboard[3:].split("?")[0]
            self.assertIn(uid, uids, name)
            self.assertEqual(uid, rule["labels"].get("dashboard"), f"{name}: the dashboard label opens it from a firing alert")

    def test_grafana_alert_rules_carry_the_same_labels_and_reach_a_runbook(self) -> None:
        uids = dashboard_uids()
        rules = grafana_alerts()
        self.assertGreaterEqual(len(rules), 4)
        for rule in rules:
            self.assertIn(rule["labels"]["team"], TEAMS, rule["title"])
            self.assertIn(rule["labels"]["severity"], SEVERITIES, rule["title"])
            self.assertTrue(rule["annotations"]["summary"], rule["title"])
            self.assertIn(rule["annotations"]["runbook_url"][len(DOCS):], ANCHORS, rule["title"])
            self.assertIn(rule["annotations"]["dashboard_url"][3:], uids, rule["title"])
            self.assertEqual("clickhouse", rule["data"][0]["datasourceUid"])
        policy = yaml.safe_load((GRAFANA / "provisioning/alerting/contact-points.yml").read_text())
        self.assertEqual("prometheus-alertmanager", policy["contactPoints"][0]["receivers"][0]["type"])
        self.assertEqual("cdnfoundry-alertmanager", policy["policies"][0]["receiver"])

    def test_alert_names_are_unique(self) -> None:
        names = [rule["alert"] for rule in prometheus_alerts()] + [rule["title"] for rule in grafana_alerts()]
        self.assertEqual(len(names), len(set(names)))

    def test_the_failed_operations_alert_reads_the_windowed_gauge(self) -> None:
        rule = next(rule for rule in prometheus_alerts() if rule["alert"] == "CDNFoundryFailedOperations")
        self.assertIn("cdnfoundry_operations_failed_recent", rule["expr"])
        self.assertNotRegex(rule["expr"], r"cdnfoundry_operations_failed\b(?!_)")

    def test_every_alert_has_a_prometheus_unit_test_or_is_a_burn_rate_alert(self) -> None:
        tested = {case["alertname"] for suite in yaml.safe_load((PROMETHEUS / "alerts.test.yml").read_text())["tests"] for case in suite.get("alert_rule_test", [])}
        for required in ("CDNFoundryFailedOperations", "EdgeGlobal5xxRatioHigh", "PopTrafficDropped", "SLOBurnEdge5xxFast", "EmergencyModeEnabled"):
            self.assertIn(required, tested)

    def test_slo_rules_are_the_generated_file(self) -> None:
        self.assertEqual(build_slo_rules.render(), build_slo_rules.OUTPUT.read_text())
        slos = {rule["labels"]["slo"] for rule in prometheus_alerts() if "slo" in rule["labels"]}
        self.assertEqual(7, len(slos))
        recorded = {rule["record"] for group in yaml.safe_load(build_slo_rules.OUTPUT.read_text())["groups"] for rule in group["rules"] if "record" in rule}
        for slo in slos:
            self.assertLessEqual({f"slo:{slo}:bad_ratio_rate{window}" for window in ("5m", "30m", "1h", "6h")}, recorded)


class VectorMetricNamesContractTest(unittest.TestCase):
    """The names Vector exports are the names the recording rules and dashboards read."""

    VECTOR = yaml.safe_load((ROOT / "docker/vector/vector.yaml").read_text())

    def test_the_exporter_sets_no_empty_namespace(self) -> None:
        # Vector 0.58 turns an empty default namespace into a leading underscore (_cdnf_...), which no rule matches.
        sink = self.VECTOR["sinks"]["prometheus"]
        self.assertNotIn("default_namespace", sink)

    def test_every_cdnf_series_the_recording_rules_read_is_exported_by_vector(self) -> None:
        exported = {
            metric["name"]
            for transform in self.VECTOR["transforms"].values() if transform.get("type") == "log_to_metric"
            for metric in transform["metrics"]
        }
        rules = (PROMETHEUS / "dashboard-rules.yml").read_text()
        read = set(re.findall(r"\b(cdnf_[a-z_]+?)(?:_bucket)?\b(?=\[|\{|\))", rules))
        self.assertTrue(read, "the recording rules read no cdnf_ series")
        for name in sorted(read):
            self.assertIn(name, exported, f"{name} is read by a recording rule but no log_to_metric produces it")
        for name in exported:
            self.assertTrue(name.startswith("cdnf_") and not name.startswith("_"), name)


    def test_the_dns_probe_name_is_dropped_from_customer_dns_telemetry(self) -> None:
        blackbox = yaml.safe_load((PROMETHEUS / "blackbox.yml").read_text())
        names = {
            module["dns"]["query_name"]
            for module in blackbox["modules"].values() if module.get("prober") == "dns"
        }
        self.assertTrue(names, "no DNS probe module found")
        condition = self.VECTOR["transforms"]["customer_dns_events"]["condition"]
        for name in names:
            self.assertIn(name, condition, f"the probe name {name} must be filtered out of customer DNS events")
        for consumer in ("dns_metric_events", "clickhouse_dns"):
            section = self.VECTOR["transforms"].get(consumer) or self.VECTOR["sinks"][consumer]
            self.assertEqual(["customer_dns_events"], section["inputs"], consumer)


class RoutingContractTest(unittest.TestCase):
    def amtool(self, *arguments: str) -> str:
        completed = subprocess.run(
            ["docker", "run", "--rm", "--entrypoint", "/bin/amtool", "-v", f"{ROOT / 'docker/alertmanager'}:/a:ro",
             "prom/alertmanager:v0.34.1@sha256:e9733bafb1bdef9b00e25a21f8f99dc26a22224bf16641ad754d1649f4c3357a", *arguments],
            check=True, capture_output=True, text=True)
        return completed.stdout.strip()

    def test_alertmanager_routes_every_team_to_its_receiver(self) -> None:
        self.amtool("check-config", "/a/alertmanager.yml")
        for team in TEAMS:
            self.assertEqual(team, self.amtool("config", "routes", "test", "--config.file=/a/alertmanager.yml", f"team={team}", "alertname=Example"))
        self.assertEqual("platform", self.amtool("config", "routes", "test", "--config.file=/a/alertmanager.yml", "alertname=Watchdog"))

    def test_inhibition_rules_name_alerts_that_exist(self) -> None:
        names = {rule["alert"] for rule in prometheus_alerts()} | {rule["title"] for rule in grafana_alerts()}
        document = yaml.safe_load((ROOT / "docker/alertmanager/alertmanager.yml").read_text())
        for rule in document["inhibit_rules"]:
            for matcher in rule["source_matchers"] + rule["target_matchers"]:
                pattern = re.search(r'alertname =~ "(.+)"', matcher)
                if not pattern:
                    continue
                for part in pattern.group(1).split("|"):
                    if ".*" in part:
                        self.assertTrue(any(re.fullmatch(part, name) for name in names), part)
                    else:
                        self.assertIn(part, names, f"inhibition names an alert that does not exist: {part}")
        pop_down = {rule["source_matchers"][0] for rule in document["inhibit_rules"] if rule.get("equal") == ["pop"]}
        self.assertTrue(pop_down, "a PoP that is down silences that PoP's per-domain alerts")


class ScrapeContractTest(unittest.TestCase):
    def test_every_compose_service_is_scraped_or_exempt_with_a_reason(self) -> None:
        coverage = yaml.safe_load((PROMETHEUS / "scrape-coverage.yml").read_text())
        production = compose("--env-file", ".env.prod.example", "-f", "compose.prod.yml", "--profile", "telemetry", "--profile", "control", "--profile", "dns", "--profile", "edge", "--profile", "logs")
        services = set(production["services"])
        scraped, exempt = set(coverage["scraped"]), set(coverage["exempt"])
        self.assertEqual(set(), services - scraped - exempt, "a service has neither a scrape job nor an exemption")
        self.assertEqual(set(), (scraped | exempt) - services, "coverage names a service that no longer exists")
        self.assertEqual(set(), scraped & exempt)
        jobs = {job["job_name"] for job in yaml.safe_load((PROMETHEUS / "prometheus.yml").read_text())["scrape_configs"]}
        self.assertLessEqual(set(coverage["scraped"].values()), jobs)
        for name, entry in coverage["exempt"].items():
            self.assertGreater(len(entry["reason"]), 20, name)

    def test_prometheus_keeps_the_documented_retention_and_probes_use_both_ip_versions(self) -> None:
        platform = (ROOT / "core/config/platform.php").read_text()
        days = int(re.search(r"'prometheus_retention_days'.*?'default' => (\d+)", platform).group(1))
        for document in (compose("-f", "compose.dev.yml"), compose("--env-file", ".env.prod.example", "-f", "compose.prod.yml", "--profile", "telemetry")):
            self.assertIn(f"--storage.tsdb.retention.time={days}d", document["services"]["prometheus"]["command"])
        probes = yaml.safe_load((PROMETHEUS / "probe-targets.dev.yml").read_text())
        self.assertTrue({"http", "tcp", "dns_udp", "dns_tcp"} <= {group["labels"]["probe"] for group in probes})
        render = (ROOT / "scripts/cdnfoundry_fleet/render.py").read_text()
        self.assertIn('("6", node.get("public_ipv6"))', render)


class CardinalityContractTest(unittest.TestCase):
    """Live counters carry only the PoP, region, edge, and fixed-value classes. This is the budget."""

    ALLOWED = {
        "pop": 200, "region": 50, "edge": 400, "status_class": 6, "cache_status": 8, "class": 6, "reason": 8, "rcode": 7, "outcome": 2,
    }
    # Metrics that carry a PoP's hosts: label sets multiply, so each metric's worst case is checked against one budget.
    BUDGET_PER_METRIC = 200_000

    def tags(self) -> dict[str, dict]:
        document = yaml.safe_load((ROOT / "docker/vector/vector.yaml").read_text())
        metrics = {}
        for name, transform in document["transforms"].items():
            if transform.get("type") == "log_to_metric":
                for metric in transform["metrics"]:
                    metrics[metric["name"]] = metric["tags"]
        return metrics

    def test_every_label_is_bounded_and_the_worst_case_fits_the_budget(self) -> None:
        metrics = self.tags()
        self.assertGreaterEqual(len(metrics), 8)
        for name, tags in metrics.items():
            self.assertLessEqual(set(tags), set(self.ALLOWED), f"{name} uses an unbounded label")
            # A PoP has one region, and an edge belongs to one PoP, so those three multiply as the PoP count.
            worst = self.ALLOWED["edge"]
            for label in set(tags) - {"pop", "region", "edge"}:
                worst *= self.ALLOWED[label]
            self.assertLessEqual(worst, self.BUDGET_PER_METRIC, name)

    def test_no_label_is_a_domain_client_path_or_request(self) -> None:
        for name, tags in self.tags().items():
            self.assertTrue(set(tags).isdisjoint({"domain", "domain_id", "hostname", "client_ip", "path", "request_id", "asn", "country"}), name)

    def test_control_plane_route_label_is_capped(self) -> None:
        source = (ROOT / "core/app/Support/ControlPlaneMetrics.php").read_text()
        self.assertRegex(source, r"ROUTE_LIMIT = \d+")


class SecurityClassContractTest(unittest.TestCase):
    """Security events use a fixed class set, and every reason a cell can report maps into it."""

    CLASSES = {"waf", "block", "cache_abuse_detected", "emergency_mode", "dns_response_limit", "waf_body_limit"}

    def test_vector_assigns_only_the_fixed_classes(self) -> None:
        source = (ROOT / "docker/vector/vector.yaml").read_text()
        assigned = set(re.findall(r'\.security_class = "([a-z_]+)"', source)) - {""}
        self.assertEqual(self.CLASSES, assigned)

    def test_every_reason_the_edge_can_report_is_classified_or_a_plain_block(self) -> None:
        lua = (ROOT / "docker/openresty/runtime.lua").read_text()
        reasons = set(re.findall(r'security_reject\(\d+, "([a-z_]+)"', lua)) | set(re.findall(r'cdn_security_reason = "([a-z_]+)"', lua))
        self.assertTrue(reasons)
        known = {
            "body_too_large", "client_connections_exceeded", "client_rate_exceeded", "domain_connections_exceeded", "domain_quarantined", "domain_rate_exceeded",
            "domain_restricted", "edge_emergency_mode", "header_too_large", "invalid_method", "malformed_request_body", "origin_capacity_exceeded",
            "origin_circuit_open", "sni_host_mismatch", "unknown_host", "waf_body_limit", "waf_block", "cache_abuse_detected",
            # The incident profile's refusals are plain blocks.
            "incident_method", "incident_body_size", "challenge_required", "challenge_invalid",
            # A domain's own custom rule blocking a request is a plain block.
            "custom_rule_block", "custom_rule_rate_limited",
        }
        self.assertEqual(set(), reasons - known, "a new security reason must be classified here and in Vector before it ships")

    def test_tls_failure_reasons_are_a_fixed_set(self) -> None:
        lua = (ROOT / "docker/openresty/runtime.lua").read_text()
        table = re.search(r"tls_failure_reasons = \{(.*?)\}", lua, re.S).group(1)
        declared = set(re.findall(r"([a-z_]+) = true", table))
        used = set(re.findall(r'report_tls_failure\("([a-z_]+)"', lua))
        self.assertEqual(used, declared)
        self.assertLessEqual(len(declared), 8, "keep the TLS reason label under the cardinality budget")


class RetentionContractTest(unittest.TestCase):
    """The retention tiers in the documentation, the typed settings, and the schema agree."""

    def default(self, group: str, key: str) -> int:
        platform = (ROOT / "core/config/platform.php").read_text()
        block = platform.split(f"'{group}' => [", 1)[1]
        return int(re.search(rf"'{key}'.*?'default' => (\d+)", block).group(1))

    def test_clickhouse_ttls_match_the_typed_defaults(self) -> None:
        schema = (ROOT / "docker/clickhouse/init.sql").read_text()
        def ttl(table: str) -> str:
            return re.search(rf"cdnf\.{table}\b.*?TTL \w+(?:\(\w+\))? \+ INTERVAL (\d+ \w+)", schema, re.S).group(1)
        self.assertEqual(f"{self.default('telemetry', 'raw_retention_days')} DAY", ttl("edge_events"))
        self.assertEqual(f"{self.default('telemetry', 'one_minute_retention_hours')} HOUR", ttl("edge_traffic_1m"))
        self.assertEqual(f"{self.default('telemetry', 'hourly_retention_days')} DAY", ttl("edge_traffic_hourly"))
        self.assertEqual("3 YEAR", ttl("edge_traffic_daily"))
        self.assertEqual(1095, self.default("telemetry", "daily_retention_days"))

    def test_loki_and_prometheus_retention_match_the_typed_defaults(self) -> None:
        production = compose("--env-file", ".env.prod.example", "-f", "compose.prod.yml", "--profile", "telemetry")
        self.assertEqual(f"{self.default('telemetry', 'loki_retention_days') * 24}h", production["services"]["loki"]["environment"]["LOKI_RETENTION_PERIOD"])

    def test_the_retention_table_is_documented(self) -> None:
        text = (ROOT / "docs/reference/platform-settings.md").read_text()
        for tier in ("Raw events", "1-minute rollup", "Hourly rollup", "Daily rollup", "Prometheus", "Loki"):
            self.assertIn(tier, text)


if __name__ == "__main__":
    unittest.main()
