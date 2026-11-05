"""A disposable S3-compatible object store for backup qualification (SeaweedFS).

The public MinIO images were withdrawn, so qualification uses SeaweedFS's S3 gateway. restic creates
the bucket on `init`, so no bucket tooling is needed.
"""
from __future__ import annotations

import json
import pathlib
import subprocess
import tempfile
import time

IMAGE = "chrislusf/seaweedfs@sha256:c2658820e763294a9e9de7b36c81e59b879e3459148351aee4e31a126ca64860"
PORT = 8333


def start(name: str, network: str, access_key: str, secret_key: str) -> pathlib.Path:
    """Start the store; return the temporary directory holding its identity file (remove it afterwards)."""
    directory = pathlib.Path(tempfile.mkdtemp(prefix=name))
    directory.chmod(0o755)
    config = directory / "s3.json"
    config.write_text(json.dumps({"identities": [{"name": "qualification", "credentials": [
        {"accessKey": access_key, "secretKey": secret_key}], "actions": ["Admin", "Read", "Write", "List", "Tagging"]}]}))
    config.chmod(0o644)
    subprocess.run(["docker", "run", "-d", "--rm", "--name", name, "--network", network, "-v", f"{directory}:/cfg:ro", IMAGE,
                    "server", "-dir=/data", "-s3", "-s3.config=/cfg/s3.json", "-ip.bind=0.0.0.0"], check=True, capture_output=True, text=True)
    for _ in range(60):
        probe = subprocess.run(["docker", "exec", name, "wget", "-q", "-O", "/dev/null", f"http://127.0.0.1:{PORT}/"], capture_output=True)
        # An unauthenticated request is refused (exit 1 or 8), which still proves the gateway answers.
        if probe.returncode in (0, 1, 8):
            return directory
        time.sleep(1)
    raise RuntimeError("object store did not become ready")
