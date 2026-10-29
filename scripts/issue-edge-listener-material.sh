#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: scripts/issue-edge-listener-material.sh --state-dir DIR --hostname NAME \
         --public-ipv4 ADDRESS --output DIR

Issues the two per-PoP files the generated bundle expects in /dev/shm/cdnfoundry:
  edge-runtime.crt   serving certificate signed by the fleet edge-server CA
  edge-runtime.key   its private key (mode 0600)
  edge-state-encryption.key   stable 64-hex recovery key (mode 0600), created once

Run it as root on the workstation that holds the fleet state. Copy the output to
the PoP's /dev/shm/cdnfoundry with owner 10101 and mode 0400. Existing keys are
kept; delete them deliberately to rotate. Secret values are never printed.
EOF
}

state_dir=""
hostname=""
public_ipv4=""
output=""
while (($#)); do
    case "$1" in
        --state-dir) state_dir=${2:?--state-dir requires a path}; shift 2 ;;
        --hostname) hostname=${2:?--hostname requires a name}; shift 2 ;;
        --public-ipv4) public_ipv4=${2:?--public-ipv4 requires an address}; shift 2 ;;
        --output) output=${2:?--output requires a path}; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
done
for value in state_dir hostname public_ipv4 output; do
    [[ -n "${!value}" ]] || { printf 'Missing --%s\n' "${value//_/-}" >&2; usage >&2; exit 2; }
done

ca_crt="$state_dir/pki/edge-server-ca.crt"
ca_key="$state_dir/pki/edge-server-ca.key"
for file in "$ca_crt" "$ca_key"; do
    [[ -f "$file" ]] || { printf 'Missing fleet CA file: %s\n' "$file" >&2; exit 1; }
done

umask 077
install -d -m 0700 "$output"
cert="$output/edge-runtime.crt"
key="$output/edge-runtime.key"
recovery="$output/edge-state-encryption.key"

if [[ ! -s "$key" || ! -s "$cert" ]]; then
    work=$(mktemp -d "$output/.issue.XXXXXX")
    trap 'rm -rf "$work"' EXIT
    openssl ecparam -name prime256v1 -genkey -noout -out "$work/edge-runtime.key"
    openssl req -new -sha256 -key "$work/edge-runtime.key" -subj "/CN=$hostname" -out "$work/req.csr"
    printf '%s\n' \
        'basicConstraints=critical,CA:FALSE' \
        'keyUsage=critical,digitalSignature,keyEncipherment' \
        'extendedKeyUsage=serverAuth,clientAuth' \
        "subjectAltName=DNS:$hostname,IP:$public_ipv4" > "$work/ext.cnf"
    openssl x509 -req -sha256 -in "$work/req.csr" -CA "$ca_crt" -CAkey "$ca_key" -CAcreateserial \
        -out "$work/edge-runtime.crt" -days 825 -extfile "$work/ext.cnf" >/dev/null 2>&1
    chmod 0600 "$work/edge-runtime.key"
    chmod 0644 "$work/edge-runtime.crt"
    mv -f "$work/edge-runtime.key" "$key"
    mv -f "$work/edge-runtime.crt" "$cert"
    rm -rf "$work"
    trap - EXIT
fi

if [[ ! -s "$recovery" ]]; then
    openssl rand -hex 32 > "$recovery"
    chmod 0600 "$recovery"
fi

openssl verify -CAfile "$ca_crt" "$cert" >/dev/null
certificate_public="$(openssl x509 -in "$cert" -pubkey -noout | openssl pkey -pubin -outform DER | sha256sum)"
private_public="$(openssl pkey -in "$key" -pubout -outform DER | sha256sum)"
[[ "$certificate_public" == "$private_public" ]] || { printf 'Certificate and key do not match.\n' >&2; exit 1; }
[[ "$(wc -c < "$recovery")" -ge 64 && "$(wc -c < "$recovery")" -le 65 ]] || { printf 'Recovery key must be 64 hex characters.\n' >&2; exit 1; }
printf 'edge listener material ready: %s\n' "$output"
