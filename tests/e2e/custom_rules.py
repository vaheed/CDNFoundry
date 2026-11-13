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
def platform_rule(identifier: str, ruleset: str, conditions: list[dict], action: str = "block", priority: int = 10, rate: dict | None = None) -> dict:
    return {"id": identifier, "ruleset": ruleset, "name": None, "conditions": conditions, "action": action, "priority": priority, "rate_limit": rate}


# Two platform rules shared by the platform domains: a block on a path prefix and a rate limit on another path.
PLATFORM_RULES = [
    platform_rule("p1", "Known probes", [{"field": "path", "operator": "starts_with", "value": "/wp-"}]),
    platform_rule("p2", "Known probes", [{"field": "path", "operator": "equals", "value": "/search"}], priority=20,
                  rate={"key": "client_ip", "threshold": 2, "period_seconds": 600, "duration_seconds": 10}),
]

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


def rate_rule(identifier: int, conditions: list[dict], action: str = "block", key: str = "client_ip", threshold: int = 5, period: int = 600,
              duration: int = 10, header_name: str | None = None) -> dict:
    limit = {"key": key, "threshold": threshold, "period_seconds": period, "duration_seconds": duration}
    if header_name:
        limit["header_name"] = header_name
    return {**rule(identifier, conditions, action), "rate_limit": limit}


def host(domain_id: int, rules: list[dict], waf: dict | None = None, platform_rules: list[dict] | None = None) -> dict:
    config = base.host(domain_id, rules)
    config["security"]["platform_rules"] = platform_rules or []
    config["security"]["allowed_methods"] = ["GET", "HEAD", "POST", "PUT"]
    if waf:
        config["waf"] = waf
    return config


def waf_profile(name: str, **tuning: object) -> dict:
    settings = {
        "off": (0, 0, 0, False), "monitor": (1, 5, 1048576, False), "balanced": (1, 5, 1048576, True),
    }[name]
    return {"name": name, "paranoia_level": settings[0], "inbound_threshold": settings[1], "outbound_threshold": 4,
            "body_limit_bytes": settings[2], "blocking": settings[3], "ruleset": "owasp-crs/4.26.0-modsecurity/3.0.14", "exclusions": [],
            "off_categories": [], "off_rules": [], "log_only_rules": [], **tuning}


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
        "ratepath.example": host(33, [rate_rule(1, [condition("path", "equals", "/limited")], key="client_ip_path")]),
        "rateheader.example": host(34, [rate_rule(1, [], key="header", header_name="x-api-key")]),
        "rateall.example": host(35, [rate_rule(1, [], threshold=3)]),
        "ratelog.example": host(36, [rate_rule(1, [condition("path", "equals", "/watched")], "log", threshold=2)]),
        "ratechallenge.example": host(37, [rate_rule(1, [condition("path", "equals", "/members")], "challenge", threshold=2)]),
        "rateallow.example": host(38, [rule(1, [condition("client_ip", "equals", "198.51.100.77")], "allow"), rate_rule(2, [], threshold=1)]),
        "tunecategory.example": host(39, [], waf_profile("balanced", off_categories=["942"])),
        "tunerule.example": host(40, [], waf_profile("balanced", off_rules=[913100])),
        "tuneloganly.example": host(41, [], waf_profile("balanced", log_only_rules=[913100])),
        "tunethreshold.example": host(42, [], waf_profile("balanced", inbound_threshold=100)),
        "hotlink.example": host(43, [rule(1, [
            condition("file_extension", "in_list", ["jpg", "png"]), condition("referer_present", "equals", True),
            condition("referer_host", "not_in_list", ["partner.example", "hotlink.example", "www.hotlink.example"]),
        ])]),
        "uapreset.example": host(44, [rule(1, [condition("user_agent", "contains", "sqlmap")]), rule(2, [condition("user_agent", "contains", "nikto")]),
                                      rule(3, [condition("user_agent", "contains", "curl")], "challenge")]),
        "platformone.example": host(45, [], platform_rules=PLATFORM_RULES),
        "platformtwo.example": host(46, [rule(1, [condition("path", "equals", "/wp-login.php")], "allow")], platform_rules=PLATFORM_RULES),
        "platformnone.example": host(47, []),
        "unsafe.example": host(26, [{"id": 1, "conditions": [], "action": "block", "priority": 1},
                                   {"id": 4, "conditions": [{"field": "path", "operator": "equals", "value": "/"}], "action": "block", "priority": 4, "rate_limit": {"key": "client_ip", "threshold": 0, "period_seconds": 7, "duration_seconds": 1}},
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
    # a log-only rate limit lets every request through and records one event type for the domain
    for _ in range(5):
        expect(get("ratelog.example", "/watched"), 200)
    events = json.loads(run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token", "http://127.0.0.1:9080/passive-failures").stdout)["security"]
    limited = [event for event in events if event["reason_code"] == "custom_rule_rate_limited"]
    assert len(limited) == 1 and limited[0]["domain_id"] == 36, events
    # a log-only managed rule lets the scanner through and records an event; the same rule not adjusted would block
    scanner = "User-Agent: sqlmap/1.7.2#stable (https://sqlmap.org)"
    expect(get("scorebalanced.example", "/", scanner), 403, "waf_block")
    expect(get("tuneloganly.example", "/", scanner), 200)
    events = json.loads(run("docker", "exec", NAME, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: runtime-test-token", "http://127.0.0.1:9080/passive-failures").stdout)["security"]
    logged_rules = [event for event in events if event["reason_code"] == "waf_rule_logged"]
    assert len(logged_rules) == 1 and logged_rules[0]["domain_id"] == 41, events
    expect(get("tuneloganly.example", "/"), 200)
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

    # rate-limit rules: counted per key and per cell, over fixed windows, then released after their duration
    rate_reason = "custom_rule_rate_limited"
    for _ in range(5):
        expect(get("ratepath.example", "/limited"), 200)
    expect(get("ratepath.example", "/limited"), 429, rate_reason)
    expect(get("ratepath.example", "/limited"), 429, rate_reason)
    expect(get("ratepath.example", "/other"), 200)                              # another path is another key
    expect(get("ratepath.example", "/limited", xff("203.0.113.9")), 200)        # another client is another key
    # a header key counts each value separately, and a request without the header is never counted
    for _ in range(5):
        expect(get("rateheader.example", "/", "X-Api-Key: alpha"), 200)
    expect(get("rateheader.example", "/", "X-Api-Key: alpha"), 429, rate_reason)
    expect(get("rateheader.example", "/", "X-Api-Key: beta"), 200)
    for _ in range(8):
        expect(get("rateheader.example"), 200)
    # a rule with no conditions counts every request of the domain
    for _ in range(3):
        expect(get("rateall.example"), 200)
    expect(get("rateall.example", "/anywhere"), 429, rate_reason)
    # a client an allow rule trusts is never counted
    for _ in range(4):
        expect(get("rateallow.example", "/", xff("198.51.100.77")), 200)
    expect(get("rateallow.example", "/", xff("198.51.100.78")), 200)
    expect(get("rateallow.example", "/", xff("198.51.100.78")), 429, rate_reason)
    # log counts and records an event but never blocks; challenge asks a browser for the proof of work
    for _ in range(2):
        expect(get("ratechallenge.example", "/members", "Accept: text/html", f"Cookie: x={_}"), 200)
    code, head, body = get("ratechallenge.example", "/members", "Accept: text/html")
    assert code == 403 and "Checking your browser" in body, (code, head)
    # the mitigation lasts its duration and the client then starts again with a fresh allowance
    time.sleep(11)
    expect(get("ratepath.example", "/limited"), 200)
    expect(get("rateheader.example", "/", "X-Api-Key: alpha"), 200)

    # adjustable managed rules: a category off, one rule off, and a raised threshold each let a payload through that the profile blocks
    expect(get("tunecategory.example", sqli), 200)
    expect(get("tunecategory.example", xss), 403, "waf_block")
    expect(get("tunerule.example", "/", scanner), 200)
    expect(get("tunerule.example", sqli), 403, "waf_block")
    expect(get("tunethreshold.example", sqli), 200)
    assert [entry for entry in access_entries("tunethreshold.example") if entry["path"] == sqli][0]["attack_score"] < 40
    # the catalog the control plane validates against is the one the cells run: same release, one control per category and rule
    image = "cdnfoundry/edge-runtime:e2e"
    version = run("docker", "exec", NAME, "sh", "-c", "grep -h -o -m1 \"ver:'OWASP_CRS/[0-9.]*'\" /etc/cdnfoundry/crs/rules/REQUEST-901-INITIALIZATION.conf").stdout.strip()
    catalog = json.loads((ROOT / "core/resources/contracts/crs-rules.json").read_text())
    assert version == f"ver:'OWASP_CRS/{catalog['ruleset'].split('/')[1]}'", (version, catalog["ruleset"])
    controls = int(run("docker", "exec", NAME, "sh", "-c", "grep -c ruleRemoveById /etc/cdnfoundry/waf-controls.conf").stdout)
    assert controls == len(catalog["categories"]) + len(catalog["rules"]), controls

    # hotlink protection: only a request with a Referer from another host, for a listed extension, is blocked
    expect(get("hotlink.example", "/a.jpg"), 200)                                                          # no Referer
    expect(get("hotlink.example", "/a.jpg", "Referer: https://hotlink.example/page"), 200)                 # the domain itself
    expect(get("hotlink.example", "/a.png", "Referer: https://PARTNER.example:8443/x"), 200)               # an allowed host, port and case ignored
    expect(get("hotlink.example", "/a.jpg", "Referer: https://evil.example/p"), 403, blocked)
    expect(get("hotlink.example", "/A.JPG", "Referer: http://user:secret@evil.example:81/p?q=1"), 403, blocked)
    expect(get("hotlink.example", "/page.html", "Referer: https://evil.example/p"), 200)                  # another file type
    expect(get("hotlink.example", "/a.jpg", "Referer: not-an-address"), 200)                               # a Referer that is no address is no Referer
    expect(get("hotlink.example", "/photo.jpg.html", "Referer: https://evil.example/p"), 200)
    # user agent presets: a word anywhere in the user agent, case does not matter, block or challenge
    expect(get("uapreset.example", "/", "User-Agent: Mozilla SQLMap/1.7"), 403, blocked)
    expect(get("uapreset.example", "/", "User-Agent: Nikto/2.5"), 403, blocked)
    expect(get("uapreset.example", "/", "User-Agent: Mozilla/5.0 Firefox"), 200)
    expect(get("uapreset.example", "/"), 403, "challenge_required")                                        # the test client is curl, so it is challenged
    code, head, body = get("uapreset.example", "/", "Accept: text/html")
    assert code == 403 and "Checking your browser" in body, (code, head)

    # platform rulesets run before the domain's own rules: the block holds on both domains, even where a domain rule would allow it
    platform_reason = "platform_rule_block"
    expect(get("platformone.example", "/wp-login.php"), 403, platform_reason)
    expect(get("platformtwo.example", "/wp-login.php"), 403, platform_reason)
    expect(get("platformone.example", "/index.html"), 200)
    expect(get("platformnone.example", "/wp-login.php"), 200)                       # a domain the ruleset does not reach
    for _ in range(2):
        expect(get("platformone.example", "/search"), 200)
    expect(get("platformone.example", "/search"), 429, "platform_rule_rate_limited")
    # removing the ruleset restores both domains: the cell loads a new runtime state without the platform rules
    state = json.loads((directory / "active.json").read_text())
    for name in ("platformone.example", "platformtwo.example"):
        state["hosts"][name]["security"]["platform_rules"] = []
    state.update({"sequence": 2, "generation_id": "e2e-rules-2"})
    (directory / "active.json").write_text(json.dumps(state, separators=(",", ":")))
    for _ in range(20):
        if get("platformone.example", "/wp-login.php")[0] == 200:
            break
        time.sleep(1)
    expect(get("platformone.example", "/wp-login.php"), 200)
    expect(get("platformtwo.example", "/wp-login.php"), 200)

    # the phase gate: the same scripted attack is stopped by a rule on the attack score, a rate-limit rule, and a platform ruleset,
    # and each stop is in the request telemetry the cell ships to Vector, with its reason, action, and score
    stopped_by_score = [entry for entry in access_entries("scoreoff.example") if entry["path"] == sqli and entry["status"] == 403]
    assert stopped_by_score and stopped_by_score[0]["security_reason"] == "custom_rule_block" and stopped_by_score[0]["security_rule"] == "r1" and stopped_by_score[0]["security_action"] == "block" and 1 <= stopped_by_score[0]["attack_score"] < 20, stopped_by_score
    stopped_by_rate = [entry for entry in access_entries("ratepath.example") if entry["status"] == 429]
    assert stopped_by_rate and stopped_by_rate[0]["security_reason"] == "custom_rule_rate_limited" and stopped_by_rate[0]["security_rule"] == "r1" and stopped_by_rate[0]["security_action"] == "block", stopped_by_rate
    stopped_by_platform = [entry for entry in access_entries("platformone.example") if entry["path"] == "/wp-login.php" and entry["status"] == 403]
    assert stopped_by_platform and stopped_by_platform[0]["security_reason"] == "platform_rule_block" and stopped_by_platform[0]["security_rule"] == "p1", stopped_by_platform
    waf_stop = [entry for entry in access_entries("scorebalanced.example") if entry["path"] == sqli and entry["status"] == 403]
    assert waf_stop and waf_stop[0]["security_rule"] == "waf", waf_stop

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
