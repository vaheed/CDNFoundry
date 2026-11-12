#!/usr/bin/env python3
"""A counted transfer must match the download and upload that telemetry reports.

Runs a real cell, the production Vector pipeline, and a real ClickHouse on a throwaway network. A
client sends a known number of GET and POST requests and records, from its own side, the bytes it
sent and received. The test then reads the rollups by edge, cell, domain, and country and the live
Prometheus counters, and requires each to equal the client's count within a stated tolerance.
"""
from __future__ import annotations

import json
import os
import pathlib
import re
import subprocess
import sys
import tempfile
import time
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tests/e2e"))
import managed_waf  # noqa: E402  (reuses the cell runtime fixture)

IMAGE = os.environ.get("CDNF_WAF_IMAGE", "cdnfoundry/edge-runtime:waf-qualification")
CLICKHOUSE = "clickhouse/clickhouse-server:26.8.7.19-alpine@sha256:2bece7c646f08865eadc2000a60427e2964adf2b46908a6d476dfa7cc5408720"
VECTOR = "timberio/vector:0.58.0-debian@sha256:1c1ea358c617ea0b23003d5af87f7a678b30f8f7096437e680380c47fc13d2d9"
CURL = "curlimages/curl:8.16.0"
TOLERANCE = 0.02
GETS, POSTS, POST_BYTES = 25, 15, 4096
TOKEN = "volume-accuracy-token"
EDGE, CELL, DOMAIN_ID = "e2e-edge", "cell-e2e", 90


def run(*args: str, check: bool = True, input_text: str | None = None) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, text=True, capture_output=True, input=input_text, timeout=120)
    if check and result.returncode:
        raise RuntimeError(f"command failed: {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def clickhouse(name: str, query: str) -> list[list[str]]:
    out = run("docker", "exec", name, "clickhouse-client", "-u", "cdnf", "--password", "p", "-q", query + " FORMAT TSV").stdout
    return [line.split("\t") for line in out.splitlines() if line]


def within(label: str, reported: float, counted: float) -> None:
    assert counted > 0, label
    drift = abs(reported - counted) / counted
    assert drift <= TOLERANCE, f"{label}: telemetry {reported} vs client {counted} ({drift:.2%} apart, tolerance {TOLERANCE:.0%})"
    print(f"  {label}: client {int(counted)} bytes, telemetry {int(reported)} bytes ({drift:.3%} apart)")


def main() -> None:
    suffix = uuid.uuid4().hex[:8]
    network, names = f"cdnf-e2e-volume-{suffix}", {key: f"cdnf-e2e-volume-{key}-{suffix}" for key in ("ch", "vector", "origin", "cell")}
    run("docker", "network", "create", network)
    try:
        with tempfile.TemporaryDirectory(prefix="cdnf-e2e-volume-") as directory:
            tmp = pathlib.Path(directory)
            tmp.chmod(0o755)
            run("docker", "run", "-d", "--name", names["ch"], "--network", network, "--network-alias", "clickhouse",
                "-e", "CLICKHOUSE_USER=cdnf", "-e", "CLICKHOUSE_PASSWORD=p", "-e", "CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT=1",
                "-v", f"{ROOT}/docker/clickhouse/init.sql:/docker-entrypoint-initdb.d/10-init.sql:ro", CLICKHOUSE)
            for _ in range(60):
                if run("docker", "exec", names["ch"], "clickhouse-client", "-u", "cdnf", "--password", "p", "-q", "EXISTS TABLE cdnf.edge_volume_1m", check=False).stdout.strip() == "1":
                    break
                time.sleep(1)
            else:
                raise RuntimeError("ClickHouse did not finish creating the schema")
            run("docker", "run", "-d", "--name", names["vector"], "--network", network, "--network-alias", "vector",
                "-e", f"CDNF_TELEMETRY_EDGE_ID={EDGE}", "-e", "CDNF_TELEMETRY_POP=e2e-pop", "-e", "CDNF_TELEMETRY_REGION=e2e-region",
                "-e", "CLICKHOUSE_ENDPOINT=http://clickhouse:8123", "-e", "CLICKHOUSE_USER=cdnf", "-e", "CLICKHOUSE_PASSWORD=p",
                "-e", f"EDGE_STATUS_TOKEN={TOKEN}", "-e", "VECTOR_DANGEROUSLY_ALLOW_ENV_VAR_INTERPOLATION=true",
                "-v", "/vector-data-dir", "-v", f"{ROOT}/docker/vector/vector.yaml:/etc/vector/vector.yaml:ro", VECTOR)
            run("docker", "run", "-d", "--name", names["origin"], "--network", network, "nginx:1.30.3-alpine")
            origin_ip = json.loads(run("docker", "inspect", names["origin"]).stdout)[0]["NetworkSettings"]["Networks"][network]["IPAddress"]
            runtime = tmp / "runtime.json"
            runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-volume", "certificates": {},
                                           "hosts": {"off.waf.test": managed_waf.host("off", False, 0, origin_ip)}}))
            runtime.chmod(0o644)
            run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=waf.test",
                "-keyout", str(tmp / "tls.key"), "-out", str(tmp / "tls.crt"))
            for item in (tmp / "tls.key", tmp / "tls.crt"):
                item.chmod(0o644)
            run("docker", "run", "-d", "--name", names["cell"], "--network", network,
                "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", f"EDGE_STATUS_TOKEN={TOKEN}", "-e", f"EDGE_CELL_NAME={CELL}",
                "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
                "-v", f"{tmp / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{tmp / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
            time.sleep(6)

            sent = received = 0
            body = "x" * POST_BYTES
            script = f"""
for i in $(seq 1 {GETS}); do curl -s -o /dev/null -H 'Host: off.waf.test' -w '%{{size_request}} %{{size_upload}} %{{size_header}} %{{size_download}}\\n' http://{names['cell']}:8080/; done
for i in $(seq 1 {POSTS}); do curl -s -o /dev/null -H 'Host: off.waf.test' -H 'Content-Type: application/octet-stream' --data-binary '{body}' -w '%{{size_request}} %{{size_upload}} %{{size_header}} %{{size_download}}\\n' http://{names['cell']}:8080/; done
"""
            out = run("docker", "run", "--rm", "--network", network, "--entrypoint", "sh", CURL, "-c", script).stdout
            lines = [line.split() for line in out.splitlines() if line.strip()]
            assert len(lines) == GETS + POSTS, out
            for request, upload, header, download in lines:
                # curl's size_request is every byte of the request it sent, body included (the body also appears as size_upload).
                sent += int(request)
                received += int(header) + int(download)
            assert sent > POSTS * POST_BYTES, 'the client must have sent the POST bodies'
            expected_requests = GETS + POSTS

            rows: list[list[str]] = []
            for _ in range(60):
                rows = clickhouse(names["ch"], f"SELECT sum(requests), sum(bytes_in), sum(bytes_sent) FROM cdnf.edge_volume_1m WHERE domain_id = {DOMAIN_ID}")
                if rows and rows[0][0] != "0" and int(rows[0][0]) >= expected_requests:
                    break
                time.sleep(1)
            else:
                for key in ("vector", "cell"):
                    logs = run("docker", "logs", "--tail", "15", names[key], check=False)
                    print(f"--- {key} ---\n{logs.stdout[-1500:]}{logs.stderr[-1500:]}")
                raise AssertionError(f"telemetry never reached ClickHouse: {rows}")
            requests, upload, download = (int(value) for value in rows[0])
            assert requests == expected_requests, (requests, expected_requests)
            print("client count against the rollup, by view:")
            for dimension, expected in (("edge_id", EDGE), ("cell", CELL), ("domain_id", str(DOMAIN_ID)), ("country", None)):
                grouped = clickhouse(names["ch"], f"SELECT {dimension}, sum(bytes_in), sum(bytes_sent) FROM cdnf.edge_volume_1m WHERE domain_id = {DOMAIN_ID} GROUP BY {dimension}")
                assert len(grouped) == 1, (dimension, grouped)
                if expected is not None:
                    assert grouped[0][0] == expected, (dimension, grouped)
                within(f"upload by {dimension}", int(grouped[0][1]), sent)
                within(f"download by {dimension}", int(grouped[0][2]), received)
            hourly = clickhouse(names["ch"], f"SELECT sum(bytes_in), sum(bytes_sent) FROM cdnf.edge_volume_hourly WHERE domain_id = {DOMAIN_ID}")[0]
            within("upload, hourly rollup", int(hourly[0]), sent)
            within("download, hourly rollup", int(hourly[1]), received)

            metrics = run("docker", "run", "--rm", "--network", network, CURL, "-fsS", f"http://{names['vector']}:9598/metrics").stdout
            def counter(name: str) -> float:
                return sum(float(value) for value in re.findall(rf'^{name}\{{[^}}]*edge="{EDGE}"[^}}]*\}} ([0-9.e+]+)(?: [0-9]+)?$', metrics, re.M))
            within("Prometheus bytes received", counter("cdnf_edge_bytes_received_total"), sent)
            within("Prometheus bytes sent", counter("cdnf_edge_bytes_sent_total"), received)
    finally:
        run("docker", "rm", "-fv", *names.values(), check=False)
        run("docker", "network", "rm", network, check=False)
    print("e2e traffic volume accuracy passed")


if __name__ == "__main__":
    main()
