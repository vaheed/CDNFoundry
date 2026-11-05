from __future__ import annotations

import datetime as dt
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet.security import classify  # noqa: E402

TODAY = dt.date(2026, 11, 3)


def repo(tmp_path: Path, expiry: str = "2026-11-28") -> Path:
    (tmp_path / "supply-chain").mkdir()
    (tmp_path / "supply-chain" / "trivy-classifications.yaml").write_text(f"vulnerabilities:\n  - id: CVE-C\n    expired_at: {expiry}\n")
    return tmp_path


def vuln(i: str, fixed: str | None = None):
    return {"id": i, "image": "grafana", "severity": "HIGH", "fixed_version": fixed}


def test_each_finding_gets_one_state(tmp_path: Path) -> None:
    cur = {"release": "2.0.0", "vulnerabilities": [vuln("CVE-A", "1.2"), vuln("CVE-B"), vuln("CVE-C"), vuln("CVE-D", "9")]}
    new = {"release": "2.0.1", "vulnerabilities": [vuln("CVE-B"), vuln("CVE-C"), vuln("CVE-D", "9")]}
    rows = {r["id"]: r for r in classify(cur, repo(tmp_path), new, today=TODAY)}
    assert rows["CVE-A"]["state"] == "fixed" and "2.0.1" in rows["CVE-A"]["detail"]
    assert rows["CVE-B"]["state"] == "no-fix"
    assert rows["CVE-C"]["state"] == "classified" and "2026-11-28" in rows["CVE-C"]["detail"]
    assert rows["CVE-D"]["state"] == "fix-upstream"


def test_expired_classification_is_flagged(tmp_path: Path) -> None:
    rows = classify({"vulnerabilities": [vuln("CVE-C")]}, repo(tmp_path, "2026-10-01"), today=TODAY)
    assert rows[0]["state"] == "expired"


def test_no_vulnerabilities_means_an_empty_list(tmp_path: Path) -> None:
    assert classify({"vulnerabilities": []}, tmp_path) == []
    assert classify(None, tmp_path) == []
