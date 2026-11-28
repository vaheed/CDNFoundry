"""Narrow activation: in-place file updates and service-level reloads, on a real filesystem and with real containers.

The first half runs the exact shell Fleet sends to a host against a temporary directory. The second half starts a real
gateway container from the production image family and proves, by container ID and start time, that an allowlist edit
reloads the gateway and recreates nothing. It is skipped when Docker or the gateway image is not available.
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import time
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from cdnfoundry_fleet import actions as a  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
GATEWAY_IMAGE = "caddy:2.11-alpine"


def sh(script: str, cwd: Path | None = None) -> subprocess.CompletedProcess:
    return subprocess.run(["sh", "-c", script], cwd=cwd, capture_output=True, text=True, check=False, timeout=120)


def write_tree(root: Path, files: dict[str, str]) -> None:
    for name, text in files.items():
        path = root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)


def tree(root: Path) -> dict[str, str]:
    return {str(p.relative_to(root)): p.read_text() for p in sorted(root.rglob("*")) if p.is_file()}


def test_the_sync_updates_files_in_place_and_removes_files_the_new_bundle_does_not_hold(tmp_path: Path) -> None:
    live, nxt = tmp_path / "live", tmp_path / "live.next"
    write_tree(live, {"docker/a.yml": "old-a", "docker/gone.yml": "old", "generated/list.caddy": "old-list", ".cdnf-good": "", "RELEASE": "1"})
    write_tree(nxt, {"docker/a.yml": "new-a", "docker/added.yml": "added", "generated/list.caddy": "new-list", "RELEASE": "2"})
    inodes = {p: (live / p).stat().st_ino for p in ("docker/a.yml", "generated/list.caddy")}
    directory = live.stat().st_ino
    done = sh(sync_command(live, nxt, backup=True))
    assert done.returncode == 0, done.stderr
    assert tree(live) == {"docker/a.yml": "new-a", "docker/added.yml": "added", "generated/list.caddy": "new-list", "RELEASE": "2"}
    assert {p: (live / p).stat().st_ino for p in inodes} == inodes, "a rewritten file keeps its inode, so a container bind mount still sees it"
    assert live.stat().st_ino == directory, "the directory is never replaced"
    previous = tmp_path / "live.previous"
    assert tree(previous) == {"docker/a.yml": "old-a", "docker/gone.yml": "old", "generated/list.caddy": "old-list", ".cdnf-good": "", "RELEASE": "1"}


def sync_command(live: Path, nxt: Path, *, backup: bool) -> str:
    return a.sync_script(str(nxt), str(live), keep_good_copy=backup)


def test_a_directory_that_never_passed_its_gate_is_not_kept_as_the_rollback_target(tmp_path: Path) -> None:
    live, nxt = tmp_path / "live", tmp_path / "live.next"
    write_tree(live, {"RELEASE": "1"})
    write_tree(nxt, {"RELEASE": "2"})
    assert sh(sync_command(live, nxt, backup=True)).returncode == 0
    assert not (tmp_path / "live.previous").exists()
    assert (live / "RELEASE").read_text() == "2"


def test_a_first_install_creates_the_directory(tmp_path: Path) -> None:
    live, nxt = tmp_path / "live", tmp_path / "live.next"
    write_tree(nxt, {"compose.yml": "services: {}", "generated/x": "1"})
    assert sh(sync_command(live, nxt, backup=True)).returncode == 0
    assert tree(live) == {"compose.yml": "services: {}", "generated/x": "1"}


def test_restoring_the_previous_files_returns_the_directory_to_what_it_was(tmp_path: Path) -> None:
    live, nxt = tmp_path / "live", tmp_path / "live.next"
    original = {"docker/a.yml": "old-a", "docker/gone.yml": "old", ".cdnf-good": "", "RELEASE": "1"}
    write_tree(live, original)
    write_tree(nxt, {"docker/a.yml": "new-a", "RELEASE": "2"})
    assert sh(sync_command(live, nxt, backup=True)).returncode == 0
    assert sh(sync_command(live, tmp_path / "live.previous", backup=False)).returncode == 0
    assert tree(live) == original


def test_running_the_sync_twice_after_the_staged_directory_is_gone_changes_nothing(tmp_path: Path) -> None:
    live, nxt = tmp_path / "live", tmp_path / "live.next"
    write_tree(live, {"a": "1", ".cdnf-good": ""})
    write_tree(nxt, {"a": "2"})
    command = f"set -e; if [ -d {nxt} ]; then {sync_command(live, nxt, backup=True)}; rm -rf {nxt}; fi"
    assert sh(command).returncode == 0 and sh(command).returncode == 0
    assert tree(live) == {"a": "2"} and (tmp_path / "live.previous" / "a").read_text() == "1"


def test_live_commands_order_the_firewall_then_reloads_then_restarts_then_recreations() -> None:
    commands = a.live_commands([("prometheus", "reload"), ("vector", "restart"), ("core", "recreate"), ("host", "firewall"), ("horizon", "recreate")])
    assert commands[0] == "./firewall.sh"
    text = "\n".join(commands)
    assert text.index("promtool check config") < text.index("kill -s HUP prometheus") < text.index("vector validate") < text.index("restart vector") < text.index("up -d --no-deps core horizon")
    assert a.decode_live(a.encode_live([("caddy", "reload"), ("host", "firewall")])) == [("caddy", "reload"), ("host", "firewall")]


def test_a_reload_with_no_defined_command_is_refused_and_not_guessed() -> None:
    with pytest.raises(a.ActionError):
        a.live_commands([("loki", "reload")])


GATEWAY = """{
	admin off
	auto_https off
}
:8080 {
	@outside {
		path /admin
		not {
			import /etc/caddy/allowlists/management-networks.caddy
		}
	}
	handle @outside {
		respond "Forbidden" 403
	}
	respond "ok" 200
}
"""

needs_gateway = pytest.mark.skipif(shutil.which("docker") is None or subprocess.run(["docker", "image", "inspect", GATEWAY_IMAGE], capture_output=True).returncode != 0,
                                   reason="Docker and the gateway image are required")


def docker(*args: str, cwd: Path | None = None) -> subprocess.CompletedProcess:
    return subprocess.run(["docker", *args], cwd=cwd, capture_output=True, text=True, check=False, timeout=180)


def inspect(container: str) -> tuple[str, str]:
    data = json.loads(docker("inspect", container).stdout)[0]
    return data["Id"], data["State"]["StartedAt"]


@needs_gateway
def test_an_allowlist_edit_reloads_the_gateway_without_recreating_it_and_a_bad_file_is_refused(tmp_path: Path) -> None:
    project = tmp_path / "node"
    (project / "deploy/production").mkdir(parents=True)
    (project / "generated/allowlists").mkdir(parents=True)
    (project / "deploy/production/Caddyfile").write_text(GATEWAY)
    (project / "generated/allowlists/management-networks.caddy").write_text("remote_ip 192.0.2.0/24\n")
    (project / ".env.prod").write_text("")
    name = f"cdnf-live-{os.getpid()}"
    port = 28000 + os.getpid() % 1000
    (project / "compose.yml").write_text(
        f"name: {name}\nservices:\n  caddy:\n    image: {GATEWAY_IMAGE}\n    container_name: {name}-caddy\n    ports: ['127.0.0.1:{port}:8080']\n    volumes:\n"
        "      - ./deploy/production/Caddyfile:/etc/caddy/Caddyfile:ro\n      - ./generated/allowlists:/etc/caddy/allowlists:ro\n    profiles: [control]\n")
    try:
        assert docker("compose", "--env-file", ".env.prod", "--profile", "*", "up", "-d", cwd=project).returncode == 0
        container = f"{name}-caddy"
        before = inspect(container)

        def status() -> int:
            for _ in range(20):
                out = subprocess.run(["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}", f"http://127.0.0.1:{port}/admin"], capture_output=True, text=True)
                if out.stdout.strip() not in {"", "000"}:
                    return int(out.stdout)
                time.sleep(0.5)
            raise AssertionError("the gateway did not answer")

        assert status() == 403  # the client is outside the list

        # Stage the new list the way Fleet does, then run the exact commands of the narrow activation.
        nxt = tmp_path / "node.next"
        shutil.copytree(project, nxt)
        (nxt / "generated/allowlists/management-networks.caddy").write_text("remote_ip 0.0.0.0/0 ::/0\n")
        (project / ".cdnf-good").write_text("")
        script = f"set -e; {a.sync_script(str(nxt), str(project), keep_good_copy=True)}; cd {project}" + "".join(f" && {{ {c}; }}" for c in a.live_commands([("caddy", "reload")]))
        done = sh(script)
        assert done.returncode == 0, done.stdout + done.stderr
        assert status() == 200, "the gateway reads the new list"
        assert inspect(container) == before, "the gateway container was neither recreated nor restarted"

        # A list the gateway cannot parse is refused before the reload; the running configuration keeps serving.
        shutil.rmtree(nxt)
        shutil.copytree(project, nxt)
        (nxt / "generated/allowlists/management-networks.caddy").write_text("not-a-matcher 1\n")
        bad = sh(f"set -e; {a.sync_script(str(nxt), str(project), keep_good_copy=True)}; cd {project}" + "".join(f" && {{ {c}; }}" for c in a.live_commands([("caddy", "reload")])))
        assert bad.returncode != 0
        assert status() == 200 and inspect(container) == before

        # The rollback puts the last known-good files back in place and reloads the same service.
        back = sh(f"set -e; {a.sync_script(str(project) + '.previous', str(project), keep_good_copy=False)}; cd {project}" + "".join(f" && {{ {c}; }}" for c in a.live_commands([("caddy", "reload")])))
        assert back.returncode == 0, back.stdout + back.stderr
        assert (project / "generated/allowlists/management-networks.caddy").read_text() == "remote_ip 192.0.2.0/24\n"
        assert status() == 403 and inspect(container) == before
    finally:
        docker("compose", "--env-file", ".env.prod", "--profile", "*", "down", "-t", "1", cwd=project)


def production_image(service: str) -> str:
    import yaml

    return yaml.safe_load((ROOT / "compose.prod.yml").read_text())["services"][service]["image"]


def have_images(*services: str) -> bool:
    return shutil.which("docker") is not None and all(docker("image", "inspect", production_image(s)).returncode == 0 for s in services)


PROMETHEUS = """global: {scrape_interval: 1m}
rule_files: [/etc/prometheus/rules.yml]
scrape_configs:
  - job_name: self
    file_sd_configs: [{files: [/etc/prometheus/targets.yml]}]
"""
ALERTMANAGER = """route: {receiver: none}
receivers: [{name: none}]
"""
VECTOR = """sources: {demo: {type: demo_logs, format: json, interval: 1}}
sinks: {out: {type: blackhole, inputs: [demo]}}
"""


def run_live(project: Path, nxt: Path, items: list[tuple[str, str]]) -> subprocess.CompletedProcess:
    """The command line of a narrow activation exactly as the engine builds it."""
    return sh(f"set -e; if [ -d {nxt} ]; then {a.sync_script(str(nxt), str(project), keep_good_copy=True)}; rm -rf {nxt}; fi; cd {project}"
              + "".join(f" && {{ {c}; }}" for c in a.live_commands(items)))


def stage(project: Path, nxt: Path, files: dict[str, str]) -> None:
    shutil.rmtree(nxt, ignore_errors=True)
    shutil.copytree(project, nxt, ignore=shutil.ignore_patterns(".cdnf-good"))
    for name, text in files.items():
        (nxt / name).write_text(text)
    (project / ".cdnf-good").write_text("")


@pytest.mark.skipif(not have_images("prometheus", "alertmanager", "vector"), reason="the pinned production images are not on this host")
def test_reloads_restarts_and_recreations_touch_only_the_named_service_on_the_pinned_production_images(tmp_path: Path) -> None:
    project = tmp_path / "node"
    for directory in ("docker/prometheus", "docker/alertmanager", "docker/vector", "generated"):
        (project / directory).mkdir(parents=True)
    files = {"docker/prometheus/prometheus.yml": PROMETHEUS, "docker/prometheus/rules.yml": "groups: []\n", "generated/targets.yml": "[]\n",
             "docker/alertmanager/alertmanager.yml": ALERTMANAGER, "docker/vector/vector.yaml": VECTOR, ".env.prod": ""}
    write_tree(project, files)
    for path in project.rglob("*"):
        path.chmod(0o755 if path.is_dir() else 0o644)
    name = f"cdnf-live2-{os.getpid()}"
    (project / "compose.yml").write_text(f"""name: {name}
services:
  prometheus:
    image: {production_image('prometheus')}
    command: [--config.file=/etc/prometheus/prometheus.yml, --storage.tsdb.path=/prometheus]
    volumes: ['./docker/prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro', './docker/prometheus/rules.yml:/etc/prometheus/rules.yml:ro', './generated/targets.yml:/etc/prometheus/targets.yml:ro']
    profiles: [telemetry]
  alertmanager:
    image: {production_image('alertmanager')}
    volumes: ['./docker/alertmanager/alertmanager.yml:/etc/alertmanager/alertmanager.yml:ro']
    profiles: [telemetry]
  vector:
    image: {production_image('vector')}
    volumes: ['./docker/vector/vector.yaml:/etc/vector/vector.yaml:ro']
    environment: {{MARKER: one}}
    profiles: [telemetry]
""")
    base = ["docker", "compose", "--env-file", ".env.prod", "--profile", "*"]
    nxt = tmp_path / "node.next"

    def ids() -> dict[str, tuple[str, str]]:
        return {s: inspect(f"{name}-{s}-1") for s in ("prometheus", "alertmanager", "vector")}

    try:
        assert subprocess.run([*base, "up", "-d"], cwd=project, capture_output=True, text=True, timeout=180).returncode == 0
        for _ in range(30):  # Prometheus and Alertmanager answer only after start
            if docker("exec", f"{name}-prometheus-1", "wget", "-qO-", "http://127.0.0.1:9090/-/ready").returncode == 0:
                break
            time.sleep(1)
        before = ids()

        # A reload: a new rule file is read, the same containers keep running.
        stage(project, nxt, {"docker/prometheus/rules.yml": "groups:\n  - name: probe\n    rules:\n      - record: probe_one\n        expr: vector(1)\n"})
        done = run_live(project, nxt, [("prometheus", "reload")])
        assert done.returncode == 0, done.stdout + done.stderr
        assert "probe" in docker("exec", f"{name}-prometheus-1", "wget", "-qO-", "http://127.0.0.1:9090/api/v1/rules").stdout, "Prometheus reads the new rule"
        assert ids() == before

        # A broken configuration is refused by promtool before any signal is sent; the old rule keeps loaded.
        stage(project, nxt, {"docker/prometheus/prometheus.yml": "scrape_configs: [this is not valid"})
        bad = run_live(project, nxt, [("prometheus", "reload")])
        assert bad.returncode != 0 and ids() == before
        assert "probe" in docker("exec", f"{name}-prometheus-1", "wget", "-qO-", "http://127.0.0.1:9090/api/v1/rules").stdout
        restored = sh(f"set -e; {a.sync_script(str(project) + '.previous', str(project), keep_good_copy=False)}")
        assert restored.returncode == 0 and (project / "docker/prometheus/prometheus.yml").read_text() == PROMETHEUS

        # Alertmanager reloads too.
        stage(project, nxt, {"docker/alertmanager/alertmanager.yml": ALERTMANAGER + "# changed\n"})
        assert run_live(project, nxt, [("alertmanager", "reload")]).returncode == 0
        assert ids() == before

        # A restart: validated first, only that container restarts. A broken pipeline is refused and the old process keeps running.
        stage(project, nxt, {"docker/vector/vector.yaml": VECTOR.replace("interval: 1", "interval: 2")})
        assert run_live(project, nxt, [("vector", "restart")]).returncode == 0
        after = ids()
        assert after["vector"][0] == before["vector"][0] and after["vector"][1] != before["vector"][1], "vector restarted"
        assert after["prometheus"] == before["prometheus"] and after["alertmanager"] == before["alertmanager"]
        stage(project, nxt, {"docker/vector/vector.yaml": "sources: {broken"})
        assert run_live(project, nxt, [("vector", "restart")]).returncode != 0
        assert ids() == after

        # A recreation: one service whose Compose configuration changed; the others keep their container and start time.
        stage(project, nxt, {"compose.yml": (project / "compose.yml").read_text().replace("MARKER: one", "MARKER: two")})
        assert run_live(project, nxt, [("vector", "recreate")]).returncode == 0
        last = ids()
        assert last["vector"][0] != after["vector"][0], "vector was recreated"
        assert last["prometheus"] == after["prometheus"] and last["alertmanager"] == after["alertmanager"]
    finally:
        subprocess.run([*base, "down", "-t", "1"], cwd=project, capture_output=True, timeout=120)
