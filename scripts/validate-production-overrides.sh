#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

export CONTROL_HOSTNAME=control.ops.example.com
export TELEMETRY_HOSTNAME=telemetry.ops.example.com
export CONTROL_PUBLIC_IPV4_ALLOWLIST="198.51.100.10 198.51.100.11"
export EDGE_PUBLIC_IPV4_ALLOWLIST="198.51.100.20 198.51.100.30 198.51.100.40"
export LOG_SOURCE_IPV4_ALLOWLIST="198.51.100.10 198.51.100.20 198.51.100.30 198.51.100.40 198.51.100.50"
export PUBLIC_BIND_IPV4=198.51.100.10
export PUBLIC_BIND_IPV6=2001:db8:10::10
export EDGE_CONTROL_BIND=198.51.100.10:8443
export DNS_BIND_V4=198.51.100.20
export EDGE_HTTP_BIND=198.51.100.20:80
export EDGE_HTTPS_BIND=198.51.100.20:443
export DNS_API_HOSTNAME=dns-api-1.ops.example.com
export DNS_API_SERVER_CERTIFICATE=/etc/cdnfoundry/pki/dns-api-1.crt
export DNS_API_SERVER_PRIVATE_KEY=/etc/cdnfoundry/pki/dns-api-1.key

compose() {
    docker compose --env-file .env.prod.example -f compose.prod.yml "$@"
}

# IPv4-only configuration must not require a fake IPv6 value.
unset PUBLIC_BIND_IPV6
compose -f deploy/production/compose.control-host.yml --profile control --profile telemetry --profile logs config --quiet

export PUBLIC_BIND_IPV4=198.51.100.20
compose -f deploy/production/compose.dns-edge-host.yml --profile dns --profile edge --profile logs config --quiet
compose -f deploy/production/compose.dns-host.yml --profile dns --profile logs config --quiet
compose -f deploy/production/compose.edge-host.yml --profile edge --profile logs config --quiet

export PUBLIC_BIND_IPV4=198.51.100.50
compose -f deploy/production/compose.telemetry-host.yml --profile telemetry --profile logs config --quiet

# IPv6 publication is explicit and validated independently.
export PUBLIC_BIND_IPV6=2001:db8:50::50
compose -f deploy/production/compose.control-host.yml \
    -f deploy/production/compose.control-host-ipv6.yml \
    --profile control --profile telemetry config --quiet
compose -f deploy/production/compose.dns-edge-host.yml \
    -f deploy/production/compose.dns-host-ipv6.yml \
    -f deploy/production/compose.edge-host-ipv6.yml \
    --profile dns --profile edge config --quiet
compose -f deploy/production/compose.telemetry-host.yml \
    -f deploy/production/compose.telemetry-host-ipv6.yml \
    --profile telemetry config --quiet

export DB_URL='postgresql://cdnf:password@db.ops.example.com:5432/cdnf?sslmode=verify-full'
export REDIS_URL='tls://:password@redis.ops.example.com:6379'
compose -f deploy/production/compose.external-control-data.yml --profile control config --quiet

if compose -f deploy/production/compose.external-control-data.yml --profile control config --services \
    | grep -Eq '^(control-db|redis)$'; then
    echo "External control-data override unexpectedly enables a local data service." >&2
    exit 1
fi

echo "production_overrides=passed"
