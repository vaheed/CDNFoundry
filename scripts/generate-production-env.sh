#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: scripts/generate-production-env.sh [--output PATH]

Interactively creates a mode-0600 CDNFoundry production environment file.
Run it once on each host. It refuses to overwrite an existing file.

Public or NAT addresses are advertised identities used for DNS records and
peer allowlists, not listener binds. Shared listeners use a local host address
(0.0.0.0 by default); edge service addresses use an explicit one-to-one local
mapping, so public addresses need not exist on hosts behind NAT or a load balancer.

Encrypted Restic control-plane backups are optional. The generator explains
the recommended S3-compatible setup and leaves backup settings empty when
disabled.
EOF
}

output=.env.prod
script_dir=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
template="$script_dir/../.env.prod.example"
while (($#)); do
    case "$1" in
        --output) output=${2:?--output requires a path}; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
done

if [[ -e "$output" ]]; then
    printf 'Refusing to overwrite %s\n' "$output" >&2
    exit 1
fi
command -v openssl >/dev/null || { printf 'openssl is required\n' >&2; exit 1; }

ask() {
    local name=$1 prompt=$2 default=${3-} value
    if [[ -n "$default" ]]; then
        read -r -p "$prompt [$default]: " value
        value=${value:-$default}
    else
        while [[ -z "${value:-}" ]]; do read -r -p "$prompt: " value; done
    fi
    printf -v "$name" '%s' "$value"
}

ask_optional() {
    local name=$1 prompt=$2 value
    read -r -p "$prompt (leave blank to disable): " value
    printf -v "$name" '%s' "$value"
}

ask_secret() {
    local name=$1 prompt=$2 value
    while [[ -z "${value:-}" ]]; do
        read -r -s -p "$prompt: " value
        printf '\n'
    done
    printf -v "$name" '%s' "$value"
}

ask_yes_no() {
    local name=$1 prompt=$2 default=${3:-no} value suffix
    [[ $default == yes ]] && suffix='Y/n' || suffix='y/N'
    while true; do
        read -r -p "$prompt [$suffix]: " value
        value=${value:-$default}
        case "${value,,}" in
            y|yes) printf -v "$name" '%s' yes; return ;;
            n|no) printf -v "$name" '%s' no; return ;;
            *) printf 'Enter yes or no.\n' >&2 ;;
        esac
    done
}

random_secret() { openssl rand -hex 32; }
valid_simple() { [[ $1 =~ ^[A-Za-z0-9._:@/+?=-]+$ ]]; }
valid_list() { [[ $1 =~ ^[0-9A-Fa-f:.]+([[:space:]][0-9A-Fa-f:.]+)*$ ]]; }

printf 'CDNFoundry production environment generator\n'
printf 'Secrets generated here are unique to this host unless you enter a shared value.\n\n'
ask role 'Host role (control or dns-edge)' control
[[ $role == control || $role == dns-edge ]] || { printf 'Role must be control or dns-edge\n' >&2; exit 2; }
ask ops_domain 'Operator domain (records are created at this existing DNS provider)' ops.example.com
ask platform_domain 'CDN platform domain' example.net
ask release 'Exact release tag or 40-character commit SHA'
ask advertised_ipv4 'Public/NAT IPv4 advertised for this host and seen by peer firewalls'
ask_optional advertised_ipv6 'Public/routed IPv6 advertised for this host'
ask host_bind_ipv4 'Local IPv4 listener address (use 0.0.0.0 behind NAT/firewall)' 0.0.0.0
ask control_ipv4 'Control-plane source IPv4 as seen by DNS/edge firewalls' "$advertised_ipv4"
ask acme_email 'ACME contact email'

for value in "$ops_domain" "$platform_domain" "$release" "$advertised_ipv4" "$advertised_ipv6" "$host_bind_ipv4" "$control_ipv4" "$acme_email"; do
    [[ -z $value ]] || valid_simple "$value" || { printf 'Unsupported character in input: %s\n' "$value" >&2; exit 2; }
done
[[ $host_bind_ipv4 != "$advertised_ipv4" ]] || {
    printf 'The local bind address must not be the advertised public/NAT IPv4; use 0.0.0.0 or a private host address.\n' >&2
    exit 2
}

if [[ $role == control ]]; then
    ask edge_allowlist 'Space-separated public/NAT source IPv4 addresses of all edge nodes'
    valid_list "$edge_allowlist" || { printf 'Invalid edge IPv4 allow-list\n' >&2; exit 2; }
    printf '\nOptional encrypted control-plane backups\n'
    printf 'RESTIC_REPOSITORY is the storage location, not an encryption secret.\n'
    printf 'The supported quick-start backend is S3-compatible object storage, for example:\n'
    printf '  s3:https://object-storage.example/bucket/cdnfoundry-control\n'
    printf 'Use a separate Restic password and credentials restricted to that bucket/prefix.\n'
    printf 'Other Restic backends require deployment-specific credentials or mounts.\n'
    ask_yes_no configure_backup 'Configure S3-compatible Restic backups now?' no
    if [[ $configure_backup == yes ]]; then
        ask restic_repository 'Restic S3 repository location'
        [[ $restic_repository == s3:* ]] || { printf 'Quick-start backup repository must start with s3:. Configure other backends manually.\n' >&2; exit 2; }
        ask restic_password_file 'Absolute host path to the Restic password file' /etc/cdnfoundry/secrets/restic-password
        ask backup_access_key 'Backup-only S3 access key ID'
        ask_secret backup_secret_key 'Backup-only S3 secret access key'
        ask backup_region 'S3 region' us-east-1
        for value in "$restic_repository" "$restic_password_file" "$backup_access_key" "$backup_region"; do
            valid_simple "$value" || { printf 'Unsupported character in backup input: %s\n' "$value" >&2; exit 2; }
        done
    else
        restic_repository=
        restic_password_file=
        backup_access_key=
        backup_secret_key=
        backup_region=us-east-1
    fi
    ask_secret clickhouse_password 'Shared telemetry ingestion password (store it in your password manager)'
    clickhouse_url=http://clickhouse:8123
    loki_endpoint=http://loki:3100
    log_role=control
    log_host=control-01
    log_collector_id=control-01
    log_source_allowlist="$advertised_ipv4 $edge_allowlist"
    dns_api_hostname="dns-api-placeholder.$ops_domain"
    dns_api_cert=/etc/cdnfoundry/pki/dns-api.crt
    dns_api_key=/etc/cdnfoundry/pki/dns-api.key
else
    edge_allowlist=$advertised_ipv4
    restic_repository=
    restic_password_file=
    backup_access_key=
    backup_secret_key=
    backup_region=us-east-1
    ask dns_api_name 'DNS API host label (for example dns-api-1)' dns-api-1
    dns_api_hostname="$dns_api_name.$ops_domain"
    dns_api_cert="/etc/cdnfoundry/pki/$dns_api_name.crt"
    dns_api_key="/etc/cdnfoundry/pki/$dns_api_name.key"
    ask_secret clickhouse_password 'Shared telemetry ingestion password from the password manager'
    clickhouse_url="https://telemetry.$ops_domain:8444"
    loki_endpoint="https://telemetry.$ops_domain:8444"
    log_role=dns-edge
    log_host=$dns_api_name
    log_collector_id=$dns_api_name
    log_source_allowlist=
fi

for value in "$clickhouse_password" "$backup_secret_key"; do
    [[ -z $value ]] || valid_simple "$value" || { printf 'Secret contains characters unsupported by the environment-file format.\n' >&2; exit 2; }
done

app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
artifact_key=$(random_secret)
control_db_password=$(random_secret)
redis_password=$(random_secret)
pdns_db_password=$(random_secret)
pdns_api_key=$(random_secret)
edge_status_token=$(random_secret)
grafana_admin_password=$(random_secret)
grafana_clickhouse_password=$(random_secret)
grafana_postgres_password=$(random_secret)

umask 077
tmp=$(mktemp "${output}.tmp.XXXXXX")
trap 'rm -f "$tmp"' EXIT

awk -v app_key="$app_key" \
    -v artifact_key="$artifact_key" \
    -v app_url="https://control.$ops_domain" \
    -v control_hostname="control.$ops_domain" \
    -v telemetry_hostname="telemetry.$ops_domain" \
    -v control_allowlist="$control_ipv4" \
    -v edge_allowlist="$edge_allowlist" \
    -v control_db_password="$control_db_password" \
    -v redis_password="$redis_password" \
    -v restic_repository="$restic_repository" \
    -v restic_password_file="$restic_password_file" \
    -v backup_access_key="$backup_access_key" \
    -v backup_secret_key="$backup_secret_key" \
    -v backup_region="$backup_region" \
    -v acme_email="$acme_email" \
    -v pdns_db_password="$pdns_db_password" \
    -v pdns_api_key="$pdns_api_key" \
    -v clickhouse_password="$clickhouse_password" \
    -v clickhouse_url="$clickhouse_url" \
    -v grafana_admin_password="$grafana_admin_password" \
    -v grafana_clickhouse_password="$grafana_clickhouse_password" \
    -v grafana_postgres_password="$grafana_postgres_password" \
    -v log_source_allowlist="$log_source_allowlist" \
    -v log_role="$log_role" \
    -v log_host="$log_host" \
    -v log_collector_id="$log_collector_id" \
    -v loki_endpoint="$loki_endpoint" \
    -v release="$release" \
    -v host_bind_ipv4="$host_bind_ipv4" \
    -v host_bind_ipv6="::" \
    -v edge_control_url="https://edge-control.$ops_domain:8443" \
    -v edge_control_bind="$host_bind_ipv4:8443" \
    -v edge_status_token="$edge_status_token" \
    -v dns_api_hostname="$dns_api_hostname" \
    -v dns_api_cert="$dns_api_cert" \
    -v dns_api_key="$dns_api_key" \
    -v dns_bind_v4="$host_bind_ipv4" \
    '
    BEGIN {
      values["APP_KEY"]=app_key; values["EDGE_ARTIFACT_SIGNING_KEY"]=artifact_key
      values["APP_URL"]=app_url; values["CONTROL_HOSTNAME"]=control_hostname
      values["TELEMETRY_HOSTNAME"]=telemetry_hostname
      values["CONTROL_PUBLIC_IPV4_ALLOWLIST"]=control_allowlist
      values["EDGE_PUBLIC_IPV4_ALLOWLIST"]=edge_allowlist
      values["LOG_SOURCE_IPV4_ALLOWLIST"]=log_source_allowlist
      values["CONTROL_DB_PASSWORD"]=control_db_password; values["REDIS_PASSWORD"]=redis_password
      values["RESTIC_REPOSITORY"]=restic_repository
      values["RESTIC_PASSWORD_FILE"]=restic_password_file
      values["BACKUP_ACCESS_KEY_ID"]=backup_access_key
      values["BACKUP_SECRET_ACCESS_KEY"]=backup_secret_key
      values["BACKUP_DEFAULT_REGION"]=backup_region
      values["ACME_CONTACT_EMAIL"]=acme_email
      values["PDNS_DB_PASSWORD"]=pdns_db_password; values["PDNS_API_KEY"]=pdns_api_key
      values["DNS_BIND_V4"]=dns_bind_v4; values["CLICKHOUSE_PASSWORD"]=clickhouse_password
      values["CLICKHOUSE_URL"]=clickhouse_url
      values["GRAFANA_ADMIN_PASSWORD"]=grafana_admin_password
      values["GRAFANA_CLICKHOUSE_PASSWORD"]=grafana_clickhouse_password
      values["GRAFANA_POSTGRES_PASSWORD"]=grafana_postgres_password
      values["LOG_ROLE"]=log_role; values["LOG_HOST"]=log_host
      values["LOG_COLLECTOR_ID"]=log_collector_id; values["LOKI_ENDPOINT"]=loki_endpoint
      values["CDNF_RELEASE"]=release; values["HOST_BIND_IPV4"]=host_bind_ipv4
      values["HOST_BIND_IPV6"]=host_bind_ipv6; values["EDGE_CONTROL_URL"]=edge_control_url
      values["EDGE_CONTROL_BIND"]=edge_control_bind; values["EDGE_STATUS_TOKEN"]=edge_status_token
      values["DNS_API_HOSTNAME"]=dns_api_hostname
      values["DNS_API_SERVER_CERTIFICATE"]=dns_api_cert
      values["DNS_API_SERVER_PRIVATE_KEY"]=dns_api_key
    }
    /^[A-Z][A-Z0-9_]*=/ {
      key=$0; sub(/=.*/, "", key)
      if (key in values) { print key "=" values[key]; next }
    }
    { print }
    ' "$template" > "$tmp"

mv "$tmp" "$output"
trap - EXIT
chmod 0600 "$output"
printf '\nCreated %s (mode 0600) for role %s.\n' "$output" "$role"
printf 'Platform DNS identity: ns1.%s, ns2.%s; proxy.%s\n' "$platform_domain" "$platform_domain" "$platform_domain"
if [[ -n $advertised_ipv6 ]]; then
    printf 'IPv6 is configured; include the matching compose.*-host-ipv6.yml override.\n'
else
    printf 'IPv6 is disabled; do not include an IPv6 Compose override.\n'
fi
