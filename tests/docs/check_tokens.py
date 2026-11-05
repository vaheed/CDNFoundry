#!/usr/bin/env python3
"""Keep the documentation tokens identical to the product design tokens.

Run with --write to refresh docs/.vitepress/theme/tokens.css from the product theme.
"""
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
SOURCE = ROOT / "core/resources/css/filament/shared/theme.css"
TARGET = ROOT / "docs/.vitepress/theme/tokens.css"
HEADER = (
    "/* Copied from core/resources/css/filament/shared/theme.css by tests/docs/check_tokens.py --write. "
    "Do not edit by hand. */\n"
)


def expected() -> str:
    source = SOURCE.read_text(encoding="utf-8")
    start = source.index(":root {")
    end = source.index("/* ----", start)
    return HEADER + source[start:end].rstrip() + "\n"


def main() -> int:
    if "--write" in sys.argv:
        TARGET.write_text(expected(), encoding="utf-8")
        return 0
    if TARGET.read_text(encoding="utf-8") != expected():
        print("docs tokens differ from the product theme; run tests/docs/check_tokens.py --write")
        return 1
    print("docs_tokens=passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
