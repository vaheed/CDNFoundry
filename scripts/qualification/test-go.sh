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
        golang:1.26.11-alpine@sha256:ae52b5bc6ed045a9ed26ab03dc19327a802a9cc9aa8b27ed57f5c6b60579b0bb \
        sh -ec 'unformatted="$(gofmt -l .)"; test -z "${unformatted}" || { printf "Unformatted Go files:\n%s\n" "${unformatted}" >&2; exit 1; }; go vet ./...; go test ./...; go build -o /tmp/cdnf-component .'
done
