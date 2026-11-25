#!/usr/bin/env python3
"""Real-cell check that a cell built from a different rule set commit reports it, and one built from the pinned commits does not.

The edge-runtime image records the WAF engine and rule set commits it was built from in /etc/cdnfoundry/ruleset-versions.json, and
the cell reports them in its status, which the agent forwards in every heartbeat. This test starts one cell from the image as built
and one from a derived image whose recorded CRS commit is changed, then reads each cell's own status: the first equals the pinned
commits in core/resources/contracts/runtime-pins.json, the second differs in exactly the CRS commit. The control plane's comparison,
the cells and pools lists, the metric and the alert are covered by CellRulesetVersionTest, the browser suite and the Prometheus rule test.
"""

import json
import os
import pathlib
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
IMAGE = os.environ.get("CDNF_WAF_IMAGE", "cdnfoundry/edge-runtime:waf-qualification")
DERIVED = "cdnfoundry/edge-runtime:ruleset-mismatch-test"
NETWORK = "cdnf-ruleset-mismatch"
PINNED = "cdnf-ruleset-mismatch-pinned"
CHANGED = "cdnf-ruleset-mismatch-changed"
FAKE_CRS = "0" * 40


def run(*args: str, check: bool = True, input_text: str | None = None) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, check=False, input=input_text)
    if check and result.returncode:
        raise RuntimeError(f"command failed: {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def reported(name: str, runtime: pathlib.Path, directory: pathlib.Path, image: str) -> dict:
    run("docker", "run", "-d", "--name", name, "--network", NETWORK,
        "-e", "EDGE_RUNTIME_FILE=/var/lib/cdnfoundry/runtime/active.json", "-e", "EDGE_STATUS_TOKEN=e2e",
        "-v", f"{runtime}:/var/lib/cdnfoundry/runtime/active.json:ro",
        "-v", f"{directory / 'tls.crt'}:/run/edge/tls.crt:ro", "-v", f"{directory / 'tls.key'}:/run/edge/tls.key:ro",
        "--tmpfs", "/var/cache/nginx:rw,noexec,nosuid,size=64m,mode=0777", image)
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        result = run("docker", "exec", name, "wget", "-q", "-O-", "--header=X-Edge-Status-Token: e2e", "http://127.0.0.1:9080/passive-failures", check=False)
        if result.returncode == 0 and result.stdout.startswith("{"):
            return json.loads(result.stdout)["cell"]["capacity"]["ruleset_versions"]
        time.sleep(1)
    raise AssertionError(run("docker", "logs", name, check=False).stderr[-2000:])


def main() -> None:
    pins = json.loads((ROOT / "core/resources/contracts/runtime-pins.json").read_text())
    run("docker", "rm", "-f", PINNED, CHANGED, check=False)
    run("docker", "network", "rm", NETWORK, check=False)
    run("docker", "network", "create", NETWORK)
    user = run("docker", "image", "inspect", "--format", "{{.Config.User}}", IMAGE).stdout.strip()
    dockerfile = (f"FROM {IMAGE}\nUSER root\nRUN printf '{{\"crs\":\"{FAKE_CRS}\",\"modsecurity\":\"{pins['modsecurity']}\"}}\\n' > /etc/cdnfoundry/ruleset-versions.json\n"
                  f"USER {user or 'root'}\n")
    run("docker", "build", "-q", "-t", DERIVED, "-", input_text=dockerfile)
    try:
        with tempfile.TemporaryDirectory(prefix="cdnf-ruleset-mismatch-") as directory:
            temporary = pathlib.Path(directory)
            temporary.chmod(0o755)
            runtime = temporary / "runtime.json"
            runtime.write_text(json.dumps({"schema_version": 1, "sequence": 1, "generation_id": "e2e-ruleset-1", "certificates": {}, "hosts": {}}))
            run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-subj", "/CN=ruleset.test",
                "-keyout", str(temporary / "tls.key"), "-out", str(temporary / "tls.crt"))
            for item in (runtime, temporary / "tls.key", temporary / "tls.crt"):
                item.chmod(0o644)
            assert reported(PINNED, runtime, temporary, IMAGE) == pins
            different = reported(CHANGED, runtime, temporary, DERIVED)
            assert different == {"crs": FAKE_CRS, "modsecurity": pins["modsecurity"]}, different
            assert different != pins
    finally:
        run("docker", "rm", "-f", PINNED, CHANGED, check=False)
        run("docker", "network", "rm", NETWORK, check=False)
        run("docker", "rmi", DERIVED, check=False)
    print("e2e ruleset version mismatch qualification passed")


if __name__ == "__main__":
    main()
