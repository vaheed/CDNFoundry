#!/usr/bin/env python3
"""Every Loki investigation template returns its known line, and a mismatched filter returns nothing.

For each template of `docker/grafana/log_templates.py` the script pushes one structured line to the development Loki under its own
environment label (`e2e-templates`), with a service the template selects, the words its line filter looks for and the identifiers it can
narrow by. It then runs the template's own LogQL through Loki's query API: the line must come back, the same query with a different
request identifier must come back empty, and a line of another class must not leak in. No label outside environment, host, service and
level is used, so the run adds no label cardinality. Lines age out with Loki's retention; nothing else is changed.
"""

import json
import pathlib
import re
import subprocess
import sys
import time
import urllib.parse

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "docker/grafana"))
from log_templates import FIELDS, FIXED_LEVEL, TEMPLATES, template_logql  # noqa: E402

CONTAINER = "cdnfoundry-dev-grafana-1"
ENVIRONMENT, HOST = "e2e-templates", "e2e-host"
RUN = str(int(time.time()))


def loki(path: str, body: bytes | None = None, content_type: str | None = None) -> dict:
    command = ["docker", "exec", "-i", CONTAINER, "curl", "-s", "-m", "20", "-w", "\n%{http_code}", f"http://loki:3100{path}"]
    if body is not None:
        command += ["-H", f"Content-Type: {content_type}", "--data-binary", "@-"]
    done = subprocess.run(command, input=body, capture_output=True, check=True)
    text, _, code = done.stdout.decode().rpartition("\n")
    assert code in ("200", "204"), (path, code, text[:300])
    return json.loads(text) if text.strip() else {}


def concrete(service_regex: str) -> str:
    """A service name that the template's regular expression selects."""
    first = service_regex.split("|")[0]
    return first.replace("[1-8]", "1") if first != ".+" else "core"


def sample_filter(line_filter: str | None) -> str:
    return line_filter.split("|")[0].replace(".?", "") if line_filter else "ordinary"


def main() -> None:
    assert subprocess.run(["docker", "inspect", CONTAINER], capture_output=True).returncode == 0, "the development stack must run"
    now = time.time_ns()
    lines = {}
    streams = []
    for index, (key, title, _, service, line_filter) in enumerate(TEMPLATES):
        token = f"known-{key}-{RUN}"
        fields = {"request_id": f"req-{key}-{RUN}", "operation_id": f"op-{key}-{RUN}", "task_id": f"task-{key}-{RUN}", "domain_id": "4242", "edge_id": "edge-e2e",
                  "cell": "cell-01", "revision_id": "77", "event": f"e2e_{key}"}
        level = "error" if key in FIXED_LEVEL else "info"
        message = f"{token} {sample_filter(line_filter)} observed"
        lines[key] = {"token": token, "fields": fields}
        streams.append({"stream": {"environment": ENVIRONMENT, "host": HOST, "service": concrete(service), "level": level},
                        "values": [[str(now + index), json.dumps({"message": message, **fields})]]})
    loki("/loki/api/v1/push", json.dumps({"streams": streams}).encode(), "application/json")
    time.sleep(3)

    def query(expression: str) -> list[str]:
        start = time.time_ns() - 15 * 60 * 10**9
        answer = loki("/loki/api/v1/query_range?" + urllib.parse.urlencode({"query": expression, "start": start, "end": time.time_ns() + 10**9, "limit": 200, "direction": "forward"}))
        return [line for stream in answer["data"]["result"] for _, line in stream["values"]]

    blank = {variable: "" for variable, _, _ in FIELDS}
    for key, title, _, _, _ in TEMPLATES:
        known = lines[key]
        expression = template_logql(key, environment=ENVIRONMENT, host=".+", level=".+", fields=blank, text="")
        returned = query(expression)
        assert any(known["token"] in line for line in returned), f"{title}: its known line did not come back for {expression}"
        others = [lines[other]["token"] for other, *_ in TEMPLATES if other != key and other not in ("errors", "correlation") and key not in ("errors", "correlation")]
        narrowed = template_logql(key, environment=ENVIRONMENT, host=".+", level=".+", fields={**blank, "request_id": known["fields"]["request_id"]}, text="")
        assert any(known["token"] in line for line in query(narrowed)), f"{title}: filtering by its own request ID lost the line"
        assert not any(token in line for token in others for line in query(narrowed)), f"{title}: a line of another class leaked into it"
        mismatched = template_logql(key, environment=ENVIRONMENT, host=".+", level=".+", fields={**blank, "request_id": "no-such-request"}, text="")
        assert query(mismatched) == [], f"{title}: a mismatched filter must return nothing"
        free = template_logql(key, environment=ENVIRONMENT, host=".+", level=".+", fields=blank, text="zzz-no-such-text")
        assert query(free) == [], f"{title}: a mismatched free text must return nothing"
        print(f"ok  {title}")
    # No new label: the stream that was pushed carries exactly the four labels the templates select by.
    labels = loki("/loki/api/v1/labels")["data"]
    assert not {"request_id", "operation_id", "task_id", "domain_id", "edge_id", "cell", "revision_id"} & set(labels), "an identifier became a label"
    print("e2e loki investigation templates passed:", len(TEMPLATES), "templates")


if __name__ == "__main__":
    main()
