#!/usr/bin/env python3
"""Qualify per-rule match counts and the impact estimate against a real ClickHouse and a counted sample.

The sample has known counts: rule r1 enforced 5 times, r1 and platform rule 7 shadow-matched 3 and 2 times, plus 10 requests
for other paths, one of them an old event outside the window. The hourly rollup must report exactly those counts for both
modes and nothing for other domains, and the control plane's own estimate query (the PHP code, not a copy of its SQL)
must count what the stored fields support and call the rest unknown.
"""
from __future__ import annotations

import json
import subprocess
import sys
import time
import uuid
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parent))
from docker_images import ensure_image  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
CORE_IMAGE = "cdnfoundry-dev-core:latest"


def run(*args: str, check: bool = True, stdin: str | None = None) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, check=check, text=True, capture_output=True, timeout=240, input=stdin)


def main() -> None:
    image = yaml.safe_load((ROOT / "compose.prod.yml").read_text())["services"]["clickhouse"]["image"]
    ensure_image(image)
    suffix = uuid.uuid4().hex[:10]
    name, network = f"cdnf-rule-counts-{suffix}", f"cdnf-rule-counts-{suffix}"
    run("docker", "network", "create", network)
    try:
        run("docker", "run", "-d", "--name", name, "--network", network, "--memory", "2g",
            "-e", "CLICKHOUSE_DB=cdnf", "-e", "CLICKHOUSE_USER=cdnf", "-e", "CLICKHOUSE_PASSWORD=isolated-clickhouse",
            "-e", "CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT=1", "-e", "GRAFANA_CLICKHOUSE_PASSWORD=isolated-grafana",
            "-e", "CLICKHOUSE_INGEST_PASSWORD=isolated-ingest", "-e", "CLICKHOUSE_READER_PASSWORD=isolated-reader",
            "-e", "CLICKHOUSE_ZONES_PASSWORD=isolated-zones",
            "-v", f"{ROOT / 'docker/clickhouse/init.sql'}:/docker-entrypoint-initdb.d/10-init.sql:ro",
            "-v", f"{ROOT}/docker/clickhouse/users.d/grafana.xml:/etc/clickhouse-server/users.d/grafana.xml:ro",
            "-v", f"{ROOT}/docker/clickhouse/users.d/telemetry.xml:/etc/clickhouse-server/users.d/telemetry.xml:ro", image)

        def query(sql: str) -> str:
            return run("docker", "exec", name, "clickhouse-client", "--user", "cdnf", "--password", "isolated-clickhouse", "--query", sql).stdout.strip()

        for _ in range(90):
            if subprocess.run(["docker", "exec", name, "clickhouse-client", "--user", "cdnf", "--password", "isolated-clickhouse", "--query", "SELECT count() FROM cdnf.edge_events"], capture_output=True).returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError("ClickHouse did not initialize")

        def insert(count: int, domain: int, path: str, country: str, security_rule: str = "", shadow_rule: str = "", age: str = "10 MINUTE", action: str = "allow") -> None:
            query(f"INSERT INTO cdnf.edge_events (occurred_at, domain_id, hostname, method, path, status, bytes_out, security_action, security_rule, shadow_rule, country, user_agent) "
                  f"SELECT now() - INTERVAL {age}, {domain}, 'sample.test', 'GET', '{path}', 200, 1, '{action}', '{security_rule}', '{shadow_rule}', '{country}', 'agent/1' FROM numbers({count})")

        insert(5, 42, "/admin", "DE", security_rule="r1", action="block")
        insert(3, 42, "/admin/x", "FR", shadow_rule="r1")
        insert(2, 42, "/admin/y", "DE", shadow_rule="r1,7")
        insert(9, 42, "/home?x=1", "DE")
        insert(1, 42, "/admin", "DE", age="3 DAY")
        insert(4, 43, "/admin", "DE", shadow_rule="r1")

        rows = {tuple(line.split("\t")[:2]): int(line.split("\t")[2]) for line in query(
            "SELECT rule_ref, mode, sum(requests) FROM cdnf.rule_matches_hourly WHERE domain_id = 42 GROUP BY rule_ref, mode ORDER BY rule_ref, mode").splitlines()}
        assert rows == {("7", "shadow"): 2, ("r1", "enforce"): 5, ("r1", "shadow"): 5}, rows
        other = query("SELECT sum(requests) FROM cdnf.rule_matches_hourly WHERE domain_id = 43 AND mode = 'shadow'")
        assert other == "4", other

        # The control plane's own code runs against this ClickHouse: rule counts and the estimate.
        script = r"""
use App\Models\Domain; use App\Support\AnalyticsStore; use App\Support\SecurityRuleImpact; use Carbon\CarbonImmutable;
$store = app(AnalyticsStore::class); $domain = new Domain; $domain->id = 42;
$out = ['counts' => $store->ruleMatches(42, CarbonImmutable::now('UTC')->subDay())];
$c = fn ($f, $o, $v, $n = null) => array_filter(['field' => $f, 'operator' => $o, 'value' => $v, 'name' => $n], fn ($x) => $x !== null);
$out['admin_de'] = SecurityRuleImpact::estimate($domain, [$c('path', 'starts_with', '/admin'), $c('country', 'in_list', ['DE', 'FR'])], $store);
$out['admin_de_only'] = SecurityRuleImpact::estimate($domain, [$c('path', 'starts_with', '/admin'), $c('country', 'equals', 'DE')], $store);
$out['with_header'] = SecurityRuleImpact::estimate($domain, [$c('path', 'starts_with', '/admin'), $c('header', 'equals', 'x', 'x-a')], $store);
$out['query'] = SecurityRuleImpact::estimate($domain, [$c('query_present', 'equals', true), $c('user_agent', 'contains', 'AGENT')], $store);
echo json_encode($out);
"""
        result = run("docker", "run", "--rm", "--network", network, "-v", f"{ROOT / 'core'}:/app", "-w", "/app", "-e", "APP_ENV=local",
                     "-e", f"CLICKHOUSE_URL=http://{name}:8123", "-e", "CLICKHOUSE_USER=cdnf", "-e", "CLICKHOUSE_PASSWORD=isolated-clickhouse",
                     "-e", "DB_CONNECTION=sqlite", "-e", "DB_DATABASE=:memory:", "-e", "CACHE_STORE=array", "-e", "SESSION_DRIVER=array",
                     CORE_IMAGE, "php", "artisan", "tinker", "--execute", script)
        out = json.loads(result.stdout[result.stdout.index("{"):])
        assert out["counts"] == {"r1": {"enforce": 5, "shadow": 5}, "7": {"enforce": 0, "shadow": 2}}, out["counts"]
        # Stored fields only support /admin* in DE or FR within 24 h: 5 + 3 + 2 (the 3-day-old event is outside the window).
        assert out["admin_de"]["matched"] == 10 and out["admin_de"]["unknown_fields"] == [], out["admin_de"]
        assert out["admin_de_only"]["matched"] == 7, out["admin_de_only"]
        assert out["with_header"]["matched"] is None and out["with_header"]["matched_at_most"] == 10 and out["with_header"]["unknown_fields"] == ["header"], out["with_header"]
        assert out["query"]["matched"] == 9, out["query"]
        assert all(out[key]["partial"] is True for key in ("admin_de", "with_header", "query"))
        print(json.dumps({"qualification": "rule_match_counts", "rollup": "passed", "estimate": "passed", "partial_label": "passed"}))
    finally:
        run("docker", "rm", "-f", name, check=False)
        run("docker", "network", "rm", network, check=False)


if __name__ == "__main__":
    main()
