"""The Alertmanager start script: every receiver posts to the control plane's private alert receiver."""
from __future__ import annotations

import os
import shutil
import subprocess
from pathlib import Path

import pytest
import yaml

ROOT = Path(__file__).resolve().parents[2]
pytestmark = pytest.mark.skipif(shutil.which("sh") is None, reason="a POSIX shell is required")


def run_start(tmp_path: Path, environment: dict[str, str]) -> tuple[subprocess.CompletedProcess[str], dict | None]:
    """Run the real start.sh with its container paths pointed at a scratch directory and a fake alertmanager that prints its config."""
    script = (ROOT / "docker/alertmanager/start.sh").read_text(encoding="utf-8")
    config_dir, fake = tmp_path / "etc", tmp_path / "alertmanager"
    config_dir.mkdir()
    shutil.copy(ROOT / "docker/alertmanager/alertmanager.yml", config_dir / "alertmanager.yml")
    fake.write_text('#!/bin/sh\nfor a in "$@"; do case "$a" in --config.file=*) cat "${a#--config.file=}";; esac; done\n')
    fake.chmod(0o755)
    patched = script.replace("/etc/alertmanager/alertmanager.yml", str(config_dir / "alertmanager.yml")).replace("/tmp/alert-receiver-url", str(tmp_path / "receiver-url")).replace("/bin/alertmanager", str(fake))
    (tmp_path / "start.sh").write_text(patched)
    done = subprocess.run(["sh", str(tmp_path / "start.sh")], env={"PATH": os.environ["PATH"], **environment}, capture_output=True, text=True, check=False)
    return done, (yaml.safe_load(done.stdout) if done.returncode == 0 and done.stdout.strip() else None)


def test_by_default_every_receiver_posts_to_the_control_receiver_with_the_monitoring_token(tmp_path: Path) -> None:
    done, config = run_start(tmp_path, {})
    assert done.returncode == 0, done.stderr
    assert {r["name"] for r in config["receivers"]} == {"noc", "soc", "platform"}
    for receiver in config["receivers"]:
        hook = receiver["webhook_configs"][0]
        assert hook["url_file"].endswith("receiver-url") and hook["send_resolved"] is True
        assert hook["http_config"]["authorization"]["credentials_file"] == "/run/secrets/metrics-token"
    assert (tmp_path / "receiver-url").read_text() == "http://web:8080/internal/alertmanager"
    assert config["route"]["receiver"] == "platform" and config["inhibit_rules"], "routing and inhibition stay as they are"


def test_a_dedicated_monitoring_host_can_point_at_another_receiver(tmp_path: Path) -> None:
    done, _ = run_start(tmp_path, {"ALERT_RECEIVER_URL": "https://control.example.com/internal/alertmanager"})
    assert done.returncode == 0
    assert (tmp_path / "receiver-url").read_text() == "https://control.example.com/internal/alertmanager"


def test_a_receiver_that_is_not_http_is_refused(tmp_path: Path) -> None:
    done, _ = run_start(tmp_path, {"ALERT_RECEIVER_URL": "ftp://x"})
    assert done.returncode != 0 and "http:// or https://" in done.stderr
