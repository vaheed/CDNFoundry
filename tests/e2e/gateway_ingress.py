#!/usr/bin/env python3
"""Real gateway qualification. This does not automate a browser."""

import json
import pathlib
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
COMPOSE = ["docker", "compose", "--env-file", ".env.dev", "-f", "compose.dev.yml"]
GATEWAY = "cdnfoundry-dev-edge-gateway-a-1"
IPV4_ONLY_GATEWAY = "cdnfoundry-dev-edge-gateway-b-1"
STATE_VOLUME = "cdnfoundry-dev_edge-a-state"
IPV4_ONLY_STATE_VOLUME = "cdnfoundry-dev_edge-b-state"


def run(*command: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(command, cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if check and result.returncode:
        raise RuntimeError(f"{' '.join(command)} failed ({result.returncode}):\n{result.stdout}")
    return result


def metrics(container: str = GATEWAY) -> dict[str, int]:
    output = run("docker", "exec", container, "wget", "-qO-", "http://127.0.0.1:9105/metrics").stdout
    return {line.split()[0]: int(line.split()[1]) for line in output.splitlines() if len(line.split()) == 2}


def runtime(volume: str = STATE_VOLUME) -> tuple[dict, dict]:
    script = (
        "import json;"
        "print(json.dumps(json.load(open('/state/gateway.json'))));"
        "print(json.dumps(json.load(open('/state/active.json'))))"
    )
    output = run("docker", "run", "--rm", "-v", f"{volume}:/state:ro", "python:3.13-alpine", "python", "-c", script).stdout
    gateway, cell = output.splitlines()
    return json.loads(gateway), json.loads(cell)


def strictly_servable_tls_hostname(cell: dict, routed_hostnames: set[str]) -> str:
    certificates = cell.get("certificates", {})
    candidates = []
    for hostname, value in cell["hosts"].items():
        if hostname not in routed_hostnames:
            continue
        certificate_id = value.get("tls", {}).get("certificate_id")
        certificate = certificates.get(certificate_id)
        if not certificate or certificate.get("expires_at", 0) <= int(time.time()):
            continue
        names = certificate.get("names", [])
        if hostname in names or any(
            name.startswith("*.") and hostname.endswith(name[1:])
            for name in names
        ):
            # UUIDv7 certificate IDs are creation ordered. Prefer the newest
            # active fixture instead of an arbitrary historical host retained
            # in the persistent qualification database.
            candidates.append((certificate_id, hostname))
    if not candidates:
        raise RuntimeError("runtime has no unexpired, name-matched TLS host and certificate")
    return max(candidates)[1]


def curl(address: str, hostname: str, tls: bool = False, expect_success: bool = True,
         gateway: str = GATEWAY, tls_ca: pathlib.Path | None = None) -> str:
    url = f"{'https' if tls else 'http'}://{hostname}/"
    command = [
        "docker", "run", "--rm", "--network", f"container:{gateway}", "curlimages/curl:8.16.0",
        "--noproxy", "*", "--max-time", "10", "-sS", "-D", "-", "-o", "/dev/null",
        "--resolve", f"{hostname}:{443 if tls else 80}:{'[' + address + ']' if ':' in address else address}",
    ]
    if tls:
        if tls_ca is None:
            raise RuntimeError("strict TLS probe requires a CA certificate")
        command[5:5] = ["-v", f"{tls_ca}:/tls/ca.pem:ro"]
        command.extend(["--cacert", "/tls/ca.pem"])
    result = run(*command, url, check=False)
    if expect_success and (result.returncode or "server: openresty" not in result.stdout.lower()):
        raise RuntimeError(f"route {address}/{hostname} did not reach OpenResty:\n{result.stdout}")
    if not expect_success and result.returncode == 0:
        raise RuntimeError(f"unknown route {address}/{hostname} was accepted")
    return result.stdout


def isolated_last_valid_test() -> None:
    with tempfile.TemporaryDirectory(prefix="cdnfoundry-gateway-") as temporary:
        base = pathlib.Path(temporary)
        config_path, state_path = base / "config", base / "state"
        config_path.mkdir()
        state_path.mkdir()
        state_path.chmod(0o777)
        candidate = {
            "schema_version": 1,
            "revision": 17,
            "listeners": ["127.0.0.50:80", "127.0.0.50:443"],
            "routes": [{
                "address": "127.0.0.50", "hostname": "last-valid.example.test",
                "http": "127.0.0.1:9", "https": "127.0.0.1:9",
            }],
        }
        (config_path / "gateway.json").write_text(json.dumps(candidate))
        container = "cdnfoundry-gateway-last-valid-e2e"
        run(
            "docker", "run", "-d", "--rm", "--name", container,
            "--cap-drop", "ALL", "--cap-add", "NET_BIND_SERVICE",
            "-v", f"{config_path}:/config", "-v", f"{state_path}:/state",
            "-e", "GATEWAY_CONFIG_FILE=/config/gateway.json",
            "-e", "GATEWAY_STATE_DIR=/state",
            "-e", "GATEWAY_METRICS_ADDRESS=127.0.0.1:9105",
            "cdnfoundry/edge-gateway:qualification",
        )
        try:
            time.sleep(2)
            before = metrics(container)
            (config_path / "gateway.json").write_text('{"schema_version":1,"revision":18,"listeners":[],"routes":[]}')
            time.sleep(2)
            after = metrics(container)
            if after["cdnfoundry_gateway_active_revision"] != 17:
                raise RuntimeError("invalid candidate replaced the active gateway map")
            if after["cdnfoundry_gateway_candidate_rejections_total"] <= before["cdnfoundry_gateway_candidate_rejections_total"]:
                raise RuntimeError("invalid candidate rejection was not observable")
            run("docker", "stop", container)
            restart_container = container + "-restart"
            run(
                "docker", "run", "-d", "--rm", "--name", restart_container,
                "--cap-drop", "ALL", "--cap-add", "NET_BIND_SERVICE",
                "-v", f"{config_path}:/config:ro", "-v", f"{state_path}:/state",
                "-e", "GATEWAY_CONFIG_FILE=/config/missing.json",
                "-e", "GATEWAY_STATE_DIR=/state",
                "-e", "GATEWAY_METRICS_ADDRESS=127.0.0.1:9105",
                "cdnfoundry/edge-gateway:qualification",
            )
            time.sleep(2)
            if metrics(restart_container)["cdnfoundry_gateway_active_revision"] != 17:
                raise RuntimeError("gateway restart did not restore the last valid map")
        finally:
            run("docker", "stop", container, check=False)
            run("docker", "stop", container + "-restart", check=False)


def multi_cell_pool_test() -> None:
    network = "cdnfoundry-multi-cell-e2e"
    gateway = "cdnfoundry-multi-cell-gateway-e2e"
    backends = [f"cdnfoundry-multi-cell-{slot}-e2e" for slot in range(1, 4)]
    run("docker", "network", "create", network)
    try:
        with tempfile.TemporaryDirectory(prefix="cdnfoundry-multi-cell-gateway-") as temporary:
            base = pathlib.Path(temporary)
            base.chmod(0o755)
            for slot, backend in enumerate(backends, 1):
                config = base / f"cell-{slot:02d}.conf"
                config.write_text(
                    "events {} http { server { listen 8080 proxy_protocol; "
                    f"location / {{ return 200 'cell-{slot:02d}'; }} }} }}"
                )
                run("docker", "run", "-d", "--rm", "--name", backend, "--network", network,
                    "-v", f"{config}:/etc/nginx/nginx.conf:ro", "nginx:1.29-alpine")
            state = base / "state"
            state.mkdir()
            state.chmod(0o777)
            candidate = {
                "schema_version": 1, "revision": 23,
                "listeners": ["127.0.0.50:80"],
                "routes": [{
                    "address": "127.0.0.50", "hostname": f"domain-{slot}.example.test",
                    "http": f"{backend}:8080", "https": f"{backend}:8080",
                } for slot, backend in enumerate(backends, 1)],
            }
            (base / "gateway.json").write_text(json.dumps(candidate))
            run("docker", "run", "-d", "--rm", "--name", gateway, "--network", network,
                "--cap-drop", "ALL", "--cap-add", "NET_BIND_SERVICE",
                "-v", f"{base}:/config:ro", "-v", f"{state}:/state",
                "-e", "GATEWAY_CONFIG_FILE=/config/gateway.json",
                "-e", "GATEWAY_STATE_DIR=/state",
                "-e", "GATEWAY_METRICS_ADDRESS=127.0.0.1:9105",
                "cdnfoundry/edge-gateway:qualification")
            time.sleep(2)
            for slot in range(1, 4):
                hostname = f"domain-{slot}.example.test"
                response = run("docker", "run", "--rm", "--network", f"container:{gateway}",
                    "curlimages/curl:8.16.0", "--noproxy", "*", "-fsS", "--max-time", "5",
                    "-H", f"Host: {hostname}", "http://127.0.0.50/").stdout.strip()
                if response != f"cell-{slot:02d}":
                    raise RuntimeError(f"{hostname} reached {response!r}, expected cell-{slot:02d}")
            unknown = run("docker", "run", "--rm", "--network", f"container:{gateway}",
                "curlimages/curl:8.16.0", "--noproxy", "*", "-fsS", "--max-time", "5",
                "-H", "Host: unrelated.example.test", "http://127.0.0.50/", check=False)
            if unknown.returncode == 0:
                raise RuntimeError("non-participating hostname reached a multi-cell pool")
    finally:
        run("docker", "stop", gateway, check=False)
        for backend in backends:
            run("docker", "stop", backend, check=False)
        run("docker", "network", "rm", network, check=False)


def main() -> None:
    gateway, cell = runtime()
    dual_stack_hostnames = {
        route["hostname"] for route in gateway["routes"]
        if {candidate["address"] for candidate in gateway["routes"]
            if candidate["hostname"] == route["hostname"]}
        >= {"172.28.10.10", "fd00:cd0f:10::10"}
    }
    hostname = strictly_servable_tls_hostname(
        cell, dual_stack_hostnames,
    )
    addresses = {route["address"] for route in gateway["routes"] if route["hostname"] == hostname}
    if not {"172.28.10.10", "fd00:cd0f:10::10"}.issubset(addresses):
        raise RuntimeError(f"dual-stack route missing for {hostname}: {sorted(addresses)}")
    revision = metrics()["cdnfoundry_gateway_active_revision"]
    with tempfile.TemporaryDirectory(prefix="cdnfoundry-gateway-ca-") as ca_directory:
        ca_path = pathlib.Path(ca_directory) / "root.pem"
        pathlib.Path(ca_directory).chmod(0o777)
        run(
            "docker", "run", "--rm", "--network", "cdnfoundry-dev_control",
            "-v", f"{ca_directory}:/out", "curlimages/curl:8.16.0", "-ksS",
            "https://pebble:15000/roots/0", "-o", "/out/root.pem",
        )
        for address in ("172.28.10.10", "fd00:cd0f:10::10"):
            curl(address, hostname)
            curl(address, hostname, tls=True, tls_ca=ca_path)
            curl(address, "unknown-gateway.example.test", expect_success=False)
        run(*COMPOSE, "restart", "edge-gateway-a")
        time.sleep(2)
        if metrics()["cdnfoundry_gateway_active_revision"] != revision:
            raise RuntimeError("gateway restart changed the active revision")
        curl("172.28.10.10", hostname)

        ipv4_gateway, ipv4_cell = runtime(IPV4_ONLY_STATE_VOLUME)
        ipv4_hostname = strictly_servable_tls_hostname(
            ipv4_cell, {route["hostname"] for route in ipv4_gateway["routes"]},
        )
        ipv4_addresses = {route["address"] for route in ipv4_gateway["routes"] if route["hostname"] == ipv4_hostname}
        if "172.28.20.10" not in ipv4_addresses or any(":" in address for address in ipv4_addresses):
            raise RuntimeError(f"IPv4-only gateway unexpectedly required another family: {sorted(ipv4_addresses)}")
        curl("172.28.20.10", ipv4_hostname, gateway=IPV4_ONLY_GATEWAY)
        curl("172.28.20.10", ipv4_hostname, tls=True, gateway=IPV4_ONLY_GATEWAY, tls_ca=ca_path)

    isolated_last_valid_test()
    multi_cell_pool_test()
    print(json.dumps({
        "status": "passed", "revision": revision, "hostname": hostname,
        "families": ["IPv4", "IPv6"], "protocols": ["HTTP Host", "HTTPS SNI"],
        "tls_verification": "strict",
        "ipv4_only_gateway": {"hostname": ipv4_hostname, "addresses": sorted(ipv4_addresses)},
        "metrics": metrics(),
    }, indent=2))


if __name__ == "__main__":
    main()
