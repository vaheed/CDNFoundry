"""Security status: known vulnerabilities in the images a release runs.

Input is the `vulnerabilities` list of the signed manifest (written by CI from the
Trivy scan) and the repository's owner-approved classifications. Each finding gets
exactly one of four states.
"""
from __future__ import annotations

import datetime as dt
from pathlib import Path
from typing import Any

import yaml


def _classifications(repo_root: Path) -> dict[str, dt.date]:
    path = repo_root / "supply-chain" / "trivy-classifications.yaml"
    if not path.is_file():
        return {}
    data = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
    out: dict[str, dt.date] = {}
    for item in data.get("vulnerabilities", []):
        expiry = item.get("expired_at")
        if isinstance(expiry, str):
            expiry = dt.date.fromisoformat(expiry)
        if expiry:
            out[str(item["id"])] = expiry
    return out


def classify(current: dict[str, Any] | None, repo_root: Path, available: dict[str, Any] | None = None, *, today: dt.date | None = None) -> list[dict[str, Any]]:
    today = today or dt.date.today()
    classified = _classifications(repo_root)
    fixed_in_available: set[str] = set()
    if available and current:
        still = {(v["id"], v["image"]) for v in available.get("vulnerabilities", [])}
        fixed_in_available = {v["id"] for v in current.get("vulnerabilities", []) if (v["id"], v["image"]) not in still}
    rows: list[dict[str, Any]] = []
    for v in (current or {}).get("vulnerabilities", []):
        vid = v["id"]
        if vid in fixed_in_available:
            state, detail = "fixed", f"fixed in release {available['release']}"  # type: ignore[index]
        elif vid in classified and classified[vid] >= today:
            state, detail = "classified", f"classified until {classified[vid].isoformat()}"
        elif vid in classified:
            state, detail = "expired", f"classification expired {classified[vid].isoformat()}"
        elif not v.get("fixed_version"):
            state, detail = "no-fix", "no fix upstream yet"
        else:
            state, detail = "fix-upstream", f"fixed upstream in {v['fixed_version']}; no release carries it yet"
        rows.append({"id": vid, "image": v["image"], "severity": v.get("severity", "unknown"), "state": state, "detail": detail})
    return sorted(rows, key=lambda r: (r["state"] == "classified", r["severity"] != "CRITICAL", r["id"]))
