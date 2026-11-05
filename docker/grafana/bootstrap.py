#!/usr/bin/env python3
"""Reconcile the parts of Grafana that file provisioning cannot hold: library panels, teams, folder
permissions, team home dashboards, and playlists. It reads only the repository files next to it and talks to
Grafana's HTTP API as the administrator. Running it again changes nothing that is already right, so it also
repairs permissions someone edited by hand. Standard library only.
"""
from __future__ import annotations

import base64
import json
import os
import pathlib
import sys
import time
import urllib.error
import urllib.request

HERE = pathlib.Path(__file__).resolve().parent
ACCESS = json.loads((HERE / "access.json").read_text())
VIEW, EDIT, ADMIN = 1, 2, 4


class Grafana:
    def __init__(self, base: str, user: str, password: str) -> None:
        self.base = base.rstrip("/")
        self.auth = "Basic " + base64.b64encode(f"{user}:{password}".encode()).decode()

    def call(self, method: str, path: str, body: dict | None = None, *, allow: tuple[int, ...] = ()) -> dict | list | None:
        request = urllib.request.Request(self.base + path, method=method, data=None if body is None else json.dumps(body).encode(),
                                         headers={"Authorization": self.auth, "Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                payload = response.read()
                return json.loads(payload) if payload else None
        except urllib.error.HTTPError as error:
            if error.code in allow:
                return None
            raise RuntimeError(f"{method} {path} failed with {error.code}: {error.read().decode()[:300]}") from error


def wait_for(grafana: Grafana, seconds: int = 180) -> None:
    deadline = time.monotonic() + seconds
    while True:
        try:
            health = grafana.call("GET", "/api/health")
            if isinstance(health, dict) and health.get("database") == "ok":
                return
        except (RuntimeError, OSError):
            pass
        if time.monotonic() > deadline:
            raise RuntimeError("Grafana did not become healthy")
        time.sleep(2)


def library_panels(grafana: Grafana) -> int:
    changed = 0
    for path in sorted((HERE / "library-panels").glob("*.json")):
        document = json.loads(path.read_text())
        existing = grafana.call("GET", f"/api/library-elements/{document['uid']}", allow=(404,))
        payload = {"uid": document["uid"], "name": document["name"], "model": document["model"], "kind": 1}
        if existing is None:
            grafana.call("POST", "/api/library-elements", payload)
            changed += 1
            continue
        current = existing["result"]
        if current.get("model") != document["model"] or current.get("name") != document["name"]:
            grafana.call("PATCH", f"/api/library-elements/{document['uid']}", {**payload, "version": current["version"]})
            changed += 1
    return changed


def teams(grafana: Grafana) -> dict[str, int]:
    ids: dict[str, int] = {}
    for name in ACCESS["teams"]:
        found = grafana.call("GET", f"/api/teams/search?name={name}")
        match = next((team for team in found.get("teams", []) if team["name"] == name), None) if isinstance(found, dict) else None
        if match is None:
            created = grafana.call("POST", "/api/teams", {"name": name})
            ids[name] = int(created["teamId"])
        else:
            ids[name] = int(match["id"])
    return ids


def folder_permissions(grafana: Grafana, ids: dict[str, int]) -> None:
    folders = {uid for settings in ACCESS["teams"].values() for uid in settings["folders"]}
    for uid in sorted(folders):
        items = [{"role": "Admin", "permission": ADMIN}]
        for name, settings in ACCESS["teams"].items():
            if uid in settings["folders"]:
                items.append({"teamId": ids[name], "permission": EDIT if settings["edit"] else VIEW})
        grafana.call("POST", f"/api/folders/{uid}/permissions", {"items": items})


def homes(grafana: Grafana, ids: dict[str, int]) -> None:
    for name, settings in ACCESS["teams"].items():
        grafana.call("PUT", f"/api/teams/{ids[name]}/preferences", {"homeDashboardUID": settings["home"]})
    grafana.call("PUT", "/api/org/preferences", {"homeDashboardUID": ACCESS["default_home"]})


def playlists(grafana: Grafana) -> None:
    existing = grafana.call("GET", "/api/playlists") or []
    for name, uids in ACCESS["playlists"].items():
        body = {"name": name, "interval": "1m", "items": [{"type": "dashboard_by_uid", "value": uid} for uid in uids]}
        match = next((item for item in existing if item["name"] == name), None)
        if match is None:
            grafana.call("POST", "/api/playlists", body)
        else:
            grafana.call("PUT", f"/api/playlists/{match['uid']}", body)


def test_users(grafana: Grafana, ids: dict[str, int], password: str) -> None:
    """Development and test stacks only: one viewer per team, so the browser suite can sign in as each."""
    for name, team_id in ids.items():
        login = f"{name}-user"
        found = grafana.call("GET", f"/api/users/lookup?loginOrEmail={login}", allow=(404,))
        if found is None:
            created = grafana.call("POST", "/api/admin/users", {"name": login, "login": login, "email": f"{login}@cdnfoundry.test", "password": password})
            user_id = int(created["id"])
        else:
            user_id = int(found["id"])
            grafana.call("PUT", f"/api/admin/users/{user_id}/password", {"password": password})
        grafana.call("POST", f"/api/teams/{team_id}/members", {"userId": user_id}, allow=(400, 409))


def reconcile(grafana: Grafana, *, test_password: str | None = None) -> None:
    library_panels(grafana)
    ids = teams(grafana)
    folders_ready = all(grafana.call("GET", f"/api/folders/{uid}", allow=(404,)) is not None for uid in {uid for s in ACCESS["teams"].values() for uid in s["folders"]})
    if folders_ready:
        folder_permissions(grafana, ids)
    homes(grafana, ids)
    playlists(grafana)
    if test_password:
        test_users(grafana, ids, test_password)


def main() -> int:
    grafana = Grafana(os.environ.get("GRAFANA_URL", "http://grafana:3000"), os.environ.get("GRAFANA_ADMIN_USER", "admin"), os.environ["GRAFANA_ADMIN_PASSWORD"])
    interval = int(os.environ.get("GRAFANA_BOOTSTRAP_INTERVAL", "300"))
    test_password = os.environ.get("GRAFANA_TEST_USER_PASSWORD") or None
    wait_for(grafana)
    while True:
        try:
            reconcile(grafana, test_password=test_password)
            pathlib.Path("/tmp/bootstrap-ok").write_text(str(int(time.time())))
            print("grafana bootstrap reconciled", flush=True)
        except RuntimeError as error:
            print(f"grafana bootstrap failed: {error}", file=sys.stderr, flush=True)
            pathlib.Path("/tmp/bootstrap-ok").unlink(missing_ok=True)
        if interval <= 0:
            return 0
        time.sleep(interval)


if __name__ == "__main__":
    sys.exit(main())
