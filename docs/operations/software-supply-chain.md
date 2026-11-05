---
title: Software supply-chain verification
description: Verify CDNFoundry image digests, signatures, SBOMs, provenance, scans, releases, and rollbacks.
---

# Software supply-chain verification

You will check that the CDNFoundry images you deploy are the ones CI built, signed, and scanned. You need the `release-evidence-<commit>` artifact from the CI run, a checkout of the same commit, and `cosign` and `jq`.

Production images are built only after all test jobs succeed. The protected
`production` environment grants the publishing job only `contents: read`,
`packages: write`, and OIDC `id-token: write`. Publishing is permitted for
pushes to `main`, `dev`, and version tags after required checks pass. Pull
requests cannot publish or sign. Environment protection is an operator setting
and must be verified in the repository; the workflow file alone cannot prove it.

Each release image is pushed by commit tag, resolved to its registry digest,
scanned, signed keylessly, and given an SPDX JSON attestation and a SLSA provenance
attestation. GitHub's attestation action generates the provenance from the workflow
run; the workflow does not assemble it by hand.
Mutable channel tags are convenience aliases only. Deploy the `image` digest
from `release-manifest.json`.

The release contains only the seven CDNFoundry-built images: `core`, `web`,
`edge-control`, `edge-runtime`, `edge-agent`, `edge-gateway`, and `mmdb-updater`.
Supply-chain verification, scanning, signing, and provenance cover these images
only.

Upstream software is not built, scanned, or signed by this project. This covers Caddy, PostgreSQL, Vector, Prometheus, Alertmanager, node-exporter, Grafana, Loki, ClickHouse, PowerDNS, and dnsdist. Compose references each upstream image by its readable tag and exact registry digest, for example `prom/prometheus:v3.13.3@sha256:...`. It mounts the configuration files that CDNFoundry owns. The publishers maintain those images. To upgrade one, change its pinned digest in Compose and qualify the result.

PostgreSQL uses the official image and entrypoint with its data directory unchanged. Make a verified backup before you upgrade an existing installation.

Vector uses the official `timberio/vector` image. Vector 0.58 disables environment interpolation by default. The two operator-controlled Vector services set `VECTOR_DANGEROUSLY_ALLOW_ENV_VAR_INTERPOLATION=true` for their endpoint, authentication, and metadata variables. Keep those configuration files operator-owned and read-only to workloads. Never load tenant-supplied configuration into Vector.

Grafana loads the ClickHouse data source plugin from a pinned download. An init step in `compose.prod.yml` fetches `grafana-clickhouse-datasource` 4.21.3 and checks its SHA-256 before unpacking it.

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
  (.images | length == 7 and all(.image | test("@sha256:[0-9a-f]{64}$")))' release-manifest.json
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
              'edge-gateway', 'mmdb-updater'}
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
any unclassified High or Critical finding, whether fixed or unfixed, or on an
end-of-life OS. The scan uses an empty ignore file, retains every finding in JSON
and a readable table, then evaluates that same JSON with `convert`. Approved
classifications apply only during conversion. Additional `*.classified.json`
reports retain the excluded findings, reasons and policy path through Trivy's
`ExperimentalModifiedFindings` field; the original reports remain unchanged.
Supply-chain scanning covers only images built from this repository. Upstream
images referenced by Compose (for example Grafana, Prometheus, or PostgreSQL)
are outside this scope; they are pinned by digest and maintained by their
publishers. The scan runs once per release image, in the compose gate, and
publication reuses that report. No High finding is accepted automatically. An exception must name
the CVE, affected component/digest, compensating control, owner, approval, and
an expiry no later than 30 days. Exceptions are narrow reviewed policy changes;
permanent wildcards and broad ignore files are forbidden. Keep scanner version and database freshness metadata with the reports.

### Recorded exception: Tempo findings in Grafana

`supply-chain/trivy-classifications.yaml` holds two owner-approved classifications for CVE-2026-21728 and CVE-2026-28377. The owner approved them on 2026-09-19 and renewed them on 2026-10-29. They expire on **2026-11-28**. After that date the scanner blocks these findings again.

Each classification matches only the Grafana backend at `usr/share/grafana/bin/grafana` and exactly `github.com/grafana/tempo@v1.5.1-0.20260427112133-525d1bab07e0`. The scanner compares a Go pseudo-version with product 2.x versions, so it still reports both fixes as missing. Grafana links only the Tempo protobuf packages, not the affected server. The pinned source contains both fixes:

| Finding | Evidence that the pinned source is fixed |
| --- | --- |
| CVE-2026-21728 | [Frontend configuration](https://github.com/grafana/tempo/blob/525d1bab07e0/modules/frontend/config.go) sets the search maximum to 256 × 1024. The pinned changelog includes the 2.10.2 fix. |
| CVE-2026-28377 | [S3 configuration](https://github.com/grafana/tempo/blob/525d1bab07e0/tempodb/backend/s3/config.go) uses `flagext.Secret` for the customer encryption key. The pinned changelog includes the 2.10.3 fix. |

This approval does not accept a running affected Tempo server, and it does not waive any other Grafana or plugin finding. Grafana is an upstream image that Compose pins by digest. The seven images this project builds do not ship it, so these classifications only matter if a scanned image contains that exact binary.

`python3 tests/e2e/trivy_classifications.py` exercises the pinned scanner. Matching findings are classified. Different versions, paths, or packages, unapproved findings, an expired approval, and an end-of-life OS still fail.

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

Obtain the evidence artifact from the exact successful workflow run. Inspect its source commit, and verify its bundle before you use any digest. An example manifest or an invented digest is not deployable release evidence. Retrieving a workflow artifact can need an authenticated GitHub session, even for a public repository. You do not need the GitHub CLI.

CI publishes only from push events on `main`, `dev`, or version tags (`v*`). It publishes after the required jobs pass and the protected `production` environment allows it. Match the exact workflow ref of the selected release when you verify image evidence. The verification commands use an exact `--certificate-identity` for that ref. Provenance uses `slsaprovenance1`, which matches the workflow's SLSA v1 predicate, not the older `slsaprovenance` type.

## Scan before publication

The compose qualification job scans every release image built locally from the production Compose files and role overrides, before publication can start. It never pulls a replacement for a missing local build. A failed scan keeps its full evidence, continues the inventory, and then fails the job. No publishing job runs until all required jobs succeed. Download the `release-image-scans` artifact for these reports. The publication job also scans the registry digests and keeps the signed release evidence separately.
