#!/usr/bin/env python3
"""Real alert path of a standby control node. This does not automate a browser.

A standby control node runs only Prometheus (with `docker/prometheus/watcher.yml`), Alertmanager, and a node
exporter. This starts the real pinned Prometheus and Alertmanager images with the repository's watcher
configuration, its HA rules, and its Alertmanager configuration and start script, and a stand-in for the
active node's exporter. When that exporter stops, the webhook must receive `ControlNodeUnreachable` for the
lost node from the standby's own Alertmanager.

Needs Docker. It creates only its own `cdnf-ha-alert*` containers and network and removes them.
"""
from __future__ import annotations

import json
import pathlib
import re
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
NETWORK = "cdnf-ha-alert"
COMPOSE = (ROOT / "compose.prod.yml").read_text(encoding="utf-8")


def pinned(image: str) -> str:
    match = re.search(rf"image: ({re.escape(image)}:[^\s@]+@sha256:[0-9a-f]+)", COMPOSE)
    assert match, image
    return match.group(1)


def docker(*arguments: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(("docker", *arguments), text=True, capture_output=True, check=False)
    if check and result.returncode:
        raise RuntimeError(f"docker {' '.join(arguments)} failed:\n{result.stdout}\n{result.stderr}")
    return result


def query(expression: str) -> list[dict]:
    script = "import json,sys,urllib.request,urllib.parse;print(urllib.request.urlopen('http://prometheus:9090/api/v1/query?query='+urllib.parse.quote(sys.argv[1]),timeout=5).read().decode())"
    out = docker("run", "--rm", "--network", NETWORK, "python:3.13-alpine", "python", "-c", script, expression).stdout
    return json.loads(out)["data"]["result"]


def wait(label: str, seconds: int, condition) -> None:  # noqa: ANN001
    deadline = time.monotonic() + seconds
    last: object = None
    while time.monotonic() < deadline:
        try:
            last = condition()
            if last:
                return
        except Exception as error:  # noqa: BLE001
            last = error
        time.sleep(5)
    raise AssertionError(f"timed out after {seconds}s waiting for {label}: {last!r}")


def main() -> int:
    directory = pathlib.Path(tempfile.mkdtemp(prefix="cdnf-ha-alert-"))
    directory.chmod(0o755)
    (directory / "node-targets.yml").write_text(json.dumps([
        {"targets": ["active-exporter:9100"], "labels": {"node": "control-a", "role": "control"}},
        {"targets": ["standby-exporter:9100"], "labels": {"node": "control-b", "role": "control"}},
    ]))
    (directory / "sink.py").write_text(
        "import http.server\n"
        "class H(http.server.BaseHTTPRequestHandler):\n"
        "    def do_POST(self):\n"
        "        n = int(self.headers.get('Content-Length', 0)); print('WEBHOOK ' + self.rfile.read(n).decode(), flush=True)\n"
        "        self.send_response(200); self.end_headers()\n"
        "    def log_message(self, *a): pass\n"
        "http.server.HTTPServer(('0.0.0.0', 8000), H).serve_forever()\n")
    exporter = "import http.server\nclass H(http.server.BaseHTTPRequestHandler):\n    def do_GET(self):\n        self.send_response(200); self.send_header('Content-Type', 'text/plain; version=0.0.4'); self.end_headers(); self.wfile.write(b'node_up 1\\n')\n    def log_message(self, *a): pass\nhttp.server.HTTPServer(('0.0.0.0', 9100), H).serve_forever()\n"
    (directory / "exporter.py").write_text(exporter)
    docker("network", "create", NETWORK, check=False)
    names = ["sink", "active-exporter", "standby-exporter", "prometheus", "alertmanager"]
    try:
        python = "python:3.13-alpine"
        mount = f"{directory}:/lab:ro"
        docker("run", "-d", "--name", "cdnf-ha-alert-sink", "--network", NETWORK, "--network-alias", "sink", "-v", mount, python, "python", "/lab/sink.py")
        for name in ("active-exporter", "standby-exporter"):
            docker("run", "-d", "--name", f"cdnf-ha-alert-{name}", "--network", NETWORK, "--network-alias", name, "-v", mount, python, "python", "/lab/exporter.py")
        docker("run", "-d", "--name", "cdnf-ha-alert-alertmanager", "--network", NETWORK, "--network-alias", "alertmanager", "--entrypoint", "/bin/sh",
               "-e", "ALERT_WEBHOOK_URL=http://sink:8000/", "-e", "ALERT_WEBHOOK_ALLOW_HTTP=true",
               "-v", f"{ROOT}/docker/alertmanager/alertmanager.yml:/etc/alertmanager/alertmanager.yml:ro", "-v", f"{ROOT}/docker/alertmanager/start.sh:/usr/local/bin/alertmanager-start:ro",
               pinned("prom/alertmanager"), "/usr/local/bin/alertmanager-start")
        docker("run", "-d", "--name", "cdnf-ha-alert-prometheus", "--network", NETWORK, "--network-alias", "prometheus",
               "-v", f"{ROOT}/docker/prometheus/watcher.yml:/etc/prometheus/prometheus.yml:ro",
               "-v", f"{ROOT}/docker/prometheus/control-ha-alerts.yml:/etc/prometheus/control-ha-alerts.yml:ro",
               "-v", f"{directory}/node-targets.yml:/etc/prometheus/node-targets.yml:ro", pinned("prom/prometheus"))

        wait("both exporters to be scraped", 120, lambda: len([row for row in query('up{job="node",role="control"} == 1')]) == 2)
        assert not [row for row in query('ALERTS{alertname="ControlNodeUnreachable",alertstate="firing"}')], "no alert while both nodes answer"
        print("   both control nodes are up; no alert")

        docker("kill", "cdnf-ha-alert-active-exporter")  # the active node is lost
        lost_at = time.monotonic()
        wait("the alert to fire", 240, lambda: query('ALERTS{alertname="ControlNodeUnreachable",alertstate="firing",node="control-a"}'))
        seen = ""
        for _ in range(48):
            seen = docker("logs", "cdnf-ha-alert-sink", check=False).stdout
            if '"alertname":"ControlNodeUnreachable"' in seen.replace(" ", ""):
                break
            time.sleep(5)
        assert '"alertname":"ControlNodeUnreachable"' in seen.replace(" ", ""), seen[-1500:]
        assert '"node":"control-a"' in seen.replace(" ", ""), seen[-1500:]
        assert "control-b" not in re.findall(r'"node":"[^"]+"', seen.replace(" ", ""))
        print(f"   the standby's Alertmanager delivered ControlNodeUnreachable for control-a {time.monotonic() - lost_at:.0f}s after the loss")
    finally:
        for name in names:
            docker("rm", "-f", f"cdnf-ha-alert-{name}", check=False)
        docker("network", "rm", NETWORK, check=False)
    print("control_ha_alert=passed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
