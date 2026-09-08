---
title: Software supply-chain verification
description: Verify CDNFoundry image digests, signatures, SBOMs, provenance, scans, releases, and rollbacks.
---

# Software supply-chain verification

Production images are built only after all test jobs succeed. The protected
`production` environment grants the publishing job only `contents: read`,
`packages: write`, and OIDC `id-token: write`. Publishing is permitted for
pushes to `main`, `dev`, and version tags after required checks pass. Pull
requests cannot publish or sign. Environment protection is an operator setting
and must be verified in the repository; the workflow file alone cannot prove it.

Each release image is pushed by commit tag, resolved to its registry digest,
scanned, signed keylessly, and given SPDX JSON and SLSA provenance attestations.
Mutable channel tags are convenience aliases only. Deploy the `image` digest
from `release-manifest.json`.

## Verify a release

Download the `release-evidence-<commit>` artifact from the successful official
workflow run. Select the exact source workflow ref from that run (for example, its full
`refs/tags/...` ref). Use that same exact identity for the manifest and all
image attestations; do not silently accept another branch's signing identity.
From the checked-out source directory, with the extracted evidence files in the
current directory:

```bash
read -r -p 'Exact workflow ref for the selected release: ' CDNF_WORKFLOW_REF
case "$CDNF_WORKFLOW_REF" in
  refs/heads/main|refs/heads/dev|refs/tags/v*) ;;
  *) echo 'Unsupported publishing ref' >&2; exit 1 ;;
esac
export CDNF_SOURCE_COMMIT="$(git rev-parse HEAD)"
identity="https://github.com/vaheed/CDNFoundry/.github/workflows/ci.yml@$CDNF_WORKFLOW_REF"
```

Verify the signed manifest bundle:

```bash
set -euo pipefail
cosign verify-blob \
  --bundle release-manifest.sigstore.json \
  --certificate-identity "$identity" \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  release-manifest.json
jq -e --arg commit "$CDNF_SOURCE_COMMIT" '.source_commit == $commit and
  (.images | length == 9 and all(.image | test("@sha256:[0-9a-f]{64}$")))' release-manifest.json
```

For every digest in the manifest:

```bash
set -euo pipefail
for image in $(jq -r '.images[].image' release-manifest.json); do

cosign verify \
  --certificate-identity "$identity" \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  "$image"
cosign verify-attestation --type spdxjson \
  --certificate-identity "$identity" \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  "$image" | jq -r '.payload' | base64 -d | jq '.predicate'
cosign verify-attestation --type slsaprovenance1 \
  --certificate-identity "$identity" \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  "$image" | jq -r '.payload' | base64 -d | jq '.predicate'
done
```

Confirm the provenance source commit, workflow, builder identity, build type,
and subject digest match the manifest. The retained `*.spdx.json` and
`*.trivy.json` files are convenient copies; the digest-bound OCI attestations
are canonical.

## Populate Fleet image references

After all signature/provenance checks above succeed, populate every node from
the same verified manifest. This projection does not verify signatures itself.
It rejects missing/duplicate components, a source mismatch, or conflicting
existing image references and writes a separate mode-0600 candidate file:

```bash
python3 - <<'PYTHON'
import json, os, re, subprocess
from pathlib import Path
def require(condition, message):
    if not condition:
        raise SystemExit(message)

manifest = json.loads(Path('release-manifest.json').read_text())
fleet = json.loads(Path('fleet.json').read_text())
commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
require(manifest['source_commit'] == commit, 'Manifest source differs from checkout')
components = {'core', 'web', 'edge-control', 'edge-runtime', 'edge-agent',
              'edge-gateway', 'mmdb-updater', 'grafana', 'loki'}
rows = manifest['images']
require(len(rows) == len(components) and {r['component'] for r in rows} == components,
        'Manifest components are missing or duplicated')
images = {}
for row in rows:
    require(re.fullmatch(r'ghcr.io/vaheed/cdnfoundry-'+re.escape(row['component'])+
                        r'@sha256:[0-9a-f]{64}', row['image']), 'Unexpected image publisher or digest')
    images['CDNF_'+row['component'].upper().replace('-', '_')+'_IMAGE'] = row['image']
fleet['global']['release'] = commit
for node in fleet['nodes']:
    require(node.get('release', commit) == commit, 'Resolve per-node release override first')
    extra = node.setdefault('extra_env', {})
    require(all(k not in extra or extra[k] == v for k, v in images.items()), 'Existing image conflict')
    extra.update(images)
fd = os.open('fleet.verified.json', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
with os.fdopen(fd, 'w') as output:
    output.write(json.dumps(fleet, indent=2)+'\n')
    output.flush()
    os.fsync(output.fileno())
print('fleet.verified.json created; use it for Fleet dry-run and setup')
PYTHON
./scripts/cdnfoundry-fleet --config fleet.verified.json --non-interactive --dry-run setup
```

Use `fleet.verified.json` for the subsequent `setup` command. A topology dry run
proves validation only. Generated `validate.sh` also checks all actual image
values before starting any container. Local image IDs support isolated tests;
production transfer requires portable registry digests and verified evidence.
If no signed manifest exists for the desired source, stop installation at this
gate; a source build or example manifest cannot substitute for release evidence.

## Vulnerability policy and exceptions

Trivy reports every severity in machine-readable JSON and fails publication on
any High or Critical vulnerability, whether fixed or unfixed, or on an end-of-life
OS. The scan runs once by digest, retains every severity in JSON and a readable
table, then evaluates High/Critical findings from that same JSON with `convert`.
The same gate scans all digest-pinned third-party production Compose images,
including role overrides, before publication. It is also an agent-owned check
in the production qualification runner:

```sh
python3 scripts/supply-chain-policy.py --scan-production-dependencies
```

The command retains complete JSON/table reports and a summary with commands
and exit codes under `storage/qualification/production-dependencies`. A failed
image does not skip scanning the remaining images. No High finding is accepted automatically. An exception must name
the CVE, affected component/digest, compensating control, owner, approval, and
an expiry no later than 30 days. Exceptions are narrow reviewed policy changes;
permanent wildcards and broad ignore files are forbidden. Keep scanner version and database freshness metadata with the reports.

## Updating dependencies and bases

1. Resolve the exact multi-platform manifest digest from the upstream registry
   and review the publisher/release notes.
2. Change the readable tag and `@sha256:` together. Never update a digest alone
   without confirming what it identifies.
3. For Git source inputs, resolve the signed release tag to its full commit SHA.
   For archives, download over HTTPS and update the committed SHA-256 only after
   independent verification.
4. Regenerate ecosystem lockfiles with their native package manager; do not
   hand-edit them.
5. Run `make supply-chain-check`, dependency audits, image builds, SBOM
   generation, and scans. Review the diff and resulting inventory.

Automated dependency-update proposals may detect stale digests, but a human
must review and merge every digest change. APK package repositories remain the
one non-byte-reproducible input: versions available under a pinned Alpine image
can advance. The release SBOM, image digest, scan, and provenance record the
actual result; rebuilding is expected to be functionally equivalent, not
necessarily byte-for-byte identical.

## Compromise response and rollback

If a dependency, workflow, action, or image is suspected:

1. Stop release publication and remove the affected digest from deployment
   manifests. Do not overwrite or trust a mutable tag.
2. Identify releases whose SBOM or provenance contains the affected input and
   preserve their evidence for investigation.
3. Rotate any exposed credentials, revoke affected certificates or identities,
   patch the pinned input, rebuild from a reviewed commit, and verify all gates.
4. Roll back by selecting every component digest from the most recent already
   verified release manifest. Never mix arbitrary tags across a fleet.
5. Confirm running digests with the command below and compare them to the
   selected manifest before restoring rollout.

   ```sh
   docker inspect --format '{{json .RepoDigests}}' <container-or-image>
   ```

Run `make supply-chain-check` locally to reject mutable production bases,
floating Git inputs/actions, missing checksums/labels/lockfiles, and incomplete
release evidence controls.

## Release availability and identity

The public release metadata inspected on 2026-09-05 listed `v0.9.7`, `v0.9.6`
and `v0.9.5` with no attached assets. This does not establish that a signed
manifest exists for this audit checkout. Obtain the artifact from the exact
successful workflow run, inspect its source commit, and verify its bundle before
using any digest. An example manifest or an invented digest is not deployable
release evidence. Workflow artifact retrieval may require an authenticated
GitHub session even for a public repository; no GitHub CLI is required.

Publishing is currently allowed only from push events on `main`, `dev`, or
version tags, after the required jobs pass and the protected production
publishing environment permits it. Match the exact workflow ref for the selected
release when verifying image evidence. The verification commands use an exact
`--certificate-identity` for the selected workflow ref. Provenance uses
`slsaprovenance1`, matching the workflow's SLSA v1 predicate, not the older
`slsaprovenance` predicate type.
