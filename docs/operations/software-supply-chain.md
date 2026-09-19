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

The release includes the nine application components, managed Caddy ingress, PostgreSQL, Vector, the three Prometheus monitoring components, and both DNS services.
Caddy 2.11.4 is rebuilt with the committed Go module locks and patched Go
toolchain; all three ingress services use `CDNF_CADDY_IMAGE` from the same
verified manifest. Existing configuration and certificate volumes are retained.
Rebuilding upstream binaries makes their dependency updates our responsibility.

PostgreSQL 18.6 retains the official entrypoint and data directory. Its `gosu`
1.19 helper is rebuilt from the pinned upstream commit with Go 1.26.8.
All database and migration services use `CDNF_POSTGRES_IMAGE`; make a verified
backup before upgrading an existing installation.

Vector 0.58.0 uses a pinned Ubuntu 26.04 runtime with `journalctl`. Both traffic
and operational collectors use `CDNF_VECTOR_IMAGE`; their configuration paths
and persistent buffer volumes remain the same. Vector 0.58 disables environment
interpolation by default, so the two operator-controlled services explicitly set
`VECTOR_DANGEROUSLY_ALLOW_ENV_VAR_INTERPOLATION=true` for their existing endpoint,
authentication and metadata variables. These configuration files must remain
operator-owned and read-only to workloads; do not load tenant-supplied configuration.
HTTP JSON sources now use `decoding.codec: json`, replacing the removed `encoding`
option. The journal writer used by tests
is installed only in a disposable fixture container.

Prometheus 3.13.3, Alertmanager 0.34.1 and node exporter 1.12.1 are rebuilt
from pinned upstream commits with patched Go 1.26.8 and committed module locks.
The server and companion tools are both replaced. Prometheus/Alertmanager retain
the matching upstream UI asset archives, verified by checksum before embedding.

PowerDNS 5.1.4 and DNSdist 2.1.2 are built from signature-verified upstream
release archives with committed checksums. Their Alpine runtimes retain the
existing configuration, service identities, secret permissions and DNS features
used by this deployment. Both images are required in the verified manifest.

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
  (.images | length == 17 and all(.image | test("@sha256:[0-9a-f]{64}$")))' release-manifest.json
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
              'edge-gateway', 'mmdb-updater', 'grafana', 'loki', 'postgres', 'vector', 'node-exporter', 'alertmanager', 'prometheus', 'pdns', 'dnsdist', 'caddy'}
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

### Approved Tempo false-positive classification

Owner **vaheed** explicitly approved the following two classifications on
**2026-09-19**, expiring at **2026-10-19 UTC**. After expiry the scanner blocks
these findings again. Scope is the Grafana backend at
`usr/share/grafana/bin/grafana` and exactly
`github.com/grafana/tempo@v1.5.1-0.20260427112133-525d1bab07e0`.
The pinned Grafana 12.4.11 base digest is
`sha256:3ea272e5cab64a4a62240c682e2c62433b25614d956d44c299a10cb6994f6f2e`;
each derived release digest remains recorded in its scan metadata and signed
release manifest. Other package versions, binary paths and findings do not match.

| Finding | Evidence that the pinned source is fixed |
| --- | --- |
| CVE-2026-21728 | [Frontend configuration](https://github.com/grafana/tempo/blob/525d1bab07e0/modules/frontend/config.go) sets the search maximum to 256 × 1024; the pinned changelog includes the 2.10.2 fix. |
| CVE-2026-28377 | [S3 configuration](https://github.com/grafana/tempo/blob/525d1bab07e0/tempodb/backend/s3/config.go) uses `flagext.Secret` for the customer encryption key; the pinned changelog includes the 2.10.3 fix. |

Grafana's resolved OSS backend dependency graph additionally contains only four
Tempo protobuf packages (`pkg/tempopb` and its common/resource/trace subpackages),
not the affected server packages. Module checksums were verified; the source
checksum is `h1:kE5kdMnyPJmlNUdWhahfzqPYB3vM8DtAxgWymbB8nOA=`. The scanner compares
the Go pseudo-version against product 2.x versions and still reports both fixes
as missing. This approval accepts that classification evidence; it does not accept
running an affected Tempo server or waive the remaining Grafana/plugin findings.

The old unbounded kin-openapi ignore was removed: current Grafana uses 0.147.0,
after [the upstream 0.144.0 fix](https://github.com/advisories/GHSA-r277-6w6q-xmqw).
`python3 tests/e2e/trivy_classifications.py` exercises the actual pinned scanner:
matching findings are classified, while different versions/paths/packages,
unapproved findings, expiry and end-of-life OS still fail. No browser is involved.

### Approved rebuilt ClickHouse plugin

On **2026-09-19**, owner **vaheed** explicitly approved loading the rebuilt
`grafana-clickhouse-datasource` plugin under the signed application image's trust
boundary. Vendor plugin 4.21.3 still embeds Go 1.26.5 with eight High findings.
The image instead compiles the same upstream source commit
`551f9c4e32359f3844f659ce7ffd51fe7df77a07` with pinned Go 1.26.8, verifies module
checksums, and retains the checksum-pinned vendor frontend assets and license.

Rebuilding invalidates the vendor plugin manifest, so only that manifest is
removed and `GF_PLUGINS_ALLOW_LOADING_UNSIGNED_PLUGINS` names only
`grafana-clickhouse-datasource`. This deliberately replaces that plugin's vendor
signature check with verification of the immutable CDNFoundry image. It does
not permit arbitrary unsigned plugins or bypass image scan/signature gates.
The production root filesystem and bundled plugin directory remain read-only;
startup plugin downloads remain disabled. Verify the signed release manifest
and image digest before deployment. Return to the vendor-signed distribution
when a compatible, scan-qualified vendor build becomes available.

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

## Gate managed images before publication

The Compose qualification job scans every locally built release image discovered
from production Compose and role overrides before publication can start. This
includes managed infrastructure images as well as the application images. The
scan never pulls a replacement for a missing local build. A failed scan retains
its full evidence, continues the inventory, and fails the job; no publishing job
runs until all required jobs succeed. Retrieve `release-image-scans` for these
reports. The publication job still scans the registry digests and retains the
signed release evidence separately.
