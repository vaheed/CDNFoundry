#!/usr/bin/env python3
"""Start Fleet web on Windows, macOS, or Linux with one command.

    python3 scripts/fleet-web.py          (macOS, Linux)
    py scripts\fleet-web.py               (Windows)

It needs only Python 3.10 or newer. On first run it creates a private virtual environment under your user
directory and installs the two libraries Fleet needs (PyYAML and cryptography). If you have no Fleet vault yet it
runs the interactive setup, then it starts Fleet web on http://127.0.0.1:8765 and opens your browser.
Any other arguments are passed to `cdnfoundry-fleet web` (for example --port 9000 or --vault PATH).
"""
from __future__ import annotations

import os
import shutil
import subprocess
import sys
import threading
import venv
import webbrowser
from pathlib import Path

MINIMUM = (3, 10)
REQUIREMENTS = ["PyYAML==6.0.1", "cryptography>=41,<46"]
SCRIPTS = Path(__file__).resolve().parent


def config_dir() -> Path:
    if os.name == "nt":
        return Path(os.environ.get("APPDATA", Path.home() / "AppData" / "Roaming")) / "cdnfoundry-fleet"
    return Path.home() / ".config" / "cdnfoundry-fleet"


def fail(message: str) -> int:
    print(f"error: {message}", file=sys.stderr)
    return 1


def venv_python(root: Path) -> Path:
    return root / ("Scripts/python.exe" if os.name == "nt" else "bin/python")


def ensure_environment(root: Path) -> Path:
    python = venv_python(root)
    marker = root / ".requirements"
    wanted = "\n".join(REQUIREMENTS)
    if python.exists() and marker.exists() and marker.read_text() == wanted:
        return python
    print(f"Preparing the Fleet environment in {root} (first run only)...")
    if not python.exists():
        venv.EnvBuilder(with_pip=True, clear=root.exists()).create(root)
    done = subprocess.run([str(python), "-m", "pip", "install", "--quiet", "--disable-pip-version-check", *REQUIREMENTS])
    if done.returncode != 0:
        raise SystemExit(fail("could not install PyYAML and cryptography; check your internet connection and try again"))
    marker.write_text(wanted)
    return python


def main() -> int:
    if sys.version_info < MINIMUM:
        return fail(f"Python {MINIMUM[0]}.{MINIMUM[1]} or newer is required; this is {sys.version.split()[0]}")
    args = sys.argv[1:]
    if not shutil.which("ssh") or not shutil.which("ssh-keygen"):
        print("warning: OpenSSH (ssh, ssh-keygen) was not found. Fleet web starts, but it cannot reach hosts until you install it.\n"
              "  Windows: Settings > Apps > Optional features > OpenSSH Client.  macOS and Linux include it.", file=sys.stderr)
    python = ensure_environment(config_dir() / "venv")
    env = {**os.environ, "PYTHONPATH": str(SCRIPTS), "PYTHONIOENCODING": "utf-8"}
    base = [str(python), "-m", "cdnfoundry_fleet"]
    vault = Path(args[args.index("--vault") + 1]) if "--vault" in args and args.index("--vault") + 1 < len(args) else config_dir() / "fleet.db"
    if not vault.exists():
        print("No Fleet vault yet. Running first-time setup.\n")
        code = subprocess.run([*base, "setup", *(["--vault", str(vault)] if "--vault" in args else [])], env=env).returncode
        if code != 0:
            return code
    port = int(args[args.index("--port") + 1]) if "--port" in args and args.index("--port") + 1 < len(args) else 8765
    threading.Timer(3.0, lambda: webbrowser.open(f"http://127.0.0.1:{port}/")).start()
    try:
        return subprocess.run([*base, "web", *args], env=env).returncode
    except KeyboardInterrupt:
        return 130


if __name__ == "__main__":
    raise SystemExit(main())
