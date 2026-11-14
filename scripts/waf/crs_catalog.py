#!/usr/bin/env python3
"""Builds the adjustable-rule catalog from a CRS rules directory: python3 crs_catalog.py RULES_DIR OUTPUT.json.

The catalog lists every request rule a domain may disable or set to log only, grouped by category. Blocking evaluation,
initialization, and exception rules are never adjustable. The control plane ships the result as a contract file and a test
checks it against the pinned CRS release, so the catalog never drifts from the rules the cells run.
"""

import json
import pathlib
import re
import sys

CATEGORIES = {
    "911": ("method", "Method enforcement"),
    "913": ("scanner", "Scanner detection"),
    "920": ("protocol", "Protocol enforcement"),
    "921": ("protocol_attack", "Protocol attacks"),
    "922": ("multipart", "Multipart attacks"),
    "930": ("lfi", "Local file inclusion"),
    "931": ("rfi", "Remote file inclusion"),
    "932": ("rce", "Command injection"),
    "933": ("php", "PHP injection"),
    "934": ("generic", "Generic injection"),
    "941": ("xss", "Cross-site scripting"),
    "942": ("sqli", "SQL injection"),
    "943": ("session_fixation", "Session fixation"),
    "944": ("java", "Java injection"),
}


def main(rules_directory: str, output: str) -> None:
    root = pathlib.Path(rules_directory)
    version = None
    rules = []
    for path in sorted(root.glob("REQUEST-9*.conf")):
        text = path.read_text()
        if version is None and (match := re.search(r"ver:'OWASP_CRS/([0-9.]+)'", text)):
            version = match.group(1)
        prefix = path.name.split("-")[1]
        if prefix not in CATEGORIES:
            continue
        # A rule starts at its id; the first message after it belongs to it. Chained rules carry no id of their own.
        for block in re.split(r"(?=\bid:\d+)", text)[1:]:
            identifier = re.match(r"id:(\d+)", block).group(1)
            message = re.search(r"msg:'([^']*)'", block)
            if not message:
                continue  # paranoia gates and bookkeeping rules detect nothing
            paranoia = re.search(r"tag:'paranoia-level/(\d)'", block)
            rules.append({
                "id": int(identifier), "category": CATEGORIES[prefix][0],
                "message": message.group(1).strip(),
                "paranoia_level": int(paranoia.group(1)) if paranoia else 1,
            })
    seen = {}
    for rule in rules:
        seen.setdefault(rule["id"], rule)
    catalog = {
        "ruleset": f"owasp-crs/{version}",
        "categories": {prefix: {"key": key, "label": label} for prefix, (key, label) in CATEGORIES.items()},
        "rules": sorted(seen.values(), key=lambda rule: rule["id"]),
    }
    pathlib.Path(output).write_text(json.dumps(catalog, indent=2) + "\n")
    print(f"{len(catalog['rules'])} rules, CRS {version}")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
