#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

export CONTROL_HOSTNAME=control.ops.example.com
export TELEMETRY_HOSTNAME=telemetry.ops.example.com
export GRAFANA_HOSTNAME=grafana.ops.example.com
export CONTROL_PUBLIC_IPV4_ALLOWLIST="198.51.100.10 198.51.100.11"
export EDGE_PUBLIC_IPV4_ALLOWLIST="198.51.100.20 198.51.100.30 198.51.100.40"
export LOG_SOURCE_IPV4_ALLOWLIST="198.51.100.10 198.51.100.20 198.51.100.30 198.51.100.40 198.51.100.50"
export CONTROL_PUBLIC_IPV6_ALLOWLIST="2001:db8::10"
export EDGE_PUBLIC_IPV6_ALLOWLIST="2001:db8::20 2001:db8::30"
export LOG_SOURCE_IPV6_ALLOWLIST="2001:db8::10 2001:db8::20 2001:db8::30"
export HOST_BIND_IPV4=0.0.0.0
export HOST_BIND_IPV6=::
export EDGE_CONTROL_BIND=0.0.0.0:8443
export DNS_BIND_V4=0.0.0.0
export EDGE_GATEWAY_ADDRESS_MAP='{"198.51.100.120":"10.20.1.120","198.51.100.121":"10.20.1.121"}'
export DNS_API_HOSTNAME=dns-api-1.ops.example.com
export DNS_API_SERVER_CERTIFICATE=/etc/cdnfoundry/pki/dns-api-1.crt
export DNS_API_SERVER_PRIVATE_KEY=/etc/cdnfoundry/pki/dns-api-1.key

compose() {
    docker compose --env-file .env.prod.example -f compose.prod.yml "$@"
}

compose --profile control --profile telemetry --profile logs config --quiet
compose --profile dns --profile edge --profile logs config --quiet
compose --profile tools config --quiet

test "$(find deploy/production -maxdepth 1 -name 'compose*.yml' -print -quit)" = ""
echo "production_compose=passed"
