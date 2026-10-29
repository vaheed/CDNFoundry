#!/usr/bin/env python3
"""Fail when any release-scoped version disagrees with the root VERSION file."""
import json
import os
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]


def regex(path: str, pattern: str):
    match = re.search(pattern, (ROOT / path).read_text(), re.MULTILINE)
    return match.group(1) if match else None


def main() -> int:
    expected = (ROOT / "VERSION").read_text().strip()
    if not re.fullmatch(r"\d+\.\d+\.\d+", expected):
        print(f"VERSION is not MAJOR.MINOR.PATCH: {expected!r}")
        return 1
    found = {
        "scripts/cdnfoundry_fleet/__init__.py": regex("scripts/cdnfoundry_fleet/__init__.py", r'^__version__ = "([^"]+)"'),
        "docs/public/openapi.json": json.loads((ROOT / "docs/public/openapi.json").read_text())["info"]["version"],
        "supply-chain/release-manifest.example.json": json.loads((ROOT / "supply-chain/release-manifest.example.json").read_text())["release_version"],
        "core/config/release.php": regex("core/config/release.php", r"'version' => '([^']+)'"),
        "edge-agent/main.go": regex("edge-agent/main.go", r'^var version = "([^"]+)"'),
        "edge-gateway/main.go": regex("edge-gateway/main.go", r'^var version = "([^"]+)"'),
    }
    bake = (ROOT / "docker-bake.hcl").read_text()
    if re.search(r'RELEASE_VERSION"\s*{\s*default\s*=\s*"([^"]+)"', bake) is None:
        found["docker-bake.hcl"] = None
    failed = False
    for path, value in found.items():
        status = "ok" if value == expected else "MISMATCH"
        failed |= value != expected
        print(f"{status:9} {path}: {value}")
    ref = os.environ.get("GITHUB_REF_NAME", "")
    if re.fullmatch(r"v\d+\.\d+\.\d+", ref) and ref != f"v{expected}":
        print(f"MISMATCH  git tag {ref} != v{expected}")
        failed = True
    print(f"expected {expected} (docker-bake.hcl RELEASE_VERSION receives the git ref from CI; Go binaries are built with -X main.version from it)")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
