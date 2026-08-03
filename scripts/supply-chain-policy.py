#!/usr/bin/env python3
"""Fail closed on CDNFoundry's locally verifiable supply-chain contract."""

from __future__ import annotations

import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
DOCKERFILES = [
    ROOT / "core/Dockerfile", ROOT / "edge-agent/Dockerfile", ROOT / "edge-gateway/Dockerfile",
    ROOT / "docker/nginx/Dockerfile.production", ROOT / "docker/openresty/Dockerfile",
    ROOT / "docker/mmdb-updater/Dockerfile", ROOT / "docker/grafana/Dockerfile", ROOT / "docker/loki/Dockerfile",
]
REQUIRED_LABELS = ["image.source", "image.revision", "image.version", "image.created", "image.description", "image.licenses"]


def fail(message: str) -> None:
    print(f"supply-chain-policy: {message}", file=sys.stderr)
    raise SystemExit(1)


for path in DOCKERFILES:
    text = path.read_text()
    stage_aliases = set(re.findall(r"^FROM\s+\S+\s+AS\s+(\S+)", text, re.MULTILINE | re.IGNORECASE))
    for line in text.splitlines():
        if not line.startswith("FROM ") and "--from=" not in line:
            continue
        references = re.findall(r"(?:FROM|--from=)([^\s]+)", line)
        for reference in references:
            if reference == "scratch" or reference in stage_aliases:
                continue
            if reference.startswith("${"):
                if path.name != "Dockerfile" or path.parent.name != "grafana":
                    continue  # internal commit-built CORE_IMAGE is verified after push
            elif not re.search(r"@sha256:[0-9a-f]{64}$", reference):
                fail(f"{path.relative_to(ROOT)} has unpinned image reference {reference}")
    for label in REQUIRED_LABELS:
        if f"org.opencontainers.{label}" not in text:
            fail(f"{path.relative_to(ROOT)} lacks OCI label org.opencontainers.{label}")
    for clone in re.finditer(r"git clone[^\n]+\s+(\S+)\s+(/\S+)", text):
        destination = clone.group(2).rstrip(" \\")
        following = text[clone.end():clone.end() + 300]
        if not re.search(rf"git -C {re.escape(destination)} checkout \"?\$\{{?[A-Z0-9_]+\}}?\"?", following):
            fail(f"{path.relative_to(ROOT)} has a Git dependency without an explicit checkout")
    if "openresty.org/download/" in text and "sha256sum -c" not in text:
        fail("OpenResty source archive lacks checksum verification")

for workflow in (ROOT / ".github/workflows").glob("*.yml"):
    text = workflow.read_text()
    for action in re.findall(r"uses:\s*([^\s#]+)", text):
        if not re.search(r"@[0-9a-f]{40}$", action):
            fail(f"{workflow.relative_to(ROOT)} uses mutable action {action}")

for manifest, lockfile in [("core/composer.json", "core/composer.lock"), ("core/package.json", "core/package-lock.json"), ("docs/package.json", "docs/package-lock.json")]:
    if not (ROOT / manifest).is_file() or not (ROOT / lockfile).is_file():
        fail(f"{manifest} lacks required lockfile {lockfile}")
for module in [ROOT / "edge-agent/go.mod", ROOT / "edge-gateway/go.mod"]:
    if "require " in module.read_text() and not module.with_name("go.sum").is_file():
        fail(f"{module.relative_to(ROOT)} has dependencies but no go.sum")

release = (ROOT / ".github/workflows/ci.yml").read_text()
for evidence in ["syft", "trivy", "sign --yes", "attest --yes", "release-manifest.json", "@sha256:"]:
    if evidence not in release:
        fail(f"release workflow lacks {evidence}")
if re.search(r"\\[ \t]+(?:>>|>)", release):
    fail("release workflow has an escaped whitespace sequence before an output redirection")
if "Normalize release evidence permissions" not in release:
    fail("release workflow does not make container-generated evidence readable before upload")
if '--user "$(id -u):$(id -g)"' not in release:
    fail("release manifest signer does not write evidence as the runner user")

example = json.loads((ROOT / "supply-chain/release-manifest.example.json").read_text())
for image in example.get("images", []):
    if not re.search(r"@sha256:[0-9a-f]{64}$", image.get("image", "")):
        fail("release-manifest example contains a mutable image reference")

print(f"supply_chain_policy=passed dockerfiles={len(DOCKERFILES)} workflows=2")
