#!/bin/sh
set -eu

usage() {
    echo "Usage: $0 OUTPUT_DIRECTORY CONTROL_HOSTNAME RUNTIME_HOSTNAME [CONTROL_IP] [RUNTIME_IP] [DNS_API_HOSTNAME ...]" >&2
    exit 2
}

[ "$#" -ge 3 ] || usage

output_directory=$1
control_hostname=$2
runtime_hostname=$3
shift 3
control_ip=${1:-}
[ "$#" -eq 0 ] || shift
runtime_ip=${1:-}
[ "$#" -eq 0 ] || shift

command -v openssl >/dev/null 2>&1 || { echo "openssl is required" >&2; exit 1; }
[ ! -e "$output_directory" ] || { echo "Refusing to overwrite existing directory: $output_directory" >&2; exit 1; }

umask 077
mkdir -p "$output_directory"

add_ip_san() {
    [ -z "$1" ] || printf ',IP:%s' "$1"
}

control_san="DNS:$control_hostname$(add_ip_san "$control_ip")"
runtime_san="DNS:$runtime_hostname$(add_ip_san "$runtime_ip")"

issue_ca() {
    name=$1
    common_name=$2
    openssl ecparam -name prime256v1 -genkey -noout -out "$output_directory/$name.key"
    openssl req -x509 -new -sha256 -days 3650 \
        -key "$output_directory/$name.key" -subj "/CN=$common_name" \
        -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
        -addext "keyUsage=critical,keyCertSign,cRLSign" \
        -out "$output_directory/$name.crt"
}

issue_ca edge-identity-ca "CDNFoundry Edge Identity CA"
issue_ca edge-server-ca "CDNFoundry Edge Server CA"

issue_server_certificate() {
    name=$1
    common_name=$2
    san=$3
    openssl ecparam -name prime256v1 -genkey -noout -out "$output_directory/$name.key"
    openssl req -new -sha256 -key "$output_directory/$name.key" -subj "/CN=$common_name" -out "$output_directory/$name.csr"
    openssl x509 -req -sha256 -days 3650 \
        -in "$output_directory/$name.csr" \
        -CA "$output_directory/edge-server-ca.crt" \
        -CAkey "$output_directory/edge-server-ca.key" \
        -CAcreateserial \
        -extfile /dev/stdin \
        -out "$output_directory/$name.crt" <<EOF
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=$san
EOF
    rm "$output_directory/$name.csr"
}

issue_server_certificate edge-control-server "$control_hostname" "$control_san"
issue_server_certificate edge-runtime "$runtime_hostname" "$runtime_san"

dns_api_index=1
for dns_api_hostname in "$@"; do
    issue_server_certificate "dns-api-$dns_api_index" "$dns_api_hostname" "DNS:$dns_api_hostname"
    dns_api_index=$((dns_api_index + 1))
done
rm "$output_directory/edge-server-ca.srl"
chmod 600 "$output_directory"/*.key
chmod 644 "$output_directory"/*.crt

set -- "$output_directory/edge-control-server.crt" "$output_directory/edge-runtime.crt"
certificate_index=1
while [ "$certificate_index" -lt "$dns_api_index" ]; do
    set -- "$@" "$output_directory/dns-api-$certificate_index.crt"
    certificate_index=$((certificate_index + 1))
done
openssl verify -CAfile "$output_directory/edge-server-ca.crt" "$@"
echo "Certificates created in $output_directory (valid for 3650 days)."
echo "Protect and back up both CA keys; copy only edge-server-ca.crt to edge hosts."
