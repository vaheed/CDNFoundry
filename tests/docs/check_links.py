#!/usr/bin/env python3
"""Fail when a repository Markdown document links to a missing local file."""

from __future__ import annotations

import pathlib
import re
import sys
import urllib.parse


ROOT = pathlib.Path(__file__).resolve().parents[2]
DOCUMENTS = [
    ROOT / "README.md",
    ROOT / "CONTRIBUTING.md",
    ROOT / "SECURITY.md",
    ROOT / "CODE_OF_CONDUCT.md",
    *sorted((ROOT / "docs").rglob("*.md")),
]
LINK = re.compile(r"(?<!!)\[[^\]]*\]\((?:<([^>]+)>|([^\s)]+))(?:\s+['\"][^'\"]*['\"])?\)")


def main() -> int:
    failures: list[str] = []
    for document in DOCUMENTS:
        text = document.read_text(encoding="utf-8")
        for match in LINK.finditer(text):
            target = urllib.parse.unquote(match.group(1) or match.group(2))
            path = target.split("#", 1)[0].split("?", 1)[0]
            if not path or path.startswith(("http://", "https://", "mailto:")):
                continue
            resolved = (ROOT / path.lstrip("/")) if path.startswith("/") else (document.parent / path)
            if not resolved.exists():
                line = text.count("\n", 0, match.start()) + 1
                failures.append(f"{document.relative_to(ROOT)}:{line}: missing local target {target}")
    if failures:
        print("\n".join(failures), file=sys.stderr)
        return 1
    print(f"documentation_links=passed documents={len(DOCUMENTS)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
