#!/usr/bin/env python3
"""Check documentation screenshot references, theme pairs, and unused captures."""

from __future__ import annotations

from pathlib import Path
import re
import struct
import sys

ROOT = Path(__file__).resolve().parents[2]


def validate(docs: Path) -> tuple[list[str], int]:
    failures: list[str] = []
    names: set[str] = set()
    for page in sorted(docs.rglob("*.md")):
        if {"node_modules", ".vitepress"}.intersection(page.relative_to(docs).parts):
            continue
        # Examples inside fenced code are instructional syntax, not rendered images.
        text = re.sub(r"(?ms)^(```|~~~).*?^\1\s*$", "", page.read_text())
        for tag in re.findall(r"<Screenshot\b[^>]*>", text):
            attributes = dict(re.findall(r'(\w+)="([^"]*)"', tag))
            name = attributes.get("name", "")
            if not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", name):
                failures.append(f"{page.relative_to(docs)}: invalid screenshot name")
                continue
            names.add(name)
            if not attributes.get("alt", "").strip():
                failures.append(f"{page.relative_to(docs)}: {name} needs alternative text")

    images = docs / "public" / "screenshots"
    for name in sorted(names):
        dimensions = []
        for theme in ("light", "dark"):
            path = images / theme / f"{name}.png"
            if not path.is_file():
                failures.append(f"missing screenshot: {theme}/{name}.png")
                continue
            with path.open("rb") as image:
                header = image.read(24)
            if len(header) != 24 or header[:8] != b"\x89PNG\r\n\x1a\n" or header[12:16] != b"IHDR":
                failures.append(f"invalid PNG header: {theme}/{name}.png")
                continue
            dimensions.append(struct.unpack(">II", header[16:24]))
        if len(dimensions) == 2 and dimensions[0] != dimensions[1]:
            failures.append(f"theme dimensions differ: {name}")

    for theme in ("light", "dark"):
        for path in sorted((images / theme).glob("*.png")):
            if path.stem not in names:
                failures.append(f"unused screenshot: {theme}/{path.name}")
    return failures, len(names)


if __name__ == "__main__":
    errors, count = validate(ROOT / "docs")
    if errors:
        print("\n".join(errors), file=sys.stderr)
        raise SystemExit(1)
    print(f"screenshot_validation=passed names={count} themes=2")
