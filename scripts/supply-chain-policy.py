#!/usr/bin/env python3
"""Fail closed on CDNFoundry's locally verifiable supply-chain contract."""

from __future__ import annotations

import argparse
import json
import subprocess
import pathlib
import re
import shlex
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
DOCKERFILES = [
    ROOT / "core/Dockerfile", ROOT / "edge-agent/Dockerfile", ROOT / "edge-gateway/Dockerfile",
    ROOT / "docker/nginx/Dockerfile.production", ROOT / "docker/openresty/Dockerfile",
    ROOT / "docker/mmdb-updater/Dockerfile", ROOT / "docker/grafana/Dockerfile", ROOT / "docker/loki/Dockerfile",
    ROOT / "docker/caddy/Dockerfile",
    ROOT / "docker/dnsdist/Dockerfile",
    ROOT / "docker/pdns/Dockerfile",
    ROOT / "docker/prometheus/Dockerfile",
    ROOT / "docker/alertmanager/Dockerfile",
    ROOT / "docker/node-exporter/Dockerfile",
    ROOT / "docker/vector-runtime/Dockerfile",
    ROOT / "docker/postgres/Dockerfile",
]
REQUIRED_LABELS = ["image.source", "image.revision", "image.version", "image.created", "image.description", "image.licenses"]


def fail(message: str) -> None:
    print(f"supply-chain-policy: {message}", file=sys.stderr)
    raise SystemExit(1)


def validate_image_references(source: str, *, internal_core: bool = False) -> None:
    """Validate logical Dockerfile instructions; ARG defaults are build inputs too.

    The sole local-image exception is the core stage copied by our ingress
    Dockerfile. CI builds it from the same checkout before building ingress.
    Arbitrary unresolved ARGs, external COPY sources and forward stage aliases
    are never treated as internal images.
    """
    arguments: dict[str, str] = {}
    stages: set[str] = set()
    stage_count = 0

    def pinned(reference: str, *, copy_source: bool = False) -> None:
        original = reference
        reference = re.sub(r"\$\{([A-Za-z_][A-Za-z0-9_]*)\}|\$([A-Za-z_][A-Za-z0-9_]*)",
                           lambda match: arguments.get(match[1] or match[2], match[0]), reference)
        if internal_core and original == "${CORE_IMAGE}" and reference == "cdnfoundry/core:ci":
            return
        if reference == "scratch" or reference.lower() in stages:
            return
        if copy_source and reference.isdigit() and int(reference) < stage_count:
            return
        if not re.fullmatch(r"[^\s$@]+@sha256:[0-9a-f]{64}", reference):
            raise ValueError(f"unpinned image reference {original}")

    for line in re.sub(r"\\\r?\n", " ", source).splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue
        parts = stripped.split(None, 1)
        instruction, body = parts[0], parts[1] if len(parts) == 2 else ""
        instruction = instruction.upper()
        tokens = shlex.split(body)
        if instruction == "ARG":
            for token in tokens:
                name, separator, default = token.partition("=")
                if separator:
                    arguments[name] = default
        elif instruction == "FROM":
            while tokens and tokens[0].startswith("--"):
                flag = tokens.pop(0)
                if "=" not in flag:
                    if not tokens:
                        raise ValueError("missing FROM flag value")
                    tokens.pop(0)
            if not tokens:
                raise ValueError("missing FROM image")
            pinned(tokens[0])
            if len(tokens) > 1:
                if len(tokens) != 3 or tokens[1].upper() != "AS":
                    raise ValueError("invalid FROM stage alias")
                stages.add(tokens[2].lower())
            stage_count += 1
        elif instruction in {"COPY", "ADD", "RUN"}:
            for index, token in enumerate(tokens):
                if token.startswith("--from="):
                    pinned(token.split("=", 1)[1], copy_source=True)
                elif token == "--from":
                    if index + 1 == len(tokens):
                        raise ValueError("missing COPY source")
                    pinned(tokens[index + 1], copy_source=True)
                elif token.startswith("--mount="):
                    for option in token.removeprefix("--mount=").split(","):
                        if option.startswith("from="):
                            pinned(option.split("=", 1)[1], copy_source=True)


def validate_git_dependencies(source: str) -> None:
    """Every cloned dependency must select a full immutable commit before use."""
    logical = re.sub(r"\\\r?\n", " ", source)
    arguments: dict[str, str] = {}
    for line in logical.splitlines():
        if re.match(r"\s*ARG\s", line, re.I):
            for token in shlex.split(line.strip().split(None, 1)[1]):
                name, separator, value = token.partition("=")
                if separator:
                    arguments[name] = value
        for clone in re.finditer(r"\bgit\s+clone\s+([^&;\n]+)", line):
            tokens = shlex.split(clone[1])
            if len(tokens) < 2 or not tokens[-1].startswith('/'):
                raise ValueError("Git clone must have an explicit absolute destination")
            destination = tokens[-1]
            following = line[clone.end():]
            checkout = re.search(r"\bgit\s+-C\s+"+re.escape(destination)+r"\s+checkout\s+(?:--detach\s+)?([^&;\n]+)", following)
            if checkout is None:
                raise ValueError("Git dependency lacks an explicit immutable checkout")
            values = shlex.split(checkout[1])
            if len(values) != 1:
                raise ValueError("Git checkout must select exactly one commit")
            value = re.sub(r"\$\{([A-Z0-9_]+)\}|\$([A-Z0-9_]+)",
                           lambda m: arguments.get(m[1] or m[2], m[0]), values[0])
            if not re.fullmatch(r"[0-9a-f]{40}", value):
                raise ValueError("Git dependency checkout is not a full immutable commit")


def validate_compose_images(document: dict) -> None:
    components = {'CORE', 'WEB', 'EDGE_CONTROL', 'EDGE_RUNTIME', 'EDGE_AGENT', 'EDGE_GATEWAY', 'MMDB_UPDATER', 'GRAFANA', 'LOKI', 'POSTGRES', 'VECTOR', 'NODE_EXPORTER', 'ALERTMANAGER', 'PROMETHEUS', 'PDNS', 'DNSDIST', 'CADDY'}
    for name, service in document.get('services', {}).items():
        reference = service.get('image')
        if reference is None:
            continue
        variable = re.fullmatch(r'\$\{CDNF_([A-Z_]+)_IMAGE:\?[^}]+\}', str(reference))
        if variable and variable[1] in components:
            # Supplied deployment references are checked by generated validate.sh.
            continue
        if not re.fullmatch(r'[^\s$@]+@sha256:[0-9a-f]{64}', str(reference)):
            raise ValueError(f'Production Compose service {name} has a mutable image')


def production_dependency_images(root: pathlib.Path) -> list[str]:
    import yaml
    references: set[str] = set()
    for path in [root / 'compose.prod.yml', *sorted((root / 'deploy/production').glob('*.yml'))]:
        document = yaml.safe_load(path.read_text())
        validate_compose_images(document)
        references.update(service['image'] for service in document.get('services', {}).values()
                          if service.get('image') and not service['image'].startswith('${'))
    if not references:
        raise ValueError('No production dependency images discovered')
    return sorted(references)


def production_release_images(root: pathlib.Path, release: str) -> list[str]:
    import yaml
    if not re.fullmatch(r'ci|[0-9a-f]{40}', release):
        raise ValueError('Release scan requires ci or a full source commit')
    components: set[str] = set()
    for path in [root / 'compose.prod.yml', *sorted((root / 'deploy/production').glob('*.yml'))]:
        document = yaml.safe_load(path.read_text())
        validate_compose_images(document)
        for service in document.get('services', {}).values():
            match = re.fullmatch(r'\$\{CDNF_([A-Z_]+)_IMAGE:\?[^}]+\}', service.get('image', ''))
            if match:
                components.add(match[1].lower().replace('_', '-'))
    if not components:
        raise ValueError('No production release images discovered')
    return [f'ghcr.io/vaheed/cdnfoundry-{component}:{release}' for component in sorted(components)]


def scan_production_dependencies(output: pathlib.Path, *, release: str | None = None) -> None:
    output = output.resolve()
    output.mkdir(parents=True, exist_ok=True, mode=0o700)
    scanner = 'aquasec/trivy:0.66.0@sha256:086971aaf400beebd94e8300fd8ea623774419597169156cec56eec5b00dfb1e'
    cache = output / 'cache'
    cache.mkdir(exist_ok=True)
    base = ['docker', 'run', '--rm', '--memory', '1g',
            '-v', '/var/run/docker.sock:/var/run/docker.sock:ro',
            '-v', f'{ROOT}:/work:ro', '-v', f'{output}:/out',
            '-v', f'{cache}:/root/.cache', scanner]
    results = []
    references = production_dependency_images(ROOT) if release is None else production_release_images(ROOT, release)
    for index, reference in enumerate(references, 1):
        name = f'{"dependency" if release is None else "release"}-{index:02}'
        # Locally built release images must exist; pulling a similarly named
        # registry tag would qualify different bytes from this checkout.
        commands = ([['docker', 'pull', reference]] if release is None else []) + [
            [*base, 'image', '--image-src', 'docker', '--config', '/work/supply-chain/trivy.yaml',
             '--format', 'json', '--output', f'/out/{name}.json', '--exit-code', '0', reference],
            [*base, 'convert', '--ignorefile', '/dev/null', '--format', 'table', '--output', f'/out/{name}.txt', f'/out/{name}.json'],
            [*base, 'convert', '--ignorefile', '/work/supply-chain/trivy-classifications.yaml', '--show-suppressed',
             '--format', 'json', '--output', f'/out/{name}.classified.json', f'/out/{name}.json'],
            [*base, 'convert', '--exit-code', '1', '--exit-on-eol', '1', '--severity', 'HIGH,CRITICAL',
             '--ignorefile', '/work/supply-chain/trivy-classifications.yaml', '--show-suppressed', f'/out/{name}.json'],
        ]
        result = {'image': reference, 'report': f'{name}.json', 'commands': [], 'status': 'passed'}
        for command in commands:
            try:
                code = subprocess.run(command, check=False, timeout=900).returncode
            except subprocess.TimeoutExpired:
                code = 124
            result['commands'].append({'command': command, 'exit_code': code})
            if code:
                result['status'] = 'failed'
                break
        results.append(result)
        (output / 'summary.json').write_text(json.dumps({'schema_version': 1, 'results': results}, indent=2)+'\n')
        print(f"production_dependency_scan={result['status']} image={reference}", flush=True)
    if any(result['status'] != 'passed' for result in results):
        fail(f'production dependency scan failed; inspect {output / "summary.json"}')


def main() -> None:
    import yaml
    for path in [ROOT / 'compose.prod.yml', *sorted((ROOT / 'deploy/production').glob('*.yml'))]:
        try:
            validate_compose_images(yaml.safe_load(path.read_text()))
        except ValueError as error:
            fail(f'{path.relative_to(ROOT)}: {error}')
    for path in DOCKERFILES:
        text = path.read_text()
        try:
            validate_image_references(text, internal_core=path == ROOT / "docker/nginx/Dockerfile.production")
            validate_git_dependencies(text)
        except ValueError as error:
            fail(f"{path.relative_to(ROOT)}: {error}")
        for label in REQUIRED_LABELS:
            if f"org.opencontainers.{label}" not in text:
                fail(f"{path.relative_to(ROOT)} lacks OCI label org.opencontainers.{label}")
        if "openresty.org/download/" in text:
            if "sha256sum -c" not in text:
                fail("OpenResty source archive lacks checksum verification")
            for option in ["--fail", "--retry", "--retry-all-errors", "--connect-timeout"]:
                if option not in text:
                    fail(f"OpenResty source archive download lacks {option}")

    for workflow in (ROOT / ".github/workflows").glob("*.yml"):
        text = workflow.read_text()
        for action in re.findall(r"uses:\s*([^\s#]+)", text):
            if not re.search(r"@[0-9a-f]{40}$", action):
                fail(f"{workflow.relative_to(ROOT)} uses mutable action {action}")

    for manifest, lockfile in [("core/composer.json", "core/composer.lock"), ("core/package.json", "core/package-lock.json"), ("docs/package.json", "docs/package-lock.json")]:
        if not (ROOT / manifest).is_file() or not (ROOT / lockfile).is_file():
            fail(f"{manifest} lacks required lockfile {lockfile}")
    for module in [ROOT / "edge-agent/go.mod", ROOT / "edge-gateway/go.mod", ROOT / "docker/caddy/go.mod",
                   ROOT / "docker/alertmanager/upstream.go.mod", ROOT / "docker/prometheus/upstream.go.mod",
                   ROOT / "docker/node-exporter/upstream.go.mod"]:
        if "require " in module.read_text() and not module.with_suffix(".sum").is_file():
            fail(f"{module.relative_to(ROOT)} has dependencies but no go.sum")

    release = (ROOT / ".github/workflows/ci.yml").read_text()
    for evidence in ["syft", "trivy", "sign --yes", "attest --yes", "release-manifest.json", "@sha256:"]:
        if evidence not in release:
            fail(f"release workflow lacks {evidence}")
    if 'scripts/supply-chain-policy.py --scan-release-images ci' not in release:
        fail('release workflow lacks the image gate before publication')
    for evidence in ['convert --exit-code 1 --exit-on-eol 1 --severity HIGH,CRITICAL', 'convert --ignorefile /dev/null --format table', '--exit-code 0 "${digest}"',
                     '--ignorefile /work/supply-chain/trivy-classifications.yaml --show-suppressed']:
        if evidence not in release:
            fail(f"release scan lacks complete reporting and enforcement: {evidence}")
    if "push_with_retry()" not in release or release.count('docker push "${image}"') != 1:
        fail("release workflow does not route all image pushes through bounded retries")
    if re.search(r"\\[ \t]+(?:>>|>)", release):
        fail("release workflow has an escaped whitespace sequence before an output redirection")
    if "Normalize release evidence permissions" not in release:
        fail("release workflow does not make container-generated evidence readable before upload")
    if re.search(r"^\s+chmod .*release-manifest\.sigstore", release, re.MULTILINE):
        fail("release workflow changes root-owned Cosign evidence without normalizing ownership first")

    example = json.loads((ROOT / "supply-chain/release-manifest.example.json").read_text())
    for image in example.get("images", []):
        if not re.search(r"@sha256:[0-9a-f]{64}$", image.get("image", "")):
            fail("release-manifest example contains a mutable image reference")

    print(f"supply_chain_policy=passed dockerfiles={len(DOCKERFILES)} workflows=2")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    scans = parser.add_mutually_exclusive_group()
    scans.add_argument('--scan-production-dependencies', action='store_true')
    scans.add_argument('--scan-release-images', metavar='RELEASE')
    parser.add_argument('--output', type=pathlib.Path, default=ROOT / 'storage/qualification/production-dependencies')
    args = parser.parse_args()
    main()
    if args.scan_production_dependencies:
        scan_production_dependencies(args.output)
    elif args.scan_release_images:
        scan_production_dependencies(args.output, release=args.scan_release_images)
