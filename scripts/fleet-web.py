#!/usr/bin/env python3
"""Start Fleet web on Windows, macOS, or Linux with one command.

    python3 scripts/fleet-web.py          (macOS, Linux)
    py scripts\fleet-web.py               (Windows)

It needs only Python 3.10 or newer. On first run it creates a private virtual environment under your user
directory and installs the two libraries Fleet needs (PyYAML and cryptography). Then it starts Fleet web on http://127.0.0.1:8765 and opens your browser.
Creating the vault, entering your domains and hosts, and unlocking all happen in the browser.
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
TESTED_UP_TO = (3, 13)
REQUIREMENTS = ["PyYAML>=6.0.1", "cryptography>=41,<46"]
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
    pip = [str(python), "-m", "pip", "install", "--disable-pip-version-check"]
    # cryptography ships prebuilt wheels and must never be compiled. PyYAML is pure Python when no wheel exists,
    # so it may be built from source, which keeps very new Python versions working.
    done = subprocess.run([*pip, "--only-binary=cryptography", *REQUIREMENTS])
    if done.returncode != 0:
        raise SystemExit(fail("could not install PyYAML and cryptography. Check your internet connection, or run this with Python 3.12 or 3.13 "
                              "(for example: python3.12 scripts/fleet-web.py). If you ran it before, delete the folder "
                              f"{root} and try again"))
    marker.write_text(wanted)
    return python


def supported_interpreter() -> str | None:
    """Return a Python 3.13 down to 3.10 on PATH, for when the running Python is too new for prebuilt wheels."""
    for minor in range(TESTED_UP_TO[1], MINIMUM[1] - 1, -1):
        found = shutil.which(f"python3.{minor}") 
        if found:
            return found
    return None


def main() -> int:
    if sys.version_info[:2] > TESTED_UP_TO and not os.environ.get("CDNFOUNDRY_FLEET_REEXEC"):
        other = supported_interpreter()
        if other:
            print(f"Python {sys.version.split()[0]} is newer than Fleet supports; restarting with {other}.", file=sys.stderr)
            env = {**os.environ, "CDNFOUNDRY_FLEET_REEXEC": "1"}
            return subprocess.run([other, str(Path(__file__).resolve()), *sys.argv[1:]], env=env).returncode
    if sys.version_info < MINIMUM:
        return fail(f"Python {MINIMUM[0]}.{MINIMUM[1]} or newer is required; this is {sys.version.split()[0]}")
    if sys.version_info[:2] > TESTED_UP_TO:
        print(f"warning: Python {sys.version.split()[0]} is newer than the versions Fleet is tested with (up to "
              f"{TESTED_UP_TO[0]}.{TESTED_UP_TO[1]}); prebuilt packages may be missing, and no Python 3.10-3.13 was found on PATH to switch to. If setup fails, install Python 3.12 or 3.13 (macOS: brew install python@3.13).", file=sys.stderr)
    args = sys.argv[1:]
    if not shutil.which("ssh") or not shutil.which("ssh-keygen"):
        print("warning: OpenSSH (ssh, ssh-keygen) was not found. Fleet web starts, but it cannot reach hosts until you install it.\n"
              "  Windows: Settings > Apps > Optional features > OpenSSH Client.  macOS and Linux include it.", file=sys.stderr)
    python = ensure_environment(config_dir() / "venv")
    env = {**os.environ, "PYTHONPATH": str(SCRIPTS), "PYTHONIOENCODING": "utf-8"}
    base = [str(python), "-m", "cdnfoundry_fleet"]
    port = int(args[args.index("--port") + 1]) if "--port" in args and args.index("--port") + 1 < len(args) else 8765
    threading.Timer(3.0, lambda: webbrowser.open(f"http://127.0.0.1:{port}/")).start()
    try:
        return subprocess.run([*base, "web", *args], env=env).returncode
    except KeyboardInterrupt:
        return 130


if __name__ == "__main__":
    raise SystemExit(main())
