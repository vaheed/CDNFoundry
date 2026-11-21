#!/usr/bin/env python3
"""Reproducible, loss-aware capacity benchmark of the authoritative DNS path: DNSdist in front of PowerDNS.

It runs on its own Docker network and never touches the development stack: PowerDNS with the repository's
pdns.conf (SQLite and GeoIP backends) and a generated zone set, DNSdist with the repository's dnsdist.conf
(or a variant given with --dnsdist-conf), a Vector that receives DNSdist's dnstap stream exactly as in
production and discards it, and dnsperf as the load generator. For every offered rate it reports answered
queries per second, loss, latency, the average response size, how many answers DNSdist truncated or served
from its cache, the kernel's UDP receive-buffer drops, and the CPU each server really used (cgroup).

The response size matters: a truncated reply (TC) is counted by dnsperf as a completed NOERROR answer, but it
carries no records. The per-source rule MaxQPSIPRule(500) truncates everything one load generator sends above
500 queries per second, so --lift-source-limit raises it in a copy of the configuration (the rule still runs).

Usage (one JSON line per rate):

    python3 tests/e2e/dns_benchmark.py --rates 5000,10000,20000,40000 --lift-source-limit
"""

from __future__ import annotations

import argparse
import json
import os
import pathlib
import platform
import random
import re
import subprocess
import tempfile
import time
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]
NETWORK = "cdnf-dnsbench"
SUBNET = "192.168.251.0/24"
PDNS, DNSDIST, VECTOR, LOAD = "cdnf-dnsbench-pdns", "cdnf-dnsbench-dnsdist", "cdnf-dnsbench-vector", "cdnf-dnsbench-load"
PDNS_IMAGE = "powerdns/pdns-auth-51:5.1.4@sha256:bb5b1c133bcca1dd455075321de7d55db4945a8d7f2ba23339e3c7bbe416b205"
DNSDIST_IMAGE = "powerdns/dnsdist-21:2.1.2@sha256:efabf173811e27438bebee5f1f40e047f263ea39093a8f7d6097df494cf1ed67"
VECTOR_IMAGE = "timberio/vector:0.58.0-debian@sha256:1c1ea358c617ea0b23003d5af87f7a678b30f8f7096437e680380c47fc13d2d9"
LOAD_IMAGE = os.environ.get("CDNF_DNSPERF_IMAGE", "cdnf-dnsperf:latest")
VECTOR_CONFIG = """sources:
  dns_dnstap: {type: dnstap, address: "0.0.0.0:6000", mode: tcp, connection_limit: 32, max_frame_length: 102400, multithreaded: true}
sinks:
  discard: {type: blackhole, inputs: [dns_dnstap], print_interval_secs: 0}
"""


def run(*args: str, timeout: int = 300, check: bool = True, input_text: str | None = None) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, timeout=timeout, check=False, input=input_text)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed: {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def cgroup(container: str) -> pathlib.Path:
    full_id = run("docker", "inspect", "--format={{.Id}}", container).stdout.strip()
    for candidate in (f"/sys/fs/cgroup/system.slice/docker-{full_id}.scope", f"/sys/fs/cgroup/docker/{full_id}"):
        if pathlib.Path(candidate).exists():
            return pathlib.Path(candidate)
    raise RuntimeError(f"cgroup of {container} not found")


def cpu_usec(path: pathlib.Path) -> tuple[int, int]:
    stat = dict(line.split() for line in (path / "cpu.stat").read_text().splitlines())
    return int(stat["usage_usec"]), int(stat["throttled_usec"])


def udp_errors(container: str) -> dict[str, int]:
    pid = run("docker", "inspect", "--format={{.State.Pid}}", container).stdout.strip()
    lines = [line.split() for line in pathlib.Path(f"/proc/{pid}/net/snmp").read_text().splitlines() if line.startswith("Udp:")]
    return dict(zip(lines[0][1:], (int(value) for value in lines[1][1:])))


def dnsdist_counters(address: str) -> dict[str, float]:
    with urllib.request.urlopen(f"http://{address}:8083/metrics", timeout=5) as response:  # noqa: S310 - fixed benchmark address
        text = response.read().decode()
    counters: dict[str, float] = {}
    for line in text.splitlines():
        match = re.match(r"^(dnsdist_[a-z_]+)(?:\{[^}]*\})? ([0-9.e+]+)$", line)
        if match:
            counters[match.group(1)] = counters.get(match.group(1), 0.0) + float(match.group(2))
    return counters


def address(container: str) -> str:
    return run("docker", "inspect", "--format", "{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}", container).stdout.strip()


def cleanup() -> None:
    for name in (LOAD, DNSDIST, PDNS, VECTOR):
        run("docker", "rm", "-f", name, check=False)
    run("docker", "network", "rm", NETWORK, check=False)


def zone_sql(zones: int) -> str:
    statements = ["BEGIN;"]
    for index in range(1, zones + 1):
        zone = f"zone{index}.bench"
        statements.append(f"INSERT INTO domains (id, name, type) VALUES ({index}, '{zone}', 'NATIVE');")
        rows = [
            (zone, "SOA", f"ns1.{zone} hostmaster.{zone} 1 10800 3600 604800 300", 3600),
            (zone, "NS", f"ns1.{zone}", 3600), (zone, "NS", f"ns2.{zone}", 3600),
            (zone, "A", f"192.0.2.{index % 250 + 1}", 300), (zone, "AAAA", f"2001:db8::{index:x}", 300),
            (f"www.{zone}", "CNAME", zone, 300), (f"api.{zone}", "A", "198.51.100.10", 60),
            (f"ns1.{zone}", "A", "192.0.2.53", 3600), (f"ns2.{zone}", "A", "192.0.2.54", 3600),
        ]
        for name, kind, content, ttl in rows:
            statements.append("INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, auth) "
                              f"VALUES ({index}, '{name}', '{kind}', '{content}', {ttl}, 0, 0, 1);")
    statements.append("COMMIT;")
    return "\n".join(statements)


def query_file(zones: int, profile: str, count: int = 200000) -> str:
    generator = random.Random(7)
    lines = []
    for _ in range(count):
        zone = f"zone{generator.randint(1, zones)}.bench"
        roll = generator.random()
        if profile == "nxdomain" or (profile == "mixed" and roll < 0.2):
            lines.append(f"r{generator.getrandbits(40):x}.{zone} A")
        elif roll < 0.6:
            lines.append(f"{zone} A")
        elif roll < 0.75:
            lines.append(f"{zone} AAAA")
        elif roll < 0.9:
            lines.append(f"www.{zone} A")
        else:
            lines.append(f"api.{zone} A")
    return "\n".join(lines) + "\n"


def dnsdist_config(args: argparse.Namespace) -> str:
    source = pathlib.Path(args.dnsdist_conf) if args.dnsdist_conf else ROOT / "docker/dnsdist/dnsdist.conf"
    text = source.read_text()
    if args.lift_source_limit:
        text, count = re.subn(r"MaxQPSIPRule\(500,", "MaxQPSIPRule(100000000,", text)
        if count != 1:
            raise RuntimeError("dnsdist.conf no longer has the MaxQPSIPRule(500, ...) this benchmark lifts")
    if args.lift_global_limit:
        text, count = re.subn(r"MaxQPSRule\(50000\)", "MaxQPSRule(100000000)", text)
        if count != 1:
            raise RuntimeError("dnsdist.conf no longer has the MaxQPSRule(50000) this benchmark lifts")
    return text


def start(args: argparse.Namespace, directory: pathlib.Path) -> None:
    run("docker", "network", "create", "--subnet", SUBNET, NETWORK)
    data = directory / "pdns"
    data.mkdir()
    data.chmod(0o777)
    (directory / "zones.sql").write_text(zone_sql(args.zones))
    run("docker", "run", "--rm", "-v", f"{data}:/data", "-v", f"{directory / 'zones.sql'}:/zones.sql:ro", "--entrypoint", "sh", PDNS_IMAGE,
        "-c", "sqlite3 /data/pdns.sqlite3 < /usr/local/share/doc/pdns/schema.sqlite3.sql && sqlite3 /data/pdns.sqlite3 < /zones.sql "
              "&& chmod 666 /data/pdns.sqlite3", timeout=900)
    (directory / "vector.yaml").write_text(VECTOR_CONFIG)
    run("docker", "run", "-d", "--name", VECTOR, "--network", NETWORK, "--network-alias", "vector", "--cpus", "2",
        "-v", f"{directory / 'vector.yaml'}:/etc/vector/vector.yaml:ro", VECTOR_IMAGE, "--config", "/etc/vector/vector.yaml")
    pdns_command = ["docker", "run", "-d", "--name", PDNS, "--network", NETWORK, "--network-alias", "pdns-auth",
                    "--cpus", str(args.pdns_cpus), "--memory", "512m",
                    "-v", f"{ROOT / 'docker/pdns/pdns.conf'}:/etc/powerdns/pdns.conf:ro",
                    "-v", f"{ROOT / 'docker/pdns/geoip-zones.yml'}:/etc/powerdns/geoip-zones.yml:ro",
                    "-v", f"{data}:/var/lib/powerdns"]
    if args.mmdb_volume:
        pdns_command += ["-v", f"{args.mmdb_volume}:/mmdb:ro"]
    pdns_command += [PDNS_IMAGE, "--disable-syslog", "--daemon=no", "--guardian=no"] + list(args.pdns_arg)
    run(*pdns_command)
    (directory / "dnsdist.conf").write_text(dnsdist_config(args))
    for _ in range(60):
        if run("docker", "exec", PDNS, "pdns_control", "rping", check=False).returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError("PowerDNS did not start:\n" + run("docker", "logs", PDNS, check=False).stderr[-3000:])
    time.sleep(2)  # Vector's dnstap listener
    run("docker", "run", "-d", "--name", DNSDIST, "--network", NETWORK, "--cpus", str(args.dnsdist_cpus), "--memory", "512m",
        "--ulimit", "nofile=65536:65536",
        "-v", f"{directory / 'dnsdist.conf'}:/etc/dnsdist/dnsdist.conf:ro", "--entrypoint", "/usr/local/bin/dnsdist",
        DNSDIST_IMAGE, "--supervised", "--disable-syslog")
    run("docker", "run", "-d", "--name", LOAD, "--network", NETWORK, "--cpus", str(args.loadgen_cpus), "--entrypoint", "sleep",
        LOAD_IMAGE, "infinity")
    for profile in ("positive", "mixed", "nxdomain"):
        run("docker", "exec", "-i", LOAD, "sh", "-c", f"cat > /{profile}.txt", input_text=query_file(args.zones, profile))
    server = address(DNSDIST)
    for _ in range(60):
        probe = run("docker", "exec", LOAD, "sh", "-c", f"printf 'zone1.bench A\\n' > /probe.txt && dnsperf -s {server} -d /probe.txt -l 1 -Q 10 2>&1",
                    check=False)
        if "Queries completed:    " in probe.stdout and "(100.00%)" in probe.stdout:
            break
        time.sleep(1)
    else:
        raise RuntimeError("DNSdist did not answer:\n" + run("docker", "logs", DNSDIST, check=False).stderr[-3000:])


def parse_dnsperf(text: str) -> dict:
    def value(pattern: str, default: float = 0.0) -> float:
        match = re.search(pattern, text)
        return float(match.group(1)) if match else default
    codes = dict((name, int(count)) for name, count in re.findall(r"([A-Z]+) (\d+) \(", text.split("Response codes:")[-1].split("\n")[0]))
    return {
        "sent": int(value(r"Queries sent:\s+(\d+)")), "completed": int(value(r"Queries completed:\s+(\d+)")),
        "lost": int(value(r"Queries lost:\s+(\d+)")), "qps": value(r"Queries per second:\s+([0-9.]+)"),
        "latency_avg_ms": value(r"Average Latency \(s\):\s+([0-9.]+)") * 1000,
        "latency_max_ms": value(r"Average Latency \(s\):\s+[0-9.]+ \(min [0-9.]+, max ([0-9.]+)\)") * 1000,
        "latency_stddev_ms": value(r"Latency StdDev \(s\):\s+([0-9.]+)") * 1000,
        "response_bytes_avg": value(r"Average packet size:\s+request \d+, response (\d+)"), "rcodes": codes,
    }


def measure(args: argparse.Namespace, rate: int, profile: str) -> dict:
    server = address(DNSDIST)
    groups = {name: cgroup(name) for name in (DNSDIST, PDNS, VECTOR)}
    before_cpu = {name: cpu_usec(path) for name, path in groups.items()}
    before_counters = dnsdist_counters(server)
    before_udp = {name: udp_errors(name) for name in (DNSDIST, PDNS)}
    started = time.monotonic()
    result = run("docker", "exec", LOAD, "dnsperf", "-s", server, "-d", f"/{profile}.txt", "-l", str(args.duration), "-Q", str(rate),
                 "-c", str(args.clients), "-T", str(args.threads), "-q", str(args.max_outstanding), "-t", "2",
                 timeout=args.duration + 60)
    elapsed = time.monotonic() - started
    after_cpu = {name: cpu_usec(path) for name, path in groups.items()}
    after_counters = dnsdist_counters(server)
    after_udp = {name: udp_errors(name) for name in (DNSDIST, PDNS)}
    perf = parse_dnsperf(result.stdout)

    def delta(name: str) -> int:
        return int(after_counters.get(name, 0) - before_counters.get(name, 0))
    return {
        "label": args.label, "profile": profile, "offered_qps": rate, "duration_s": args.duration, "zones": args.zones,
        "clients": args.clients, "max_outstanding": args.max_outstanding, **perf,
        "loss_percent": round(100 * perf["lost"] / max(1, perf["sent"]), 3),
        "dnsdist_truncated": delta("dnsdist_self_answered"), "dnsdist_cache_hits": delta("dnsdist_cache_hits"),
        "dnsdist_cache_misses": delta("dnsdist_cache_misses"), "dnsdist_downstream_timeouts": delta("dnsdist_downstream_timeouts"),
        "dnsdist_responses": delta("dnsdist_responses"),
        "cpu_cores": {name.replace("cdnf-dnsbench-", ""): round((after_cpu[name][0] - before_cpu[name][0]) / 1e6 / elapsed, 3) for name in groups},
        "throttled_ms": {name.replace("cdnf-dnsbench-", ""): round((after_cpu[name][1] - before_cpu[name][1]) / 1000, 1) for name in groups},
        "udp_rcvbuf_errors": {name.replace("cdnf-dnsbench-", ""): after_udp[name].get("RcvbufErrors", 0) - before_udp[name].get("RcvbufErrors", 0)
                              for name in after_udp},
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--label", default="repository")
    parser.add_argument("--dnsdist-conf", default="", help="a DNSdist configuration to test instead of docker/dnsdist/dnsdist.conf")
    parser.add_argument("--pdns-arg", action="append", default=[], help="an extra PowerDNS command-line setting, such as --receiver-threads=2")
    parser.add_argument("--rates", default="5000,10000,20000,40000")
    parser.add_argument("--profiles", default="positive")
    parser.add_argument("--duration", type=int, default=20)
    parser.add_argument("--zones", type=int, default=1000)
    parser.add_argument("--clients", type=int, default=32, help="dnsperf sockets (source ports)")
    parser.add_argument("--threads", type=int, default=4)
    parser.add_argument("--max-outstanding", type=int, default=2000,
                        help="dnsperf's limit of queries in flight; its own default of 100 caps the rate at about 100 / latency")
    parser.add_argument("--dnsdist-cpus", type=float, default=2)
    parser.add_argument("--pdns-cpus", type=float, default=2)
    parser.add_argument("--loadgen-cpus", type=float, default=4)
    parser.add_argument("--mmdb-volume", default="cdnfoundry-dev_mmdb")
    parser.add_argument("--lift-source-limit", action="store_true", help="raise MaxQPSIPRule(500) so one load generator is not truncated")
    parser.add_argument("--lift-global-limit", action="store_true", help="raise MaxQPSRule(50000) to measure past the platform ceiling")
    parser.add_argument("--keep", action="store_true")
    args = parser.parse_args()
    cleanup()
    try:
        with tempfile.TemporaryDirectory(prefix="cdnf-dnsbench-") as directory:
            work = pathlib.Path(directory)
            work.chmod(0o755)
            start(args, work)
            environment = {"kernel": platform.release(), "host_logical_cpus": os.cpu_count(),
                           "docker": run("docker", "version", "--format", "{{.Server.Version}}").stdout.strip(),
                           "dnsdist_cpus": args.dnsdist_cpus, "pdns_cpus": args.pdns_cpus,
                           "lift_source_limit": args.lift_source_limit, "lift_global_limit": args.lift_global_limit,
                           "pdns_args": args.pdns_arg, "dnsdist_conf": args.dnsdist_conf or "docker/dnsdist/dnsdist.conf"}
            for profile in args.profiles.split(","):
                for rate in (int(item) for item in args.rates.split(",")):
                    result = measure(args, rate, profile)
                    result["environment"] = environment
                    print(json.dumps(result, sort_keys=True), flush=True)
            if args.keep:
                return
    finally:
        if not args.keep:
            cleanup()


if __name__ == "__main__":
    main()
