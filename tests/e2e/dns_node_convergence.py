#!/usr/bin/env python3
"""Twenty real PowerDNS secondaries follow one hidden primary through a catalog zone with TSIG-signed transfers.

The nodes are driven the way the PoP agent drives them (the same local API calls), so this measures
PowerDNS itself: how long a change takes to reach every node, that an update and a deletion reach
all of them, and that an unsigned transfer is refused.
"""

import concurrent.futures
import json
import pathlib
import subprocess
import tempfile
import time
import urllib.request

IMAGE = "powerdns/pdns-auth-51:5.1.4@sha256:bb5b1c133bcca1dd455075321de7d55db4945a8d7f2ba23339e3c7bbe416b205"
NETWORK = "cdnf-dns-convergence"
SUBNET = "172.31.77.0/24"
PRIMARY_IP = "172.31.77.10"
NODES = 20
ZONES = 200
CATALOG = "catalog.cdnfoundry.invalid."
KEY = "cdnf-nodes"
SECRET = "c2VjcmV0LWZvci10aGUtY29udmVyZ2VuY2UtdGVzdC0wMDAwMDA="
API_KEY = "convergence-test"
# The documented bound for a node that is polled every few seconds: a change reaches every node within this.
CONVERGENCE_BOUND_SECONDS = 60


def docker(*args: str, timeout: int = 120) -> subprocess.CompletedProcess[str]:
    return subprocess.run(["docker", *args], text=True, capture_output=True, timeout=timeout, check=False)


def api(address: str, method: str, path: str, body: dict | None = None) -> tuple[int, dict | list | None]:
    request = urllib.request.Request(f"http://{address}:8081/api/v1/servers/localhost{path}", method=method,
                                     data=json.dumps(body).encode() if body is not None else None,
                                     headers={"X-API-Key": API_KEY, "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            data = response.read()
            return response.status, json.loads(data) if data else None
    except urllib.error.HTTPError as error:
        return error.code, None


def write_config(directory: pathlib.Path, name: str, primary: bool) -> pathlib.Path:
    path = directory / f"{name}.conf"
    path.write_text("\n".join([
        "launch=gsqlite3", "gsqlite3-database=/data/pdns.sqlite3", "gsqlite3-dnssec=no",
        f"primary={'yes' if primary else 'no'}", f"secondary={'no' if primary else 'yes'}",
        "api=yes", f"api-key={API_KEY}", "webserver=yes", "webserver-address=0.0.0.0", "webserver-port=8081",
        "webserver-allow-from=0.0.0.0/0", "local-address=0.0.0.0", f"local-port={5300 if primary else 53}",
        "enable-lua-records=yes", "default-soa-content=ns1.test hostmaster.test 0 10800 3600 604800 3600", "",
    ]))
    path.chmod(0o644)
    return path


def start(name: str, config: pathlib.Path, address: str) -> str:
    volume = f"{name}-data"
    docker("volume", "create", volume)
    docker("run", "--rm", "--user", "root", "-v", f"{volume}:/data", "--entrypoint", "sh", IMAGE, "-c",
           "sqlite3 /data/pdns.sqlite3 < /usr/local/share/doc/pdns/schema.sqlite3.sql && chown -R pdns /data")
    result = docker("run", "-d", "--name", name, "--network", NETWORK, "--ip", address, "--memory", "96m",
                    "-v", f"{volume}:/data", "-v", f"{config}:/etc/powerdns/pdns.conf:ro", IMAGE,
                    "--daemon=no", "--guardian=no", "--disable-syslog")
    assert result.returncode == 0, result.stderr
    return address


def wait_api(address: str) -> None:
    deadline = time.time() + 60
    while time.time() < deadline:
        try:
            if api(address, "GET", "")[0] == 200:
                return
        except OSError:
            pass
        time.sleep(0.5)
    raise AssertionError(f"{address} never answered its API")


def agent_step(address: str, zones: list[str]) -> None:
    """What the agent does for one node: install the key and catalog once, then sign and pull each zone."""
    if api(address, "GET", f"/tsigkeys/{KEY}")[0] == 404:
        api(address, "POST", "/tsigkeys", {"name": KEY, "algorithm": "hmac-sha256", "key": SECRET})
        api(address, "POST", "/zones", {"name": CATALOG, "kind": "Consumer", "masters": [f"{PRIMARY_IP}:5300"], "slave_tsig_key_ids": [KEY]})
    api(address, "PUT", f"/zones/{CATALOG}/axfr-retrieve")
    for zone in zones:
        status, body = api(address, "GET", f"/zones/{zone}")
        if status != 200:
            continue
        if not body.get("slave_tsig_key_ids"):
            api(address, "PUT", f"/zones/{zone}", {"slave_tsig_key_ids": [KEY]})
        api(address, "PUT", f"/zones/{zone}/axfr-retrieve")


def node_answers(address: str, zone: str, owner: str, value: str) -> bool:
    status, body = api(address, "GET", f"/zones/{zone}")
    if status != 200 or not body:
        return False
    return any(value in [r["content"] for r in rrset["records"]] for rrset in body["rrsets"] if rrset["name"] == owner and rrset["type"] == "A")


def converge(addresses: list[str], zones: list[str], check, bound: int) -> float:
    """Poll every node the way agents do until all report the expected state; return the elapsed seconds."""
    started = time.time()
    pending = set(addresses)
    with concurrent.futures.ThreadPoolExecutor(max_workers=NODES) as pool:
        while pending and time.time() - started < bound:
            list(pool.map(lambda address: agent_step(address, zones), sorted(pending)))
            pending = {address for address in pending if not check(address)}
            if pending:
                time.sleep(3)
    assert not pending, f"{len(pending)} of {len(addresses)} nodes did not converge within {bound}s: {sorted(pending)[:3]}"
    return time.time() - started


def main() -> None:
    cleanup()
    docker("network", "create", "--subnet", SUBNET, NETWORK)
    with tempfile.TemporaryDirectory() as scratch:
        directory = pathlib.Path(scratch)
        directory.chmod(0o755)
        try:
            primary = start("cdnf-sim-primary", write_config(directory, "primary", True), PRIMARY_IP)
            node_config = write_config(directory, "node", False)
            nodes = [start(f"cdnf-sim-node-{index}", node_config, f"172.31.77.{20 + index}") for index in range(NODES)]
            for address in [primary, *nodes]:
                wait_api(address)

            api(primary, "POST", "/tsigkeys", {"name": KEY, "algorithm": "hmac-sha256", "key": SECRET})
            api(primary, "POST", "/zones", {"name": CATALOG, "kind": "Producer", "nameservers": []})
            api(primary, "POST", f"/zones/{CATALOG}/metadata", {"kind": "TSIG-ALLOW-AXFR", "metadata": [KEY]})
            zones = [f"zone{index}.test." for index in range(ZONES)]

            def create(zone: str) -> None:
                api(primary, "POST", "/zones", {"name": zone, "kind": "Master", "nameservers": ["ns1.test."], "catalog": CATALOG, "soa_edit_api": "INCREASE",
                                                "rrsets": [{"name": f"www.{zone}", "type": "A", "ttl": 60, "records": [{"content": "192.0.2.1", "disabled": False}]},
                                                           {"name": zone, "type": "SOA", "ttl": 60, "records": [{"content": "ns1.test. hostmaster.test. 1 10800 3600 604800 60", "disabled": False}]}]})
                api(primary, "POST", f"/zones/{zone}/metadata", {"kind": "TSIG-ALLOW-AXFR", "metadata": [KEY]})

            with concurrent.futures.ThreadPoolExecutor(max_workers=16) as pool:
                list(pool.map(create, zones))

            # An unsigned transfer must be refused.
            unsigned = docker("run", "--rm", "--network", NETWORK, "alpine", "sh", "-c",
                              f"apk add -q bind-tools >/dev/null && dig @{PRIMARY_IP} -p 5300 {zones[0]} AXFR +tcp +noall +comments | grep -c NOTAUTH")
            assert unsigned.stdout.strip() == "1", f"an unsigned AXFR was not refused: {unsigned.stdout} {unsigned.stderr}"

            def has_all(address: str) -> bool:
                return all(node_answers(address, zone, f"www.{zone}", "192.0.2.1") for zone in zones[::10])

            first = converge(nodes, zones, has_all, 300)
            print(f"{NODES} nodes pulled {ZONES} zones in {first:.1f}s")

            # An update to some zones reaches every node.
            changed = zones[:20]
            for zone in changed:
                api(primary, "PATCH", f"/zones/{zone}", {"rrsets": [
                    {"name": f"www.{zone}", "type": "A", "ttl": 60, "changetype": "REPLACE", "records": [{"content": "192.0.2.99", "disabled": False}]},
                    {"name": zone, "type": "SOA", "ttl": 60, "changetype": "REPLACE", "records": [{"content": "ns1.test. hostmaster.test. 2 10800 3600 604800 60", "disabled": False}]}]})
            updated = converge(nodes, changed, lambda a: all(node_answers(a, z, f"www.{z}", "192.0.2.99") for z in changed), CONVERGENCE_BOUND_SECONDS)
            print(f"an update to {len(changed)} zones reached {NODES} nodes in {updated:.1f}s")

            # A deleted zone leaves every node through the catalog.
            api(primary, "DELETE", f"/zones/{zones[-1]}")
            deadline = time.time() + CONVERGENCE_BOUND_SECONDS
            remaining = list(nodes)
            while remaining and time.time() < deadline:
                for address in nodes:
                    api(address, "PUT", f"/zones/{CATALOG}/axfr-retrieve")
                time.sleep(3)
                remaining = [a for a in nodes if api(a, "GET", f"/zones/{zones[-1]}")[0] == 200]
            assert not remaining, f"{len(remaining)} nodes still serve a deleted zone"
            assert max(first, updated) <= CONVERGENCE_BOUND_SECONDS * 5
            print("dns node convergence qualification passed")
        finally:
            cleanup()


def cleanup() -> None:
    names = docker("ps", "-aq", "--filter", "name=cdnf-sim-").stdout.split()
    if names:
        docker("rm", "-f", *names)
    volumes = docker("volume", "ls", "-q", "--filter", "name=cdnf-sim-").stdout.split()
    if volumes:
        docker("volume", "rm", *volumes)
    docker("network", "rm", NETWORK)


if __name__ == "__main__":
    main()
