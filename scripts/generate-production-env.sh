#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: scripts/generate-production-env.sh [--output PATH]

Interactively creates a mode-0600 CDNFoundry production environment file.
Run it once on each host. It refuses to overwrite an existing file.
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
ask public_ipv4 'This host public IPv4'
ask_optional public_ipv6 'This host public IPv6'
ask control_ipv4 'Control-plane public IPv4' "$public_ipv4"
ask acme_email 'ACME contact email'

for value in "$ops_domain" "$platform_domain" "$release" "$public_ipv4" "$public_ipv6" "$control_ipv4" "$acme_email"; do
    [[ -z $value ]] || valid_simple "$value" || { printf 'Unsupported character in input: %s\n' "$value" >&2; exit 2; }
done

if [[ $role == control ]]; then
    ask edge_allowlist 'Space-separated public IPv4 addresses of all edge nodes'
    valid_list "$edge_allowlist" || { printf 'Invalid edge IPv4 allow-list\n' >&2; exit 2; }
    ask restic_repository 'Encrypted Restic repository URL'
    ask backup_access_key 'Backup-only access key ID'
    ask_secret backup_secret_key 'Backup-only secret access key'
    clickhouse_password=$(random_secret)
    dns_api_hostname="dns-api-placeholder.$ops_domain"
    dns_api_cert=/etc/cdnfoundry/pki/dns-api.crt
    dns_api_key=/etc/cdnfoundry/pki/dns-api.key
else
    edge_allowlist=$public_ipv4
    restic_repository=s3:https://unused.invalid/cdnfoundry
    backup_access_key=unused-on-edge
    backup_secret_key=$(random_secret)
    ask dns_api_name 'DNS API host label (for example dns-api-1)' dns-api-1
    dns_api_hostname="$dns_api_name.$ops_domain"
    dns_api_cert="/etc/cdnfoundry/pki/$dns_api_name.crt"
    dns_api_key="/etc/cdnfoundry/pki/$dns_api_name.key"
    ask_secret clickhouse_password 'Telemetry CLICKHOUSE_PASSWORD from the control host .env.prod'
fi

app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
artifact_key=$(random_secret)
control_db_password=$(random_secret)
redis_password=$(random_secret)
pdns_db_password=$(random_secret)
pdns_api_key=$(random_secret)
edge_status_token=$(random_secret)

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
    -v backup_access_key="$backup_access_key" \
    -v backup_secret_key="$backup_secret_key" \
    -v acme_email="$acme_email" \
    -v pdns_db_password="$pdns_db_password" \
    -v pdns_api_key="$pdns_api_key" \
    -v clickhouse_password="$clickhouse_password" \
    -v release="$release" \
    -v public_ipv4="$public_ipv4" \
    -v public_ipv6="$public_ipv6" \
    -v edge_control_url="https://edge-control.$ops_domain:8443" \
    -v edge_control_bind="$control_ipv4:8443" \
    -v edge_http_bind="$public_ipv4:80" \
    -v edge_https_bind="$public_ipv4:443" \
    -v edge_status_token="$edge_status_token" \
    -v dns_api_hostname="$dns_api_hostname" \
    -v dns_api_cert="$dns_api_cert" \
    -v dns_api_key="$dns_api_key" \
    -v dns_bind_v4="$public_ipv4" \
    '
    BEGIN {
      values["APP_KEY"]=app_key; values["EDGE_ARTIFACT_SIGNING_KEY"]=artifact_key
      values["APP_URL"]=app_url; values["CONTROL_HOSTNAME"]=control_hostname
      values["TELEMETRY_HOSTNAME"]=telemetry_hostname
      values["CONTROL_PUBLIC_IPV4_ALLOWLIST"]=control_allowlist
      values["EDGE_PUBLIC_IPV4_ALLOWLIST"]=edge_allowlist
      values["CONTROL_DB_PASSWORD"]=control_db_password; values["REDIS_PASSWORD"]=redis_password
      values["RESTIC_REPOSITORY"]=restic_repository
      values["BACKUP_ACCESS_KEY_ID"]=backup_access_key
      values["BACKUP_SECRET_ACCESS_KEY"]=backup_secret_key
      values["ACME_CONTACT_EMAIL"]=acme_email
      values["PDNS_DB_PASSWORD"]=pdns_db_password; values["PDNS_API_KEY"]=pdns_api_key
      values["DNS_BIND_V4"]=dns_bind_v4; values["CLICKHOUSE_PASSWORD"]=clickhouse_password
      values["CDNF_RELEASE"]=release; values["PUBLIC_BIND_IPV4"]=public_ipv4
      values["PUBLIC_BIND_IPV6"]=public_ipv6; values["EDGE_CONTROL_URL"]=edge_control_url
      values["EDGE_CONTROL_BIND"]=edge_control_bind; values["EDGE_HTTP_BIND"]=edge_http_bind
      values["EDGE_HTTPS_BIND"]=edge_https_bind; values["EDGE_STATUS_TOKEN"]=edge_status_token
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
if [[ -n $public_ipv6 ]]; then
    printf 'IPv6 is configured; include the matching compose.*-host-ipv6.yml override.\n'
else
    printf 'IPv6 is disabled; do not include an IPv6 Compose override.\n'
fi
