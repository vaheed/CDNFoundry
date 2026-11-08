"""The Alertmanager start script: a notifier is optional, and without one the receivers send nothing."""
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
    patched = (script.replace("/etc/alertmanager/alertmanager.yml", str(config_dir / "alertmanager.yml")).replace("/tmp/alertmanager-no-notifier.yml", str(tmp_path / "no-notifier.yml"))
               .replace("/tmp/alert-webhook-url", str(tmp_path / "webhook-url")).replace("/bin/alertmanager", str(fake)))
    (tmp_path / "start.sh").write_text(patched)
    done = subprocess.run(["sh", str(tmp_path / "start.sh")], env={"PATH": os.environ["PATH"], **environment}, capture_output=True, text=True, check=False)
    return done, (yaml.safe_load(done.stdout) if done.returncode == 0 and done.stdout.strip() else None)


def test_without_a_webhook_alertmanager_starts_with_receivers_that_send_nothing(tmp_path: Path) -> None:
    done, config = run_start(tmp_path, {})
    assert done.returncode == 0 and "no notifier configured" in done.stderr
    assert {r["name"] for r in config["receivers"]} == {"noc", "soc", "platform"}
    assert all("webhook_configs" not in r for r in config["receivers"])
    assert config["route"]["receiver"] == "platform" and config["inhibit_rules"], "routing and inhibition stay as they are"
    assert not (tmp_path / "webhook-url").exists()


def test_with_a_webhook_every_receiver_posts_to_it(tmp_path: Path) -> None:
    done, config = run_start(tmp_path, {"ALERT_WEBHOOK_URL": "https://alerts.example.com/hook"})
    assert done.returncode == 0
    assert all(r["webhook_configs"][0]["url_file"].endswith("webhook-url") for r in config["receivers"])
    assert (tmp_path / "webhook-url").read_text() == "https://alerts.example.com/hook"


@pytest.mark.parametrize(("value", "message"), [("ftp://x", "http:// or https://"), ("http://sink/", "must use https://")])
def test_a_bad_webhook_is_still_refused(tmp_path: Path, value: str, message: str) -> None:
    done, _ = run_start(tmp_path, {"ALERT_WEBHOOK_URL": value})
    assert done.returncode != 0 and message in done.stderr
