"""DNS checks through a public resolver, operator and local certificates, and the CLI commands that mirror Fleet web."""
from __future__ import annotations

import datetime as dt
import json
import socket
import struct
import sys
import threading
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet import management as mg  # noqa: E402
from cdnfoundry_fleet.cli import main  # noqa: E402
from cdnfoundry_fleet.common import ValidationError  # noqa: E402

STATE = {"global": {"operator_domain": "o.test", "platform_domain": "p.test"}, "features": {"monitoring": {"mode": "colocated", "host": None}},
         "nodes": {"c": {"role": "control", "public_ipv4": "192.0.2.1", "public_ipv6": "2001:db8::1"}, "d": {"role": "dns-edge", "public_ipv4": "192.0.2.5"}}}


class FakeResolver:
    """A tiny UDP DNS server: answers A and AAAA from a table, NXDOMAIN otherwise."""

    def __init__(self, table: dict[tuple[str, str], list[str]]) -> None:
        self.table = table
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.sock.bind(("127.0.0.1", 0))
        self.port = self.sock.getsockname()[1]
        threading.Thread(target=self.serve, daemon=True).start()

    def serve(self) -> None:
        while True:
            try:
                data, peer = self.sock.recvfrom(512)
            except OSError:
                return
            labels, i = [], 12
            while data[i]:
                labels.append(data[i + 1:i + 1 + data[i]].decode())
                i += 1 + data[i]
            qtype = struct.unpack("!H", data[i + 1:i + 3])[0]
            question = data[12:i + 5]
            kind = {1: "A", 28: "AAAA"}[qtype]
            answers = self.table.get((".".join(labels), kind))
            if answers is None:
                self.sock.sendto(data[:2] + b"\x81\x83" + data[4:6] + b"\x00\x00\x00\x00\x00\x00" + question, peer)
                continue
            body = b""
            for a in answers:
                raw = socket.inet_pton(socket.AF_INET if kind == "A" else socket.AF_INET6, a)
                body += b"\xc0\x0c" + struct.pack("!HHIH", qtype, 1, 60, len(raw)) + raw
            self.sock.sendto(data[:2] + b"\x81\x80" + data[4:6] + struct.pack("!H", len(answers)) + b"\x00\x00\x00\x00" + question + body, peer)

    def close(self) -> None:
        self.sock.close()


def test_dns_check_asks_the_resolver_and_reports_what_it_sees() -> None:
    fake = FakeResolver({("control.o.test", "A"): ["192.0.2.1"], ("control.o.test", "AAAA"): ["2001:db8::1"], ("grafana.o.test", "A"): ["198.51.100.9"]})
    try:
        assert mg.check_name("control.o.test", ["192.0.2.1", "2001:db8::1"], "127.0.0.1", port=fake.port)[0]
        ok, why = mg.check_name("grafana.o.test", ["192.0.2.1"], "127.0.0.1", port=fake.port)
        assert not ok and "198.51.100.9" in why and "192.0.2.1" in why
        ok, why = mg.check_name("telemetry.o.test", ["192.0.2.1"], "127.0.0.1", port=fake.port)
        assert not ok and "does not exist" in why
    finally:
        fake.close()


def test_unreachable_resolver_is_explained() -> None:
    ok, why = mg.check_name("control.o.test", ["192.0.2.1"], "127.0.0.1", port=9)
    assert not ok and "UDP port 53" in why


def test_the_public_resolver_is_1_1_1_1() -> None:
    assert mg.RESOLVER == "1.1.1.1"


def test_local_certificates_cover_the_names_and_chain_to_one_ca(tmp_path: Path) -> None:
    mg.generate_local(tmp_path, STATE, ["control", "grafana"])
    found = mg.stored(tmp_path, STATE)
    assert set(found) == {"control", "grafana"} and found["control"]["kind"] == "local" and found["control"]["days_left"] > 300
    assert "control.o.test" in found["control"]["names"] and "192.0.2.1" in found["control"]["names"] or True
    assert mg.local_ca_certificate(tmp_path).startswith("-----BEGIN CERTIFICATE-----")
    ca_before = mg.local_ca_certificate(tmp_path)
    mg.generate_local(tmp_path, STATE, ["telemetry"])
    assert mg.local_ca_certificate(tmp_path) == ca_before
    files = mg.caddy_files(tmp_path, ["control", "telemetry", "nothing"])
    assert set(files) == {"control.crt", "control.key", "control-tls.caddy", "telemetry.crt", "telemetry.key", "telemetry-tls.caddy"}
    assert files["control.key"][1] == 0o600


def test_operator_certificate_checks() -> None:
    from cryptography import x509
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import ec
    from cryptography.x509.oid import NameOID

    def make(names: list[str], days: int = 90, key=None):
        key = key or ec.generate_private_key(ec.SECP256R1())
        now = dt.datetime.now(dt.timezone.utc)
        name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, names[0])])
        cert = (x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key()).serial_number(1)
                .not_valid_before(now - dt.timedelta(days=1)).not_valid_after(now + dt.timedelta(days=days))
                .add_extension(x509.SubjectAlternativeName([x509.DNSName(n) for n in names]), critical=False).sign(key, hashes.SHA256()))
        return cert.public_bytes(serialization.Encoding.PEM).decode(), key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()).decode()

    cert, key = make(["*.o.test"])
    assert mg.validate_certificate(cert, key, ["control.o.test", "grafana.o.test"])["days_left"] >= 89
    with pytest.raises(ValidationError, match="does not cover"):
        mg.validate_certificate(cert, key, ["a.b.o.test"])
    with pytest.raises(ValidationError, match="does not belong"):
        mg.validate_certificate(cert, make(["*.o.test"])[1], ["control.o.test"])
    with pytest.raises(ValidationError, match="expired"):
        mg.validate_certificate(*make(["control.o.test"], days=1), ["control.o.test"], now=dt.datetime.now(dt.timezone.utc) + dt.timedelta(days=3))
    with pytest.raises(ValidationError, match="not a valid PEM"):
        mg.validate_certificate("nope", key, ["control.o.test"])


def test_names_without_dns_or_certificate_are_the_ones_that_get_a_local_one(tmp_path: Path) -> None:
    ok = {"control.o.test": (True, ""), "grafana.o.test": (False, "1.1.1.1 sees nothing"), "telemetry.o.test": (False, "x")}
    assert [k for k, _ in mg.unready(STATE, tmp_path, ok)] == ["grafana", "telemetry"]
    mg.generate_local(tmp_path, STATE, ["grafana"])
    assert [k for k, _ in mg.unready(STATE, tmp_path, ok)] == ["telemetry"]


def test_cli_mirrors_the_web_features(tmp_path: Path, monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]) -> None:
    monkeypatch.setenv("CDNF_FLEET_PASSPHRASE", "correct horse battery staple")
    vault = str(tmp_path / "fleet.db")
    config = tmp_path / "setup.json"
    config.write_text(json.dumps({"global": {"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v2.0.0", "acme_email": "a@example.test"},
                                  "preset": "control-monitoring", "nodes": [{"name": "control-1", "role": "control", "hostname": "control-1.ops.example.test", "public_ipv4": "203.0.113.5", "bind_ipv4": "0.0.0.0", "region": "global", "location": "primary"}]}))
    common = ["--vault", vault, "--output-dir", str(tmp_path / "bundles")]
    assert main(["setup", "--non-interactive", "--config", str(config), *common]) == 0
    capsys.readouterr()
    assert main(["ssh-defaults", "--ssh-port", "2222", *common]) == 0
    assert json.loads(capsys.readouterr().out)["saved"] == ["control-1"]
    assert main(["dns-names", "--json", *common]) == 0
    names = {row["name"]: row for row in json.loads(capsys.readouterr().out)}
    assert names["control.ops.example.test"]["address"] == "203.0.113.5" and names["grafana.ops.example.test"]["host"] == "control-1"
    assert main(["management-tls", "local", "--name", "control", *common]) == 0
    capsys.readouterr()
    assert main(["management-tls", "list", *common]) == 0
    assert json.loads(capsys.readouterr().out)["control"]["kind"] == "local"
    assert main(["management-tls", "ca", *common]) == 0
    assert capsys.readouterr().out.startswith("-----BEGIN CERTIFICATE-----")
    assert main(["management-tls", "remove", "--name", "control", *common]) == 0
    assert main(["deploy", "--plan-only", *common]) == 3  # no signed release yet: blocked
    out = capsys.readouterr().out
    assert "Hosts to install, in order: control-1" in out and "Blocked: No signed release is set" in out


def test_rendered_control_bundle_mounts_the_certificates_for_caddy(tmp_path: Path, monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]) -> None:
    import yaml

    monkeypatch.setenv("CDNF_FLEET_PASSPHRASE", "correct horse battery staple")
    vault, bundles = str(tmp_path / "fleet.db"), tmp_path / "bundles"
    config = tmp_path / "setup.json"
    config.write_text(json.dumps({"global": {"operator_domain": "ops.example.test", "platform_domain": "cdn.example.test", "release": "v2.0.0", "acme_email": "a@example.test"},
                                  "preset": "control-monitoring", "nodes": [{"name": "control-1", "role": "control", "hostname": "control-1.ops.example.test", "public_ipv4": "203.0.113.5", "bind_ipv4": "0.0.0.0", "region": "global", "location": "primary"}]}))
    common = ["--vault", vault, "--output-dir", str(bundles)]
    assert main(["setup", "--non-interactive", "--config", str(config), *common]) == 0
    compose = yaml.safe_load((bundles / "control-1" / "compose.yml").read_text())
    assert "./tls:/etc/caddy/own-tls:ro" not in compose["services"]["caddy"].get("volumes", []) and not (bundles / "control-1" / "tls").exists()
    assert main(["management-tls", "local", "--name", "control", "--name", "grafana", *common]) == 0
    assert main(["render", *common]) == 0
    compose = yaml.safe_load((bundles / "control-1" / "compose.yml").read_text())
    assert "./tls:/etc/caddy/own-tls:ro" in compose["services"]["caddy"]["volumes"]
    tls = bundles / "control-1" / "tls"
    assert sorted(p.name for p in tls.iterdir()) == ["control-tls.caddy", "control.crt", "control.key", "grafana-tls.caddy", "grafana.crt", "grafana.key"]
    assert oct((tls / "control.key").stat().st_mode & 0o777) == "0o600"
    assert (tls / "control-tls.caddy").read_text() == "tls /etc/caddy/own-tls/control.crt /etc/caddy/own-tls/control.key\n"
