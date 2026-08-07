#!/usr/bin/env sh
set -eu

# CDNFoundry Three-Node Production Fleet Generator
# 
# This script creates a minimal production fleet with:
# - 1 control-plane node (control-1)
# - 2 combined DNS + edge nodes (pop-1, pop-2)
#
# Usage:
#   Option 1: Using a configuration file (recommended)
#     sudo CONFIG_FILE=/path/to/fleet-config.ini ./three-node-production.sh
#
#   Option 2: Using environment variables
#     sudo OPERATOR_DOMAIN=ops.example.com \
#          PLATFORM_DOMAIN=example.net \
#          RELEASE=v1.0.0 \
#          ACME_EMAIL=ops@example.com \
#          ./three-node-production.sh
#
# Configuration file format (INI-style):
#   [control]
#   hostname = control.ops.example.com
#   ipv4 = 192.0.2.10
#   
#   [pop]
#   pop-1 = 198.51.100.20,europe,amsterdam
#   pop-2 = 198.51.100.30,europe,frankfurt
#   
#   [fleet]
#   operator_domain = ops.example.com
#   platform_domain = example.net
#   release = v1.0.0
#   acme_email = operations@example.com
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
  ACME_EMAIL=$(get_config "fleet" "acme_email" "operations@example.com")
  ENABLE_MONITORING=$(get_config "fleet" "enable_monitoring" "1")
  
  # Control node settings
  CONTROL_HOSTNAME=$(get_config "control" "hostname" "control.$OPERATOR_DOMAIN")
  CONTROL_IPV4=$(get_config "control" "ipv4" "192.0.2.10")
  CONTROL_REGION=$(get_config "control" "region" "global")
  CONTROL_LOCATION=$(get_config "control" "location" "primary")
  
  # PoP nodes (parsed from [pop] section)
  POP1_LINE=$(awk -F '=' '/^\[pop\]/,/^\[/ { if ($1 ~ /pop-1/) { gsub(/^[ \t]+|[ \t]+$/, "", $2); print $2 } }' "$CONFIG_FILE")
  POP2_LINE=$(awk -F '=' '/^\[pop\]/,/^\[/ { if ($1 ~ /pop-2/) { gsub(/^[ \t]+|[ \t]+$/, "", $2); print $2 } }' "$CONFIG_FILE")
  
  POP1_IPV4=$(echo "$POP1_LINE" | cut -d',' -f1)
  POP1_REGION=$(echo "$POP1_LINE" | cut -d',' -f2)
  POP1_LOCATION=$(echo "$POP1_LINE" | cut -d',' -f3)
  
  POP2_IPV4=$(echo "$POP2_LINE" | cut -d',' -f1)
  POP2_REGION=$(echo "$POP2_LINE" | cut -d',' -f2)
  POP2_LOCATION=$(echo "$POP2_LINE" | cut -d',' -f3)
  
  # Set defaults if not in config
  : "${POP1_IPV4:=198.51.100.20}"
  : "${POP1_REGION:=europe}"
  : "${POP1_LOCATION:=amsterdam}"
  : "${POP2_IPV4:=198.51.100.30}"
  : "${POP2_REGION:=europe}"
  : "${POP2_LOCATION:=frankfurt}"
else
  # Use environment variables or defaults
  : "${STATE_DIR:=/var/lib/cdnfoundry-fleet}"
  : "${OUTPUT_DIR:=$STATE_DIR/bundles}"
  : "${OPERATOR_DOMAIN:=ops.example.com}"
  : "${PLATFORM_DOMAIN:=example.net}"
  : "${RELEASE:=v1.0.0}"
  : "${ACME_EMAIL:=operations@example.net}"
  : "${ENABLE_MONITORING:=1}"
  
  # Default control node settings
  : "${CONTROL_HOSTNAME:=control.$OPERATOR_DOMAIN}"
  : "${CONTROL_IPV4:=192.0.2.10}"
  : "${CONTROL_REGION:=global}"
  : "${CONTROL_LOCATION:=primary}"
  
  # Default PoP settings
  : "${POP1_IPV4:=198.51.100.20}"
  : "${POP1_REGION:=europe}"
  : "${POP1_LOCATION:=amsterdam}"
  : "${POP2_IPV4:=198.51.100.30}"
  : "${POP2_REGION:=europe}"
  : "${POP2_LOCATION:=frankfurt}"
fi

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../../.." && pwd)
CLI="$REPO_ROOT/scripts/cdnfoundry-fleet"

run() {
  "$CLI" --state-dir "$STATE_DIR" --output-dir "$OUTPUT_DIR" --repo-root "$REPO_ROOT" "$@"
}

echo "Generating fleet configuration..."
echo "  Operator domain: $OPERATOR_DOMAIN"
echo "  Platform domain: $PLATFORM_DOMAIN"
echo "  Release: $RELEASE"
echo "  Control: $CONTROL_HOSTNAME ($CONTROL_IPV4)"
echo "  PoP-1: pop-1.$OPERATOR_DOMAIN ($POP1_IPV4)"
echo "  PoP-2: pop-2.$OPERATOR_DOMAIN ($POP2_IPV4)"
echo "  Monitoring: $([ "$ENABLE_MONITORING" = "1" ] || [ "$ENABLE_MONITORING" = "true" ] && echo "enabled" || echo "disabled")"
echo ""

run init \
  --operator-domain "$OPERATOR_DOMAIN" \
  --platform-domain "$PLATFORM_DOMAIN" \
  --release "$RELEASE" \
  --acme-email "$ACME_EMAIL" \
  --non-interactive

# Add nodes - replace documentation IP ranges with configured values
run add-node --node control-1 --role control --region "$CONTROL_REGION" --location "$CONTROL_LOCATION" \
  --hostname "$CONTROL_HOSTNAME" --public-ipv4 "$CONTROL_IPV4" --non-interactive
run add-node --node pop-1 --role dns-edge --region "$POP1_REGION" --location "$POP1_LOCATION" \
  --hostname "pop-1.$OPERATOR_DOMAIN" --public-ipv4 "$POP1_IPV4" --non-interactive
run add-node --node pop-2 --role dns-edge --region "$POP2_REGION" --location "$POP2_LOCATION" \
  --hostname "pop-2.$OPERATOR_DOMAIN" --public-ipv4 "$POP2_IPV4" --non-interactive

if [ "$ENABLE_MONITORING" = "1" ]; then
  run configure-monitoring --mode colocated --non-interactive
  run configure-logs --mode centralized --host control-1 --non-interactive
else
  run configure-monitoring --mode disabled --non-interactive
  run configure-logs --mode disabled --non-interactive
fi

run validate
run render
run show-start-order

cat <<EOF2
Generated bundles in: $OUTPUT_DIR
Start control-1 first. Create pop-1 and pop-2 in the control panel, then run
configure-edge-registration for each UUID/token and rerender those node bundles.
EOF2
