#!/usr/bin/env python3
"""Fail when a development service runs a different image than the production file ships.

End-to-end tests qualify the development stack, so an image that differs from production (an older
ClickHouse, another PowerDNS) would qualify versions that are never deployed. Services with no
production counterpart (origins, Pebble, PowerAdmin) are exempt. The nginx that fronts the control
plane is built from docker/nginx/Dockerfile.production, so that file is the production reference.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
EXEMPT = {"origin-http", "origin-https", "alert-sink", "pebble", "poweradmin", "dev-pki", "vendor-init", "seed-exporter"}


def repository(reference: str) -> str:
    return reference.split("@")[0].rsplit(":", 1)[0]


def main() -> int:
    dev = yaml.safe_load((ROOT / "compose.dev.yml").read_text())["services"]
    prod = yaml.safe_load((ROOT / "compose.prod.yml").read_text())["services"]
    production = {}
    for service in prod.values():
        image = service.get("image", "")
        if image and "${" not in image:
            production.setdefault(repository(image), set()).add(image)
    nginx = re.search(r"^FROM (nginx:\S+)", (ROOT / "docker/nginx/Dockerfile.production").read_text(), re.M)
    if nginx:
        production.setdefault("nginx", set()).add(nginx.group(1))

    problems = []
    for name, service in sorted(dev.items()):
        image = service.get("image")
        if not image or name in EXEMPT or image.startswith("${"):
            continue
        expected = production.get(repository(image))
        if expected is None:
            continue
        if "@sha256:" not in image:
            problems.append(f"{name}: {image} is not pinned by digest")
        elif image not in expected:
            problems.append(f"{name}: {image} differs from production {sorted(expected)}")
    for problem in problems:
        print(problem, file=sys.stderr)
    print(f"dev_image_parity={'failed' if problems else 'passed'}")
    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
