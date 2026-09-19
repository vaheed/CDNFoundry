#!/usr/bin/env python3
"""Transfer exact, commit-labelled CI images without a second release build."""
from __future__ import annotations

import argparse
import gzip
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[2]
OUTPUT = ROOT / 'storage/ci-images'


def run(*args: str) -> str:
    return subprocess.check_output(args, cwd=ROOT, text=True).strip()


def targets(group: str = 'default') -> dict:
    return json.loads(run('docker', 'buildx', 'bake', '-f', 'docker-bake.hcl', '--print', group))['target']


def identity(image: str, revision: str) -> str:
    info = json.loads(run('docker', 'image', 'inspect', image))[0]
    if info['Config']['Labels'].get('org.opencontainers.image.revision') != revision:
        raise ValueError(f'Image revision does not match this workflow: {image}')
    return info['Id']


def checksum(path: Path) -> str:
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def export(group: str, revision: str) -> None:
    images = {spec['tags'][0]: identity(spec['tags'][0], revision) for spec in targets(group).values()}
    archive = OUTPUT / f'{group}.tar.gz'
    # Stream the archive: never keep compressed and uncompressed copies on disk.
    with gzip.open(archive, 'wb', compresslevel=1) as stream:
        with subprocess.Popen(['docker', 'save', *images], stdout=subprocess.PIPE) as process:
            shutil.copyfileobj(process.stdout, stream)
            if process.wait() != 0:
                raise RuntimeError('Docker image export failed')
    (OUTPUT / f'{group}.manifest.json').write_text(json.dumps({
        'revision': revision, 'archive': archive.name, 'sha256': checksum(archive), 'images': images,
    }, indent=2)+'\n')


def load(revision: str) -> None:
    expected = {spec['tags'][0] for spec in targets().values()}
    manifests = []
    seen: set[str] = set()
    for path in sorted(OUTPUT.glob('*.manifest.json')):
        manifest = json.loads(path.read_text())
        archive = OUTPUT / (path.name.removesuffix('.manifest.json') + '.tar.gz')
        images = set(manifest['images'])
        if (manifest['revision'] != revision or manifest['archive'] != archive.name
                or not images or images - expected or images & seen):
            raise ValueError(f'Unexpected or duplicate image manifest: {path.name}')
        if checksum(archive) != manifest['sha256']:
            raise ValueError(f'Image archive checksum mismatch: {archive.name}')
        seen.update(images)
        manifests.append((manifest, archive))
    if seen != expected:
        raise ValueError(f'Incomplete image artifacts; missing: {sorted(expected - seen)}')
    for manifest, archive in manifests:
        subprocess.run(['docker', 'load', '-i', str(archive)], check=True)
        for image, expected_id in manifest['images'].items():
            if identity(image, revision) != expected_id:
                raise ValueError(f'Image identity changed in transfer: {image}')
        archive.unlink()  # Recover runner space as soon as the verified image is loaded.
    run('docker', 'tag', 'ghcr.io/vaheed/cdnfoundry-core:ci', 'cdnfoundry/core:ci')


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['cache', 'export', 'load', 'tag'])
    parser.add_argument('group', nargs='?', default='default')
    args = parser.parse_args()
    OUTPUT.mkdir(parents=True, exist_ok=True)
    if args.action == 'cache':
        # Separate target scopes prevent the last image overwriting a group's cache.
        config = {'target': {name: {
            'cache-from': [f'type=gha,scope=production-{name}'],
            'cache-to': [f'type=gha,scope=production-{name},mode=max,timeout=3m,ignore-error=true'],
        } for name in targets(args.group)}}
        (OUTPUT / 'cache.json').write_text(json.dumps(config)+'\n')
        return
    revision = os.environ['SOURCE_REVISION']
    if args.action == 'export':
        export(args.group, revision)
    elif args.action == 'load':
        load(revision)
    else:
        for spec in targets().values():
            image = spec['tags'][0]
            identity(image, revision)
            run('docker', 'tag', image, image.removesuffix(':ci') + ':' + revision)


if __name__ == '__main__':
    main()
