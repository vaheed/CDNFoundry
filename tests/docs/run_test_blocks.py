#!/usr/bin/env python3
"""Run every shell block in the documentation that is marked `test`.

A block opens with ```sh test or ```bash test. It runs from the repository root with
`bash -euo pipefail`, and the page fails the check when the block exits non-zero.
Blocks run in a throwaway copy of the environment and must not need a running stack,
secrets, or network access beyond the container engine.
"""
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
DOCS = ROOT / "docs"
FENCE = re.compile(r"^```(?:sh|bash|shell)\s+test\s*$\n(.*?)^```\s*$", re.MULTILINE | re.DOTALL)
TIMEOUT_SECONDS = 180


def pages() -> list[pathlib.Path]:
    return sorted(
        path
        for path in DOCS.rglob("*.md")
        if not {"node_modules", ".vitepress"}.intersection(path.relative_to(DOCS).parts)
    )


def main() -> int:
    failures = 0
    count = 0
    for page in pages():
        for number, block in enumerate(FENCE.findall(page.read_text(encoding="utf-8")), start=1):
            count += 1
            label = f"{page.relative_to(ROOT)} block {number}"
            try:
                result = subprocess.run(
                    ["bash", "-euo", "pipefail", "-c", block],
                    cwd=ROOT, capture_output=True, text=True, timeout=TIMEOUT_SECONDS, check=False,
                )
            except subprocess.TimeoutExpired:
                print(f"FAIL {label}: timed out after {TIMEOUT_SECONDS}s")
                failures += 1
                continue
            if result.returncode != 0:
                print(f"FAIL {label}: exit {result.returncode}\n{result.stderr.strip()[-800:]}")
                failures += 1
            else:
                print(f"ok   {label}")
    if count == 0:
        print("no executable documentation blocks found")
        return 1
    print(f"doc_test_blocks={'failed' if failures else 'passed'} blocks={count} failed={failures}")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
