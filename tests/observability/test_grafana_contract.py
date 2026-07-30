#!/usr/bin/env python3
"""Static, non-browser contract checks for provisioned Grafana observability."""

from __future__ import annotations

import json
import pathlib
import re
import subprocess
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
DASHBOARDS = ROOT / "docker" / "grafana" / "dashboards"


def panels(dashboard: dict) -> list[dict]:
    result: list[dict] = []
    for panel in dashboard.get("panels", []):
        result.append(panel)
        result.extend(panel.get("panels", []))
    return result


class GrafanaContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        files = sorted(DASHBOARDS.glob("*.json"))
        if len(files) != 2:
            raise AssertionError("exactly two dashboards are authorized")
        cls.by_uid = {}
        for path in files:
            document = json.loads(path.read_text(encoding="utf-8"))
            cls.by_uid[document["uid"]] = document

    def test_stable_uids_range_refresh_and_variables(self) -> None:
        self.assertEqual(
            {"cdnf-system-command-center", "cdnf-domain-command-center"},
            set(self.by_uid),
        )
        for dashboard in self.by_uid.values():
            self.assertEqual({"from": "now-6h", "to": "now"}, dashboard["time"])
            self.assertEqual("30s", dashboard["refresh"])
        self.assertEqual([], self.by_uid["cdnf-system-command-center"]["templating"]["list"])
        variables = self.by_uid["cdnf-domain-command-center"]["templating"]["list"]
        self.assertEqual(1, len(variables))
        variable = variables[0]
        self.assertEqual("domain_id", variable["name"])
        self.assertFalse(variable["multi"])
        self.assertFalse(variable["includeAll"])
        self.assertIn("FROM domains", variable["query"])
        self.assertIn("deleted_at IS NULL", variable["query"])
        self.assertIn("LIMIT 10000", variable["query"])

    def test_only_fixed_datasource_uids_are_referenced(self) -> None:
        allowed = {"prometheus", "clickhouse", "control-db", "loki", "-- Mixed --"}
        for dashboard in self.by_uid.values():
            for panel in panels(dashboard):
                datasource = panel.get("datasource")
                if datasource:
                    self.assertIn(datasource["uid"], allowed, panel.get("title"))
                for target in panel.get("targets", []):
                    target_source = target.get("datasource")
                    if target_source:
                        self.assertIn(target_source["uid"], allowed, panel.get("title"))

    def test_every_domain_panel_query_is_domain_scoped(self) -> None:
        dashboard = self.by_uid["cdnf-domain-command-center"]
        for panel in panels(dashboard):
            if panel.get("type") == "row":
                continue
            targets = panel.get("targets", [])
            self.assertTrue(targets, panel.get("title"))
            for target in targets:
                query = target.get("rawSql", target.get("expr", ""))
                self.assertIn("${domain_id:raw}", query, panel.get("title"))

    def test_queries_do_not_select_forbidden_sensitive_fields(self) -> None:
        forbidden = re.compile(
            r"\b(client_ip|authorization|cookie|cookies|request_body|user_agent|referrer)\b",
            re.IGNORECASE,
        )
        for dashboard in self.by_uid.values():
            for panel in panels(dashboard):
                for target in panel.get("targets", []):
                    query = target.get("rawSql", "")
                    self.assertIsNone(forbidden.search(query), panel.get("title"))
                    self.assertNotIn("SELECT * FROM cdnf.", query, panel.get("title"))

    def test_required_provisioning_contracts_are_versioned(self) -> None:
        datasources = (ROOT / "docker/grafana/provisioning/datasources/datasources.yml").read_text()
        for uid in ("prometheus", "clickhouse", "control-db", "loki"):
            self.assertRegex(datasources, rf"(?m)^\s+uid: {re.escape(uid)}$")
        self.assertIn("isDefault: true", datasources)
        self.assertRegex(datasources, r'(?m)^\s+queryTimeout: "30"$')
        self.assertRegex(datasources, r'(?m)^\s+dialTimeout: "5"$')
        provider = (ROOT / "docker/grafana/provisioning/dashboards/dashboards.yml").read_text()
        self.assertIn("folder: CDNFoundry Operations", provider)
        dockerfile = (ROOT / "docker/grafana/Dockerfile").read_text()
        self.assertIn("grafana/grafana:12.3.0@sha256:", dockerfile)
        self.assertIn("CLICKHOUSE_PLUGIN_VERSION=4.8.2", dockerfile)
        self.assertIn("CLICKHOUSE_PLUGIN_SHA256=81e824a64b3b2881", dockerfile)

    def test_compose_contract_is_bounded_and_private(self) -> None:
        def compose_document(*arguments: str) -> dict:
            completed = subprocess.run(
                ["docker", "compose", *arguments, "config", "--format", "json"],
                cwd=ROOT,
                check=True,
                capture_output=True,
                text=True,
            )
            return json.loads(completed.stdout)

        development = compose_document("-f", "compose.dev.yml")
        production = compose_document(
            "--env-file",
            ".env.prod.example",
            "-f",
            "compose.prod.yml",
            "--profile",
            "telemetry",
        )

        for document in (development, production):
            grafana = document["services"]["grafana"]
            self.assertEqual("127.0.0.1", grafana["ports"][0]["host_ip"])
            self.assertEqual(3000, grafana["ports"][0]["target"])
            self.assertEqual("false", grafana["environment"]["GF_AUTH_ANONYMOUS_ENABLED"])
            self.assertTrue(
                any(
                    volume.get("source") == "grafana-data"
                    and volume.get("target") == "/var/lib/grafana"
                    for volume in grafana["volumes"]
                )
            )

            clickhouse_targets = {
                volume.get("target") for volume in document["services"]["clickhouse"]["volumes"]
            }
            self.assertIn("/etc/clickhouse-server/config.d/prometheus.xml", clickhouse_targets)
            self.assertIn("/etc/clickhouse-server/users.d/grafana.xml", clickhouse_targets)

        production_grafana = production["services"]["grafana"]
        self.assertEqual(["telemetry"], production_grafana["profiles"])
        self.assertTrue(production_grafana["read_only"])
        self.assertEqual("536870912", production_grafana["mem_limit"])
        self.assertEqual(128, production_grafana["pids_limit"])
        self.assertEqual("disable", production_grafana["environment"]["GRAFANA_POSTGRES_SSLMODE"])

        for document, retention in ((development, "168h"), (production, "336h")):
            loki = document["services"]["loki"]
            self.assertEqual(retention, loki["environment"]["LOKI_RETENTION_PERIOD"])
            self.assertTrue(any(volume.get("target") == "/loki" for volume in loki["volumes"]))
        self.assertNotIn("ports", production["services"]["loki"])

    def test_operational_log_sections_use_loki_and_preserve_domain_variable(self) -> None:
        system = self.by_uid["cdnf-system-command-center"]
        domain = self.by_uid["cdnf-domain-command-center"]
        self.assertIn("Live Operational Logs", [panel["title"] for panel in system["panels"]])
        self.assertIn("Live Operational Logs", [panel["title"] for panel in domain["panels"]])
        self.assertIn("loki", json.dumps(system))
        for panel in panels(domain):
            if panel.get("datasource", {}).get("uid") != "loki":
                continue
            for target in panel["targets"]:
                self.assertIn('domain_id=\\"${domain_id:raw}\\"', json.dumps(target["expr"]))
        self.assertIn("Open ingestion logs in Explore", json.dumps(system))
        self.assertIn("Open gateway logs in Explore", json.dumps(system))
        self.assertIn("Open domain logs in Explore", json.dumps(domain))

    def test_system_dashboard_covers_real_metric_families(self) -> None:
        serialized = json.dumps(self.by_uid["cdnf-system-command-center"])
        for metric in (
            "cdnfoundry_component_health",
            "cdnfoundry_queue_depth",
            "cdnfoundry_edge_gateway_activations_total",
            "cdnfoundry_cell_capacity_ratio",
            "dnsdist_server_status",
            "pdns_auth_udp_queries",
            "vector_component_discarded_events_total",
            "node_pressure_io_waiting_seconds_total",
            "alertmanager_notifications_failed_total",
            "ClickHouseMetrics_Query",
        ):
            self.assertIn(metric, serialized)


if __name__ == "__main__":
    unittest.main()
