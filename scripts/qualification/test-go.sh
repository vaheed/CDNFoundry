#!/usr/bin/env bash
set -euo pipefail

repository="$(pwd)"
mapfile -t modules < <(find . -name go.mod -not -path './.git/*' -printf '%h\n' | sort -u)
for module in "${modules[@]}"; do
    module="${module#./}"
    docker run --rm \
        --mount "type=bind,source=${repository},target=/src,readonly" \
        --workdir "/src/${module}" \
        golang:1.26.8-alpine@sha256:ce864e7223ac17b1775e6fd0b4c0db580c2eb50e7953a427916379e4b92a1628 \
        sh -ec 'unformatted="$(gofmt -l .)"; test -z "${unformatted}" || { printf "Unformatted Go files:\n%s\n" "${unformatted}" >&2; exit 1; }; go vet ./...; go test ./...; go build -o /tmp/cdnf-component .'
done
