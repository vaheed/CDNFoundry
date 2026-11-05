#!/usr/bin/env python3
"""Static, non-browser contract for the provisioned Grafana dashboards: structure, style, and data access.

The dashboards are generated from docker/grafana/dashboards_*.py. These tests read the written JSON, so a rule
broken in the generator, or an edit made to a file by hand, fails here.
"""

from __future__ import annotations

import json
import pathlib
import re
import subprocess
import sys
import unittest

import yaml

ROOT = pathlib.Path(__file__).resolve().parents[2]
GRAFANA = ROOT / "docker" / "grafana"
sys.path.insert(0, str(GRAFANA))

import build_dashboards  # noqa: E402
from dashboard_lib import FOLDERS, THRESHOLDS  # noqa: E402

DASHBOARDS = GRAFANA / "dashboards"
LIBRARY = GRAFANA / "library-panels"
ACCESS = json.loads((GRAFANA / "access.json").read_text())
RUNBOOKS = (ROOT / "docs/operations/runbooks.md").read_text()
RUNBOOK_ANCHORS = set(re.findall(r"\{#([a-z0-9-]+)\}", RUNBOOKS)) | {
    re.sub(r"[^a-z0-9]+", "-", heading.lower()).strip("-") for heading in re.findall(r"^#{2,3} (.+?)(?: \{#.*)?$", RUNBOOKS, re.M)
}
STANDARD_VARIABLES = ["environment", "region", "pop", "edge", "pool", "domain", "hostname"]
HOMES = {"cdnf-now"} | {team["home"] for team in ACCESS["teams"].values()}
ALLOWED_DATASOURCES = {"prometheus", "clickhouse", "control-db", "loki", "-- Mixed --", "-- Grafana --", "alertmanager"}
COUNTER_SUFFIXES = ("_total", "_count", "_sum", "_bucket")


def load() -> dict[str, dict]:
    result = {}
    for path in sorted(DASHBOARDS.rglob("*.json")):
        document = json.loads(path.read_text(encoding="utf-8"))
        document["_folder"] = path.parent.name
        result[document["uid"]] = document
    return result


def library_models() -> dict[str, dict]:
    return {path.stem: json.loads(path.read_text())["model"] for path in sorted(LIBRARY.glob("*.json"))}


def panels(dashboard: dict) -> list[dict]:
    result: list[dict] = []
    for panel in dashboard.get("panels", []):
        result.append(panel)
        result.extend(panel.get("panels", []))
    return result


def resolved(dashboard: dict, models: dict[str, dict]) -> list[dict]:
    """Every panel with its library model merged in, so a shared panel is judged by what it shows."""
    result = []
    for panel in panels(dashboard):
        if "libraryPanel" in panel:
            model = models[panel["libraryPanel"]["uid"]]
            panel = {**model, "gridPos": panel["gridPos"], "libraryPanel": panel["libraryPanel"]}
        result.append(panel)
    return result


def targets(panel: dict) -> list[dict]:
    return panel.get("targets", [])


def query_text(target: dict) -> str:
    return str(target.get("rawSql") or target.get("expr") or "")


def inside_range_function(expression: str, position: int) -> bool:
    """True when the character at `position` sits inside rate(, irate(, increase(, or a quantile over one."""
    depth_stack: list[str] = []
    index = 0
    functions = re.compile(r"([a-zA-Z_:][a-zA-Z0-9_:]*)\(")
    while index < position:
        char = expression[index]
        match = functions.match(expression, index) if char.isalpha() or char in "_:" else None
        if match:
            depth_stack.append(match.group(1))
            index = match.end()
            continue
        if char == "(":
            depth_stack.append("")
        elif char == ")" and depth_stack:
            depth_stack.pop()
        index += 1
    return any(name in {"rate", "irate", "increase", "resets", "delta", "idelta", "deriv"} for name in depth_stack)


class GrafanaContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.by_uid = load()
        cls.models = library_models()

    # --- structure ---------------------------------------------------------------------------------------

    def test_generated_files_match_the_definitions(self) -> None:
        files = build_dashboards.build()
        stale = [str(path) for path, text in files.items() if not path.exists() or path.read_text() != text]
        extra = [str(path) for path in list(DASHBOARDS.rglob("*.json")) + list(LIBRARY.glob("*.json")) if path not in files]
        self.assertEqual([], stale + extra, "run docker/grafana/build_dashboards.py")

    def test_every_dashboard_has_a_fixed_uid_and_lives_in_its_audience_folder(self) -> None:
        expected = {
            "00-overview": {"cdnf-now"},
            "10-noc": {"cdnf-noc-wall", "cdnf-global-traffic", "cdnf-pop-fleet", "cdnf-pop-detail", "cdnf-origin-health", "cdnf-cache", "cdnf-edge-latency", "cdnf-dns-authoritative", "cdnf-synthetic-probes", "cdnf-propagation"},
            "20-soc": {"cdnf-security-overview", "cdnf-waf", "cdnf-l7-flood", "cdnf-dns-abuse", "cdnf-tls-posture", "cdnf-access-audit", "cdnf-telemetry-integrity"},
            "30-platform-admin": {"cdnf-control-plane", "cdnf-fleet-rollouts", "cdnf-data-stores", "cdnf-telemetry-pipeline", "cdnf-backups", "cdnf-hosts-containers", "cdnf-daily-checks"},
            "40-on-call-slo": {"cdnf-slos", "cdnf-incident", "cdnf-logs-explorer", "cdnf-request-trace"},
            "50-domains": {"cdnf-domain-overview", "cdnf-domain-comparison"},
            "60-business-capacity": {"cdnf-usage-billing", "cdnf-growth", "cdnf-capacity"},
            "90-samples": {"cdnf-query-samples"},
        }
        actual: dict[str, set[str]] = {}
        for uid, dashboard in self.by_uid.items():
            actual.setdefault(dashboard["_folder"], set()).add(uid)
        self.assertEqual(expected, actual)

    def test_folders_are_provisioned_with_fixed_uids(self) -> None:
        providers = yaml.safe_load((GRAFANA / "provisioning/dashboards/dashboards.yml").read_text())["providers"]
        self.assertEqual([(title, uid) for _, title, uid in FOLDERS], [(item["folder"], item["folderUid"]) for item in providers])
        self.assertEqual({"cdnf-system-command-center", "cdnf-domain-command-center"}, set(ACCESS["retired_dashboards"]))
        for item, (directory, _, _) in zip(providers, FOLDERS):
            self.assertEqual(f"/var/lib/grafana/dashboards/{directory}", item["options"]["path"])
            self.assertTrue(item["disableDeletion"])
            self.assertFalse(item["allowUiUpdates"])

    def test_the_seven_standard_variables_come_in_one_order_and_default_to_all(self) -> None:
        for uid, dashboard in self.by_uid.items():
            names = [variable["name"] for variable in dashboard["templating"]["list"]][: len(STANDARD_VARIABLES)]
            self.assertEqual(STANDARD_VARIABLES, names, uid)
            for variable in dashboard["templating"]["list"][: len(STANDARD_VARIABLES)]:
                self.assertTrue(variable["includeAll"], f"{uid} {variable['name']}")
                self.assertIn("$__all", variable["current"]["value"], f"{uid} {variable['name']}")

    def test_every_dashboard_links_to_its_parent_siblings_and_runbook(self) -> None:
        for uid, dashboard in self.by_uid.items():
            links = dashboard["links"]
            self.assertTrue(any(link["type"] == "dashboards" and link["tags"] for link in links), f"{uid}: siblings")
            runbook = [link for link in links if link["type"] == "link" and "operations/runbooks#" in link["url"]]
            self.assertEqual(1, len(runbook), f"{uid}: runbook")
            self.assertIn(runbook[0]["url"].split("#", 1)[1], RUNBOOK_ANCHORS, uid)
            if uid != "cdnf-now":
                self.assertTrue(any(link["type"] == "link" and link["url"] == "/d/cdnf-now" for link in links), f"{uid}: parent")

    def test_every_dashboard_annotates_the_events_that_explain_a_spike(self) -> None:
        names = {"Fleet rollouts", "Artifact publishes", "Purges", "Settings changes", "Emergency mode", "Critical alerts"}
        for uid, dashboard in self.by_uid.items():
            self.assertTrue(names <= {item["name"] for item in dashboard["annotations"]["list"]}, uid)

    def test_team_homes_exist_and_show_no_collapsed_row_on_the_first_screen(self) -> None:
        for uid in HOMES:
            self.assertIn(uid, self.by_uid)
            for panel in panels(self.by_uid[uid]):
                if panel["type"] == "row" and panel["gridPos"]["y"] < 30:
                    self.assertFalse(panel["collapsed"], f"{uid}: {panel['title']}")

    def test_access_matrix_names_real_folders_and_dashboards(self) -> None:
        folder_uids = {uid for _, _, uid in FOLDERS}
        for name, team in ACCESS["teams"].items():
            self.assertLessEqual(set(team["folders"]), folder_uids, name)
            self.assertIn(team["home"], self.by_uid, name)
            self.assertEqual(name == "platform-admin", team["edit"], name)
        for name, uids in ACCESS["playlists"].items():
            self.assertLessEqual(set(uids), set(self.by_uid), name)

    # --- style -------------------------------------------------------------------------------------------

    def test_every_panel_has_a_title_that_fits_a_description_and_a_unit(self) -> None:
        for uid, dashboard in self.by_uid.items():
            for panel in resolved(dashboard, self.models):
                if panel["type"] == "row":
                    continue
                name = f"{uid}/{panel.get('title')}"
                self.assertTrue(panel.get("title"), name)
                self.assertTrue(panel.get("description"), name)
                self.assertLessEqual(len(panel["title"]), 5 * panel["gridPos"]["w"], f"{name}: title too long for its width")
                if panel["type"] != "text":
                    self.assertTrue(panel["fieldConfig"]["defaults"].get("unit"), name)

    def test_no_query_text_reaches_a_legend_or_a_stat(self) -> None:
        for uid, dashboard in self.by_uid.items():
            for panel in resolved(dashboard, self.models):
                for target in targets(panel):
                    if target["datasource"]["uid"] == "prometheus":
                        legend = target.get("legendFormat", "")
                        self.assertTrue(legend and legend != "__auto", f"{uid}/{panel['title']}: legend would show the query")
                if panel["type"] == "stat":
                    self.assertEqual("value", panel["options"]["textMode"], f"{uid}/{panel['title']}")

    def test_stat_panels_stay_readable(self) -> None:
        for uid, dashboard in self.by_uid.items():
            per_row: dict[int, list[dict]] = {}
            for panel in resolved(dashboard, self.models):
                if panel["type"] == "stat":
                    per_row.setdefault(panel["gridPos"]["y"], []).append(panel)
                    self.assertGreaterEqual(panel["gridPos"]["w"], 4, f"{uid}/{panel['title']}")
            for y, row in per_row.items():
                self.assertLessEqual(len(row), 6, f"{uid}: row {y}")

    def test_thresholds_come_from_one_shared_set_and_empty_states_are_neutral(self) -> None:
        shared = [json.dumps(steps, sort_keys=True) for steps in THRESHOLDS.values()]
        for uid, dashboard in self.by_uid.items():
            for panel in resolved(dashboard, self.models):
                if panel["type"] in {"row", "text"}:
                    continue
                name = f"{uid}/{panel['title']}"
                found = [panel["fieldConfig"]["defaults"]["thresholds"]["steps"]]
                found += [prop["value"]["steps"] for override in panel["fieldConfig"]["overrides"] for prop in override["properties"] if prop["id"] == "thresholds"]
                for steps in found:
                    self.assertIn(json.dumps(steps, sort_keys=True), shared, name)
                if panel["type"] == "stat":
                    mapping = [m for m in panel["fieldConfig"]["defaults"]["mappings"] if m.get("type") == "special"]
                    self.assertTrue(mapping and mapping[0]["options"]["result"]["color"] == "text", f"{name}: empty state must be neutral")

    def test_a_counter_is_shown_as_a_rate_or_an_increase(self) -> None:
        for uid, dashboard in self.by_uid.items():
            for panel in resolved(dashboard, self.models):
                for target in targets(panel):
                    if target["datasource"]["uid"] != "prometheus":
                        continue
                    expression = target["expr"]
                    for match in re.finditer(r"(?<![:\w])([a-z][a-z0-9_]*(?:_total|_count|_sum|_bucket))(?![:\w])", expression):
                        self.assertTrue(inside_range_function(expression, match.start()), f"{uid}/{panel['title']}: {match.group(1)} is a bare counter")

    def test_a_repeated_panel_is_a_library_panel(self) -> None:
        seen: dict[tuple, list[str]] = {}
        for uid, dashboard in self.by_uid.items():
            for panel in panels(dashboard):
                if panel["type"] in {"row", "text"} or "libraryPanel" in panel or not targets(panel):
                    continue
                config = json.loads(json.dumps(panel.get("fieldConfig", {})))
                config.get("defaults", {}).pop("links", None)
                key = (panel["type"], panel["title"], tuple(query_text(t) for t in targets(panel)), json.dumps(panel.get("options"), sort_keys=True), json.dumps(config, sort_keys=True))
                seen.setdefault(key, []).append(uid)
        repeated = {key[1]: value for key, value in seen.items() if len(value) > 1}
        self.assertEqual({}, repeated)
        used = {panel["libraryPanel"]["uid"] for dashboard in self.by_uid.values() for panel in panels(dashboard) if "libraryPanel" in panel}
        self.assertEqual(used, set(self.models), "every library panel is used, and every reference exists")

    # --- data access -------------------------------------------------------------------------------------

    def test_only_fixed_datasource_uids_are_referenced(self) -> None:
        for uid, dashboard in self.by_uid.items():
            for panel in resolved(dashboard, self.models):
                if panel.get("datasource"):
                    self.assertIn(panel["datasource"]["uid"], ALLOWED_DATASOURCES, f"{uid}/{panel.get('title')}")
                for target in targets(panel):
                    self.assertIn(target["datasource"]["uid"], ALLOWED_DATASOURCES, f"{uid}/{panel.get('title')}")

    def test_queries_never_select_secrets_or_raw_client_data(self) -> None:
        forbidden = re.compile(r"\b(authorization|cookie|cookies|request_body|referrer)\b", re.I)
        for uid, dashboard in self.by_uid.items():
            for panel in resolved(dashboard, self.models):
                for target in targets(panel):
                    sql = query_text(target)
                    self.assertIsNone(forbidden.search(sql), f"{uid}/{panel['title']}")
                    self.assertNotIn("SELECT * FROM cdnf.", sql)
                    # A client address is shown only as the network the privacy setting allows.
                    for match in re.finditer(r"\bclient_ip\b", sql):
                        window = sql[max(0, match.start() - 60): match.end() + 40]
                        self.assertRegex(window, r"CIDRToRange|position\(client_ip", f"{uid}/{panel['title']}: raw client address")
                    if re.search(r"\buser_agent\b", sql):
                        self.assertIn("GROUP BY", sql, f"{uid}/{panel['title']}: user agents only in aggregates")

    def test_clickhouse_queries_read_only_granted_tables_and_postgres_only_granted_views(self) -> None:
        granted = set(re.findall(r"GRANT SELECT ON cdnf\.(\w+)", (ROOT / "docker/clickhouse/users.d/grafana.xml").read_text()))
        provision = (ROOT / "docker/grafana/postgres/provision.sql").read_text()
        views = set(re.findall(r"'(grafana_\w+)'", provision))
        for uid, dashboard in self.by_uid.items():
            for panel in resolved(dashboard, self.models):
                for target in targets(panel):
                    sql = query_text(target)
                    if target["datasource"]["uid"] == "clickhouse":
                        self.assertLessEqual(set(re.findall(r"cdnf\.(\w+)", sql)), granted, f"{uid}/{panel['title']}")
                    if target["datasource"]["uid"] == "control-db":
                        tables = set(re.findall(r"\b(?:FROM|JOIN)\s+([a-z_]+)(?![\w(])", re.sub(r"extract\(\w+ FROM", "extract(", sql)))
                        self.assertLessEqual({t for t in tables if t.startswith("grafana_")}, views, f"{uid}/{panel['title']}")
                        self.assertLessEqual({t for t in tables if not t.startswith("grafana_")}, {"domains", "pg_stat_activity", "pg_database"}, f"{uid}/{panel['title']}")
            for annotation in dashboard["annotations"]["list"]:
                sql = annotation.get("target", {}).get("rawSql", "")
                self.assertLessEqual({t for t in re.findall(r"\bFROM\s+([a-z_]+)", sql)}, views, f"{uid}/{annotation['name']}")

    def test_every_clickhouse_filter_is_scoped_by_the_standard_variables(self) -> None:
        for uid, dashboard in self.by_uid.items():
            for panel in resolved(dashboard, self.models):
                for target in targets(panel):
                    sql = query_text(target)
                    if target["datasource"]["uid"] == "clickhouse" and "edge_traffic_" in sql:
                        self.assertIn("$__conditionalAll(pop IN", sql, f"{uid}/{panel['title']}")

    def test_prometheus_queries_use_metrics_that_something_exports(self) -> None:
        exported = set(re.findall(r"cdnfoundry_[a-z_0-9]+", (ROOT / "core/app/Http/Controllers/MetricsController.php").read_text()))
        exported |= set(re.findall(r"cdnfoundry_[a-z_0-9]+", (ROOT / "core/app/Support/ControlPlaneMetrics.php").read_text()))
        controller = (ROOT / "core/app/Http/Controllers/MetricsController.php").read_text()
        # The per-edge gateway gauges are named from a key map: cdnfoundry_edge_gateway_<mapped name>.
        exported |= {f"cdnfoundry_edge_gateway_{name}" for name in re.findall(r"'[a-z_]+' => '([a-z_]+)',", controller)}
        recorded = set()
        for path in (ROOT / "docker/prometheus").glob("*.yml"):
            document = yaml.safe_load(path.read_text())
            if isinstance(document, dict) and "groups" in document:
                recorded |= {rule["record"] for group in document["groups"] for rule in group["rules"] if "record" in rule}
        vector = set(re.findall(r"name: (cdnf_[a-z_0-9]+)", (ROOT / "docker/vector/vector.yaml").read_text()))
        # Histograms export _bucket, _sum, and _count series.
        vector |= {f"{name}{suffix}" for name in list(vector) for suffix in ("_bucket", "_sum", "_count")}
        external = ("node_", "dnsdist_", "vector_", "ClickHouse", "loki_", "probe_", "alertmanager_", "up", "ALERTS", "process_", "slo:", "pdns_")
        for uid, dashboard in self.by_uid.items():
            for panel in resolved(dashboard, self.models):
                for target in targets(panel):
                    if target["datasource"]["uid"] != "prometheus":
                        continue
                    expression = re.sub(r'"[^"]*"', '""', target["expr"])
                    for name in set(re.findall(r"\b([A-Za-z_:][A-Za-z0-9_:]*)\b(?=\s*[\{\[\)\(,]|\s*$|\s*[<>=!/*+-])", expression)):
                        if name.startswith(("cdnfoundry_", "cdnf_", "cdnf:")):
                            self.assertIn(name, exported | recorded | vector | {"cdnfoundry_http_request_duration_seconds_bucket"}, f"{uid}/{panel['title']}: {name}")

    # --- provisioning and Compose -----------------------------------------------------------------------

    def test_required_provisioning_contracts_are_versioned(self) -> None:
        datasources = (GRAFANA / "provisioning/datasources/datasources.yml").read_text()
        for uid in ("prometheus", "clickhouse", "control-db", "loki", "alertmanager"):
            self.assertRegex(datasources, rf"(?m)^\s+uid: {re.escape(uid)}$")
        self.assertIn("isDefault: true", datasources)
        self.assertRegex(datasources, r'(?m)^\s+queryTimeout: "30"$')
        self.assertRegex(datasources, r'(?m)^\s+dialTimeout: "5"$')
        for field in ("request_id", "domain_id", "pop", "edge"):
            self.assertRegex(datasources, rf"- name: {field}\n\s+matcherType: label\n\s+matcherRegex")
        compose = (ROOT / "compose.prod.yml").read_text()
        self.assertIn("grafana/grafana:12.4.11@sha256:", compose)
        self.assertIn("versions/4.21.3/download", compose)
        self.assertIn("53c0602214e7ca1af1733962779293587386b0b9a8c544ecead80b819e5f560b", compose)
        self.assertNotIn("docker/grafana/Dockerfile", compose)

    def test_clean_ci_migrates_before_grafana_database_provisioning(self) -> None:
        workflow = (ROOT / ".github/workflows/ci.yml").read_text()
        backend_job = workflow.split("  backend-e2e:\n", 1)[1].split("  scale-e2e:\n", 1)[0]
        self.assertLess(backend_job.index("make dev-control-up"), backend_job.index("make dev-migrate"))
        self.assertLess(backend_job.index("make dev-migrate"), backend_job.index("make dev-up"))
        self.assertIn("dev-control-up: dev-assets", (ROOT / "Makefile").read_text())

    def test_compose_contract_is_bounded_and_private(self) -> None:
        def compose_document(*arguments: str) -> dict:
            completed = subprocess.run(["docker", "compose", *arguments, "config", "--format", "json"], cwd=ROOT, check=True, capture_output=True, text=True)
            return json.loads(completed.stdout)

        development = compose_document("-f", "compose.dev.yml")
        production = compose_document("--env-file", ".env.prod.example", "-f", "compose.prod.yml", "--profile", "telemetry")
        for document in (development, production):
            grafana = document["services"]["grafana"]
            self.assertEqual("127.0.0.1", grafana["ports"][0]["host_ip"])
            self.assertEqual(3000, grafana["ports"][0]["target"])
            self.assertEqual("false", grafana["environment"]["GF_AUTH_ANONYMOUS_ENABLED"])
            self.assertEqual("/var/lib/grafana/dashboards/00-overview/cdnf-now.json", grafana["environment"]["GF_DASHBOARDS_DEFAULT_HOME_DASHBOARD_PATH"])
            self.assertTrue(any(v.get("source") == "grafana-data" and v.get("target") == "/var/lib/grafana" for v in grafana["volumes"]))
            targets_ = {v.get("target") for v in document["services"]["clickhouse"]["volumes"]}
            self.assertIn("/etc/clickhouse-server/config.d/prometheus.xml", targets_)
            self.assertIn("/etc/clickhouse-server/users.d/grafana.xml", targets_)
            bootstrap = document["services"]["grafana-bootstrap"]
            self.assertTrue(bootstrap["read_only"])
            self.assertNotIn("ports", bootstrap)
            self.assertTrue(any(v.get("target") == "/bootstrap/library-panels" for v in bootstrap["volumes"]))
        self.assertIn("GRAFANA_TEST_USER_PASSWORD", development["services"]["grafana-bootstrap"]["environment"])
        self.assertNotIn("GRAFANA_TEST_USER_PASSWORD", production["services"]["grafana-bootstrap"]["environment"])
        production_grafana = production["services"]["grafana"]
        self.assertEqual(["telemetry"], production_grafana["profiles"])
        self.assertEqual("dashboard-service:warn", production_grafana["environment"]["GF_LOG_FILTERS"])
        self.assertTrue(production_grafana["read_only"])
        self.assertEqual("536870912", production_grafana["mem_limit"])
        self.assertEqual(128, production_grafana["pids_limit"])
        self.assertEqual("disable", production_grafana["environment"]["GRAFANA_POSTGRES_SSLMODE"])
        for document, retention in ((development, "168h"), (production, "336h")):
            loki = document["services"]["loki"]
            self.assertEqual(retention, loki["environment"]["LOKI_RETENTION_PERIOD"])
            self.assertTrue(any(volume.get("target") == "/loki" for volume in loki["volumes"]))
        self.assertNotIn("ports", production["services"]["loki"])


if __name__ == "__main__":
    unittest.main()
