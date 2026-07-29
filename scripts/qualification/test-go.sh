#!/usr/bin/env bash
set -euo pipefail

repository="$(pwd)"
mapfile -t modules < <(find . -name go.mod -not -path './.git/*' -printf '%h\n' | sort -u)
for module in "${modules[@]}"; do
    module="${module#./}"
    docker run --rm \
        --mount "type=bind,source=${repository},target=/src,readonly" \
        --workdir "/src/${module}" \
        golang:1.24-alpine \
        sh -ec 'unformatted="$(gofmt -l .)"; test -z "${unformatted}" || { printf "Unformatted Go files:\n%s\n" "${unformatted}" >&2; exit 1; }; go vet ./...; go test ./...; go build -o /tmp/cdnf-component .'
done
