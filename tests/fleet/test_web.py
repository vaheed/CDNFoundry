from __future__ import annotations

import http.client
import re
import sys
import threading
import urllib.parse
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet.cli import main  # noqa: E402
from cdnfoundry_fleet.common import ValidationError  # noqa: E402
from cdnfoundry_fleet.vault import Vault  # noqa: E402
from cdnfoundry_fleet.web import FleetWeb, make_server, require_loopback  # noqa: E402

PASS = "correct horse battery"


@pytest.fixture()
def served(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setenv("CDNF_FLEET_PASSPHRASE", PASS)
    vault_path = tmp_path / "fleet.db"
    assert main(["init", "--vault", str(vault_path), "--non-interactive", "--operator-domain", "ops.example.test",
                 "--platform-domain", "cdn.example.test", "--release", "v2.0.0"]) == 0
    web = FleetWeb(Vault(vault_path, PASS), tmp_path)
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


def test_refuses_any_bind_address_but_loopback() -> None:
    assert require_loopback("127.0.0.1") == "127.0.0.1"
    for host in ("0.0.0.0", "::", "192.168.1.5", "localhost", ""):
        with pytest.raises(ValidationError):
            require_loopback(host)
    assert main(["web", "--host", "0.0.0.0"]) == 3


def test_every_page_renders_with_landmarks_and_title(served) -> None:
    _, port = served
    for path in ("/", "/nodes", "/security", "/upgrade", "/history"):
        status, body, r = get(port, path)
        assert status == 200, path
        assert '<html lang="en">' in body and "<title>" in body and "<main>" in body
        assert "script" not in body.lower()
        assert "default-src 'none'" in r.getheader("Content-Security-Policy")


def test_empty_fleet_says_what_to_do_first(served) -> None:
    _, port = served
    assert "Add the control node" in get(port, "/")[1]


def test_unknown_page_is_404(served) -> None:
    assert get(served[1], "/nope")[0] == 404


def test_rebound_host_header_is_refused(served) -> None:
    assert get(served[1], "/", host="evil.example")[0] == 403


def test_post_without_the_csrf_token_is_refused_and_changes_nothing(served) -> None:
    web, port = served
    status, body, _ = post(port, "/nodes/add", {"name": "pop-1", "role": "edge", "public_ipv4": "203.0.113.5"})
    assert status == 400 and "expired" in body
    assert web.snapshot()["nodes"] == {}


def test_add_node_stores_the_key_only_in_the_vault(served, tmp_path: Path) -> None:
    web, port = served
    status, _, r = post(port, "/nodes/add", {
        "csrf": web.csrf, "name": "pop-1", "role": "edge", "public_ipv4": "203.0.113.5",
        "ssh_port": "2222", "ssh_user": "root", "ssh_key": "-----BEGIN OPENSSH PRIVATE KEY-----\nSECRETKEYBODY\n-----END OPENSSH PRIVATE KEY-----",
    })
    assert status == 303
    assert "pop-1" in web.snapshot()["nodes"]
    assert "pop-1" in get(port, "/nodes")[1]
    assert b"SECRETKEYBODY" not in (tmp_path / "fleet.db").read_bytes()
    assert "SECRETKEYBODY" not in get(port, "/nodes")[1]


def test_check_button_reports_unreachable_node(served) -> None:
    web, port = served
    web.transport_factory = lambda work: _Down()
    post(port, "/nodes/add", {"csrf": web.csrf, "name": "pop-1", "role": "edge", "public_ipv4": "203.0.113.5",
                              "ssh_port": "22", "ssh_user": "root", "ssh_key": "PRIVATE KEY"})
    status, _, r = post(port, "/check", {"csrf": web.csrf})
    assert status == 303
    body = get(port, "/")[1]
    assert "pop-1: Host reachable" in body
    assert "Healthy at last check" in body


class _Down:
    def run(self, target, command, **_):
        from cdnfoundry_fleet.ssh import SshError
        raise SshError("connection refused")
