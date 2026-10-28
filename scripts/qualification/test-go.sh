#!/usr/bin/env bash
set -euo pipefail

repository="$(pwd)"
modules=()
while IFS= read -r -d '' module_file; do
    modules+=("$(dirname "$module_file")")
done < <(git ls-files -z -- 'go.mod' '**/go.mod')
if (( ${#modules[@]} == 0 )); then
    echo 'No tracked Go modules found for qualification.' >&2
    exit 1
fi
for module in "${modules[@]}"; do
    docker run --rm \
        --mount "type=bind,source=${repository},target=/src,readonly" \
        --workdir "/src/${module}" \
        golang:1.26.10-alpine@sha256:a2012b4a1d1f9f7e9f44e0d39b805a901e856b1dd80b4e6ee4338db547050308 \
        sh -ec 'unformatted="$(gofmt -l .)"; test -z "${unformatted}" || { printf "Unformatted Go files:\n%s\n" "${unformatted}" >&2; exit 1; }; go vet ./...; go test ./...; go build -o /tmp/cdnf-component .'
done
