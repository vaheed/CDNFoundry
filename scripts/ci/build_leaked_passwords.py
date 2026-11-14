#!/usr/bin/env python3
"""Builds the leaked-password set the cells load: the first 8 bytes of the SHA-256 of each password, as sorted lowercase hex, one per line.

The source is a plain list of known leaked passwords kept in the repository; only hashes are shipped to cells. The output is fixed in size by
the line limit below, which the cell's shared memory is sized for.
"""
import hashlib
import sys

MAXIMUM_ENTRIES = 200_000


def main(source: str, target: str) -> int:
    with open(source, encoding="utf-8") as handle:
        entries = {line.rstrip("\n") for line in handle if line.rstrip("\n")}
    if len(entries) > MAXIMUM_ENTRIES:
        print(f"{len(entries)} entries exceed the limit of {MAXIMUM_ENTRIES}", file=sys.stderr)
        return 1
    hashes = sorted({hashlib.sha256(entry.encode()).hexdigest()[:16] for entry in entries})
    with open(target, "w", encoding="ascii") as handle:
        handle.write("\n".join(hashes) + "\n")
    return 0


if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("usage: build_leaked_passwords.py SOURCE TARGET", file=sys.stderr)
        sys.exit(2)
    sys.exit(main(sys.argv[1], sys.argv[2]))
