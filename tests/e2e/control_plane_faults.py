#!/usr/bin/env python3
"""Control-plane faults must not become data-plane outages (real development stack, no browser).

Checks: DNS keeps answering while edge-control is down and heartbeats are stale;
the edge agent is restarted after it is killed; a queue job longer than 90
seconds runs exactly once.
"""

import pathlib
import subprocess
import sys
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
COMPOSE = ["docker", "compose", "-f", "compose.dev.yml"]
PROXY_HOSTNAME = "proxy.cdnf.test"


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=True, check=False)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed ({result.returncode}): {' '.join(args)}\n{result.stdout}\n{result.stderr}")
    return result


def tinker(code: str) -> str:
    return run(*COMPOSE, "exec", "-T", "core", "php", "artisan", "tinker", f"--execute={code}").stdout.strip().splitlines()[-1]


def answers(record_type: str) -> list[str]:
    output = run("dig", "@127.0.0.1", "-p", "1053", PROXY_HOSTNAME, record_type, "+short", "+timeout=3", "+tries=2", check=False).stdout
    return sorted(line for line in output.splitlines() if line and not line.startswith(";"))


def dns_survives_a_silent_control_plane() -> None:
    before = {family: answers(family) for family in ("A", "AAAA")}
    assert before["A"], f"{PROXY_HOSTNAME} publishes no IPv4 address before the test: {before}"
    run("docker", "stop", "cdnfoundry-dev-edge-control-1")
    try:
        # Days without a heartbeat from anyone, then a fresh render of the platform zone.
        tinker("App\\Models\\Edge::query()->update(['last_heartbeat_at' => now()->subDays(2)]); echo 'ok';")
        tinker("dispatch_sync(new App\\Jobs\\ReconcilePlatformDnsIdentity); echo 'ok';")
        after = {family: answers(family) for family in ("A", "AAAA")}
        assert after == before, f"DNS changed while control was silent: {before} -> {after}"
        assert after["A"] and after["AAAA"], "an empty answer set was published"
    finally:
        run("docker", "start", "cdnfoundry-dev-edge-control-1")


def killed_agent_is_restarted() -> None:
    agent = "cdnfoundry-dev-edge-agent-b-1"
    started = run("docker", "inspect", "-f", "{{.State.StartedAt}}", agent).stdout.strip()
    # `docker kill` counts as an operator stop and disables the restart policy; a
    # process that dies on its own is what Compose must recover from.
    run("docker", "exec", agent, "kill", "-TERM", "1")
    deadline = time.time() + 60
    while time.time() < deadline:
        state = run("docker", "inspect", "-f", "{{.State.Running}} {{.State.StartedAt}}", agent, check=False).stdout.split()
        if state[:1] == ["true"] and state[1] != started:
            break
        time.sleep(1)
    else:
        raise AssertionError("the killed agent was not restarted")
    deadline = time.time() + 180
    while time.time() < deadline:
        if run("docker", "inspect", "-f", "{{.State.Health.Status}}", agent).stdout.strip() == "healthy":
            return
        time.sleep(3)
    raise AssertionError("the restarted agent never became healthy")


LONG_JOB_SCRIPT = """<?php
// Closures are serialized from their source file, so this must be a real file.
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
Illuminate\\Support\\Facades\\Cache::forget('e2e:long-job');
dispatch(function () {
    Illuminate\\Support\\Facades\\Cache::increment('e2e:long-job');
    sleep(100);
})->onQueue('edge');
echo "queued\\n";
"""


def long_job_runs_once() -> None:
    result = subprocess.run([*COMPOSE, "exec", "-T", "core", "sh", "-c", "cat > /tmp/e2e-long-job.php && php /tmp/e2e-long-job.php"],
                            cwd=ROOT, input=LONG_JOB_SCRIPT, text=True, capture_output=True, check=False)
    assert "queued" in result.stdout, result.stdout + result.stderr
    time.sleep(100 + 90)  # past the old 90-second redelivery, with margin for the scheduler
    runs = tinker("echo (int) Cache::get('e2e:long-job', 0);")
    assert runs == "1", f"the 100-second job ran {runs} times"


def main() -> None:
    dns_survives_a_silent_control_plane()
    print("dns fail-static ok")
    killed_agent_is_restarted()
    print("agent restart ok")
    long_job_runs_once()
    print("control plane fault qualification passed")


if __name__ == "__main__":
    sys.exit(main())
