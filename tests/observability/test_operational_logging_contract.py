#!/usr/bin/env python3
"""Static contracts for bounded, independent operational logging."""

from __future__ import annotations

import json
import pathlib
import subprocess
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]


class OperationalLoggingContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.vector = (ROOT / "docker/vector/operational.yaml").read_text()
        cls.loki = (ROOT / "docker/loki/loki.yml").read_text()
        completed = subprocess.run(
            ["docker", "compose", "--env-file", ".env.prod.example", "-f", "compose.prod.yml", "--profile", "logs", "--profile", "telemetry", "config", "--format", "json"],
            cwd=ROOT, check=True, capture_output=True, text=True,
        )
        cls.production = json.loads(completed.stdout)

    def test_loki_is_private_persistent_pinned_and_bounded(self) -> None:
        service = self.production["services"]["loki"]
        self.assertNotIn("ports", service)
        self.assertTrue(service["read_only"])
        self.assertEqual(128, service["pids_limit"])
        self.assertTrue(any(volume.get("source") == "loki-data" and volume.get("target") == "/loki" for volume in service["volumes"]))
        dockerfile = (ROOT / "docker/loki/Dockerfile").read_text()
        self.assertIn("grafana/loki:3.7.2@sha256:", dockerfile)
        for contract in ("store: tsdb", "object_store: filesystem", "retention_enabled: true", "max_line_size: 65536", "query_timeout: 30s"):
            self.assertIn(contract, self.loki)

    def test_one_host_collector_is_bounded_and_independent(self) -> None:
        service = self.production["services"]["log-collector"]
        self.assertEqual(["logs"], service["profiles"])
        self.assertTrue(service["read_only"])
        self.assertEqual(["ALL"], service["cap_drop"])
        socket = next(volume for volume in service["volumes"] if volume.get("target") == "/var/run/docker.sock")
        self.assertTrue(socket["read_only"])
        self.assertEqual("2147483648", service["environment"]["LOG_BUFFER_BYTES"])
        self.assertIn("when_full: drop_newest", self.vector)
        self.assertIn("retry_attempts: 10", self.vector)
        self.assertNotIn("type: clickhouse", self.vector.lower())

    def test_only_low_cardinality_labels_are_configured(self) -> None:
        label_block = self.vector.split("    labels:\n", 1)[1].split("    structured_metadata:\n", 1)[0]
        labels = {line.strip().split(":", 1)[0] for line in label_block.splitlines() if line.strip()}
        self.assertEqual({"environment", "host", "role", "service", "level", "stream", "collector_id"}, labels)
        for field in ("domain_id", "operation_id", "request_id", "task_id", "revision_id"):
            self.assertNotIn(field, labels)

    def test_normalized_envelope_contains_every_required_field(self) -> None:
        for field in (
            "timestamp", "level", "environment", "service", "role", "host", "event", "message",
            "request_id", "operation_id", "job_id", "task_id", "domain_id", "edge_id", "cell_id",
            "revision_id", "duration_ms", "error_code", "parse_error",
        ):
            self.assertIn(f'"{field}"', self.vector)

    def test_access_and_dns_query_shapes_are_excluded_and_redaction_is_central(self) -> None:
        self.assertIn('parsed.event_type == "request"', self.vector)
        self.assertIn("nginx_access = match", self.vector)
        for field in ("parsed.qname", "parsed.qtype", "parsed.rcode"):
            self.assertIn(field, self.vector)
        for secret in ("authorization", "cookie", "password", "bootstrap[_-]?token", "private[_-]?key", "database[_-]?(url|password)"):
            self.assertIn(secret, self.vector)
        self.assertIn("redact_representative_secrets_before_loki", self.vector)

    def test_application_logs_are_structured_without_secret_context(self) -> None:
        logging = (ROOT / "core/config/logging.php").read_text()
        self.assertIn("OperationalJsonFormatter", logging)
        self.assertIn("ConfigureStructuredLogging", logging)
        for path in (ROOT / "edge-agent/main.go", ROOT / "edge-gateway/main.go"):
            source = path.read_text()
            self.assertIn("slog.NewJSONHandler", source)
        formatter = (ROOT / "core/app/Logging/OperationalJsonFormatter.php").read_text()
        self.assertNotIn("authorization", formatter.lower())
        self.assertNotIn("request_body", formatter.lower())

    def test_prometheus_alerts_cover_storage_collectors_buffers_and_log_rates(self) -> None:
        alerts = (ROOT / "docker/prometheus/telemetry-alerts.yml").read_text()
        for name in (
            "LokiUnavailable", "OperationalLogCollectorUnavailable", "OperationalLogsDropped",
            "OperationalLogDeliveryFailures", "OperationalLogBufferNearLimit", "LokiIngestionRejected",
            "LokiCompactorRetentionStalled",
            "OperationalErrorLogSpike", "OperationalCriticalServiceSilent",
            "LokiHostFilesystemPressure", "LokiHostFilesystemCritical",
        ):
            self.assertIn(f"alert: {name}", alerts)
        self.assertIn('intentional!="true"', alerts)


if __name__ == "__main__":
    unittest.main()
