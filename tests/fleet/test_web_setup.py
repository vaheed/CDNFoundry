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
    assert status == 303 and r.getheader("Location").startswith("/nodes")
    status, body, _ = request(port, "GET", "/nodes")
    assert status == 200 and "pop-1" in body and "pop-2" in body and "control-1" in body
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
