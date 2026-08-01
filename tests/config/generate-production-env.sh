#!/bin/sh
set -eu

cd "$(dirname "$0")/../.."

test_dir=$(mktemp -d)
trap 'rm -rf "$test_dir"' EXIT

bash -n scripts/generate-production-env.sh

printf '\n\n\nv0.9.1\n203.0.113.10\n\n\n\nops@example.com\n203.0.113.20 203.0.113.30\n\ntelemetry-test-secret\n' \
    | scripts/generate-production-env.sh --output "$test_dir/control.env" >/dev/null

test "$(stat -c '%a' "$test_dir/control.env")" = 600
grep -Fqx 'HOST_BIND_IPV4=0.0.0.0' "$test_dir/control.env"
grep -Fqx 'HOST_BIND_IPV6=::' "$test_dir/control.env"
grep -Fqx 'DNS_BIND_V4=0.0.0.0' "$test_dir/control.env"
grep -Fqx 'EDGE_CONTROL_BIND=0.0.0.0:8443' "$test_dir/control.env"
grep -Fqx 'CONTROL_PUBLIC_IPV4_ALLOWLIST=203.0.113.10' "$test_dir/control.env"
grep -Fqx 'EDGE_PUBLIC_IPV4_ALLOWLIST=203.0.113.20 203.0.113.30' "$test_dir/control.env"
grep -Fqx 'RESTIC_REPOSITORY=' "$test_dir/control.env"
grep -Fqx 'RESTIC_PASSWORD_FILE=' "$test_dir/control.env"
grep -Fqx 'BACKUP_ACCESS_KEY_ID=' "$test_dir/control.env"
grep -Fqx 'BACKUP_SECRET_ACCESS_KEY=' "$test_dir/control.env"
if grep -Eq '^PUBLIC_BIND_IPV[46]=' "$test_dir/control.env"; then
    echo 'Generator emitted a removed public bind variable.' >&2
    exit 1
fi

printf '\n\n\nv0.9.1\n203.0.113.11\n\n\n\nops@example.com\n203.0.113.21 203.0.113.31\nyes\ns3:https://objects.example.test/backups/cdnfoundry\n\nbackup-access\nbackup-secret\n\ntelemetry-test-secret\n' \
    | scripts/generate-production-env.sh --output "$test_dir/control-backup.env" >/dev/null

grep -Fqx 'RESTIC_REPOSITORY=s3:https://objects.example.test/backups/cdnfoundry' "$test_dir/control-backup.env"
grep -Fqx 'RESTIC_PASSWORD_FILE=/etc/cdnfoundry/secrets/restic-password' "$test_dir/control-backup.env"
grep -Fqx 'BACKUP_ACCESS_KEY_ID=backup-access' "$test_dir/control-backup.env"
grep -Fqx 'BACKUP_SECRET_ACCESS_KEY=backup-secret' "$test_dir/control-backup.env"
grep -Fqx 'BACKUP_DEFAULT_REGION=us-east-1' "$test_dir/control-backup.env"

printf 'dns-edge\n\n\nv0.9.1\n203.0.113.20\n\n\n203.0.113.10\nops@example.com\n\ntelemetry-test-secret\n' \
    | scripts/generate-production-env.sh --output "$test_dir/edge.env" >/dev/null

grep -Fqx 'HOST_BIND_IPV4=0.0.0.0' "$test_dir/edge.env"
grep -Fqx 'DNS_API_HOSTNAME=dns-api-1.ops.example.com' "$test_dir/edge.env"
grep -Fqx 'CONTROL_PUBLIC_IPV4_ALLOWLIST=203.0.113.10' "$test_dir/edge.env"
grep -Fqx 'RESTIC_REPOSITORY=' "$test_dir/edge.env"
grep -Fqx 'EDGE_GATEWAY_ADDRESS_MAP={}' "$test_dir/edge.env"

printf 'production_env_generator=passed\n'
