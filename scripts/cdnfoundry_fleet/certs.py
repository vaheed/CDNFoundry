from __future__ import annotations

import json
import os
import shutil
import subprocess
import tempfile
from pathlib import Path
from typing import Sequence

from .common import RenderError, atomic_json, ensure_mode, utc_now


def run_checked(args: Sequence[str], *, cwd: Path | None = None) -> None:
    try:
        subprocess.run(
            list(args),
            cwd=cwd,
            check=True,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )
    except FileNotFoundError as exc:
        raise RenderError(f"Required executable not found: {args[0]}") from exc
    except subprocess.CalledProcessError as exc:
        message = exc.stderr.strip() or exc.stdout.strip() or "unknown error"
        raise RenderError(f"Command failed ({args[0]}): {message}") from exc


class PKI:
    """Manage the certificate layout expected by CDNFoundry production Compose.

    The edge identity CA signs agent identities inside the control plane. The edge
    server CA signs the TLS endpoints used by edge-control, edge runtimes and DNS
    API servers. CA private keys remain only in the fleet state directory.
    """

    def __init__(self, root: Path, *, dry_run: bool = False) -> None:
        self.root = root
        self.dry_run = dry_run

    def ensure(self) -> None:
        if self.dry_run:
            return
        self.root.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.root.chmod(0o700)
        self._ensure_ca("edge-identity-ca", "CDNFoundry Edge Identity CA")
        self._ensure_ca("edge-server-ca", "CDNFoundry Edge Server CA")

    def _ensure_ca(self, stem: str, common_name: str) -> None:
        key = self.root / f"{stem}.key"
        cert = self.root / f"{stem}.crt"
        if key.exists() and cert.exists():
            ensure_mode(key, 0o600)
            ensure_mode(cert, 0o644)
            return
        if self.dry_run:
            return
        with tempfile.TemporaryDirectory(dir=self.root) as tmp_dir:
            tmp = Path(tmp_dir)
            tmp_key = tmp / key.name
            tmp_cert = tmp / cert.name
            run_checked(["openssl", "ecparam", "-name", "prime256v1", "-genkey", "-noout", "-out", str(tmp_key)])
            run_checked(
                [
                    "openssl",
                    "req",
                    "-x509",
                    "-new",
                    "-sha256",
                    "-key",
                    str(tmp_key),
                    "-out",
                    str(tmp_cert),
                    "-days",
                    "3650",
                    "-subj",
                    f"/CN={common_name}",
                    "-addext",
                    "basicConstraints=critical,CA:TRUE",
                    "-addext",
                    "keyUsage=critical,keyCertSign,cRLSign",
                ]
            )
            os.chmod(tmp_key, 0o600)
            os.chmod(tmp_cert, 0o644)
            os.replace(tmp_key, key)
            os.replace(tmp_cert, cert)

    def ensure_node_certificate(self, node: dict[str, object]) -> tuple[Path, Path, Path]:
        self.ensure()
        name = str(node["name"])
        hostname = str(node["hostname"])
        node_dir = self.root / "nodes" / name
        key = node_dir / "node.key"
        cert = node_dir / "node.crt"
        meta = node_dir / "metadata.json"
        ca = self.root / "edge-server-ca.crt"
        expected = {
            "issuer": "edge-server-ca",
            "name": name,
            "hostname": hostname,
            "public_ipv4": node.get("public_ipv4"),
            "public_ipv6": node.get("public_ipv6"),
        }
        if key.exists() and cert.exists() and meta.exists():
            try:
                current = json.loads(meta.read_text(encoding="utf-8"))
            except json.JSONDecodeError:
                current = {}
            if all(current.get(k) == v for k, v in expected.items()):
                ensure_mode(key, 0o600)
                ensure_mode(cert, 0o644)
                return ca, cert, key
        if self.dry_run:
            return ca, cert, key
        node_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        node_dir.chmod(0o700)
        with tempfile.TemporaryDirectory(dir=node_dir) as tmp_dir:
            tmp = Path(tmp_dir)
            tmp_key = tmp / "node.key"
            tmp_csr = tmp / "node.csr"
            tmp_cert = tmp / "node.crt"
            ext = tmp / "ext.cnf"
            sans = [f"DNS:{hostname}"]
            if node.get("public_ipv4"):
                sans.append(f"IP:{node['public_ipv4']}")
            if node.get("public_ipv6"):
                sans.append(f"IP:{node['public_ipv6']}")
            ext.write_text(
                "basicConstraints=critical,CA:FALSE\n"
                "keyUsage=critical,digitalSignature,keyEncipherment\n"
                "extendedKeyUsage=serverAuth,clientAuth\n"
                f"subjectAltName={','.join(sans)}\n",
                encoding="utf-8",
            )
            run_checked(["openssl", "ecparam", "-name", "prime256v1", "-genkey", "-noout", "-out", str(tmp_key)])
            run_checked(
                ["openssl", "req", "-new", "-sha256", "-key", str(tmp_key), "-out", str(tmp_csr), "-subj", f"/CN={hostname}"]
            )
            run_checked(
                [
                    "openssl",
                    "x509",
                    "-req",
                    "-sha256",
                    "-in",
                    str(tmp_csr),
                    "-CA",
                    str(self.root / "edge-server-ca.crt"),
                    "-CAkey",
                    str(self.root / "edge-server-ca.key"),
                    "-CAcreateserial",
                    "-out",
                    str(tmp_cert),
                    "-days",
                    "825",
                    "-extfile",
                    str(ext),
                ]
            )
            os.chmod(tmp_key, 0o600)
            os.chmod(tmp_cert, 0o644)
            os.replace(tmp_key, key)
            os.replace(tmp_cert, cert)
            expected["issued_at"] = utc_now()
            atomic_json(meta, expected, 0o600)
        return ca, cert, key

    def copy_node_material(self, node: dict[str, object], destination: Path) -> None:
        server_ca, cert, key = self.ensure_node_certificate(node)
        destination.mkdir(parents=True, exist_ok=True, mode=0o700)
        if self.dry_run:
            return

        # Generic names preserve backwards compatibility with older generated bundles.
        shutil.copy2(server_ca, destination / "edge-server-ca.crt")
        shutil.copy2(server_ca, destination / "fleet-ca.crt")
        shutil.copy2(cert, destination / "node.crt")
        shutil.copy2(key, destination / "node.key")

        if str(node.get("role")) == "control":
            shutil.copy2(self.root / "edge-identity-ca.crt", destination / "edge-identity-ca.crt")
            shutil.copy2(self.root / "edge-identity-ca.key", destination / "edge-identity-ca.key")

        for path in destination.iterdir():
            path.chmod(0o600 if path.suffix == ".key" else 0o644)
