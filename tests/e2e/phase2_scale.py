#!/usr/bin/env python3
"""Bounded Phase 2 control-plane dataset and DNS mutation qualification."""

from __future__ import annotations

import json
import os
import secrets
import subprocess
import time
import urllib.request
import uuid

BASE = os.environ.get("CDNF_BASE_URL", "http://localhost:8080").rstrip("/")
COMPOSE = os.environ.get("CDNF_COMPOSE_FILE", "compose.dev.yml")
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))
RUN = f"{int(time.time())}{secrets.token_hex(3)}"
PREFIX = f"scale-{RUN}"
EMAIL = f"{PREFIX}@example.test"
PASSWORD = f"Scale-{secrets.token_urlsafe(20)}"


def compose(*arguments: str, output: bool = False) -> str:
    result = subprocess.run(["docker", "compose", "-f", COMPOSE, *arguments], cwd=ROOT, check=True, text=True, capture_output=True, timeout=600)
    return result.stdout.strip() if output else ""


def sql(statement: str) -> str:
    return compose("exec", "-T", "control-db", "psql", "-U", "cdnf", "-d", "cdnf", "-v", "ON_ERROR_STOP=1", "-Atc", statement, output=True)


def artisan(expression: str) -> None:
    compose("exec", "-T", "core", "php", "artisan", "tinker", f"--execute={expression}")


def quote(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def api(method: str, path: str, payload: object, token: str | None = None) -> object:
    headers = {"Accept": "application/json", "Content-Type": "application/json", "Idempotency-Key": str(uuid.uuid4())}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    request = urllib.request.Request(BASE + path, data=json.dumps(payload).encode(), headers=headers, method=method)
    with urllib.request.urlopen(request, timeout=180) as response:
        return json.loads(response.read())


def main() -> None:
    dataset_started = time.monotonic()
    sql(f"INSERT INTO domains(name,display_name,lifecycle_state,revision,created_at,updated_at) SELECT '{PREFIX}-'||g||'.example','{PREFIX}-'||g||'.example','disabled',1,now(),now() FROM generate_series(1,500000) g")
    sql(f"INSERT INTO dns_records(domain_id,type,name,content,content_hash,ttl,priority,weight,port,mode,created_at,updated_at) SELECT d.id,v.type,v.label||'.'||d.name,v.content,md5(v.content)||md5(v.content),300,0,0,0,'dns_only',now(),now() FROM domains d CROSS JOIN (VALUES ('A','a','192.0.2.1'),('AAAA','aaaa','2001:db8::1')) v(type,label,content) WHERE d.name LIKE '{PREFIX}-%'")
    counts = sql(f"SELECT count(*)||(SELECT ':'||count(*) FROM dns_records r JOIN domains d ON d.id=r.domain_id WHERE d.name LIKE '{PREFIX}-%') FROM domains WHERE name LIKE '{PREFIX}-%'")
    assert counts == "500000:1000000", counts
    dataset_seconds = time.monotonic() - dataset_started

    artisan("App\\Models\\User::query()->create(['name'=>'Phase 2 Scale','email'=>" + quote(EMAIL) + ",'password'=>Illuminate\\Support\\Facades\\Hash::make(" + quote(PASSWORD) + "),'type'=>'admin']);")
    login = api("POST", "/api/auth/login", {"email": EMAIL, "password": PASSWORD, "device_name": "phase2-scale"})
    token = login["data"]["token"]
    domains: list[int] = []
    for index in range(10):
        created = api("POST", "/api/domains", {"name": f"{PREFIX}-mut-{index}.test"}, token)
        domains.append(created["data"]["id"])

    mutation_started = time.monotonic()
    processed = 0
    for domain_offset, domain_id in enumerate(domains):
        actions = []
        for index in range(5000):
            address = f"198.51.{domain_offset}.{(index % 250) + 1}"
            actions.append({"action": "create", "record": {"type": "A", "name": f"h-{index}", "content": address, "ttl": 60}})
        result = api("POST", f"/api/domains/{domain_id}/dns/records/bulk", {"actions": actions}, token)
        assert result["data"]["changed"] == 5000
        processed += 5000
    mutation_seconds = time.monotonic() - mutation_started
    assert processed == 50000
    assert sql(f"SELECT count(*) FROM dns_records r JOIN domains d ON d.id=r.domain_id WHERE d.name LIKE '{PREFIX}-mut-%'") == "50000"
    print(f"phase2_scale=passed zones=500000 records=1000000 changes=50000 burst_first_two=10000 dataset_seconds={dataset_seconds:.2f} mutation_seconds={mutation_seconds:.2f}")


if __name__ == "__main__":
    try:
        main()
    finally:
        sql(f"DELETE FROM domains WHERE name LIKE '{PREFIX}-%'")
        artisan("App\\Models\\User::query()->where('email'," + quote(EMAIL) + ")->delete();")
