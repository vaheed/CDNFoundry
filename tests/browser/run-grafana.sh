#!/usr/bin/env bash
# Runs the Grafana browser suite against a development or staging stack that was seeded with
# `make dev-observability-seed`. It signs in with the development credentials and the per-team viewers the
# Grafana bootstrap creates there, and never targets production. Pass Playwright arguments through, for example
# `./run-grafana.sh dashboards` or `GRAFANA_RANGES=6h ./run-grafana.sh`.
set -euo pipefail
cd "$(dirname "$0")"
case "${GRAFANA_URL:-http://127.0.0.1:3000}" in
  http://127.0.0.1:*|http://localhost:*|https://*staging*|https://*.test|https://*.local) ;;
  *) echo "Refusing to run the Grafana suite against ${GRAFANA_URL}: use a development, test, or staging stack." >&2; exit 2 ;;
esac
[ -d node_modules ] || npm install --no-audit --no-fund
npx playwright install chromium
exec npx playwright test -c playwright.grafana.config.ts "$@"
