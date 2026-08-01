---
title: Software supply-chain verification
description: Verify CDNFoundry image digests, signatures, SBOMs, provenance, scans, releases, and rollbacks.
---

# Software supply-chain verification

Production images are built only after all test jobs succeed. The protected
`production` environment grants the publishing job only `contents: read`,
`packages: write`, and OIDC `id-token: write`. Pull requests and non-main
branches cannot publish or sign.

Each release image is pushed by commit tag, resolved to its registry digest,
scanned, signed keylessly, and given SPDX JSON and SLSA provenance attestations.
Mutable channel tags are convenience aliases only. Deploy the `image` digest
from `release-manifest.json`.

## Verify a release

Download the `release-evidence-<commit>` artifact from the successful official
workflow run. Verify the signed manifest bundle:

```sh
cosign verify-blob \
  --bundle release-manifest.sigstore.json \
  --certificate-identity-regexp '^https://github.com/vaheed/CDNFoundry/.github/workflows/ci.yml@refs/(heads/main|tags/v.*)$' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  release-manifest.json
jq -e '.images | length == 9 and all(.image | test("@sha256:[0-9a-f]{64}$"))' release-manifest.json
```

For every digest in the manifest:

```sh
image='ghcr.io/vaheed/cdnfoundry-core@sha256:<digest>'
identity='https://github.com/vaheed/CDNFoundry/.github/workflows/ci.yml@refs/tags/v1.0.0'

cosign verify \
  --certificate-identity "$identity" \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  "$image"
cosign verify-attestation --type spdxjson \
  --certificate-identity "$identity" \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  "$image" | jq -r '.payload' | base64 -d | jq '.predicate'
cosign verify-attestation --type slsaprovenance \
  --certificate-identity "$identity" \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  "$image" | jq -r '.payload' | base64 -d | jq '.predicate'
```

Confirm the provenance source commit, workflow, builder identity, build type,
and subject digest match the manifest. The retained `*.spdx.json` and
`*.trivy.json` files are convenient copies; the digest-bound OCI attestations
are canonical.

## Vulnerability policy and exceptions

Trivy reports every severity in machine-readable JSON and fails publication on
any Critical vulnerability, whether fixed or unfixed, or on an end-of-life OS.
High findings require review before a tagged release. An exception must name
the CVE, affected component/digest, compensating control, owner, approval, and
an expiry no later than 30 days. Exceptions are narrow reviewed policy changes;
permanent wildcards and broad ignore files are forbidden. Scanner database
metadata is retained in each report.

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
