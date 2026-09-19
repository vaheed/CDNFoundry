#!/usr/bin/env bash
set -euo pipefail

# These are disposable GitHub-hosted VMs, never Fleet or development hosts.
if [[ "${GITHUB_ACTIONS:-}" != true || "${RUNNER_ENVIRONMENT:-}" != github-hosted || "${RUNNER_OS:-}" != Linux ]]; then
    echo 'Image runner preparation requires a disposable GitHub-hosted Linux runner.' >&2
    exit 1
fi

# Image builds use pinned container toolchains, not these preinstalled SDKs.
sudo rm -rf -- /usr/share/dotnet /usr/local/lib/android /opt/ghc \
    /usr/local/.ghcup /usr/local/swift /usr/share/swift /opt/hostedtoolcache/CodeQL
df -h /
