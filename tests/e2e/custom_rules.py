#!/usr/bin/env python3
"""Real OpenResty qualification of typed custom rules: every field, operator family, and action on a running cell."""

import ipaddress
import json
import os
import pathlib
import re
import struct
import tempfile
import time

import edge_security as base
import openresty_runtime as runtime

ROOT = pathlib.Path(__file__).resolve().parents[2]
NAME = "cdnf-e2e-custom-rules-e2e"
MMDB_VOLUME = base.MMDB_VOLUME
EDGE_NETWORK = base.EDGE_NETWORK
run = base.run

# Addresses from the documentation ranges, mapped to autonomous system numbers by the fixture database.
ASN_TABLE = {"203.0.113.7": 64500, "203.0.113.8": 64501, "198.51.100.9": 64502}


def mmdb_value(value: object) -> bytes:
    """Encodes the few MMDB data types the fixture needs: maps, strings, and unsigned integers."""
    def control(type_number: int, size: int) -> bytes:
        extra = b""
        if size >= 29:  # sizes 29 to 284 use one extra byte after the control byte
            assert size < 285
            size, extra = 29, bytes([size - 29])
        head = bytes([(type_number << 5) | size]) if type_number <= 7 else bytes([size, type_number - 7])
        return head + extra
    if isinstance(value, dict):
        out = control(7, len(value))
        for key, item in value.items():
            out += mmdb_value(str(key)) + mmdb_value(item)
        return out
    if isinstance(value, list):
        return control(11, len(value)) + b"".join(mmdb_value(item) for item in value)
    if isinstance(value, str):
        raw = value.encode()
        return control(2, len(raw)) + raw
    if isinstance(value, tuple):  # (type, integer)
        kind, number = value
        raw = number.to_bytes(max(1, (number.bit_length() + 7) // 8), "big") if number else b""
        return control(kind, len(raw)) + raw
    raise TypeError(value)


def write_asn_database(path: pathlib.Path, table: dict[str, int]) -> None:
    """A real MMDB with an IPv4 search tree of 24-bit records, built without any third-party library."""
    data = b""
    offsets: dict[str, int] = {}
    for address, number in table.items():
        offsets[address] = len(data)
        data += mmdb_value({"autonomous_system_number": (6, number), "autonomous_system_organization": f"AS{number}"})
    nodes: list[list[object]] = [[None, None]]
    for address in table:
        node = 0
        bits = format(int(ipaddress.IPv4Address(address)), "032b")
        for position, bit in enumerate(bits):
            side = int(bit)
            if position == 31:
                nodes[node][side] = ("data", offsets[address])
            else:
                if not isinstance(nodes[node][side], int):
                    nodes.append([None, None])
                    nodes[node][side] = len(nodes) - 1
                node = nodes[node][side]
    count = len(nodes)

    def record(entry: object) -> bytes:
        if entry is None:
            number = count
        elif isinstance(entry, int):
            number = entry
        else:
            number = count + 16 + entry[1]
        return number.to_bytes(3, "big")

    tree = b"".join(record(a) + record(b) for a, b in nodes)
    metadata = {
        "binary_format_major_version": (5, 2), "binary_format_minor_version": (5, 0), "build_epoch": (9, int(time.time())),
        "database_type": "CDNFoundry-Test-ASN", "languages": ["en"], "description": {"en": "Test ASN database"}, "ip_version": (5, 4), "node_count": (6, count), "record_size": (5, 24),
    }
    path.write_bytes(tree + b"\x00" * 16 + data + b"\xab\xcd\xefMaxMind.com" + mmdb_value(metadata))


def condition(field: str, operator: str, value: object, name: str | None = None) -> dict:
    result = {"field": field, "operator": operator, "value": value}
    if name is not None:
        result["name"] = name
    return result


def rule(identifier: int, conditions: list[dict], action: str = "block", priority: int | None = None) -> dict:
    return {"id": identifier, "name": None, "conditions": conditions, "action": action, "priority": priority or identifier}


def host(domain_id: int, rules: list[dict], waf: dict | None = None) -> dict:
    config = base.host(domain_id, rules)
    config["security"]["allowed_methods"] = ["GET", "HEAD", "POST", "PUT"]
    if waf:
        config["waf"] = waf
    return config


def waf_profile(name: str) -> dict:
    settings = {
        "off": (0, 0, 0, False), "monitor": (1, 5, 1048576, False), "balanced": (1, 5, 1048576, True),
    }[name]
    return {"name": name, "paranoia_level": settings[0], "inbound_threshold": settings[1], "outbound_threshold": 4,
            "body_limit_bytes": settings[2], "blocking": settings[3], "ruleset": "owasp-crs/4.26.0-modsecurity/3.0.14", "exclusions": []}


def access_entries(hostname: str) -> list[dict]:
    logs = run("docker", "logs", NAME, check=False)
    entries = []
    for line in (logs.stdout + logs.stderr).splitlines():
        if line.startswith("{") and f'"hostname":"{hostname}"' in line:
            entries.append(json.loads(line))
    return entries


def get(hostname: str, path: str = "/", *headers: str, method: str = "GET", data: str | None = None) -> tuple[int, str, str]:
    return runtime.curl(hostname, path, headers, method, data, container=NAME)


def xff(address: str) -> str:
    return f"X-Forwarded-For: {address}"


def expect(result: tuple[int, str, str], status: int, reason: str | None = None) -> None:
    code, head, _ = result
    assert code == status, (code, head)
    if reason:
        assert f"X-CDNF-Security-Reason: {reason}".lower() in head.lower(), head


def qualify(directory: pathlib.Path) -> None:
    blocked = "custom_rule_block"
    # Every field, each on its own host so one run proves them all.
    hosts = {
        "plain.example": host(1, []),
        "hostname.example": host(2, [rule(1, [condition("hostname", "starts_with", "hostname.")])]),
        "path.example": host(3, [
            rule(1, [condition("path", "starts_with", "/admin")]),
            rule(2, [condition("path", "ends_with", ".php")]),
            rule(3, [condition("path", "contains", "/secret/")]),
            rule(4, [condition("path", "in_list", ["/exact-one", "/exact-two"])]),
        ]),
        "log.example": host(27, [rule(1, [condition("path", "starts_with", "/watched")], "log")]),
        "query.example": host(4, [rule(1, [condition("query_present", "equals", True)])]),
        "method.example": host(5, [rule(1, [condition("method", "in_list", ["POST", "PUT"])])]),
        "country.example": host(6, [rule(1, [condition("country", "equals", "US")])]),
        "notcountry.example": host(7, [rule(1, [condition("country", "not_equals", "US")])]),
        "continent.example": host(8, [rule(1, [condition("continent", "equals", "NA")])]),
        "asn.example": host(9, [rule(1, [condition("asn", "equals", 64500)])]),
        "asngreater.example": host(10, [rule(1, [condition("asn", "greater_than", 64500)])]),
        "asnlist.example": host(11, [rule(1, [condition("asn", "in_list", [64501, 64502])])]),
        "ip.example": host(12, [rule(1, [condition("client_ip", "equals", "203.0.113.0/24")])]),
        "iplist.example": host(13, [rule(1, [condition("client_ip", "in_list", ["198.51.100.9", "2001:db8::/32"])])]),
        "notip.example": host(14, [rule(1, [condition("client_ip", "not_equals", "203.0.113.7")])]),
        "agent.example": host(15, [rule(1, [condition("user_agent", "contains", "badbot")])]),
        "referer.example": host(16, [rule(1, [condition("referer", "starts_with", "https://evil.example")])]),
        "header.example": host(17, [rule(1, [condition("header", "equals", "secret", "X-Api-Key")])]),
        "cookie.example": host(18, [rule(1, [condition("cookie_present", "equals", True, "session")])]),
        "type.example": host(19, [rule(1, [condition("content_type", "starts_with", "application/json")])]),
        "size.example": host(20, [rule(1, [condition("body_size", "greater_than", 5)])]),
        "tls.example": host(21, [rule(1, [condition("tls_version", "equals", "none")])]),
        "all.example": host(22, [rule(1, [
            condition("path", "starts_with", "/api/"), condition("method", "equals", "POST"),
            condition("header", "contains", "bearer", "Authorization"),
        ])]),
        "order.example": host(23, [
            rule(1, [condition("client_ip", "equals", "8.8.8.8")], "allow"),
            rule(2, [condition("country", "equals", "US")], "block"),
        ]),
        "skip.example": host(24, [rule(1, [condition("path", "starts_with", "/legacy")], "skip_managed")],
                             {"name": "strict", "paranoia_level": 2, "inbound_threshold": 3, "outbound_threshold": 3, "body_limit_bytes": 262144, "blocking": True}),
        "challenge.example": host(25, [rule(1, [condition("path", "starts_with", "/members")], "challenge")]),
        "scoreoff.example": host(28, [rule(1, [condition("attack_score", "less_than", 20)])], waf_profile("off")),
        "scoremonitor.example": host(29, [rule(1, [condition("attack_score_sqli", "less_than", 30)], "log")], waf_profile("monitor")),
        "scorebalanced.example": host(30, [], waf_profile("balanced")),
        "scoreskip.example": host(31, [rule(1, [condition("header", "equals", "yes", "X-Trusted")], "skip_managed")], waf_profile("balanced")),
        "scorerce.example": host(32, [rule(1, [condition("attack_score_rce", "less_than", 40)])], waf_profile("off")),
        "unsafe.example": host(26, [{"id": 1, "conditions": [], "action": "block", "priority": 1},
                                   {"id": 2, "conditions": [{"field": "path", "operator": "equals"}] * 6, "action": "block", "priority": 2},
                                   {"id": 3, "conditions": [{"field": "nonsense", "operator": "equals", "value": "x"}], "action": "block", "priority": 3}]),
    }
    (directory / "active.json").write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-rules-1", "certificates": {}, "hosts": hosts}, separators=(",", ":")))
    write_asn_database(directory / "asn.mmdb", ASN_TABLE)
    run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=e2e-rules", "-keyout", str(directory / "tls.key"), "-out", str(directory / "tls.crt"))
    for file in directory.iterdir():
        file.chmod(0o644)
    run("docker", "run", "-d", "--name", NAME, "--network", EDGE_NETWORK,
        "--tmpfs", "/var/cache/nginx:rw,size=64m", "--tmpfs", "/var/lib/nginx/tmp:rw,size=32m",
        "--memory", "512m", "--cpus", "0.5", "--pids-limit", "96", "--ulimit", "nofile=65536:65536",
        "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=runtime-test-token",
        "-e", "GEOIP_DATABASE=/mmdb/GeoLite2-City.mmdb", "-e", "GEOIP_ASN_DATABASE=/var/lib/cdnfoundry/runtime/asn.mmdb",
        "-v", f"{directory}:/var/lib/cdnfoundry/runtime:ro",
        "-v", f"{directory / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{directory / 'tls.key'}:/run/edge/tls.key:ro",
        "-v", f"{MMDB_VOLUME}:/mmdb:ro", "cdnfoundry/edge-runtime:e2e")
    for _ in range(60):  # the cell loads its state a few seconds after it starts
        probe = run("docker", "run", "--rm", "--network", f"container:{NAME}", "curlimages/curl:8.16.0", "-s", "-o", "/dev/null", "-w", "%{http_code}", "-H", "Host: plain.example", "http://127.0.0.1:8080/", check=False).stdout
        if probe == "200":
            break
        time.sleep(1)

    expect(get("plain.example"), 200)
    # log records a security event and lets the request through. The status endpoint keeps only the newest events, so this runs first.
    expect(get("log.example", "/watched/page"), 200)
    expect(get("log.example", "/elsewhere"), 200)
    events = json.loads(run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token", "http://127.0.0.1:9080/passive-failures").stdout)["security"]
    logged = [event for event in events if event["reason_code"] == "custom_rule_log"]
    assert len(logged) == 1 and logged[0]["domain_id"] == 27 and logged[0]["count"] == 1, events
    # hostname
    expect(get("hostname.example"), 403, blocked)
    # path: prefix, suffix, substring, list, and a log action that does not block
    expect(get("path.example", "/admin/panel"), 403, blocked)
    expect(get("path.example", "/index.php"), 403, blocked)
    expect(get("path.example", "/a/secret/b"), 403, blocked)
    expect(get("path.example", "/exact-two"), 403, blocked)
    expect(get("path.example", "/"), 200)
    # query presence
    expect(get("query.example", "/?a=1"), 403, blocked)
    expect(get("query.example", "/"), 200)
    # method
    expect(get("method.example", "/", method="POST", data="x"), 403, blocked)
    expect(get("method.example"), 200)
    # country and continent come from the geography database
    expect(get("country.example", "/", xff("8.8.8.8")), 403, blocked)
    expect(get("country.example", "/", xff("203.0.113.7")), 200)
    expect(get("notcountry.example", "/", xff("8.8.8.8")), 200)
    expect(get("notcountry.example", "/", xff("203.0.113.7")), 403, blocked)
    expect(get("continent.example", "/", xff("8.8.8.8")), 403, blocked)
    expect(get("continent.example"), 200)
    # ASN: equality, a numeric comparison, a list, and an unknown ASN (never equal, never greater)
    expect(get("asn.example", "/", xff("203.0.113.7")), 403, blocked)
    expect(get("asn.example", "/", xff("203.0.113.8")), 200)
    expect(get("asngreater.example", "/", xff("203.0.113.8")), 403, blocked)
    expect(get("asngreater.example", "/", xff("203.0.113.7")), 200)
    expect(get("asngreater.example", "/", xff("192.0.2.77")), 200)
    expect(get("asnlist.example", "/", xff("198.51.100.9")), 403, blocked)
    expect(get("asnlist.example", "/", xff("203.0.113.7")), 200)
    # client address: a CIDR, a list that mixes an address and an IPv6 network, and a negation
    expect(get("ip.example", "/", xff("203.0.113.200")), 403, blocked)
    expect(get("ip.example", "/", xff("198.51.100.1")), 200)
    expect(get("iplist.example", "/", xff("198.51.100.9")), 403, blocked)
    expect(get("iplist.example", "/", xff("2001:db8:1::5")), 403, blocked)
    expect(get("iplist.example", "/", xff("2001:db9::1")), 200)
    expect(get("notip.example", "/", xff("203.0.113.7")), 200)
    expect(get("notip.example", "/", xff("198.51.100.1")), 403, blocked)
    # header-based fields
    expect(get("agent.example", "/", "User-Agent: Mozilla BadBot/1.0"), 403, blocked)
    expect(get("agent.example", "/", "User-Agent: Mozilla"), 200)
    expect(get("referer.example", "/", "Referer: https://evil.example/page"), 403, blocked)
    expect(get("referer.example", "/", "Referer: https://good.example/"), 200)
    expect(get("header.example", "/", "X-Api-Key: SECRET"), 403, blocked)
    expect(get("header.example", "/", "X-Api-Key: other"), 200)
    expect(get("header.example"), 200)
    expect(get("cookie.example", "/", "Cookie: a=1; session=abc"), 403, blocked)
    expect(get("cookie.example", "/", "Cookie: sessions=abc"), 200)
    expect(get("cookie.example"), 200)
    expect(get("type.example", "/", "Content-Type: application/json; charset=utf-8", method="POST", data="{}"), 403, blocked)
    expect(get("type.example", "/", "Content-Type: text/plain", method="POST", data="{}"), 200)
    # body size and TLS version (this cell's plain HTTP listener reports "none")
    expect(get("size.example", "/", method="POST", data="0123456789"), 403, blocked)
    expect(get("size.example", "/", method="POST", data="01"), 200)
    expect(get("tls.example"), 403, blocked)
    # every condition of a rule must match
    expect(get("all.example", "/api/x", "Authorization: Bearer t", method="POST", data="x"), 403, blocked)
    expect(get("all.example", "/api/x", "Authorization: Bearer t"), 200)
    expect(get("all.example", "/other", "Authorization: Bearer t", method="POST", data="x"), 200)
    expect(get("all.example", "/api/x", method="POST", data="x"), 200)

    # allow stops evaluating later rules; a different US client still meets the block
    expect(get("order.example", "/", xff("8.8.8.8")), 200)
    expect(get("order.example", "/", xff("8.8.4.4")), 403, blocked)

    # skip_managed turns the managed WAF off for matching requests only
    attack = "?q=%3Cscript%3Ealert(document.cookie)%3C%2Fscript%3E%20union%20select%20password%20from%20users"
    expect(get("skip.example", f"/{attack}"), 403)
    expect(get("skip.example", f"/legacy{attack}"), 200)

    # challenge: a page load gets the proof-of-work page, a solved seed gives a token, and other paths are untouched
    html = "Accept: text/html"
    expect(get("challenge.example", "/open"), 200)
    code, head, body = get("challenge.example", "/members", html)
    assert code == 403 and "Checking your browser" in body, (code, head)
    expect(get("challenge.example", "/members", "Accept: */*"), 403, "challenge_required")
    seed = re.search(r'const seed="([0-9a-f.]+)"', body).group(1)
    code, head, _ = get("challenge.example", "/.cdnf/challenge", method="POST", data=f"seed={seed}&counter={runtime.solve_challenge(seed)}")
    assert code == 204 and "cdnf_chal=" in head, head
    cookie = re.search(r"cdnf_chal=([0-9a-f.]+)", head).group(1)
    expect(get("challenge.example", "/members", html, f"Cookie: cdnf_chal={cookie}"), 200)
    expect(get("plain.example", "/.cdnf/challenge", method="POST", data=f"seed={seed}&counter=1"), 200)

    # attack score: 1 (attack) to 99 (clean), from the managed WAF's anomaly points, even when the profile is off
    clean = "/?q=hello+world"
    sqli = "/?q=1%27%20UNION%20SELECT%20username,password%20FROM%20users--%20"
    xss = "/?q=%3Cscript%3Ealert(document.cookie)%3C%2Fscript%3E"
    rce = "/?q=%3Bcat%20/etc/passwd%20%7C%20bash%20-c%20%27id%27"
    expect(get("scoreoff.example", clean), 200)
    expect(get("scoreoff.example", sqli), 403, blocked)
    expect(get("scoreoff.example", xss), 403, blocked)
    expect(get("scorerce.example", rce), 403, blocked)
    expect(get("scorerce.example", sqli), 200)
    cleans = [entry for entry in access_entries("scoreoff.example") if entry["path"] == clean]
    attacks = [entry for entry in access_entries("scoreoff.example") if entry["path"] == sqli]
    assert cleans and cleans[0]["attack_score"] == 99, cleans
    assert attacks and 1 <= attacks[0]["attack_score"] < 20 and attacks[0]["attack_score_sqli"] < 20, attacks
    # the observe profile scores and logs without blocking, and a log rule on the SQL injection score records its event
    expect(get("scoremonitor.example", sqli), 200)
    monitored = [entry for entry in access_entries("scoremonitor.example") if entry["path"] == sqli]
    assert monitored and monitored[0]["attack_score"] < 40 and monitored[0]["waf_action"] == "monitor", monitored
    expect(get("scoremonitor.example", clean), 200)
    assert [entry for entry in access_entries("scoremonitor.example") if entry["path"] == clean][0]["attack_score"] == 99
    # a blocking profile refuses the payload and keeps its established reason; the clean request passes with a score of 99
    expect(get("scorebalanced.example", sqli), 403, "waf_block")
    expect(get("scorebalanced.example", clean), 200)
    blocked_entry = [entry for entry in access_entries("scorebalanced.example") if entry["path"] == sqli][0]
    assert blocked_entry["waf_action"] == "block" and blocked_entry["attack_score"] < 20, blocked_entry
    # a skip_managed rule lets the same payload through
    expect(get("scoreskip.example", sqli), 403, "waf_block")
    expect(get("scoreskip.example", sqli, "X-Trusted: yes"), 200)

    # a malformed or oversized rule never matches and never takes the cell down
    expect(get("unsafe.example"), 200)
    expect(get("plain.example"), 200)


def main() -> None:
    run("docker", "compose", "-f", "compose.dev.yml", "up", "-d", "--force-recreate", "origin-http")
    run("docker", "compose", "-f", "compose.dev.yml", "up", "-d", "mmdb-updater")
    run("docker", "build", "-f", "docker/openresty/Dockerfile", "-t", "cdnfoundry/edge-runtime:e2e", ".")
    with tempfile.TemporaryDirectory(prefix="cdnf-rules-") as directory:
        os.chmod(directory, 0o755)
        try:
            qualify(pathlib.Path(directory))
            print("typed custom rule runtime qualification passed")
        finally:
            if os.environ.get("CDNF_KEEP_FAILED_RUNTIME") != "1":
                run("docker", "rm", "-f", NAME, check=False)


if __name__ == "__main__":
    main()
