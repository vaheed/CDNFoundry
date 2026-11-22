from pathlib import Path
import os
import subprocess

import pytest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/cdnfoundry_fleet/host-bootstrap.sh'


@pytest.mark.parametrize('distro,manager', [('ubuntu','apt-get'), ('debian','apt-get'), ('fedora','dnf'), ('rocky','dnf'), ('alpine','apk'), ('arch','pacman'), ('opensuse-leap','zypper')])
def test_detects_distribution_without_changing_the_host(tmp_path, distro, manager):
    release = tmp_path / 'os-release'
    release.write_text(f'ID={distro}\nVERSION_ID=24.04\nVERSION_CODENAME=noble\n')
    result = subprocess.run(['sh', str(SCRIPT), '--plan'], env={**os.environ, 'CDNF_OS_RELEASE':str(release)}, capture_output=True, text=True)
    assert result.returncode == 0, result.stderr
    assert manager in result.stdout


def test_unknown_distribution_fails_before_installation(tmp_path):
    release = tmp_path / 'os-release'
    release.write_text('ID=unknown\n')
    result = subprocess.run(['sh',str(SCRIPT),'--plan'],env={**os.environ,'CDNF_OS_RELEASE':str(release)},capture_output=True,text=True)
    assert result.returncode != 0
    assert 'unsupported' in result.stderr.lower()


def test_unknown_distribution_with_working_dependencies_is_not_reconfigured(tmp_path):
    release = tmp_path / 'os-release'
    release.write_text('ID=custom-linux\n')
    bindir = tmp_path / 'bin'
    bindir.mkdir()
    for name in ['docker','python3','openssl','tar','ss','flock','bash','curl','iptables','ip6tables']:
        binary = bindir / name
        binary.write_text('#!/bin/sh\nexit 0\n')
        binary.chmod(0o755)
    identity = bindir / 'id'
    identity.write_text('#!/bin/sh\necho 1000\n')
    identity.chmod(0o755)
    result = subprocess.run(['sh',str(SCRIPT)],env={**os.environ,'CDNF_OS_RELEASE':str(release),'PATH':str(bindir)+':'+os.environ['PATH']},capture_output=True,text=True)
    assert result.returncode == 0, result.stderr
    assert 'already ready' in result.stdout


def test_unprivileged_host_cannot_install_missing_dependencies(tmp_path):
    release = tmp_path / 'os-release'
    release.write_text('ID=ubuntu\n')
    bindir = tmp_path / 'bin'
    bindir.mkdir()
    attempted = tmp_path / 'mutation-attempted'
    for name, body in {
        'id': 'echo 1000',
        'docker': 'exit 1',
        'apt-get': f'touch "{attempted}"; exit 99',
        'systemctl': f'touch "{attempted}"; exit 99',
    }.items():
        binary = bindir / name
        binary.write_text('#!/bin/sh\n'+body+'\n')
        binary.chmod(0o755)
    result = subprocess.run(['sh', str(SCRIPT)], env={**os.environ, 'CDNF_OS_RELEASE': str(release), 'PATH': str(bindir)+':'+os.environ['PATH']}, capture_output=True, text=True)
    assert result.returncode == 1
    assert 'requires root' in result.stderr
    assert not attempted.exists()


def test_installing_a_missing_tool_preserves_an_existing_docker_installation(tmp_path):
    release = tmp_path / 'os-release'
    release.write_text('ID=ubuntu\nVERSION_CODENAME=noble\n')
    bindir = tmp_path / 'bin'
    bindir.mkdir()
    log = tmp_path / 'packages'
    commands = {name:'exit 0' for name in ['docker','python3','openssl','tar','ss','flock','bash','iptables','ip6tables','systemctl']}
    commands['id'] = 'echo 0'
    commands['timeout'] = 'shift; exec "$@"'
    commands['install'] = 'exit 99'  # No test may create repository files in the real /etc.
    commands['apt-get'] = f'printf "%s\\n" "$*" >> "{log}"\nprintf "#!/bin/sh\\nexit 0\\n" > "{bindir}/curl"\n/bin/chmod +x "{bindir}/curl"'
    for name, body in commands.items():
        binary = bindir / name
        binary.write_text('#!/bin/sh\n'+body+'\n')
        binary.chmod(0o755)
    script = tmp_path / 'bootstrap.sh'
    script.write_text(SCRIPT.read_text().replace('/run/systemd/system',str(tmp_path)))
    result = subprocess.run(['/bin/sh',str(script)],env={**os.environ,'CDNF_OS_RELEASE':str(release),'PATH':str(bindir)},capture_output=True,text=True)
    assert result.returncode == 0, result.stderr
    assert 'curl' in log.read_text()
    assert 'docker' not in log.read_text()


@pytest.mark.parametrize('healthy', [True, False])
def test_resolver_preparation_preserves_dns_and_rolls_back_on_failure(tmp_path, healthy):
    root = tmp_path / 'host'
    etc = root / 'etc'
    runtime = root / 'run/systemd/resolve'
    etc.mkdir(parents=True)
    runtime.mkdir(parents=True)
    (runtime / 'resolv.conf').write_text('nameserver 192.0.2.53\n')
    (runtime / 'stub-resolv.conf').write_text('nameserver 127.0.0.53\n')
    (etc / 'resolv.conf').symlink_to(runtime / 'stub-resolv.conf')
    bindir = tmp_path / 'bin'
    bindir.mkdir()
    for name, body in {'ss':'echo "udp UNCONN 0 0 127.0.0.53:53 0.0.0.0:* users:((systemd-resolve))"', 'systemctl':'exit 0', 'getent':f'exit {0 if healthy else 1}'}.items():
        path = bindir / name
        path.write_text('#!/bin/sh\n'+body+'\n')
        path.chmod(0o755)
    script = SCRIPT.with_name('host-dns.sh').read_text().replace('/etc/',str(etc)+'/').replace('/run/systemd/resolve/',str(runtime)+'/')
    test_script = tmp_path / 'resolver.sh'
    test_script.write_text(script)
    result = subprocess.run(['sh',str(test_script),'dns','0.0.0.0','::'],env={**os.environ,'PATH':str(bindir)+':'+os.environ['PATH']},capture_output=True,text=True)
    assert result.returncode == (0 if healthy else 1), result.stderr
    assert (etc / 'resolv.conf').resolve() == runtime / ('resolv.conf' if healthy else 'stub-resolv.conf')
    assert (etc / 'systemd/resolved.conf.d/cdnfoundry.conf').exists() is healthy


@pytest.mark.parametrize('role,ipv4,ipv6', [('edge','0.0.0.0','::'), ('control','0.0.0.0','::'), ('dns','192.0.2.1','2001:db8::1')])
def test_resolver_is_untouched_when_the_role_or_bind_does_not_need_it(tmp_path, role, ipv4, ipv6):
    marker = tmp_path / 'inspected'
    binary = tmp_path / 'ss'
    binary.write_text(f'#!/bin/sh\ntouch {marker}\nexit 99\n')
    binary.chmod(0o755)
    result = subprocess.run(['sh',str(SCRIPT.with_name('host-dns.sh')),role,ipv4,ipv6],env={**os.environ,'PATH':str(tmp_path)+':'+os.environ['PATH']},capture_output=True,text=True)
    assert result.returncode == 0, result.stderr
    assert not marker.exists()
