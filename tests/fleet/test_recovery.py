from __future__ import annotations

import sys
from pathlib import Path
from types import SimpleNamespace

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts'))
from cdnfoundry_fleet import engine as module
from cdnfoundry_fleet.common import ValidationError
from cdnfoundry_fleet.engine import FleetEngine
from cdnfoundry_fleet.ssh import Target


def engine(tmp_path, monkeypatch, *, role='edge', material=True):
    node = {'name': 'node', 'role': role, 'enabled': True, 'release': 'v2.1.4'}
    state = {'nodes': {'node': node}, 'global': {'release': 'v2.1.4'}}
    runner = object.__new__(FleetEngine)
    runner.work = tmp_path
    runner.say = lambda _: None
    runner.state = lambda: state
    runner.targets = lambda *_: {'node': Target('node', '127.0.0.1')}
    calls = []
    runner.transport = SimpleNamespace(run=lambda target, command, **kw: calls.append(command) or SimpleNamespace(ok=True))
    monkeypatch.setattr(module, 'node_gate', lambda *_: (True, ''))
    if material:
        folder = tmp_path / 'secrets/nodes/node/listener'
        folder.mkdir(parents=True)
        for name in ('edge-runtime.crt', 'edge-runtime.key', 'edge-state-encryption.key'):
            (folder / name).write_bytes(b'existing')
        key = tmp_path / 'pki/nodes/node/node.key'
        key.parent.mkdir(parents=True)
        key.write_bytes(b'existing-node-key')
    installed = []
    monkeypatch.setattr(module.tmpfs_material, 'install', lambda t, target, files: installed.append(files) or (True, ''))
    return runner, calls, installed


@pytest.mark.parametrize('role', ['control', 'dns', 'monitoring', 'edge', 'dns-edge'])
def test_recovery_handles_every_role_without_deploying_or_reissuing_material(tmp_path, monkeypatch, role):
    runner, calls, installed = engine(tmp_path, monkeypatch, role=role)
    result = runner.recover()
    assert result.ok and result.done == ['node']
    assert bool(installed) == (role in {'edge', 'dns-edge'})
    if installed:
        assert installed[0]['edge-state-encryption.key'] == b'existing'
        assert ('node.key' in installed[0]) == (role == 'dns-edge')
    assert 'systemctl enable --now docker' in calls[-1]
    assert not any('pull' in c or 'up -d' in c or 'migrate' in c for c in calls)


def test_missing_recovery_key_fails_before_touching_hosts(tmp_path, monkeypatch):
    runner, calls, installed = engine(tmp_path, monkeypatch, material=False)
    with pytest.raises(ValidationError, match='restore its backup'):
        runner.recover()
    assert not calls and not installed


def test_unknown_node_is_rejected(tmp_path, monkeypatch):
    runner, calls, _ = engine(tmp_path, monkeypatch)
    with pytest.raises(ValidationError, match='Unknown nodes'):
        runner.recover(['typo'])
    assert not calls


def test_release_mismatch_stops_before_provisioning(tmp_path, monkeypatch):
    runner, _, installed = engine(tmp_path, monkeypatch)
    runner.transport.run = lambda *_a, **_kw: SimpleNamespace(ok=False)
    result = runner.recover()
    assert not result.ok and not installed and 'release' in result.detail


def test_failed_health_gate_is_reported(tmp_path, monkeypatch):
    runner, _, _ = engine(tmp_path, monkeypatch, role='control')
    monkeypatch.setattr(module, 'gate_with_retry', lambda *_a, **_kw: (False, 'unhealthy'))
    result = runner.recover()
    assert not result.ok and result.detail == 'unhealthy'


def test_recovery_starts_only_stopped_long_running_containers(tmp_path):
    import json
    import os
    import shlex
    import subprocess
    from cdnfoundry_fleet.provision import recovery_command

    items = [
        {'Id': 'database', 'HostConfig': {'RestartPolicy': {'Name': 'unless-stopped'}}, 'State': {'Running': False}},
        {'Id': 'migration', 'HostConfig': {'RestartPolicy': {'Name': 'no'}}, 'State': {'Running': False}},
        {'Id': 'healthy', 'HostConfig': {'RestartPolicy': {'Name': 'unless-stopped'}}, 'State': {'Running': True}},
    ]
    docker = tmp_path / 'docker'
    log = tmp_path / 'started'
    docker.write_text('#!/usr/bin/env python3\nimport json,sys\n'
                      'if sys.argv[1] == "compose": print("database migration healthy")\n'
                      f'elif sys.argv[1] == "inspect": print({json.dumps(items)!r})\n'
                      f'elif sys.argv[1] == "start": open({str(log)!r}, "a").write(sys.argv[2]+"\\n")\n')
    docker.chmod(0o700)
    script = shlex.split(recovery_command())[-1]
    result = subprocess.run([sys.executable, '-c', script], env={**os.environ, 'PATH': str(tmp_path) + os.pathsep + os.environ['PATH']}, capture_output=True, text=True)
    assert result.returncode == 0, result.stderr
    assert log.read_text().splitlines() == ['database']
