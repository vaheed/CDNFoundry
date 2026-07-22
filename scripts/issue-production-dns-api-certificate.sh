#!/bin/sh
set -eu

usage() {
    echo "Usage: $0 PKI_DIRECTORY OUTPUT_NAME DNS_API_HOSTNAME" >&2
    exit 2
}

[ "$#" -eq 3 ] || usage

pki_directory=$1
output_name=$2
dns_api_hostname=$3

case "$output_name" in
    ''|*[!A-Za-z0-9._-]*)
        echo "OUTPUT_NAME may contain only letters, numbers, dots, underscores, and hyphens." >&2
        exit 2
        ;;
esac

case "$dns_api_hostname" in
    ''|*[!A-Za-z0-9.-]*|.*|*.)
        echo "DNS_API_HOSTNAME must be a plain DNS hostname." >&2
        exit 2
        ;;
esac

command -v openssl >/dev/null 2>&1 || { echo "openssl is required" >&2; exit 1; }
[ -r "$pki_directory/edge-server-ca.crt" ] || { echo "Missing edge-server-ca.crt" >&2; exit 1; }
[ -r "$pki_directory/edge-server-ca.key" ] || { echo "Missing edge-server-ca.key" >&2; exit 1; }

for suffix in key csr crt; do
    [ ! -e "$pki_directory/$output_name.$suffix" ] || {
        echo "Refusing to overwrite $pki_directory/$output_name.$suffix" >&2
        exit 1
    }
done

umask 077
openssl ecparam -name prime256v1 -genkey -noout -out "$pki_directory/$output_name.key"
openssl req -new -sha256 \
    -key "$pki_directory/$output_name.key" \
    -subj "/CN=$dns_api_hostname" \
    -out "$pki_directory/$output_name.csr"
openssl x509 -req -sha256 -days 3650 \
    -in "$pki_directory/$output_name.csr" \
    -CA "$pki_directory/edge-server-ca.crt" \
    -CAkey "$pki_directory/edge-server-ca.key" \
    -set_serial "0x$(openssl rand -hex 16)" \
    -extfile /dev/stdin \
    -out "$pki_directory/$output_name.crt" <<EOF
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:$dns_api_hostname
EOF
rm "$pki_directory/$output_name.csr"
chmod 600 "$pki_directory/$output_name.key"
chmod 644 "$pki_directory/$output_name.crt"
openssl verify -CAfile "$pki_directory/edge-server-ca.crt" "$pki_directory/$output_name.crt"
echo "Created $output_name.crt and $output_name.key for $dns_api_hostname."
