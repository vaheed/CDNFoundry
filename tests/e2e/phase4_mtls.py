#!/usr/bin/env python3
"""Qualify the dedicated edge-control listener's real mutual TLS boundary."""

import os
import pathlib
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
NAME = "cdnf-phase4-mtls-e2e"


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(args, cwd=ROOT, check=check, text=True, capture_output=True)


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="cdnf-mtls-") as directory:
        os.chmod(directory, 0o755)
        target = pathlib.Path(directory)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=Edge Identity CA",
            "-keyout", str(target / "ca.key"), "-out", str(target / "ca.crt"))
        run("openssl", "req", "-newkey", "rsa:2048", "-nodes", "-subj", "/CN=edge-control",
            "-addext", "subjectAltName=DNS:edge-control", "-keyout", str(target / "server.key"), "-out", str(target / "server.csr"))
        run("openssl", "x509", "-req", "-in", str(target / "server.csr"), "-CA", str(target / "ca.crt"),
            "-CAkey", str(target / "ca.key"), "-CAcreateserial", "-days", "1", "-copy_extensions", "copy", "-out", str(target / "server.crt"))
        run("openssl", "req", "-newkey", "rsa:2048", "-nodes", "-subj", "/CN=00000000-0000-4000-8000-000000000001",
            "-keyout", str(target / "client.key"), "-out", str(target / "client.csr"))
        extension = target / "client.ext"
        extension.write_text("extendedKeyUsage=clientAuth\n")
        run("openssl", "x509", "-req", "-in", str(target / "client.csr"), "-CA", str(target / "ca.crt"),
            "-CAkey", str(target / "ca.key"), "-CAcreateserial", "-days", "1", "-extfile", str(extension), "-out", str(target / "client.crt"))
        for path in target.iterdir():
            path.chmod(0o644)
        run("docker", "run", "-d", "--rm", "--name", NAME,
            "-v", f"{ROOT / 'core/public'}:/app/public:ro",
            "-v", f"{ROOT / 'docker/nginx/edge-control.conf'}:/etc/nginx/conf.d/default.conf:ro",
            "-v", f"{target / 'server.crt'}:/run/secrets/edge-control-server.crt:ro",
            "-v", f"{target / 'server.key'}:/run/secrets/edge-control-server.key:ro",
            "-v", f"{target / 'ca.crt'}:/run/secrets/edge-identity-ca.crt:ro", "nginx:1.30.3-alpine")
        try:
            run("docker", "exec", NAME, "nginx", "-t")
            common = ("docker", "run", "--rm", "--network", f"container:{NAME}", "-v", f"{directory}:/tls:ro",
                "curlimages/curl:8.16.0", "-sS", "--resolve", "edge-control:8443:127.0.0.1", "--cacert", "/tls/ca.crt")
            unauthenticated = run(*common, "-o", "/dev/null", "-w", "%{http_code}", "https://edge-control:8443/mtls-health")
            assert unauthenticated.stdout == "401", unauthenticated.stdout
            authenticated = run(*common, "--cert", "/tls/client.crt", "--key", "/tls/client.key",
                "https://edge-control:8443/mtls-health")
            assert authenticated.stdout == "ok\n", authenticated.stdout
        finally:
            run("docker", "stop", NAME, check=False)
    print("Phase 4 edge-control mTLS qualification passed.")


if __name__ == "__main__":
    main()
