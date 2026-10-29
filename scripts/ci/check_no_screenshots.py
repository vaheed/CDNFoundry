#!/usr/bin/env python3
"""Fail when raster images are tracked outside the allowlist."""
import pathlib
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
RASTER = (".png", ".jpg", ".jpeg", ".gif", ".webp")
ALLOWLIST: set[str] = set()


def main() -> int:
    tracked = subprocess.check_output(["git", "ls-files"], cwd=ROOT, text=True).splitlines()
    offenders = [p for p in tracked if p.lower().endswith(RASTER) and p not in ALLOWLIST]
    for path in offenders:
        print(f"tracked raster image: {path}")
    return 1 if offenders else 0


if __name__ == "__main__":
    sys.exit(main())
