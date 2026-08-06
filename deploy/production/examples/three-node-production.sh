#!/usr/bin/env sh
set -eu

: "${STATE_DIR:=/var/lib/cdnfoundry-fleet}"
: "${OUTPUT_DIR:=$STATE_DIR/bundles}"
: "${OPERATOR_DOMAIN:=ops.example.com}"
: "${PLATFORM_DOMAIN:=example.net}"
: "${RELEASE:=v1.0.0}"
: "${ACME_EMAIL:=operations@example.net}"
: "${ENABLE_MONITORING:=1}"

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../../.." && pwd)
CLI="$REPO_ROOT/scripts/cdnfoundry-fleet"

run() {
  "$CLI" --state-dir "$STATE_DIR" --output-dir "$OUTPUT_DIR" --repo-root "$REPO_ROOT" "$@"
}

run init \
  --operator-domain "$OPERATOR_DOMAIN" \
  --platform-domain "$PLATFORM_DOMAIN" \
  --release "$RELEASE" \
  --acme-email "$ACME_EMAIL" \
  --non-interactive

# Documentation-only IP ranges. Replace every address before production use.
run add-node --node control-1 --role control --region global --location primary \
  --hostname "control.$OPERATOR_DOMAIN" --public-ipv4 192.0.2.10 --non-interactive
run add-node --node pop-1 --role dns-edge --region europe --location amsterdam \
  --hostname "pop-1.$OPERATOR_DOMAIN" --public-ipv4 198.51.100.20 --non-interactive
run add-node --node pop-2 --role dns-edge --region europe --location frankfurt \
  --hostname "pop-2.$OPERATOR_DOMAIN" --public-ipv4 198.51.100.30 --non-interactive

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
