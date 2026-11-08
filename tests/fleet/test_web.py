from __future__ import annotations

import http.client
import json
import sys
import threading
import time
import urllib.parse
from dataclasses import dataclass
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet import release  # noqa: E402
from cdnfoundry_fleet.cli import main  # noqa: E402
from cdnfoundry_fleet.common import ValidationError  # noqa: E402
from cdnfoundry_fleet.ssh import SshError  # noqa: E402
from cdnfoundry_fleet.vault import Vault  # noqa: E402
from cdnfoundry_fleet.web import FleetWeb, make_server, require_loopback  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
PASS = "correct horse battery"
PAGES = ["/", "/deploy", "/dns", "/tls", "/nodes", "/security", "/upgrade", "/config", "/rollback", "/backups", "/ha", "/secrets", "/history"]


@dataclass
class R:
    code: int = 0
    stdout: str = ""
    stderr: str = ""

    @property
    def ok(self) -> bool:
        return self.code == 0


class Fake:
    """Stands in for SSH: records what ran, answers the way a healthy node does, or refuses."""

    down = False
    ran: list[str] = []

    def __init__(self, work: Path) -> None:
        self.work = work

    def ensure_fleet_key(self):
        return "ssh-ed25519 AAAAFLEETPUBLICKEY cdnfoundry-fleet"

    def pin_host_key(self, target):
        return target.host

    def run(self, target, command, **_):
        Fake.ran.append(command)
        if Fake.down:
            raise SshError("connection refused")
        if command == "true":
            return R()
        if "ps -a --format json" in command:
            return R(0, "")
        if "RELEASE" in command:
            return R(0, "v2.0.0\n")
        return R()

    def upload_tree(self, *_a, **_k):
        pass


@pytest.fixture()
def served(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setenv("CDNF_FLEET_PASSPHRASE", PASS)
    vault_path = tmp_path / "fleet.db"
    assert main(["init", "--vault", str(vault_path), "--non-interactive", "--operator-domain", "ops.example.test",
                 "--platform-domain", "cdn.example.test", "--release", "v2.0.0"]) == 0
    Fake.down, Fake.ran = False, []
    web = FleetWeb(Vault(vault_path, PASS), ROOT, tmp_path / "bundles", transport_factory=Fake)
    server = make_server(web, "127.0.0.1", 0)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    yield web, server.server_address[1]
    server.shutdown()


def get(port: int, path: str, host: str | None = None):
    conn = http.client.HTTPConnection("127.0.0.1", port)
    conn.request("GET", path, headers={"Host": host or f"127.0.0.1:{port}"})
    r = conn.getresponse()
    return r.status, r.read().decode(), r


def post(port: int, path: str, data: dict[str, str]):
    conn = http.client.HTTPConnection("127.0.0.1", port)
    conn.request("POST", path, urllib.parse.urlencode(data), {"Content-Type": "application/x-www-form-urlencoded"})
    r = conn.getresponse()
    return r.status, r.read().decode(), r


def wait_job(port: int, location: str) -> str:
    for _ in range(100):
        body = get(port, location.split("?")[0])[1]
        if "Running" not in body:
            return body
        time.sleep(0.1)
    raise AssertionError("job did not finish")


def add_node(web, port, name="control-1", role="control", ip="203.0.113.5"):
    return post(port, "/nodes/add", {"csrf": web.csrf, "name": name, "role": role, "public_ipv4": ip, "ssh_port": "2222", "ssh_user": "root"})


def test_refuses_any_bind_address_but_loopback() -> None:
    assert require_loopback("127.0.0.1") == "127.0.0.1"
    for host in ("0.0.0.0", "::", "192.168.1.5", "localhost", ""):
        with pytest.raises(ValidationError):
            require_loopback(host)
    assert main(["web", "--host", "0.0.0.0"]) == 3


def test_every_page_renders_with_landmarks_title_and_no_script(served) -> None:
    _, port = served
    for path in PAGES:
        status, body, r = get(port, path)
        assert status == 200, path
        assert '<html lang="en">' in body and "<title>" in body and "<main>" in body and "<h1>" in body
        assert "<script>" not in body.lower() and '<script src="/assets/fleet.js" defer>' in body
        assert "default-src 'none'" in r.getheader("Content-Security-Policy")


def test_empty_fleet_says_what_to_do_first(served) -> None:
    assert "Add the control node" in get(served[1], "/")[1]


def test_unknown_page_and_rebound_host(served) -> None:
    assert get(served[1], "/nope")[0] == 404
    assert get(served[1], "/", host="evil.example")[0] == 403


def test_post_without_the_csrf_token_is_refused_and_changes_nothing(served) -> None:
    web, port = served
    status, body, _ = post(port, "/nodes/add", {"name": "pop-1", "role": "edge", "public_ipv4": "203.0.113.5"})
    assert status == 400 and "expired" in body
    assert web.read(lambda e: e.state())["nodes"] == {}


def test_add_node_shows_its_plan_and_the_one_fleet_public_key(served, tmp_path: Path) -> None:
    web, port = served
    status, _, r = add_node(web, port)
    assert status == 303 and r.getheader("Location").startswith("/nodes/control-1")
    page = get(port, "/nodes/control-1")[1]
    assert "prereq" in page and "Install and start this node" in page
    assert "control-1" in get(port, "/nodes")[1]
    nodes_page = get(port, "/nodes")[1]
    assert "AAAAFLEETPUBLICKEY" in nodes_page and "authorized_keys" in nodes_page
    assert "PRIVATE KEY" not in nodes_page and "ssh_key" not in nodes_page
    assert b"ops.example.test" not in (tmp_path / "fleet.db").read_bytes()


def test_a_pop_plan_asks_for_its_location(served) -> None:
    web, port = served
    add_node(web, port, "pop-1", "edge", "203.0.113.9")
    page = get(port, "/nodes/pop-1")[1]
    assert "enroll" in page and "Country code" in page


def test_check_runs_as_a_job_and_reports_an_unreachable_node(served) -> None:
    web, port = served
    add_node(web, port)
    Fake.down = True
    status, _, r = post(port, "/check", {"csrf": web.csrf})
    assert status == 303 and r.getheader("Location").startswith("/jobs/")
    assert "Finished" in wait_job(port, r.getheader("Location"))
    body = get(port, "/")[1]
    assert "control-1: Host reachable" in body and "connection refused" in body
    assert "Healthy at last check" in body


def test_check_of_a_healthy_node_passes_host_and_release(served) -> None:
    web, port = served
    add_node(web, port)
    _, _, r = post(port, "/check", {"csrf": web.csrf})
    wait_job(port, r.getheader("Location"))
    assert not any(c.node == "control-1" and c.name in {"Host reachable", "Release matches"} and not c.ok for c in web.last_checks)


def test_one_action_runs_at_a_time(served) -> None:
    web, port = served
    web.write_lock.acquire()
    try:
        status, body, _ = post(port, "/config/apply", {"csrf": web.csrf})
        assert status == 400 and "Another action is running" in body
    finally:
        web.write_lock.release()


def test_destructive_actions_need_the_typed_confirmation(served) -> None:
    web, port = served
    for path, data in (("/rollback/run", {}), ("/backups/restore", {"snapshot": "a" * 64, "node": "control-1"}), ("/secrets/rotate", {"secret": "metrics-token"})):
        status, body, _ = post(port, path, {"csrf": web.csrf, "confirm": "yes", **data})
        assert status == 400 and "Type exactly" in body, path
    status, body, _ = post(port, "/secrets/rotate", {"csrf": web.csrf, "secret": "app-key", "confirm": "ROTATE"})
    assert status == 400 and "only through the CLI" in body


def test_upgrade_shows_a_plan_for_a_signed_manifest_and_refuses_an_unsigned_one(served, tmp_path: Path) -> None:
    web, port = served
    add_node(web, port, "pop-1", "edge", "203.0.113.9")
    private, public = release.generate_key(tmp_path / "keys")
    assert post(port, "/upgrade/trust", {"csrf": web.csrf, "key": public.read_text()})[0] == 303
    import re

    env = {n: f"ghcr.io/o/{n.lower()}@sha256:{'a' * 64}" for n in set(re.findall(r"\$\{(CDNF_[A-Z_]+_IMAGE)", (ROOT / "compose.prod.yml").read_text()))}
    manifest = release.build(ROOT, release="v2.0.1", env_images=env)
    doc = release.sign_file(manifest, private.read_bytes())
    status, body, _ = post(port, "/upgrade/plan", {"csrf": web.csrf, "manifest": json.dumps(doc)})
    assert status == 200 and "Upgrade the fleet" in body and "pop-1: prepull" in body
    status, body, _ = post(port, "/upgrade/plan", {"csrf": web.csrf, "manifest": json.dumps({"manifest": manifest})})
    assert status == 400 and "not signed" in body


def test_history_records_actions_with_the_operator(served) -> None:
    web, port = served
    add_node(web, port)
    page = get(port, "/history")[1]
    assert "add-node" in page and web.operator in page


def test_check_job_lists_every_node_as_a_task_and_ticks_them(served) -> None:
    web, port = served
    add_node(web, port, "control-1")
    add_node(web, port, "pop-1", "edge", "203.0.113.9")
    _, _, r = post(port, "/check", {"csrf": web.csrf})
    page = wait_job(port, r.getheader("Location"))
    assert 'data-step="control-1:check"' in page and 'data-step="pop-1:check"' in page
    tasks = page.split('class="tasks-wrap"')[1].split('class="log-tools"')[0]
    assert 'data-state="running"' not in tasks and "2 of 2 steps" in page
    assert "control-1: " in page.split("<pre")[1]
    assert "<progress" in page and 'href="' + r.getheader("Location") + '/log.txt"' in page


def test_job_event_stream_replays_then_ends_with_done(served) -> None:
    web, port = served
    add_node(web, port)
    _, _, r = post(port, "/check", {"csrf": web.csrf})
    location = r.getheader("Location")
    wait_job(port, location)
    status, body, resp = get(port, location + "/events?after=0")
    assert status == 200 and resp.getheader("Content-Type") == "text/event-stream"
    kinds = [json.loads(chunk[chunk.index("data: ") + 6:])["type"] for chunk in body.strip().split("\n\n") if "data: " in chunk]
    assert kinds[0] == "plan" and kinds[-1] == "done" and "step" in kinds
    status, tail, _ = get(port, location + "/events?after=999")
    assert status == 200 and tail.strip() == ""


def test_unknown_job_stream_and_log_are_not_found(served) -> None:
    _, port = served
    assert get(port, "/jobs/nope/events")[0] == 404 and get(port, "/jobs/nope/log.txt")[0] == 404


def test_log_download_is_plain_text(served) -> None:
    web, port = served
    add_node(web, port)
    _, _, r = post(port, "/check", {"csrf": web.csrf})
    location = r.getheader("Location")
    wait_job(port, location)
    web.jobs[location.rsplit("/", 1)[1]].line("custom line")
    status, body, resp = get(port, location + "/log.txt")
    assert status == 200 and resp.getheader("Content-Type").startswith("text/plain") and "custom line" in body


def test_a_running_action_can_be_asked_to_stop_and_the_page_says_so(served) -> None:
    web, port = served
    gate = threading.Event()

    def slow(engine):
        engine.progress.plan([{"id": "n:one", "node": "n", "kind": "pin", "label": "n: pin"}])
        engine.progress.step("n:one", "running")
        gate.wait(5)
        engine.progress.step("n:one", "ok")
        return "done"

    job = web.run_job("Slow action", slow, "test")
    for _ in range(100):
        if job.steps:
            break
        time.sleep(0.05)
    page = get(port, f"/jobs/{job.id}")[1]
    assert "Stop after this step" in page and 'data-state="running"' in page
    assert post(port, f"/jobs/{job.id}/cancel", {"csrf": "wrong"})[0] == 400
    assert post(port, f"/jobs/{job.id}/cancel", {"csrf": web.csrf})[0] == 303
    assert job.cancel_requested
    gate.set()
    wait_job(port, f"/jobs/{job.id}")
    assert post(port, f"/jobs/{job.id}/cancel", {"csrf": web.csrf})[0] == 400


def test_history_keeps_the_log_and_steps_of_a_finished_action(served) -> None:
    web, port = served
    add_node(web, port)
    _, _, r = post(port, "/check", {"csrf": web.csrf})
    wait_job(port, r.getheader("Location"))
    for _ in range(50):
        if any(h["kind"] == "check" for h in web.vault.history()):
            break
        time.sleep(0.1)
    detail = json.loads(next(h for h in web.vault.history() if h["kind"] == "check")["detail"])
    assert detail["steps"][0]["label"] == "control-1: check" and isinstance(detail["log"], list)


def test_leaving_a_running_action_and_coming_back_shows_it_on_every_page(served) -> None:
    web, port = served
    add_node(web, port)
    gate = threading.Event()

    def slow(engine):
        engine.progress.plan([{"id": "control-1:pin", "node": "control-1", "kind": "pin", "label": "control-1: pin"}] * 1)
        engine.progress.step("control-1:pin", "running")
        gate.wait(5)
        engine.progress.step("control-1:pin", "ok")
        return "ok"

    job = web.run_job("Deploy the fleet", slow, "deploy")
    for _ in range(100):
        if job.steps:
            break
        time.sleep(0.05)
    for path in ("/", "/nodes", "/history"):
        page = get(port, path)[1]
        assert 'data-testid="running-banner"' in page and f'/jobs/{job.id}' in page and "0 of 1 steps" in page, path
    deploy = get(port, "/deploy")[1]
    assert 'data-testid="deploy-running"' in deploy and 'data-testid="running-banner"' not in deploy
    gate.set()
    for _ in range(100):
        if job.state != "running":
            break
        time.sleep(0.05)
    assert 'data-testid="finished-banner"' in get(port, "/")[1]
    get(port, f"/jobs/{job.id}")
    assert "finished-banner" not in get(port, "/")[1]


def test_an_interrupted_install_offers_to_continue(served) -> None:
    web, port = served
    add_node(web, port)

    def stops(engine):
        engine.remember_deploy({}, "v2.0.0")
        return type("Out", (), {"ok": False, "failed": "control-1:prereq", "detail": "boom", "rolled_back": [], "done": []})()

    job = web.run_job("Deploy the fleet", stops, "deploy")
    wait_job(port, f"/jobs/{job.id}")
    page = get(port, "/deploy")[1]
    assert 'data-testid="deploy-resume"' in page and "Continue installing 1 host(s)" in page and 'action="/deploy/continue"' in page


def test_continue_without_an_interrupted_install_says_so(served) -> None:
    web, port = served
    status, _, r = post(port, "/deploy/continue", {"csrf": web.csrf})
    assert status == 303
    assert "no interrupted installation" in wait_job(port, r.getheader("Location"))
