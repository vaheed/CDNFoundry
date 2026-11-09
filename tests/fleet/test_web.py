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

sys.path.insert(0, str(Path(__file__).resolve().parent))
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from test_inventory import HOST as HOST_INVENTORY  # noqa: E402

from cdnfoundry_fleet import release  # noqa: E402
from cdnfoundry_fleet.cli import main  # noqa: E402
from cdnfoundry_fleet.common import ValidationError  # noqa: E402
from cdnfoundry_fleet.ssh import SshError  # noqa: E402
from cdnfoundry_fleet.vault import Vault  # noqa: E402
from cdnfoundry_fleet.web import FleetWeb, make_server, require_loopback  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
PASS = "correct horse battery"
PAGES = ["/", "/deploy", "/dns", "/tls", "/nodes", "/inventory", "/security", "/upgrade", "/config", "/rollback", "/backups", "/ha", "/secrets", "/history"]


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
        if "__cdnfoundry_inventory__" in command:
            return R(0, HOST_INVENTORY)
        if command.startswith("docker logs"):
            return R(0, "listening\nAPI_TOKEN=abc123\n")
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
    assert "Add node" in page and web.operator in page


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


def test_a_failed_install_offers_a_retry_that_resumes_the_installation(served) -> None:
    web, _ = served
    from cdnfoundry_fleet.web import Job

    deploy = Job("d1", "Deploy the fleet")
    deploy.finish("failed", "Stopped at pop-1:gate")
    view = web._job_view(deploy)
    assert 'action="/deploy/continue"' in view and 'data-testid="job-retry-button"' in view and 'data-state="failed"' in view
    assert 'data-testid="job-retry-button"' not in web._job_view(Job("c1", "Check the fleet"))


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


def wait_for(predicate, tries: int = 100) -> None:
    for _ in range(tries):
        if predicate():
            return
        time.sleep(0.1)
    raise AssertionError("condition not met")


def test_inventory_page_asks_for_a_first_read_then_shows_hardware_and_findings(served) -> None:
    web, port = served
    assert "No nodes yet" in get(port, "/inventory")[1]
    add_node(web, port, "control-1")
    add_node(web, port, "pop-1", "edge", "203.0.113.9")
    assert "has not read any host" in get(port, "/inventory")[1]
    status, _, r = post(port, "/inventory/refresh", {"csrf": web.csrf})
    assert status == 303
    wait_job(port, r.getheader("Location"))
    page = get(port, "/inventory")[1]
    assert "CPU cores" in page and "16" in page and "6.8.0-60-generic" in page and "Hardware of every host" in page
    assert "vector" in page and "docker disk" in page.lower()


def test_node_page_shows_hardware_containers_logs_link_and_actions(served) -> None:
    web, port = served
    add_node(web, port, "control-1")
    _, _, r = post(port, "/nodes/control-1/inventory", {"csrf": web.csrf})
    wait_job(port, r.getheader("Location"))
    page = get(port, "/nodes/control-1")[1]
    assert "Hardware and system" in page and "Intel(R) Xeon" in page and "Synchronized" in page and "eth0" in page
    assert '/nodes/control-1/logs?c=edge-agent' in page and "Check this host" in page and "Read hardware again" in page


def test_container_log_page_hides_credentials_and_refuses_unknown_containers(served) -> None:
    web, port = served
    add_node(web, port, "control-1")
    _, _, r = post(port, "/nodes/control-1/inventory", {"csrf": web.csrf})
    wait_job(port, r.getheader("Location"))
    page = get(port, "/nodes/control-1/logs?c=edge-agent")[1]
    assert "listening" in page and "abc123" not in page
    assert "Fleet has not seen that container" in get(port, "/nodes/control-1/logs?c=ghost")[1]
    status, body, _ = get(port, "/api/logs?node=control-1&c=edge-agent;reboot")
    assert status == 400 and "not a container name" in body
    status, body, _ = get(port, "/api/logs?node=control-1&c=edge-agent")
    assert status == 200 and "abc123" not in body


def test_live_probe_updates_states_everywhere_and_the_api(served) -> None:
    web, port = served
    add_node(web, port, "control-1")
    add_node(web, port, "pop-1", "edge", "203.0.113.9")
    Fake.down = True
    status, _, _ = post(port, "/live/refresh", {"csrf": web.csrf})
    assert status == 303
    wait_for(lambda: web.live.updated)
    data = json.loads(get(port, "/api/live")[1])
    assert data["nodes"]["control-1"]["state"] == "offline" and data["interval"] == 0 and data["job"] is None
    assert "Unreachable" in get(port, "/nodes")[1] and "unreachable (connection refused)" in get(port, "/")[1]
    Fake.down = False
    web.live.refresh()
    data = json.loads(get(port, "/api/live")[1])
    assert data["nodes"]["control-1"]["state"] == "degraded" and data["nodes"]["control-1"]["memory"] == 75
    assert "Containers need attention" in get(port, "/nodes")[1]


def test_topology_links_every_host_and_names_its_state(served) -> None:
    web, port = served
    add_node(web, port, "control-1")
    web.live.refresh()
    page = get(port, "/")[1]
    assert 'href="/nodes/control-1"' in page and "control-1: Containers need attention" in page and "8 cores" in page


def test_check_one_host_runs_as_a_job_for_that_host_only(served) -> None:
    web, port = served
    add_node(web, port, "control-1")
    add_node(web, port, "pop-1", "edge", "203.0.113.9")
    _, _, r = post(port, "/nodes/pop-1/check", {"csrf": web.csrf})
    page = wait_job(port, r.getheader("Location"))
    assert 'data-step="pop-1:check"' in page and 'data-step="control-1:check"' not in page


def test_history_lists_each_action_with_its_steps_and_log(served) -> None:
    web, port = served
    add_node(web, port)
    _, _, r = post(port, "/check", {"csrf": web.csrf})
    wait_job(port, r.getheader("Location"))
    wait_for(lambda: any(h["kind"] == "check" for h in web.vault.history()))
    page = get(port, "/history")[1]
    assert '<details class="entry">' in page and "control-1: check" in page and "Check of the whole stack" in page


def test_live_json_and_logs_are_not_served_before_unlock(tmp_path, monkeypatch) -> None:
    from cdnfoundry_fleet.web_setup import WebGate

    gate = WebGate(tmp_path / "none.db", ROOT, tmp_path / "bundles")
    server = make_server(gate, "127.0.0.1", 0)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    port = server.server_address[1]
    try:
        assert get(port, "/api/live")[0] == 404 and get(port, "/api/logs?node=a&c=b")[0] == 404
    finally:
        server.shutdown()


def test_event_stream_delivers_lines_while_the_action_is_still_running(served) -> None:
    web, port = served
    gate = threading.Event()

    def slow(engine):
        engine.say("first line")
        gate.wait(10)
        engine.say("second line")
        return "ok"

    job = web.run_job("Slow action", slow, "test")
    conn = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
    conn.request("GET", f"/jobs/{job.id}/events?after=0", headers={"Host": f"127.0.0.1:{port}"})
    resp = conn.getresponse()
    seen = ""
    while "first line" not in seen:
        seen += resp.fp.readline().decode()
    assert job.state == "running" and "second line" not in seen
    gate.set()
    while '"type": "done"' not in seen:
        seen += resp.fp.readline().decode()
    assert "second line" in seen
    conn.close()


def test_the_alert_webhook_is_asked_for_saved_and_required_before_an_install(served) -> None:
    web, port = served
    add_node(web, port)
    page = get(port, "/deploy")[1]
    assert "Alert webhook (optional)" in page and "Not set" in page and 'action="/alert-webhook"' in page
    assert "What is this, and where do I get one?" in page and "webhook.site" in page
    status, body, _ = post(port, "/alert-webhook", {"csrf": web.csrf, "url": "http://alerts.example.com/x", "next": "/deploy"})
    assert status == 400 and "https://" in body
    status, _, r = post(port, "/alert-webhook", {"csrf": web.csrf, "url": "https://alerts.example.com/cdnfoundry", "next": "/config"})
    assert status == 303 and r.getheader("Location").startswith("/config")
    assert "https://alerts.example.com/cdnfoundry" in get(port, "/config")[1]
    assert "Not set" not in get(port, "/deploy")[1]


def test_the_alert_webhook_is_optional_and_can_be_cleared_again(served) -> None:
    web, port = served
    add_node(web, port)
    assert web.read(lambda e: e.deploy_blockers(["control-1"])) == ["No signed release is set"]
    _, _, r = post(port, "/config/apply", {"csrf": web.csrf})
    assert "Alertmanager" not in wait_job(port, r.getheader("Location"))
    wait_for(lambda: not web.write_lock.locked())
    assert post(port, "/alert-webhook", {"csrf": web.csrf, "url": "https://alerts.example.com/h", "next": "/deploy"})[0] == 303
    assert web.read(lambda e: e.alert_webhook()) == "https://alerts.example.com/h"
    assert post(port, "/alert-webhook", {"csrf": web.csrf, "url": "", "next": "/deploy"})[0] == 303
    assert web.read(lambda e: e.alert_webhook()) == "" and "Optional" in get(port, "/deploy")[1]


def test_config_page_sets_monitoring_logs_and_backups_like_the_cli(served) -> None:
    web, port = served
    add_node(web, port)
    page = get(port, "/config")[1]
    assert 'data-testid="monitoring-mode"' in page and 'data-testid="logs-mode"' in page and 'data-testid="backups-mode"' in page
    assert post(port, "/config/feature/monitoring", {"csrf": web.csrf, "mode": "colocated", "host": ""})[0] == 303
    assert post(port, "/config/feature/backups", {"csrf": web.csrf, "mode": "control", "repository": "s3:https://storage.example/b", "region": "eu-1"})[0] == 303
    features = web.read(lambda e: e.features())
    assert features["monitoring"]["mode"] == "colocated"
    assert features["backups"] == {"mode": "control", "repository": "s3:https://storage.example/b", "region": "eu-1"}
    assert "selected" in get(port, "/config")[1]


def test_config_page_refuses_a_log_host_that_does_not_exist_and_an_unknown_setting(served) -> None:
    web, port = served
    code, body, _ = post(port, "/config/feature/logs", {"csrf": web.csrf, "mode": "centralized", "host": "ghost"})
    assert code == 400 and "existing log host" in body
    code, body, _ = post(port, "/config/feature/nothing", {"csrf": web.csrf, "mode": "x"})
    assert code == 400 and "Unknown setting" in body
    assert web.read(lambda e: e.features())["logs"]["mode"] == "disabled"


def test_a_form_with_a_missing_field_or_a_bad_number_is_explained_in_words(served) -> None:
    web, port = served
    code, body, _ = post(port, "/nodes/add", {"csrf": web.csrf, "name": "x"})
    assert code == 400 and "missing the field" in body and "KeyError" not in body
    code, body, _ = post(port, "/deploy/ssh-defaults", {"csrf": web.csrf, "ssh_user": "root", "ssh_port": "abc"})
    assert code == 400 and "Enter a whole number" in body and "invalid literal" not in body


def test_the_standby_form_asks_for_the_ssh_user_and_refuses_a_bad_one(served) -> None:
    web, port = served
    add_node(web, port)
    code, body, _ = post(port, "/ha/check", {"csrf": web.csrf, "name": "control-2", "host": "203.0.113.11", "ssh_user": "Bad User", "ssh_port": "22"})
    assert code == 400 and "SSH user" in body
