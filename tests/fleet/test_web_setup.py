"""The browser does the whole first run: create the vault and the fleet, then unlock it later."""
from __future__ import annotations

import http.client
import sys
import threading
import urllib.parse
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

from cdnfoundry_fleet.web import make_server  # noqa: E402
from cdnfoundry_fleet.web_setup import WebGate, parse_extra_nodes  # noqa: E402
from cdnfoundry_fleet.common import ValidationError  # noqa: E402

PASS = "correct horse battery staple"
FORM = {"passphrase": PASS, "passphrase2": PASS, "operator_domain": "ops.example.test", "platform_domain": "cdn.example.test",
        "release": "v2.0.0", "acme_email": "", "preset": "control-monitoring", "control_name": "control-1",
        "control_ipv4": "203.0.113.5", "control_hostname": "", "nodes": "pop-1 dns-edge 203.0.113.10 EU\npop-2, dns-edge, 203.0.113.11",
        "ssh_user": "root", "ssh_port": "22"}


@pytest.fixture()
def gate(tmp_path: Path):
    gate = WebGate(tmp_path / "fleet.db", ROOT, tmp_path / "bundles")
    server = make_server(gate, "127.0.0.1", 0)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    yield gate, server.server_address[1]
    server.shutdown()


def request(port: int, method: str, path: str, data: dict[str, str] | None = None):
    conn = http.client.HTTPConnection("127.0.0.1", port)
    body = urllib.parse.urlencode(data) if data is not None else None
    conn.request(method, path, body, {"Content-Type": "application/x-www-form-urlencoded"} if body else {})
    r = conn.getresponse()
    return r.status, r.read().decode(), r


def test_first_visit_shows_setup_and_creates_the_fleet(gate):
    g, port = gate
    status, body, _ = request(port, "GET", "/")
    assert status == 200 and "Set up your fleet" in body and 'name="passphrase2"' in body
    status, _, r = request(port, "POST", "/setup", {**FORM, "csrf": g.csrf})
    assert status == 303 and r.getheader("Location").startswith("/deploy")
    status, body, _ = request(port, "GET", "/nodes")
    assert status == 200 and "pop-1" in body and "What is this page?" in body and "pop-2" in body and "control-1" in body
    assert (g.output_dir / "pop-1").is_dir()


def test_existing_vault_asks_for_the_passphrase_in_the_browser(gate):
    g, port = gate
    request(port, "POST", "/setup", {**FORM, "csrf": g.csrf})
    fresh = WebGate(g.vault_path, ROOT, g.output_dir)
    server = make_server(fresh, "127.0.0.1", 0)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    try:
        port2 = server.server_address[1]
        assert "Unlock" in request(port2, "GET", "/")[1]
        status, body, _ = request(port2, "POST", "/unlock", {"csrf": fresh.csrf, "passphrase": "wrong passphrase!"})
        assert status == 400 and "did not unlock" in body
        status, _, r = request(port2, "POST", "/unlock", {"csrf": fresh.csrf, "passphrase": PASS})
        assert status == 303
        assert "Overview" in request(port2, "GET", "/")[1]
    finally:
        server.shutdown()


@pytest.mark.parametrize("change,message", [({"passphrase2": "different passphrase"}, "do not match"), ({"passphrase": "short", "passphrase2": "short"}, "at least"),
                                            ({"nodes": "pop-1 router 203.0.113.9"}, "role must be")])
def test_bad_setup_is_refused_and_leaves_no_vault(gate, change, message):
    g, port = gate
    status, body, _ = request(port, "POST", "/setup", {**FORM, **change, "csrf": g.csrf})
    assert status == 400 and message in body
    assert not g.vault_path.exists()
    assert "Set up your fleet" in request(port, "GET", "/")[1]


def test_setup_rejects_a_bad_form_token(gate):
    _, port = gate
    assert request(port, "POST", "/setup", {**FORM, "csrf": "nope"})[0] == 400


def test_extra_node_lines():
    nodes = parse_extra_nodes("# c\npop-1 edge 203.0.113.10 EU\n\n", "x.test")
    assert nodes[0]["hostname"] == "pop-1.x.test" and nodes[0]["region"] == "EU"
    with pytest.raises(ValidationError):
        parse_extra_nodes("only two", "x.test")


def _wait_job(port, location):
    import time

    for _ in range(100):
        body = request(port, "GET", location)[1]
        if "Running" not in body:
            return body
        time.sleep(0.1)
    raise AssertionError("job did not finish")


def _created(gate):
    g, port = gate
    request(port, "POST", "/setup", {**FORM, "csrf": g.csrf})
    return g, port


def test_deploy_page_needs_a_signed_release_before_review(gate):
    g, port = _created(gate)
    status, body, _ = request(port, "GET", "/deploy")
    assert status == 200 and "Step 1 of 2" in body and "Not installed" in body and "Your road to a running CDN" in body and "Signed release" in body
    status, body, _ = request(port, "POST", "/deploy/review", {"csrf": g.csrf})
    assert status == 400 and "signed release" in body


def test_review_then_confirm_installs_in_start_order(gate, monkeypatch):
    from cdnfoundry_fleet.actions import Outcome
    from cdnfoundry_fleet.engine import FleetEngine

    installed: list[tuple[str, str | None]] = []
    monkeypatch.setattr(FleetEngine, "release_manifest", lambda self, which="current": {"release": "v2.0.0", "roles": {}, "images": {}} if which == "current" else None)
    monkeypatch.setattr(FleetEngine, "bootstrap", lambda self, name, *, country=None, continent=None, log=None: installed.append((name, country)) or Outcome(True, [name]))
    monkeypatch.setattr(FleetEngine, "test_connection", lambda self, name: None)
    g, port = _created(gate)
    body = request(port, "GET", "/deploy")[1]
    assert "Run <b>Test connections</b>" in body
    assert request(port, "POST", "/deploy/review", {"csrf": g.csrf})[0] == 400
    _, _, r = request(port, "POST", "/deploy/test", {"csrf": g.csrf})
    _wait_job(port, r.getheader("Location"))
    body = request(port, "GET", "/deploy")[1]
    assert 'name="country:pop-1"' in body and "Connected" in body and "Step 2: review" in body
    geo = {"csrf": g.csrf, "country:pop-1": "de", "continent:pop-1": "eu", "country:pop-2": "jp", "continent:pop-2": "as"}
    status, body, _ = request(port, "POST", "/deploy/review", geo)
    assert status == 200 and "Step 2 of 2" in body and "Nothing has been sent" in body and installed == []
    token = body.split('name="token" value="')[1].split('"')[0]
    status, body, _ = request(port, "POST", "/deploy/run", {"csrf": g.csrf, "token": token, "confirm": "nope"})
    assert status == 400 and installed == []
    status, _, r = request(port, "POST", "/deploy/run", {"csrf": g.csrf, "token": token, "confirm": "DEPLOY"})
    assert status == 303 and r.getheader("Location").startswith("/jobs/")
    import time

    for _ in range(100):
        if len(installed) == 3:
            break
        time.sleep(0.1)
    assert [n for n, _ in installed] == ["control-1", "pop-1", "pop-2"] and installed[1][1] == "DE"
    time.sleep(0.5)
    assert "Every host is installed" in request(port, "GET", "/deploy")[1]


def test_failed_connection_explains_the_fix_and_shows_the_key(gate, monkeypatch):
    from cdnfoundry_fleet.engine import FleetEngine
    from cdnfoundry_fleet.ssh import SshError

    def refuse(self, name):
        raise SshError(f"SSH to {name} failed or was interrupted: root@203.0.113.5: Permission denied (publickey).")

    monkeypatch.setattr(FleetEngine, "test_connection", refuse)
    g, port = _created(gate)
    _, _, r = request(port, "POST", "/deploy/test", {"csrf": g.csrf})
    _wait_job(port, r.getheader("Location"))
    body = request(port, "GET", "/deploy")[1]
    assert "did not accept the Fleet key" in body and "authorized_keys" in body and "How do I add the key to a host?" in body


def test_missing_ssh_login_is_fixed_in_the_browser(gate):
    from cdnfoundry_fleet.ssh import TARGETS_FILE

    g, port = _created(gate)
    # a vault from before logins were saved at setup: remove them through the web layer's own session
    with g.web.vault.session() as work:
        (work / "ssh" / TARGETS_FILE).unlink()
    body = request(port, "GET", "/deploy")[1]
    assert "No SSH login saved" in body and "Save this login for hosts without one" in body and 'name="ssh_port"' in body
    assert "no login saved" in request(port, "GET", "/nodes/pop-1")[1]
    assert request(port, "POST", "/deploy/ssh-defaults", {"csrf": g.csrf})[0] == 303
    assert "No SSH login saved" not in request(port, "GET", "/deploy")[1]


def test_each_host_can_use_its_own_ssh_port(gate):
    g, port = gate
    form = {**FORM, "ssh_port": "2200", "nodes": "pop-1 dns-edge 203.0.113.10 EU port=2222 user=ubuntu\npop-2 dns-edge 203.0.113.11"}
    assert request(port, "POST", "/setup", {**form, "csrf": g.csrf})[0] == 303
    body = request(port, "GET", "/deploy")[1]
    assert "ubuntu@203.0.113.10:2222" in body and "root@203.0.113.11:2200" in body and "root@203.0.113.5:2200" in body
    assert "ssh -p 2200 root@203.0.113.5" in body
    assert request(port, "POST", "/nodes/pop-2/ssh", {"csrf": g.csrf, "ssh_user": "root", "ssh_host": "203.0.113.11", "ssh_port": "2022"})[0] == 303
    assert "root@203.0.113.11:2022" in request(port, "GET", "/deploy")[1]


def test_bad_node_ssh_port_is_refused():
    with pytest.raises(ValidationError):
        parse_extra_nodes("pop-1 edge 203.0.113.10 port=99999", "x.test")
    assert parse_extra_nodes("pop-1 edge 203.0.113.10 port=2222", "x.test")[0]["_ssh_port"] == 2222
