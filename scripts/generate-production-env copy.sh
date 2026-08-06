#!/usr/bin/env sh
set -eu
umask 077

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
STATE_DIR=${CDNFOUNDRY_FLEET_STATE_DIR:-$REPO_ROOT/.cdnfoundry-fleet}
OUTPUT_DIR=${CDNFOUNDRY_FLEET_OUTPUT_DIR:-$REPO_ROOT/build/fleet-bundles}

printf '%s\n' 'Starting CDNFoundry production fleet generator...'
printf 'State directory: %s\n' "$STATE_DIR"
printf 'Bundle directory: %s\n' "$OUTPUT_DIR"

exec python3 "$SCRIPT_DIR/cdnfoundry-fleet" \
  --state-dir "$STATE_DIR" \
  --output-dir "$OUTPUT_DIR" \
  --repo-root "$REPO_ROOT" \
  setup "$@"
