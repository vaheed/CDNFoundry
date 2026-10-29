import stat
import subprocess
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[2] / "scripts/issue-edge-listener-material.sh"


def _make_ca(state: Path) -> None:
    pki = state / "pki"
    pki.mkdir(parents=True)
    subprocess.run(["openssl", "ecparam", "-name", "prime256v1", "-genkey", "-noout", "-out", str(pki / "edge-server-ca.key")], check=True)
    subprocess.run(
        ["openssl", "req", "-x509", "-new", "-sha256", "-key", str(pki / "edge-server-ca.key"), "-subj", "/CN=test edge CA",
         "-days", "30", "-addext", "basicConstraints=critical,CA:TRUE", "-addext", "keyUsage=critical,keyCertSign,cRLSign",
         "-out", str(pki / "edge-server-ca.crt")],
        check=True,
    )


def _run(state: Path, output: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(SCRIPT), "--state-dir", str(state), "--hostname", "pop-1.example.test",
         "--public-ipv4", "192.0.2.78", "--output", str(output)],
        check=True, text=True, capture_output=True,
    )


def test_issues_listener_material_once_and_keeps_existing_keys(tmp_path: Path) -> None:
    state = tmp_path / "state"
    _make_ca(state)
    output = tmp_path / "shm"

    result = _run(state, output)
    assert "edge listener material ready" in result.stdout
    cert = output / "edge-runtime.crt"
    key = output / "edge-runtime.key"
    recovery = output / "edge-state-encryption.key"
    for path in (cert, key, recovery):
        assert path.exists(), path
    assert stat.S_IMODE(key.stat().st_mode) == 0o600
    assert stat.S_IMODE(recovery.stat().st_mode) == 0o600
    assert len(recovery.read_text().strip()) == 64

    subprocess.run(["openssl", "verify", "-CAfile", str(state / "pki/edge-server-ca.crt"), str(cert)], check=True, capture_output=True)
    san = subprocess.run(["openssl", "x509", "-in", str(cert), "-noout", "-ext", "subjectAltName"],
                         check=True, text=True, capture_output=True).stdout
    assert "DNS:pop-1.example.test" in san and "IP Address:192.0.2.78" in san

    before = (key.read_bytes(), cert.read_bytes(), recovery.read_bytes())
    _run(state, output)
    assert (key.read_bytes(), cert.read_bytes(), recovery.read_bytes()) == before, "rerun must not rotate keys"


def test_refuses_missing_fleet_ca(tmp_path: Path) -> None:
    completed = subprocess.run(
        ["bash", str(SCRIPT), "--state-dir", str(tmp_path / "empty"), "--hostname", "pop-1.example.test",
         "--public-ipv4", "192.0.2.78", "--output", str(tmp_path / "out")],
        text=True, capture_output=True,
    )
    assert completed.returncode == 1
    assert "Missing fleet CA file" in completed.stderr
