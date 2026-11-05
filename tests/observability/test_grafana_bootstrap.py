#!/usr/bin/env python3
"""The Grafana bootstrap against a stand-in API: it creates what is missing, repairs drift, and changes nothing twice."""

from __future__ import annotations

import http.server
import json
import pathlib
import sys
import threading
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "docker/grafana"))

import bootstrap  # noqa: E402


class FakeGrafana(http.server.BaseHTTPRequestHandler):
    state: dict = {}

    def log_message(self, *_args) -> None:  # noqa: D401
        pass

    def _send(self, status: int, body) -> None:
        payload = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def _body(self):
        length = int(self.headers.get("Content-Length") or 0)
        return json.loads(self.rfile.read(length)) if length else {}

    def do_GET(self) -> None:  # noqa: N802
        state = self.state
        path = self.path
        if path == "/api/health":
            return self._send(200, {"database": "ok"})
        if path.startswith("/api/library-elements/"):
            element = state["library"].get(path.rsplit("/", 1)[1])
            return self._send(200, {"result": element}) if element else self._send(404, {})
        if path.startswith("/api/teams/search"):
            name = path.split("name=")[1]
            return self._send(200, {"teams": [team for team in state["teams"].values() if team["name"] == name]})
        if path.startswith("/api/folders/"):
            return self._send(200, {"uid": path.rsplit("/", 1)[1]})
        if path == "/api/playlists":
            return self._send(200, list(state["playlists"].values()))
        return self._send(404, {})

    def do_POST(self) -> None:  # noqa: N802
        state, body = self.state, self._body()
        state["calls"].append(("POST", self.path))
        if self.path == "/api/library-elements":
            state["library"][body["uid"]] = {"version": 1, "model": body["model"], "name": body["name"]}
            return self._send(200, {"result": {}})
        if self.path == "/api/teams":
            identifier = len(state["teams"]) + 1
            state["teams"][identifier] = {"id": identifier, "name": body["name"]}
            return self._send(200, {"teamId": identifier})
        if self.path.endswith("/permissions"):
            state["permissions"][self.path.split("/")[3]] = body["items"]
            return self._send(200, {})
        if self.path == "/api/playlists":
            uid = f"p{len(state['playlists']) + 1}"
            state["playlists"][uid] = {"uid": uid, **body}
            return self._send(200, {"uid": uid})
        return self._send(404, {})

    def do_PATCH(self) -> None:  # noqa: N802
        state, body = self.state, self._body()
        state["calls"].append(("PATCH", self.path))
        uid = self.path.rsplit("/", 1)[1]
        state["library"][uid] = {"version": state["library"][uid]["version"] + 1, "model": body["model"], "name": body["name"]}
        return self._send(200, {"result": {}})

    def do_DELETE(self) -> None:  # noqa: N802
        self.state["calls"].append(("DELETE", self.path))
        return self._send(404, {})

    def do_PUT(self) -> None:  # noqa: N802
        state, body = self.state, self._body()
        state["calls"].append(("PUT", self.path))
        if self.path.startswith("/api/teams/") and self.path.endswith("/preferences"):
            state["homes"][self.path.split("/")[3]] = body["homeDashboardUID"]
        if self.path == "/api/org/preferences":
            state["homes"]["org"] = body["homeDashboardUID"]
        if self.path.startswith("/api/playlists/"):
            state["playlists"][self.path.rsplit("/", 1)[1]].update(body)
        return self._send(200, {})


class BootstrapTest(unittest.TestCase):
    def setUp(self) -> None:
        FakeGrafana.state = {"library": {}, "teams": {}, "permissions": {}, "homes": {}, "playlists": {}, "calls": []}
        self.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), FakeGrafana)
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.grafana = bootstrap.Grafana(f"http://127.0.0.1:{self.server.server_port}", "admin", "secret")

    def tearDown(self) -> None:
        self.server.shutdown()

    def test_creates_every_team_with_view_access_and_only_platform_admin_can_edit(self) -> None:
        bootstrap.reconcile(self.grafana)
        state = FakeGrafana.state
        names = {team["name"]: team["id"] for team in state["teams"].values()}
        self.assertEqual({"platform-admin", "noc", "soc", "support", "business"}, set(names))
        for folder, items in state["permissions"].items():
            self.assertIn({"role": "Admin", "permission": 4}, items)
            editors = {item["teamId"] for item in items if item.get("permission") == 2}
            self.assertEqual({names["platform-admin"]}, editors, folder)
            self.assertNotIn("Editor", [item.get("role") for item in items])
        self.assertEqual({names["noc"]}, {item["teamId"] for item in state["permissions"]["cdnf-folder-noc"] if item.get("teamId") and item["teamId"] != names["platform-admin"]})
        self.assertNotIn(names["noc"], {item.get("teamId") for item in state["permissions"]["cdnf-folder-soc"]})

    def test_the_retired_dashboards_are_removed_and_a_missing_one_is_fine(self) -> None:
        bootstrap.reconcile(self.grafana)
        deleted = {call[1] for call in FakeGrafana.state["calls"] if call[0] == "DELETE"}
        self.assertEqual({"/api/dashboards/uid/cdnf-system-command-center", "/api/dashboards/uid/cdnf-domain-command-center"}, deleted)

    def test_homes_and_playlists_are_set(self) -> None:
        bootstrap.reconcile(self.grafana)
        state = FakeGrafana.state
        self.assertEqual("cdnf-now", state["homes"]["org"])
        self.assertEqual({"NOC wall", "SOC wall"}, {playlist["name"] for playlist in state["playlists"].values()})
        self.assertEqual(5, len(state["homes"]) - 1)

    def test_a_second_run_creates_nothing_and_a_changed_panel_is_updated_once(self) -> None:
        bootstrap.reconcile(self.grafana)
        created = len(FakeGrafana.state["library"])
        self.assertGreater(created, 10)
        FakeGrafana.state["calls"].clear()
        bootstrap.reconcile(self.grafana)
        self.assertEqual(created, len(FakeGrafana.state["library"]))
        self.assertNotIn(("POST", "/api/library-elements"), FakeGrafana.state["calls"])
        self.assertNotIn(("POST", "/api/teams"), FakeGrafana.state["calls"])
        self.assertFalse([call for call in FakeGrafana.state["calls"] if call[0] == "PATCH"])
        uid = next(iter(FakeGrafana.state["library"]))
        FakeGrafana.state["library"][uid]["model"] = {"title": "edited by hand"}
        bootstrap.reconcile(self.grafana)
        self.assertEqual(1, len([call for call in FakeGrafana.state["calls"] if call == ("PATCH", f"/api/library-elements/{uid}")]))


if __name__ == "__main__":
    unittest.main()
