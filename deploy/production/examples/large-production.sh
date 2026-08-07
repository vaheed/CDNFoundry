#!/usr/bin/env sh
set -eu

# CDNFoundry Large Production Fleet Generator
# 
# This script creates a larger production fleet with:
# - 1 control-plane node (control-1)
# - 3 monitoring nodes (monitoring-1, monitoring-2, monitoring-3)
# - 4 DNS nodes (dns-ashburn, dns-frankfurt, dns-singapore, dns-sao-paulo)
# - 10 edge nodes (edge-*)
#
# Usage:
#   Option 1: Using a configuration file (recommended)
#     sudo CONFIG_FILE=/path/to/fleet-config.ini ./large-production.sh
#
#   Option 2: Using environment variables
#     sudo OPERATOR_DOMAIN=ops.example.com \
#          PLATFORM_DOMAIN=example.net \
#          RELEASE=v1.0.0 \
#          CONTROL_DB_MODE=embedded \
#          ./large-production.sh
#
# Configuration file format (INI-style):
#   [control]
#   hostname = control.ops.example.com
#   ipv4 = 192.0.2.10
#   
#   [pop]
#   dns-ashburn = 192.0.2.21,us-east,ashburn
#   dns-frankfurt = 192.0.2.22,europe,frankfurt
#   ...
#   edge-ashburn = 198.51.100.1,us-east,ashburn
#   ...
#   
#   [fleet]
#   operator_domain = ops.example.com
#   platform_domain = example.net
#   release = v1.0.0
#   control_db_mode = embedded
#   enable_monitoring = true

# Load configuration from file if provided
CONFIG_FILE="${CONFIG_FILE:-}"
if [ -n "$CONFIG_FILE" ] && [ -f "$CONFIG_FILE" ]; then
  # Parse INI-style config file
  get_config() {
    section="$1"
    key="$2"
    default="$3"
    value=$(awk -F'=' -v section="$section" -v key="$key" '
      /^\[/ { current_section = substr($0, 2, length($0)-2); next }
      current_section == section && $1 ~ key { 
        gsub(/^[ \t]+|[ \t]+$/, "", $2)
        print $2
        exit 
      }
    ' "$CONFIG_FILE")
    echo "${value:-$default}"
  }
  
  STATE_DIR=$(get_config "fleet" "state_dir" "/var/lib/cdnfoundry-fleet")
  OUTPUT_DIR=$(get_config "fleet" "output_dir" "$STATE_DIR/bundles")
  OPERATOR_DOMAIN=$(get_config "fleet" "operator_domain" "ops.example.com")
  PLATFORM_DOMAIN=$(get_config "fleet" "platform_domain" "example.net")
  RELEASE=$(get_config "fleet" "release" "v1.0.0")
  CONTROL_DB_MODE=$(get_config "fleet" "control_db_mode" "embedded")
  REMOTE_POSTGRES_HOST=$(get_config "fleet" "remote_postgres_host" "")
  REMOTE_POSTGRES_PORT=$(get_config "fleet" "remote_postgres_port" "5432")
  REMOTE_POSTGRES_SSLMODE=$(get_config "fleet" "remote_postgres_sslmode" "verify-full")
  CONTROL_DB_PASSWORD_FILE=$(get_config "fleet" "control_db_password_file" "")
else
  # Use environment variables or defaults
  : "${STATE_DIR:=/var/lib/cdnfoundry-fleet}"
  : "${OUTPUT_DIR:=$STATE_DIR/bundles}"
  : "${OPERATOR_DOMAIN:=ops.example.com}"
  : "${PLATFORM_DOMAIN:=example.net}"
  : "${RELEASE:=v1.0.0}"
  : "${CONTROL_DB_MODE:=embedded}"
  : "${REMOTE_POSTGRES_PORT:=5432}"
  : "${REMOTE_POSTGRES_SSLMODE:=verify-full}"
fi

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../../.." && pwd)
CLI="$REPO_ROOT/scripts/cdnfoundry-fleet"

run() {
  "$CLI" --state-dir "$STATE_DIR" --output-dir "$OUTPUT_DIR" "$@"
}

echo "Generating large fleet configuration..."
echo "  Operator domain: $OPERATOR_DOMAIN"
echo "  Platform domain: $PLATFORM_DOMAIN"
echo "  Release: $RELEASE"
echo "  Control DB mode: $CONTROL_DB_MODE"
if [ "$CONTROL_DB_MODE" = "remote" ]; then
  echo "  Remote PostgreSQL: ${REMOTE_POSTGRES_HOST:-not set}"
fi
echo ""

run init --operator-domain "$OPERATOR_DOMAIN" --platform-domain "$PLATFORM_DOMAIN" \
  --release "$RELEASE" --non-interactive

# Documentation-only IP ranges. Replace every address before production use.
if [ "$CONTROL_DB_MODE" = "remote" ]; then
  : "${REMOTE_POSTGRES_HOST:?Set REMOTE_POSTGRES_HOST for CONTROL_DB_MODE=remote}"
  : "${CONTROL_DB_PASSWORD_FILE:?Set CONTROL_DB_PASSWORD_FILE to a mode-0600 password file}"
  run add-node --node control-1 --role control --region global --location ashburn \
    --public-ipv4 192.0.2.10 \
    --extra-env "DB_HOST=$REMOTE_POSTGRES_HOST" \
    --extra-env "DB_PORT=$REMOTE_POSTGRES_PORT" \
    --extra-env "DB_SSLMODE=$REMOTE_POSTGRES_SSLMODE" \
    --non-interactive
  run set-secret --secret control-db-password --from-file "$CONTROL_DB_PASSWORD_FILE" --non-interactive
else
  run add-node --node control-1 --role control --region global --location ashburn \
    --public-ipv4 192.0.2.10 --non-interactive
fi
run add-node --node monitoring-1 --role monitoring --region global --location ashburn --public-ipv4 192.0.2.11 --non-interactive
run add-node --node monitoring-2 --role monitoring --region europe --location frankfurt --public-ipv4 192.0.2.12 --non-interactive
run add-node --node monitoring-3 --role monitoring --region asia --location singapore --public-ipv4 192.0.2.13 --non-interactive

run add-node --node dns-ashburn --role dns --region us-east --location ashburn --public-ipv4 192.0.2.21 --non-interactive
run add-node --node dns-frankfurt --role dns --region europe --location frankfurt --public-ipv4 192.0.2.22 --non-interactive
run add-node --node dns-singapore --role dns --region asia --location singapore --public-ipv4 192.0.2.23 --non-interactive
run add-node --node dns-sao-paulo --role dns --region south-america --location sao-paulo --public-ipv4 192.0.2.24 --non-interactive

run add-node --node edge-ashburn --role edge --region us-east --location ashburn --public-ipv4 198.51.100.1 --non-interactive
run add-node --node edge-los-angeles --role edge --region us-west --location los-angeles --public-ipv4 198.51.100.2 --non-interactive
run add-node --node edge-sao-paulo --role edge --region south-america --location sao-paulo --public-ipv4 198.51.100.3 --non-interactive
run add-node --node edge-frankfurt --role edge --region europe --location frankfurt --public-ipv4 198.51.100.4 --non-interactive
run add-node --node edge-johannesburg --role edge --region africa --location johannesburg --public-ipv4 198.51.100.5 --non-interactive
run add-node --node edge-dubai --role edge --region middle-east --location dubai --public-ipv4 198.51.100.6 --non-interactive
run add-node --node edge-mumbai --role edge --region asia --location mumbai --public-ipv4 198.51.100.7 --non-interactive
run add-node --node edge-singapore --role edge --region asia --location singapore --public-ipv4 198.51.100.8 --non-interactive
run add-node --node edge-tokyo --role edge --region asia --location tokyo --public-ipv4 198.51.100.9 --non-interactive
run add-node --node edge-sydney --role edge --region oceania --location sydney --public-ipv4 198.51.100.10 --non-interactive

run configure-monitoring --mode dedicated --host monitoring-1 --non-interactive
run configure-logs --mode centralized --host monitoring-1 --non-interactive
"$CLI" --state-dir "$STATE_DIR" --output-dir "$OUTPUT_DIR" --repo-root "$REPO_ROOT" validate
"$CLI" --state-dir "$STATE_DIR" --output-dir "$OUTPUT_DIR" --repo-root "$REPO_ROOT" render
run show-start-order
