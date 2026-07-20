#!/usr/bin/env python3
"""Single-cell HTTP/HTTPS throughput qualification with explicit host details."""

from __future__ import annotations

import concurrent.futures
import json
import math
import os
import pathlib
import platform
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
EDGE_NETWORK = os.environ.get("CDNF_EDGE_NETWORK", "cdnfoundry-dev_edge")
EDGE_IMAGE = os.environ.get("CDNF_EDGE_IMAGE", "cdnfoundry/edge-runtime:phase8-throughput")
NAME = "cdnfoundry-phase8-throughput"
HOSTNAMES = [f"throughput-{index}.phase8.test" for index in range(64)]
HTTP_PORT = 18081
HTTPS_PORT = 18444
WORKERS = int(os.environ.get("CDNF_THROUGHPUT_WORKERS", "32"))
DURATION = float(os.environ.get("CDNF_THROUGHPUT_SECONDS", "8"))


def run(*args: str, timeout: int = 180, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=timeout, check=False)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed: {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def percentile(samples: list[float], value: float) -> float:
    ordered = sorted(samples)
    return ordered[min(len(ordered) - 1, int((len(ordered) - 1) * value))]


def benchmark(scheme: str, port: int, edge_address: str) -> dict[str, float | int]:
    samples: list[float] = []
    error_messages: dict[str, int] = {}
    duration = math.ceil(DURATION)

    def worker(index: int) -> subprocess.CompletedProcess[str]:
        hostname = HOSTNAMES[index % len(HOSTNAMES)]
        client_name = f"{NAME}-{scheme}-{index}"
        insecure = "-k" if scheme == "https" else ""
        script = (
            f'end=$(( $(date +%s) + {duration} )); '
            f'while [ "$(date +%s)" -lt "$end" ]; do '
            f'curl -sS {insecure} --http1.1 --connect-timeout 2 --max-time 3 '
            f'--resolve "{hostname}:{port}:{edge_address}" '
            f'-o /dev/null -w "%{{http_code}} %{{size_download}} %{{time_total}}\\n" '
            f'"{scheme}://{hostname}:{port}/phase8-throughput" '
            f'|| echo "curl_error 0 0"; sleep 0.012; done'
        )
        return run(
            "docker", "run", "--rm", "--name", client_name, "--network", EDGE_NETWORK,
            "--entrypoint", "sh", "curlimages/curl:8.16.0", "-c", script,
            timeout=duration + 30, check=False,
        )

    started = time.monotonic()
    with concurrent.futures.ThreadPoolExecutor(max_workers=WORKERS) as executor:
        futures = [executor.submit(worker, index) for index in range(WORKERS)]
        for future in futures:
            result = future.result()
            for line in result.stdout.splitlines():
                fields = line.split()
                if len(fields) != 3 or fields[0] != "200":
                    error_messages[fields[0] if fields else "empty_result"] = error_messages.get(
                        fields[0] if fields else "empty_result", 0,
                    ) + 1
                    continue
                samples.append(float(fields[2]) * 1000)
            if result.returncode != 0:
                error_messages[f"client_exit_{result.returncode}"] = error_messages.get(
                    f"client_exit_{result.returncode}", 0,
                ) + 1
    elapsed = time.monotonic() - started
    total_requests = len(samples)
    total_errors = sum(error_messages.values())
    if total_requests == 0:
        raise AssertionError(f"{scheme} benchmark completed no successful requests")
    error_rate = total_errors / max(1, total_requests + total_errors)
    if error_rate > 0.01:
        common_errors = sorted(error_messages.items(), key=lambda item: item[1], reverse=True)[:5]
        raise AssertionError(f"{scheme} error rate {error_rate:.4%} exceeded one percent: {common_errors}")
    return {
        "requests": total_requests,
        "errors": total_errors,
        "error_rate": round(error_rate, 6),
        "requests_per_second": round(total_requests / elapsed, 2),
        "latency_ms_p50": round(percentile(samples, 0.50), 3),
        "latency_ms_p95": round(percentile(samples, 0.95), 3),
        "latency_ms_p99": round(percentile(samples, 0.99), 3),
        "duration_seconds": round(elapsed, 3),
    }


def main() -> None:
    run("docker", "compose", "-f", "compose.dev.yml", "up", "-d", "origin-http", "mmdb-updater")
    run("docker", "build", "-f", "docker/openresty/Dockerfile", "-t", EDGE_IMAGE, ".", timeout=900)
    image_id = run("docker", "image", "inspect", "--format={{.Id}}", EDGE_IMAGE).stdout.strip()
    with tempfile.TemporaryDirectory(prefix="cdnfoundry-phase8-throughput-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        run(
            "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
            "-subj", f"/CN={HOSTNAMES[0]}", "-addext", "subjectAltName=" + ",".join(f"DNS:{host}" for host in HOSTNAMES),
            "-keyout", str(temporary / "edge.key"), "-out", str(temporary / "edge.crt"),
        )
        certificate = (temporary / "edge.crt").read_text()
        private_key = (temporary / "edge.key").read_text()
        runtime = {
            "schema_version": 1,
            "sequence": 1,
            "certificates": {
                "phase8-throughput": {
                    "id": "phase8-throughput", "certificate_pem": certificate, "chain_pem": "",
                    "private_key_pem": private_key, "expires_at": int(time.time()) + 86400, "names": HOSTNAMES,
                },
            },
            "hosts": {
                hostname: {
                    "domain": hostname, "revision": 1,
                    "settings": {"enabled": True, "redirect_https": False, "http_versions": ["1.1", "2"]},
                    "cache": {
                        "enabled": True, "edge_ttl_seconds": 3600, "browser_ttl_seconds": 300,
                        "maximum_object_bytes": 1048576, "respect_origin_headers": False,
                        "include_query_string": True, "bypass_cookie_names": [], "stale_if_error_seconds": 60,
                        "epoch": 1, "development_mode_until": None,
                    },
                    "origin": {
                        "host": "origin-http", "port": 80, "scheme": "http", "host_header": hostname,
                        "sni": None, "verify_tls": False, "connect_timeout_ms": 1000,
                        "response_timeout_ms": 5000, "retry_count": 0, "websocket": False,
                        "health_check": None, "private_allowlist": ["172.16.0.0/12"],
                        "blocked_networks": [], "blocked_addresses": [],
                    },
                    "tls": {"mode": "custom", "certificate_id": "phase8-throughput"},
                } for hostname in HOSTNAMES
            },
        }
        runtime_file = temporary / "shared-default.json"
        runtime_file.write_text(json.dumps(runtime, separators=(",", ":")))
        os.chown(runtime_file, 10101, 10101)
        runtime_file.chmod(0o600)
        run(
            "docker", "run", "-d", "--rm", "--name", NAME, "--network", EDGE_NETWORK,
            "--memory", "512m", "--cpus", "1", "--pids-limit", "128", "--ulimit", "nofile=65536:65536",
            "-p", f"127.0.0.1:{HTTP_PORT}:8080", "-p", f"127.0.0.1:{HTTPS_PORT}:8443",
            "-e", "EDGE_CELL_NAME=shared-default", "-e", "EDGE_RUNTIME_FILE=/runtime/shared-default.json",
            "-e", "EDGE_STATUS_TOKEN=phase8-throughput-only", "-e", "GEOIP_DATABASE=/mmdb/GeoLite2-City.mmdb",
            "-v", f"{runtime_file}:/runtime/shared-default.json:ro",
            "-v", "cdnfoundry-dev_dev-pki:/run/edge:ro", "-v", "cdnfoundry-dev_mmdb:/mmdb:ro",
            "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=256m",
            "--tmpfs", "/var/lib/nginx/tmp:rw,noexec,nosuid,size=64m", EDGE_IMAGE,
        )
        try:
            deadline = time.monotonic() + 30
            while time.monotonic() < deadline:
                ready = run("curl", "-fsS", "-H", f"Host: {HOSTNAMES[0]}", f"http://127.0.0.1:{HTTP_PORT}/phase8-throughput", check=False)
                if ready.returncode == 0:
                    break
                time.sleep(0.5)
            else:
                logs = run("docker", "logs", NAME, check=False).stdout
                raise RuntimeError(f"throughput edge did not become ready: {logs[-4000:]}")
            # Prime every deterministic key so measurements represent edge cache-hit capacity.
            for hostname in HOSTNAMES:
                run("curl", "-fsS", "-H", f"Host: {hostname}", f"http://127.0.0.1:{HTTP_PORT}/phase8-throughput")
                hit = run(
                    "curl", "-fsS", "-D", "-", "-o", "/dev/null", "-H", f"Host: {hostname}",
                    f"http://127.0.0.1:{HTTP_PORT}/phase8-throughput",
                ).stdout.lower()
                if "x-cdnfoundry-cache: hit" not in hit:
                    raise AssertionError(f"throughput path for {hostname} was not a cache HIT: {hit}")
            edge_address = run(
                "docker", "inspect", "--format={{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}", NAME,
            ).stdout.strip()
            http_result = benchmark("http", 8080, edge_address)
            https_result = benchmark("https", 8443, edge_address)
            host_memory = os.sysconf("SC_PAGE_SIZE") * os.sysconf("SC_PHYS_PAGES")
            cpu_model = next(
                (line.split(":", 1)[1].strip() for line in pathlib.Path("/proc/cpuinfo").read_text().splitlines() if line.startswith("model name")),
                platform.processor() or "unknown",
            )
            print(json.dumps({
                "phase8_throughput": "passed",
                "host": {
                    "kernel": platform.release(), "architecture": platform.machine(),
                    "cpu_model": cpu_model, "logical_cpus": os.cpu_count(),
                    "memory_bytes": host_memory,
                },
                "cell_limits": {"cpus": 1, "memory_bytes": 536870912, "pids": 128, "nofile": 65536},
                "edge_image_id": image_id,
                "profile": "single shared OpenResty cell; 64 cache-HIT domains; 32 isolated clients paced below the 100 rps/client runtime bound; HTTP/1.1 new connections",
                "http": http_result,
                "https": https_result,
            }, sort_keys=True))
        finally:
            run("docker", "stop", NAME, check=False)


if __name__ == "__main__":
    main()
