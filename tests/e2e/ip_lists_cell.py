#!/usr/bin/env python3
"""Real-cell qualification of named IP lists: matching, activation, last valid state, and memory at the admitted maximum.

A cell with no control plane reads compiled lists from its runtime file, the way the agent writes them. The test checks that
  1. an allow-style and a deny-style rule agree with an independent reference on random IPv4 and IPv6 clients, members and not,
  2. a changed list takes effect on the running cell, and that a corrupt runtime file leaves the previous lists matching,
  3. a cell holding every list at its maximum size (the agent's 100,000-range cell budget, as IPv6) stays within the memory
     budget per worker, measured as the growth of the workers' resident memory over an identical cell with no lists.
The refusal of corrupt or oversized lists before activation is tested in the agent (edge-agent/ip_lists_test.go).
"""

import bisect
import http.client
import ipaddress
import json
import pathlib
import random
import sys
import tempfile
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from managed_waf import IMAGE, NETWORK, host, run  # noqa: E402
from rate_limit_isolation import address  # noqa: E402

CELL = "cdnf-e2e-ip-lists"
EMPTY = "cdnf-e2e-ip-lists-empty"
ORIGIN = "cdnf-e2e-ip-lists-origin"
MEMORY_BUDGET_MB_PER_WORKER = 64
CELL_RANGE_BUDGET = 100000
random.seed(11)


def compile_list(networks: list[ipaddress._BaseNetwork]) -> dict:
    """What the control plane does: sort, merge overlaps, and write whole numbers (IPv4) or four 32-bit words per address (IPv6)."""
    v4, v6 = [], []
    for network in sorted(networks, key=lambda item: (item.version, int(item.network_address))):
        (v4 if network.version == 4 else v6).append((int(network.network_address), int(network.broadcast_address)))

    def merge(ranges):
        merged = []
        for first, last in ranges:
            if merged and first <= merged[-1][1]:
                merged[-1] = (merged[-1][0], max(merged[-1][1], last))
            else:
                merged.append((first, last))
        return merged
    words = lambda value: [(value >> shift) & 0xFFFFFFFF for shift in (96, 64, 32, 0)]  # noqa: E731
    return {"v4": [n for pair in merge(v4) for n in pair], "v6": [n for pair in merge(v6) for n in words(pair[0]) + words(pair[1])]}


def random_networks(count: int, version: int) -> list:
    networks = []
    for _ in range(count):
        if version == 4:
            networks.append(ipaddress.ip_network((random.getrandbits(32), random.choice([32, 28, 24, 20])), strict=False))
        else:
            networks.append(ipaddress.ip_network(((0x20010db8 << 96) | random.getrandbits(96), random.choice([128, 120, 112, 104])), strict=False))
    return networks


class Reference:
    def __init__(self, compiled: dict):
        self.v4 = list(zip(compiled["v4"][0::2], compiled["v4"][1::2]))
        self.v6 = []
        for index in range(0, len(compiled["v6"]), 8):
            w = compiled["v6"][index:index + 8]
            self.v6.append((int.from_bytes(b"".join(x.to_bytes(4, "big") for x in w[:4]), "big"), int.from_bytes(b"".join(x.to_bytes(4, "big") for x in w[4:]), "big")))
        self.first4, self.first6 = [r[0] for r in self.v4], [r[0] for r in self.v6]

    def contains(self, text: str) -> bool:
        ip = ipaddress.ip_address(text)
        ranges, firsts = (self.v4, self.first4) if ip.version == 4 else (self.v6, self.first6)
        index = bisect.bisect_right(firsts, int(ip)) - 1
        return index >= 0 and int(ip) <= ranges[index][1]


def domain(name: str, identifier: int, origin_ip: str, condition_operator: str, list_name: str) -> dict:
    config = host("off", False, 0, origin_ip)
    config["domain"], config["domain_id"] = name, identifier
    security = config["security"]
    security["limits"].update({"requests_per_second": 100000, "request_burst": 100000, "connections_per_client": 4096, "connections_per_domain": 100000})
    security["trusted_proxy_cidrs"] = ["0.0.0.0/0", "::/0"]
    security["rules"] = [{"id": 1, "name": None, "priority": 1, "action": "block", "mode": "enforce", "expires_at": None, "rate_limit": None,
                          "conditions": [{"field": "client_ip_list", "operator": condition_operator, "value": list_name}]}]
    return config


def get(ip: str, hostname: str, client: str, connection: http.client.HTTPConnection | None = None) -> int:
    own = connection or http.client.HTTPConnection(ip, 8080, timeout=10)
    own.request("GET", "/", headers={"Host": hostname, "X-Forwarded-For": client})
    response = own.getresponse()
    response.read()
    if connection is None:
        own.close()
    return response.status


def write_runtime(path: pathlib.Path, hosts: dict, lists: dict, generation: str, sequence: int) -> None:
    path.write_text(json.dumps({"schema_version": 1, "sequence": sequence, "generation_id": generation, "certificates": {}, "hosts": hosts, "ip_lists": lists}, separators=(",", ":")))
    path.chmod(0o644)


def start(name: str, directory: pathlib.Path, cpus: str = "2") -> str:
    run("docker", "run", "-d", "--name", name, "--network", NETWORK, "--cpus", cpus, "--memory", "1g",
        "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
        "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro",
        "-v", f"{directory / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{directory / 'tls.key'}:/run/edge/tls.key:ro",
        "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", IMAGE)
    return address(name)


def wait_ready(ip: str, hostname: str) -> None:
    deadline = time.monotonic() + 90
    while time.monotonic() < deadline:
        try:
            if get(ip, hostname, "198.51.100.250") in (200, 403, 404, 502):
                return
        except OSError:
            pass
        time.sleep(1)
    raise AssertionError("the cell did not start")


def worker_memory_kb(name: str) -> list[int]:
    """Resident memory of each nginx worker, from the container's own process table."""
    output = run("docker", "exec", name, "sh", "-c", "for p in $(pgrep -f 'nginx: worker'); do awk '/VmRSS/ {print $2}' /proc/$p/status; done").stdout
    return [int(line) for line in output.split()]


def agree(ip: str, hostname: str, reference: Reference, members: list[str], others: list[str], deny_members: bool) -> None:
    connection = http.client.HTTPConnection(ip, 8080, timeout=10)
    for client in members + others:
        listed = reference.contains(client)
        status = get(ip, hostname, client, connection)
        expected = 403 if listed == deny_members else (200 if True else 0)
        assert (status == 403) == (listed == deny_members), f"{hostname} {client}: listed={listed} status={status}"
        time.sleep(0.008)
    connection.close()


def main() -> None:
    run("docker", "rm", "-f", CELL, EMPTY, ORIGIN, check=False)
    run("docker", "run", "-d", "--name", ORIGIN, "--network", NETWORK, "nginx:1.30.3-alpine")
    origin_ip = address(ORIGIN)
    with tempfile.TemporaryDirectory(prefix="cdnf-e2e-lists-") as directory:
        temporary = pathlib.Path(directory)
        temporary.chmod(0o755)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=lists.test", "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
        for item in (temporary / "tls.key", temporary / "tls.crt"):
            item.chmod(0o644)
        try:
            # 1. Correctness against an independent reference: one deny list and one allow list, each with IPv4 and IPv6 networks.
            deny = compile_list(random_networks(2500, 4) + random_networks(2500, 6))
            office = compile_list(random_networks(2500, 4) + random_networks(2500, 6))
            deny_ref, office_ref = Reference(deny), Reference(office)
            hosts = {"deny.lists.test": domain("deny.lists.test", 801, origin_ip, "equals", "deny"), "allow.lists.test": domain("allow.lists.test", 802, origin_ip, "not_equals", "office"),
                     "missing.lists.test": domain("missing.lists.test", 803, origin_ip, "equals", "nothing")}
            lists = {"801": {"deny": deny}, "802": {"office": office}}
            runtime = temporary / "active.json"
            write_runtime(runtime, hosts, lists, "lists-1", 1)
            ip = start(CELL, temporary)
            wait_ready(ip, "deny.lists.test")

            def sample(reference: Reference, count: int) -> tuple[list[str], list[str]]:
                members, others = [], []
                v4, v6 = reference.v4, reference.v6
                for index in range(count):
                    if index % 2 == 0:
                        first, last = random.choice(v4)
                        members.append(str(ipaddress.IPv4Address(random.randint(first, last))))
                    else:
                        first, last = random.choice(v6)
                        members.append(str(ipaddress.IPv6Address(random.randint(first, min(last, first + 2**64)))))
                    others.append(str(ipaddress.IPv4Address(random.getrandbits(32))) if index % 2 == 0 else str(ipaddress.IPv6Address((0x20010db8 << 96) | random.getrandbits(96))))
                return members, others
            members, others = sample(deny_ref, 150)
            agree(ip, "deny.lists.test", deny_ref, members, others, deny_members=True)
            members, others = sample(office_ref, 150)
            agree(ip, "allow.lists.test", office_ref, members, others, deny_members=False)
            # A rule that names a list the domain has matches nothing: not an error, not a block.
            assert get(ip, "missing.lists.test", "203.0.113.5") in (200, 404, 502)

            # 2. A changed list takes effect, and a corrupt runtime file leaves it matching.
            probe = members[0]
            assert get(ip, "allow.lists.test", probe) == 200
            changed = compile_list([ipaddress.ip_network("192.0.2.0/24")])
            started = time.monotonic()
            write_runtime(runtime, hosts, {"801": {"deny": deny}, "802": {"office": changed}}, "lists-2", 2)
            while get(ip, "allow.lists.test", probe) != 403:
                assert time.monotonic() - started < 30, "the changed list did not take effect"
                time.sleep(0.25)
            applied = time.monotonic() - started
            assert get(ip, "allow.lists.test", "192.0.2.77") in (200, 404, 502)
            runtime.write_text('{"schema_version": 1, "hosts": {"allow.lists.test": ')  # a torn write
            time.sleep(8)
            assert get(ip, "allow.lists.test", probe) == 403 and get(ip, "allow.lists.test", "192.0.2.77") in (200, 404, 502), "a corrupt runtime file replaced the active lists"
            write_runtime(runtime, hosts, {"801": {"deny": deny}, "802": {"office": changed}}, "lists-2", 2)

            # 3. Memory: the same cell with the lists of a full 100,000-range budget (IPv6, the largest form), against one with none.
            per_domain = CELL_RANGE_BUDGET // 4
            big_hosts, big_lists = {}, {}
            for number in range(4):
                name = f"full{number}.lists.test"
                big_hosts[name] = domain(name, 900 + number, origin_ip, "equals", "a")
                names = ["a", "b", "c", "d", "e"]
                big_lists[str(900 + number)] = {item: compile_list(random_networks(per_domain // 5, 6)) for item in names}
            total_ranges = sum(len(compiled["v6"]) // 8 + len(compiled["v4"]) // 2 for domain_lists in big_lists.values() for compiled in domain_lists.values())
            assert CELL_RANGE_BUDGET * 0.95 <= total_ranges <= CELL_RANGE_BUDGET, f'the memory test must hold a full budget, not {total_ranges} ranges'
            empty_directory = temporary / "empty"
            empty_directory.mkdir(mode=0o755)
            for item in ("tls.crt", "tls.key"):
                (empty_directory / item).write_bytes((temporary / item).read_bytes())
                (empty_directory / item).chmod(0o644)
            write_runtime(empty_directory / "active.json", big_hosts, {}, "empty-1", 1)
            empty_ip = start(EMPTY, empty_directory)
            wait_ready(empty_ip, "full0.lists.test")
            run("docker", "rm", "-f", CELL, check=False)
            ip = start(CELL, temporary)
            write_runtime(runtime, big_hosts, big_lists, "full-1", 3)
            deadline = time.monotonic() + 60
            while time.monotonic() < deadline:
                try:
                    if get(ip, "full0.lists.test", "203.0.113.1") in (200, 403, 404, 502):
                        break
                except OSError:
                    pass
                time.sleep(1)
            time.sleep(10)
            with_lists, without = worker_memory_kb(CELL), worker_memory_kb(EMPTY)
            runtime_bytes = runtime.stat().st_size
            growth = [(a - b) / 1024 for a, b in zip(sorted(with_lists), sorted(without))]
            print(json.dumps({"ranges": total_ranges, "runtime_file_mb": round(runtime_bytes / 1048576, 1), "workers": len(with_lists),
                              "growth_mb_per_worker": [round(x, 1) for x in growth], "applied_change_seconds": round(applied, 2)}))
            assert len(with_lists) >= 1 and all(value < MEMORY_BUDGET_MB_PER_WORKER for value in growth), f"worker memory grew {growth} MB, over the {MEMORY_BUDGET_MB_PER_WORKER} MB budget"
            # And the cell still answers correctly at full size: a member of the first list is blocked, a stranger is not.
            first = big_lists["900"]["a"]
            ref = Reference(first)
            member = str(ipaddress.IPv6Address(random.randint(*ref.v6[len(ref.v6) // 2])))
            assert get(ip, "full0.lists.test", member) == 403
            assert get(ip, "full0.lists.test", "2001:db8:ffff:ffff::1") in (200, 404, 502) or ref.contains("2001:db8:ffff:ffff::1")
            logs = run("docker", "logs", CELL, check=False)
            assert "no memory" not in logs.stdout + logs.stderr
        finally:
            run("docker", "rm", "-f", CELL, EMPTY, ORIGIN, check=False)
    print("e2e ip lists qualification passed")


if __name__ == "__main__":
    main()
