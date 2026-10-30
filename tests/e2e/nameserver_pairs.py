#!/usr/bin/env python3
"""Real-runtime qualification of readable nameserver pairs and re-delegation takeover.

Uses the running development stack: the control plane API, PostgreSQL, Horizon, PowerDNS
and DNSdist. The parent zone is the one external dependency the dev stack cannot provide, so
the allocation and verification jobs run with a parent that publishes a chosen delegation;
every other step is the production path. Nothing is removed or reset.
"""
from __future__ import annotations

import json
import os
import secrets
import subprocess
import time
import urllib.error
import urllib.request
import uuid

BASE = os.environ.get("CDNF_BASE_URL", "http://localhost:8080").rstrip("/")
COMPOSE = os.environ.get("CDNF_COMPOSE_FILE", "compose.dev.yml")
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))
RUN = f"{int(time.time())}-{secrets.token_hex(3)}"
PASSWORD = f"Pairs-{secrets.token_urlsafe(20)}"
NAME = f"pairs-{RUN}.example.com"


def call(method: str, path: str, payload: object | None = None, token: str | None = None, expect: int | None = None) -> tuple[int, object]:
    headers = {"Accept": "application/json"}
    body = None
    if payload is not None:
        headers["Content-Type"] = "application/json"
        headers["Idempotency-Key"] = str(uuid.uuid4())
        body = json.dumps(payload).encode()
    if token:
        headers["Authorization"] = f"Bearer {token}"
    request = urllib.request.Request(BASE + path, data=body, headers=headers, method=method)
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            raw = response.read()
            status, result = response.status, json.loads(raw) if raw else {}
    except urllib.error.HTTPError as error:
        raw = error.read()
        status, result = error.code, json.loads(raw) if raw else {}
    if expect is not None and status != expect:
        raise AssertionError(f"{method} {path}: expected {expect}, got {status}: {result}")
    return status, result


def tinker(code: str) -> str:
    result = subprocess.run(["docker", "compose", "-f", COMPOSE, "exec", "-T", "core", "php", "artisan", "tinker", f"--execute={code}"],
                            cwd=ROOT, check=True, text=True, capture_output=True, timeout=120)
    return result.stdout.strip()


def dig(owner: str, record_type: str, tcp: bool = False, ipv6: bool = False) -> str:
    command = ["dig", "@::1" if ipv6 else "@127.0.0.1", "-p", "1053", owner, record_type, "+short", "+time=3", "+tries=1"]
    if tcp:
        command.append("+tcp")
    return subprocess.run(command, check=True, text=True, capture_output=True, timeout=15).stdout.strip()


def wait_for(check, what: str, timeout: int = 60):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        value = check()
        if value:
            return value
        time.sleep(1)
    raise AssertionError(f"timed out waiting for {what}")


def wait_operation(token: str, operation_id: str, timeout: int = 60) -> dict:
    def done():
        _, result = call("GET", f"/api/operations/{operation_id}", token=token)
        if result["data"]["status"] == "failed":
            raise AssertionError(result)
        return result["data"] if result["data"]["status"] == "succeeded" else None
    return wait_for(done, f"operation {operation_id}", timeout)


def login(email: str) -> str:
    return call("POST", "/api/auth/login", {"email": email, "password": PASSWORD, "device_name": "pairs-e2e"}, expect=200)[1]["data"]["token"]


def allocate(domain_id: int, parent: list[str]) -> None:
    listing = json.dumps(parent)
    tinker(f"""
app()->bind(App\\Support\\NameserverResolver::class, fn () => new class extends App\\Support\\NameserverResolver {{
    public function delegation(string $domain): array {{ return {listing}; }}
}});
(new App\\Jobs\\AllocateNameserverPair({domain_id}))->handle(app(App\\Support\\NameserverResolver::class), app(App\\Support\\DomainNameserverVerification::class));
""")


def verify_with_delegation(domain_id: int, observed: list[str]) -> None:
    listing = json.dumps(observed)
    tinker(f"""
app()->bind(App\\Support\\NameserverResolver::class, fn () => new class extends App\\Support\\NameserverResolver {{
    public function resolve(string $domain): array {{ return {listing}; }}
}});
// The worker already tried the real internet parent for this fictional name and failed the operation; re-arm it for the controlled parent.
App\\Models\\Operation::query()->where('type', 'domain.nameservers_verify')->where('input->domain_id', {domain_id})->latest('created_at')->limit(1)
    ->update(['status' => 'pending', 'error' => null, 'finished_at' => null]);
(new App\\Jobs\\VerifyDomainNameservers({domain_id}))->handle(app(App\\Support\\NameserverResolver::class));
""")


def main() -> None:
    emails = {role: f"pairs-{role}-{RUN}@example.test" for role in ("admin", "a", "b")}
    for role, email in emails.items():
        tinker("App\\Models\\User::query()->create(['name'=>'Pairs " + role + "','email'=>'" + email + "','password'=>Illuminate\\Support\\Facades\\Hash::make('" + PASSWORD + "'),'type'=>'" + ("admin" if role == "admin" else "user") + "']);")
    admin, token_a, token_b = login(emails["admin"]), login(emails["a"]), login(emails["b"])

    # 1. An administrator configures two pairs; the platform zone publishes explicit A/AAAA for them.
    _, current = call("GET", "/api/admin/system/settings/dns", token=admin, expect=200)
    settings = {key: value for key, value in current["data"].items() if key in (
        "platform_domain", "proxy_hostname", "nameservers", "soa_primary", "soa_mailbox", "soa_refresh", "soa_retry", "soa_expire",
        "soa_minimum_ttl", "default_ttl", "cluster_targets")}
    zone = settings["platform_domain"]
    settings["customer_nameserver_pairs"] = [
        [{"hostname": f"ns1.{zone}", "ipv4": settings["nameservers"][0]["ipv4"], "ipv6": settings["nameservers"][0].get("ipv6")},
         {"hostname": f"ns2.{zone}", "ipv4": settings["nameservers"][1]["ipv4"], "ipv6": settings["nameservers"][1].get("ipv6")}],
        [{"hostname": f"ns3.{zone}", "ipv4": "8.8.4.4", "ipv6": "2001:4860:4860::8844"},
         {"hostname": f"ns4.{zone}", "ipv4": "1.0.0.1", "ipv6": "2606:4700:4700::1001"}],
    ]
    _, validated = call("POST", "/api/admin/system/settings/dns/validate", settings, admin, expect=200)
    _, applied = call("PATCH", "/api/admin/system/settings/dns", {**settings, "confirmation_token": validated["data"]["confirmation_token"]}, admin, expect=202)
    wait_operation(admin, applied["data"]["id"])

    for host, v4, v6 in (("ns3", "8.8.4.4", "2001:4860:4860::8844"), ("ns4", "1.0.0.1", "2606:4700:4700::1001")):
        wait_for(lambda h=host: dig(f"{h}.{zone}", "A") == v4, f"{host} A")
        assert dig(f"{host}.{zone}", "A", tcp=True) == v4
        assert dig(f"{host}.{zone}", "AAAA") == v6 and dig(f"{host}.{zone}", "AAAA", tcp=True) == v6
    apex = dig(zone, "NS")
    assert f"ns3.{zone}." not in apex and f"ns4.{zone}." not in apex, "customer pairs must not join the apex NS set"
    assert dig(f"deadbeefdeadbeefdeadbeefdeadbeef.ns3.{zone}", "A") == "", "no wildcard under a nameserver name"
    print("pool_published=passed", flush=True)

    # 2. Two applicants claim one name. Allocation is asynchronous and not first-come.
    _, claim_a = call("POST", "/api/domains", {"name": NAME}, token_a, expect=201)
    _, claim_b = call("POST", "/api/domains", {"name": NAME}, token_b, expect=201)
    id_a, id_b = claim_a["data"]["id"], claim_b["data"]["id"]
    assert id_a != id_b
    allocate(id_a, ["old1.registrar.example", "old2.registrar.example"])
    allocate(id_b, ["old1.registrar.example", "old2.registrar.example"])
    _, shown_a = call("GET", f"/api/domains/{id_a}", token=token_a, expect=200)
    _, shown_b = call("GET", f"/api/domains/{id_b}", token=token_b, expect=200)
    pair_a, pair_b = shown_a["data"]["assigned_nameservers"], shown_b["data"]["assigned_nameservers"]
    assert len(pair_a) == len(pair_b) == 2 and pair_a != pair_b, (pair_a, pair_b)
    assert all(name.endswith(f".{zone}") and name.split(".")[0].startswith("ns") for name in pair_a + pair_b), (pair_a, pair_b)
    assert shown_a["data"]["claim_status"] == "pending_delegation"
    call("GET", f"/api/domains/{id_b}", token=token_a, expect=404)
    call("GET", f"/api/domains/{id_a}", token=token_b, expect=404)
    print("distinct_pairs=passed", pair_a, pair_b, flush=True)

    # 3. A's stale/incumbent delegation never proves B; A proves its own and becomes the owner.
    try:
        verify_with_delegation(id_b, pair_a)
    except subprocess.CalledProcessError:
        pass
    _, still_b = call("GET", f"/api/domains/{id_b}", token=token_b, expect=200)
    assert still_b["data"]["nameservers_verified_at"] is None
    verify_with_delegation(id_a, pair_a)
    # The registrant proved the delegation, so the other pending claim on the name is superseded for good.
    _, superseded = call("GET", f"/api/domains/{id_b}", token=token_b, expect=200)
    assert superseded["data"]["claim_status"] == "superseded", superseded
    _, owner_a = call("GET", f"/api/domains/{id_a}", token=token_a, expect=200)
    assert owner_a["data"]["lifecycle_state"] == "active" and owner_a["data"]["claim_status"] == "owner"
    call("POST", f"/api/domains/{id_a}/dns/records", {"type": "A", "name": "www", "content": "192.0.2.77", "ttl": 60}, token_a, expect=201)
    wait_for(lambda: dig(f"www.{NAME}", "A") == "192.0.2.77", "incumbent record served through DNSdist")

    # 4. B's pending claim changes nothing for A; B takes the name only by proving the delegation.
    assert dig(f"www.{NAME}", "A", tcp=True) == "192.0.2.77"
    # B submits a fresh claim now that A holds the name: it must get a pair other than A's delegation.
    _, fresh = call("POST", "/api/domains", {"name": NAME}, token_b, expect=201)
    id_b = fresh["data"]["id"]
    allocate(id_b, pair_a)
    _, shown_b = call("GET", f"/api/domains/{id_b}", token=token_b, expect=200)
    pair_b = shown_b["data"]["assigned_nameservers"]
    assert len(pair_b) == 2 and pair_b != pair_a, (pair_a, pair_b)
    time.sleep(3)
    assert dig(f"www.{NAME}", "A") == "192.0.2.77", "a pending competing claim must not disturb the incumbent"
    call("GET", f"/api/domains/{id_a}", token=token_a, expect=200)
    try:
        verify_with_delegation(id_b, pair_a)
    except subprocess.CalledProcessError:
        pass
    _, unchanged = call("GET", f"/api/domains/{id_b}", token=token_b, expect=200)
    assert unchanged["data"]["nameservers_verified_at"] is None, "the incumbent's delegation never proves the applicant"
    verify_with_delegation(id_b, pair_b)
    _, owner_b = call("GET", f"/api/domains/{id_b}", token=token_b, expect=200)
    assert owner_b["data"]["lifecycle_state"] == "active" and owner_b["data"]["claim_status"] == "owner"
    call("GET", f"/api/domains/{id_a}", token=token_a, expect=404)
    ns_set = wait_for(lambda: dig(NAME, "NS") if sorted(dig(NAME, "NS").split()) == sorted(f"{host}." for host in pair_b) else "", "new owner's NS served")
    print("takeover=passed", ns_set.split(), flush=True)

    # 5. The control plane restarts; the served delegation and ownership persist.
    subprocess.run(["docker", "compose", "-f", COMPOSE, "restart", "horizon"], cwd=ROOT, check=True, capture_output=True, timeout=120)
    time.sleep(8)
    assert sorted(dig(NAME, "NS").split()) == sorted(f"{host}." for host in pair_b)
    call("GET", f"/api/domains/{id_b}", token=token_b, expect=200)
    print(json.dumps({"qualification": "nameserver_pairs", "result": "passed", "domain": NAME}))


if __name__ == "__main__":
    main()
