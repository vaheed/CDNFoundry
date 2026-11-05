#!/usr/bin/env python3
"""Write every dashboard definition to dashboards/<folder>/<uid>.json, and fail with --check when a file is stale."""
from __future__ import annotations

import json
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

from dashboard_lib import LIBRARY, shared  # noqa: E402
from dashboards_noc import DASHBOARDS as NOC  # noqa: E402

try:
    from dashboards_security import DASHBOARDS as SECURITY  # noqa: E402
except ImportError:  # the definitions land in a later change
    SECURITY = []
try:
    from dashboards_platform import DASHBOARDS as PLATFORM  # noqa: E402
except ImportError:
    PLATFORM = []

ROOT = pathlib.Path(__file__).resolve().parent / "dashboards"
LIBRARY_ROOT = pathlib.Path(__file__).resolve().parent / "library-panels"


def signature(panel: dict) -> str:
    """What makes two panels the same panel: type, title, queries, options, and field config (links aside)."""
    config = json.loads(json.dumps(panel.get("fieldConfig", {})))
    config.get("defaults", {}).pop("links", None)
    return json.dumps([panel["type"], panel["title"], [t.get("rawSql") or t.get("expr") for t in panel.get("targets", [])], panel.get("options"), config], sort_keys=True)


def share_repeated_panels(documents: list[tuple[str, dict]]) -> None:
    """A panel that appears on more than one dashboard becomes a library panel, so one fix changes them all."""
    groups: dict[str, list[dict]] = {}
    for _, document in documents:
        for panel in document["panels"]:
            if panel["type"] in {"row", "text"} or "libraryPanel" in panel or not panel.get("targets"):
                continue
            groups.setdefault(signature(panel), []).append(panel)
    for members in groups.values():
        if len(members) < 2:
            continue
        first = members[0]
        slug = "".join(char if char.isalnum() else "-" for char in first["title"].lower()).strip("-")
        uid = f"cdnf-lib-{slug}"
        counter = 1
        while uid in LIBRARY:
            counter += 1
            uid = f"cdnf-lib-{slug}-{counter}"
        reference = shared(uid, first)
        for panel in members:
            grid = panel["gridPos"]
            ident = panel["id"]
            panel.clear()
            panel.update({**reference, "gridPos": grid, "id": ident})


def build() -> dict[pathlib.Path, str]:
    files: dict[pathlib.Path, str] = {}
    documents = [(folder, factory()) for folder, factory in NOC + SECURITY + PLATFORM]
    share_repeated_panels(documents)
    for folder, document in documents:
        files[ROOT / folder / f"{document['uid']}.json"] = json.dumps(document, indent=2, sort_keys=True) + "\n"
    for uid, model in LIBRARY.items():
        files[LIBRARY_ROOT / f"{uid}.json"] = json.dumps({"uid": uid, "name": model["title"], "model": model}, indent=2, sort_keys=True) + "\n"
    return files


if __name__ == "__main__":
    files = build()
    existing = {path for path in ROOT.rglob("*.json")} | {path for path in LIBRARY_ROOT.rglob("*.json")}
    if "--check" in sys.argv:
        stale = [str(path) for path, text in files.items() if not path.exists() or path.read_text() != text] + [str(path) for path in existing - set(files)]
        if stale:
            sys.exit("Dashboards are out of date; run docker/grafana/build_dashboards.py:\n" + "\n".join(stale))
    else:
        for path in existing - set(files):
            path.unlink()
        for path, text in files.items():
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text)
        print(f"wrote {len(files)} dashboards")
