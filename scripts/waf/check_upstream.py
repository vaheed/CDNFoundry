#!/usr/bin/env python3
"""Compares the WAF components pinned in the OpenResty image with the newest upstream releases.

Usage: python3 scripts/waf/check_upstream.py [--strict]

Reads the pinned commits from docker/openresty/Dockerfile, asks each upstream repository for its release tags with
`git ls-remote` (no clone, nothing downloaded into the image), and prints the pinned and newest version of each. With
--strict it exits 3 when a newer release exists, for a scheduled CI job. It never runs on a cell: a newer ruleset reaches
cells only through a rebuilt, released image.
"""

import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
DOCKERFILE = ROOT / "docker/openresty/Dockerfile"

# Build argument, upstream repository, and the release tag pattern followed (one major line per component).
COMPONENTS = [
    ("OWASP_CRS_COMMIT", "OWASP CRS", "https://github.com/coreruleset/coreruleset.git", r"^v(4)\.(\d+)\.(\d+)$"),
    ("MODSECURITY_COMMIT", "ModSecurity", "https://github.com/owasp-modsecurity/ModSecurity.git", r"^v(3)\.(\d+)\.(\d+)$"),
    ("MODSECURITY_NGINX_COMMIT", "ModSecurity-nginx", "https://github.com/owasp-modsecurity/ModSecurity-nginx.git", r"^v(1)\.(\d+)\.(\d+)$"),
]


def pinned(argument: str) -> str:
    match = re.search(rf"^ARG {argument}=([0-9a-f]{{40}})$", DOCKERFILE.read_text(), re.MULTILINE)
    if match is None:
        raise SystemExit(f"{argument} is not pinned to a full commit in {DOCKERFILE.relative_to(ROOT)}")
    return match.group(1)


def tags(repository: str, pattern: str) -> dict[str, tuple[int, ...]]:
    """Commit of each release tag (annotated tags resolved to their commit) and its version numbers."""
    output = subprocess.run(["git", "ls-remote", "--tags", repository], check=True, capture_output=True, text=True, timeout=60).stdout
    commits: dict[str, str] = {}
    for line in output.splitlines():
        commit, reference = line.split("\t")
        name = reference.removeprefix("refs/tags/")
        peeled = name.endswith("^{}")
        name = name.removesuffix("^{}")
        if re.match(pattern, name) and (peeled or name not in commits):
            commits[name] = commit
    return {commit: tuple(int(part) for part in re.match(pattern, name).groups()) for name, commit in commits.items()}


def version(numbers: tuple[int, ...] | None) -> str:
    return "unreleased commit" if numbers is None else "v" + ".".join(map(str, numbers))


def main() -> int:
    strict = "--strict" in sys.argv[1:]
    outdated = []
    for argument, label, repository, pattern in COMPONENTS:
        commit = pinned(argument)
        releases = tags(repository, pattern)
        current = releases.get(commit)
        newest = max(releases.values())
        state = "current" if current == newest else "update available"
        if current != newest:
            outdated.append(label)
        print(f"{label}: pinned {version(current)} ({commit[:12]}), newest {version(newest)}: {state}")
    if outdated:
        print("To update: change the commit in docker/openresty/Dockerfile, rebuild the image, regenerate "
              "core/resources/contracts/crs-rules.json with scripts/waf/crs_catalog.py, set WAF_RULESET_VERSION, "
              "and run tests/e2e/managed_waf.py and tests/e2e/custom_rules.py.")
    return 3 if strict and outdated else 0


if __name__ == "__main__":
    sys.exit(main())
