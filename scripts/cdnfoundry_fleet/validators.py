"""Per-service config validators that run on the node before a config is activated.

Each command runs against the new config in the uploaded (not yet active) bundle,
inside the service's own image, so the check uses the same binary that will load it.
A node never activates a config that did not pass its validator.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from .ssh import Target


@dataclass(frozen=True)
class Validator:
    service: str
    roles: frozenset[str]
    command: str  # {dir} is the uploaded bundle directory on the node
    compose_service: str = ""  # the Compose service the command runs in


# Validation runs under the project name the bundle will run under (the install directory's name, not the staging directory's),
# so it reuses the same fixed-subnet networks instead of colliding with them. It never removes anything.
PROJECT = "COMPOSE_PROJECT_NAME={project}"
_RUN = "cd {dir} && export " + PROJECT + " && docker compose --env-file .env.prod --profile '*' run --rm --no-deps -T"
ALL_ROLES = frozenset({"control", "edge", "dns", "dns-edge", "monitoring"})
VALIDATORS = [
    # start.sh sets file ownership and then calls ./validate.sh; the same prefix runs here so a staged bundle is checked exactly as it will be started.
    Validator("compose", ALL_ROLES, "cd {dir} && export " + PROJECT + " && if grep -qx './validate.sh' start.sh; then sed '/^\\.\\/validate\\.sh$/q' start.sh | sh; else ./validate.sh; fi", "compose"),
    # The entrypoint writes worker-processes.conf before nginx starts; `nginx -t` alone would fail on the missing include, so write a placeholder first.
    Validator("openresty", frozenset({"edge", "dns-edge"}), _RUN + " --entrypoint sh cell-01 -c 'mkdir -p /var/lib/nginx/tmp && echo \"worker_processes 1;\" > /var/lib/nginx/tmp/worker-processes.conf && nginx -t'", "cell-01"),
    Validator("edge-control", frozenset({"control"}), _RUN + " --entrypoint nginx edge-control -t", "edge-control"),
    Validator("dnsdist", frozenset({"dns", "dns-edge"}), _RUN + " dnsdist --check-config --config /etc/dnsdist/dnsdist.conf", "dnsdist"),
    Validator("powerdns", frozenset({"control"}), _RUN + " --entrypoint pdns_server pdns-primary --config=check", "pdns-primary"),
    Validator("caddy", frozenset({"control"}), _RUN + " caddy caddy validate --config /etc/caddy/Caddyfile", "caddy"),
    Validator("prometheus", frozenset({"monitoring"}), _RUN + " --entrypoint promtool prometheus check config /etc/prometheus/prometheus.yml", "prometheus"),
    Validator("alertmanager", frozenset({"monitoring"}), _RUN + " --entrypoint amtool alertmanager check-config /etc/alertmanager/alertmanager.yml", "alertmanager"),
    Validator("vector", ALL_ROLES, _RUN + " vector validate --no-environment /etc/vector/vector.yaml", "vector"),
]


def validators_for(role: str, services: set[str]) -> list[Validator]:
    """Only services that the node's compose file defines are checked."""
    return [v for v in VALIDATORS if role in v.roles and (v.service == "compose" or v.compose_service in services)]


def validate_node_config(transport: Any, target: Target, role: str, directory: str, services: set[str]) -> tuple[bool, str]:
    project = directory.rstrip("/").removesuffix(".next").rsplit("/", 1)[-1].replace(".", "") or "cdnfoundry"
    for validator in validators_for(role, services):
        result = transport.run(target, validator.command.format(dir=directory, project=project))
        if not result.ok:
            detail = (result.stderr or result.stdout).strip()[-300:]
            return False, f"{validator.service} rejected the new config: {detail}"
    return True, ""
