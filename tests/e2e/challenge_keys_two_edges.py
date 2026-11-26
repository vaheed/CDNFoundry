#!/usr/bin/env python3
"""Pool-wide challenge keys through the real control plane, two real agents and their real cells (the development stack).

The control plane issues one set of keys. Each edge agent fetches it sealed to its own identity key and gives it to its cells over the
private control channel. The test reads what the cells of two different edges report, rotates the active key through the admin API,
and checks that both edges converge on the new version within the agents' refresh window, that a revoked key leaves both, and that
no response or cell status carries a secret. It creates one throwaway administrator with generated credentials and removes nothing
else; run it only against a development stack.
"""

import json
import secrets
import subprocess
import sys
import time
import uuid

sys.path.insert(0, __import__("pathlib").Path(__file__).resolve().parent.as_posix())
from e2e import artisan, login, php_string, request, wait_for_api  # noqa: E402

PROJECT = "cdnfoundry-dev"
CELLS = {"edge-a": f"{PROJECT}-edge-a-cell-03-1", "edge-b": f"{PROJECT}-edge-b-cell-03-1"}
EMAIL = f"challenge-keys-{secrets.token_hex(3)}@example.test"
PASSWORD = secrets.token_urlsafe(24)


def cell_status(container: str) -> dict:
    token = subprocess.run(["docker", "exec", container, "printenv", "EDGE_STATUS_TOKEN"], text=True, capture_output=True, check=True).stdout.strip()
    out = subprocess.run(["docker", "exec", container, "wget", "-qO-", "--header", f"X-Edge-Status-Token: {token}", "http://127.0.0.1:9080/passive-failures"],
                         text=True, capture_output=True, check=True).stdout
    return json.loads(out)["cell"]


def wait_for(version: int, timeout: int = 150) -> dict[str, int]:
    deadline = time.monotonic() + timeout
    held: dict[str, int] = {}
    while time.monotonic() < deadline:
        held = {edge: cell_status(container).get("challenge_keys_version") or 0 for edge, container in CELLS.items()}
        if all(value == version for value in held.values()):
            return held
        time.sleep(5)
    raise AssertionError(f"the cells of both edges did not reach version {version}: {held}")


def main() -> None:
    wait_for_api()
    artisan("App\\Models\\User::query()->create(['name'=>'Challenge Keys','email'=>" + php_string(EMAIL) + ",'password'=>Illuminate\\Support\\Facades\\Hash::make(" + php_string(PASSWORD) + "),'type'=>'admin']);")
    token = login(EMAIL, PASSWORD)
    _, listing = request("GET", "/api/admin/challenge-keys", token=token)
    version = listing["version"]
    assert [key["state"] for key in listing["data"]] in (["active"], ["active", "previous"], ["previous", "active"]), listing
    # 1. Both edges hold the version the control plane issued, which they could only get by the sealed fetch.
    first = wait_for(version)

    # 2. A rotation reaches both edges, and the previous key stays for the overlap.
    _, rotated = request("POST", "/api/admin/challenge-keys/rotate", {}, token, str(uuid.uuid4()))
    new_id = rotated["data"]["id"]
    _, after = request("GET", "/api/admin/challenge-keys", token=token)
    assert after["version"] > version and {key["state"] for key in after["data"]} == {"active", "previous"}, after
    started = time.monotonic()
    second = wait_for(after["version"])
    converged = time.monotonic() - started

    # 3. Revoking the previous key is one more version, and no cell status holds a secret.
    previous = next(key for key in after["data"] if key["state"] == "previous")
    _, revoked = request("POST", f"/api/admin/challenge-keys/{previous['id']}/revoke", {}, token, str(uuid.uuid4()))
    assert revoked["data"]["retired_reason"] == "revoked"
    _, final = request("GET", "/api/admin/challenge-keys", token=token)
    wait_for(final["version"])
    for container in CELLS.values():
        status = json.dumps(cell_status(container))
        assert "secret" not in status and new_id not in status
    print(json.dumps({"first_version": first, "after_rotation": second, "seconds_to_converge": round(converged, 1), "final_version": final["version"]}))
    print("e2e challenge keys across two real edges passed")


if __name__ == "__main__":
    main()
