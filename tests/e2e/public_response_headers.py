#!/usr/bin/env python3
"""Real-cell qualification of the public response headers on a cell that has no control plane.

Five domains on one cell: one with the default prefix, one with a custom prefix and a PoP label, one with the headers switched
off, one with a snapshot that predates the field, and one that refuses requests with a rule. The origin forges headers under every
prefix. Checks: the reference is unique per request, never copied from an origin or a cache entry, and equals the logged
request_id; a cache hit shows its own reference and HIT; the forged values never reach the visitor; a refusal carries the
mitigation marker and no cache decision; a domain with the headers off sends none; an old snapshot keeps its previous behavior.
"""

import http.client
import json
import pathlib
import re
import sys
import tempfile
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, NETWORK, host, run  # noqa: E402
from rate_limit_isolation import address  # noqa: E402

CELL = "cdnf-e2e-public-headers"
ORIGIN = "cdnf-e2e-public-headers-origin"
FORGED = "forged-by-origin"
ORIGIN_CONF = f"""server {{
    listen 80;
    location / {{
        add_header cdnf-ray {FORGED};
        add_header cdnf-pop {FORGED};
        add_header edge-ray {FORGED};
        add_header edge-cache-status {FORGED};
        add_header Cache-Control "public, max-age=60";
        return 200 "ok";
    }}
}}
"""


def domain(name: str, identifier: int, origin_ip: str, public: dict | None, rules: list[dict] | None = None) -> dict:
    config = host("off", False, 0, origin_ip)
    config["domain"], config["domain_id"] = name, identifier
    config["cache"] = {"enabled": True, "epoch": 1, "edge_ttl_seconds": 60, "status_ttl_seconds": {"200": 60}, "respect_origin_headers": False}
    config["security"]["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 4096, "connections_per_domain": 100000})
    config["security"]["rules"] = rules or []
    if public is not None:
        config["settings"]["public_headers"] = public
    return config


def get(ip: str, hostname: str, path: str, extra: dict[str, str] | None = None) -> tuple[int, dict[str, str]]:
    connection = http.client.HTTPConnection(ip, 8080, timeout=10)
    connection.request("GET", path, headers={"Host": hostname, **(extra or {})})
    response = connection.getresponse()
    response.read()
    headers = {name.lower(): value for name, value in response.getheaders()}
    connection.close()
    return response.status, headers


def main() -> None:
    run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-public-headers-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        (temporary / "origin.conf").write_text(ORIGIN_CONF)
        (temporary / "origin.conf").chmod(0o644)
        run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "-v", f"{temporary / 'origin.conf'}:/etc/nginx/conf.d/default.conf:ro", "nginx:1.30.3-alpine")
        origin_ip = address(ORIGIN)
        block = [{"id": 1, "name": None, "priority": 1, "action": "block", "mode": "enforce", "conditions": [{"field": "path", "operator": "equals", "value": "/blocked"}]}]
        hosts = {
            "default.headers.test": domain("default.headers.test", 811, origin_ip, {"enabled": True, "prefix": "cdnf-", "pop": "", "diagnostics": False}, block),
            "custom.headers.test": domain("custom.headers.test", 812, origin_ip, {"enabled": True, "prefix": "edge-", "pop": "eu-west"}),
            "off.headers.test": domain("off.headers.test", 813, origin_ip, {"enabled": False, "prefix": "cdnf-", "pop": "eu-west"}),
            "diag.headers.test": domain("diag.headers.test", 815, origin_ip, {"enabled": True, "prefix": "cdnf-", "pop": "", "diagnostics": True}),
            "old.headers.test": domain("old.headers.test", 814, origin_ip, None),
        }
        runtime = temporary / "runtime.json"
        runtime.write_text(json.dumps({"schema_version": 1, "sequence": 9, "generation_id": "e2e-public-headers-1", "certificates": {}, "hosts": hosts}))
        runtime.chmod(0o644)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=headers.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        main_conf = run("docker", "run", "--rm", "--entrypoint", "cat", IMAGE, "/usr/local/openresty/nginx/conf/nginx.conf").stdout
        logged = main_conf.replace("access_log syslog:server=vector:9000,facility=local7,tag=cdnfoundry_edge,nohostname edge_json;", "access_log /dev/stdout edge_json;")
        assert logged != main_conf
        (temporary / "nginx.conf").write_text(logged)
        (temporary / "nginx.conf").chmod(0o644)
        try:
            run("docker", "run", "-d", "--name", CELL, "-v", f"{temporary / 'nginx.conf'}:/usr/local/openresty/nginx/conf/nginx.conf:ro", "--network", NETWORK,
                "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
                "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
                "-v", f"{pathlib.Path(__file__).resolve().parents[2] / 'docker/openresty/runtime.lua'}:/etc/cdnfoundry/runtime.lua:ro",
                "-v", f"{pathlib.Path(__file__).resolve().parents[2] / 'docker/nginx/proxy-cache.conf'}:/etc/nginx/proxy-cache.conf:ro",
                "-v", f"{pathlib.Path(__file__).resolve().parents[2] / 'docker/nginx/edge-runtime.conf'}:/etc/nginx/conf.d/default.conf:ro",
                "-v", f"{temporary / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{temporary / 'tls.key'}:/run/edge/tls.key:ro",
                "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
            ip = address(CELL)
            deadline = time.monotonic() + 60
            while time.monotonic() < deadline:
                try:
                    if get(ip, "default.headers.test", "/ready")[0] in (200, 404):
                        break
                except OSError:
                    pass
                time.sleep(1)
            else:
                raise AssertionError(run("docker", "logs", CELL, check=False).stderr)

            ray = re.compile(r"[0-9a-f]{32}")
            status, first = get(ip, "default.headers.test", "/page", {"cdnf-ray": "client-forged"})
            _, second = get(ip, "default.headers.test", "/page")
            assert status == 200 and ray.fullmatch(first["cdnf-ray"]) and ray.fullmatch(second["cdnf-ray"]), (first, second)
            assert first["cdnf-ray"] != second["cdnf-ray"], "every response has its own reference, a cache hit included"
            assert second["cdnf-cache-status"] == "HIT" and first["cdnf-cache-status"] in ("MISS", "BYPASS"), (first, second)
            assert "cdnf-pop" not in first and "cdnf-mitigated" not in first, "no PoP label is set, and nothing was refused"
            assert FORGED not in [value for name, value in first.items() if name.startswith("cdnf-")], first

            assert not [name for name in first if name.startswith("x-cdnf-") or name == "server" or name.startswith("cdnf-diag-")], f"diagnostics off sends none: {first}"
            _, diag = get(ip, "diag.headers.test", "/page")
            assert diag["cdnf-diag-origin-status"] == "200" and "server" in diag, diag
            assert not [name for name in diag if name.startswith("x-cdnf-")], f"the origin role and errors never reach a visitor: {diag}"

            _, custom = get(ip, "custom.headers.test", "/page")
            assert ray.fullmatch(custom["edge-ray"]) and custom["edge-pop"] == "eu-west" and custom["edge-cache-status"] != FORGED, custom
            assert custom.get("cdnf-ray") == FORGED, "only the domain's own prefix is reserved; the origin's other names pass"
            _, off = get(ip, "off.headers.test", "/page")
            assert not [name for name in off if name.startswith("cdnf-")], off
            _, old = get(ip, "old.headers.test", "/page")
            assert old.get("cdnf-ray") == FORGED, "a snapshot without the field keeps its previous behavior"

            refused_status, refused = get(ip, "default.headers.test", "/blocked")
            assert refused_status == 403 and ray.fullmatch(refused["cdnf-ray"]) and refused["cdnf-mitigated"] == "1" and "cdnf-cache-status" not in refused, (refused_status, refused)

            time.sleep(1)
            logs = run("docker", "logs", CELL, check=False)
            ids = {json.loads(line)["request_id"] for line in (logs.stdout + logs.stderr).splitlines() if line.startswith("{")}
            for reference in (first["cdnf-ray"], second["cdnf-ray"], custom["edge-ray"], refused["cdnf-ray"]):
                assert reference in ids, "the visitor's reference must be the logged request_id"
            assert "attempt to" not in logs.stdout + logs.stderr, logs.stderr[-800:]
            print("public headers verified: unique per-request reference equal to the logged request_id, forged values removed, off and old snapshots handled")
        finally:
            run("docker", "rm", "-f", CELL, ORIGIN, check=False)
    print("e2e public response headers qualification passed")


if __name__ == "__main__":
    main()
