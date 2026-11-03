#!/usr/bin/env bash
# Runs the fleet web browser suite: one fresh disposable vault and server per project (theme x width), because the
# tests change state (a trusted key, a failed job) that the next project must not inherit.
# Never reaches a real host: the fixture's nodes point at a closed local port.
set -euo pipefail
cd "$(dirname "$0")"
[ -d node_modules ] || npm install --no-audit --no-fund
npx playwright install chromium
status=0
for project in light-desktop light-phone dark-desktop dark-phone; do
  npx playwright test -c playwright.fleet.config.ts --project="$project" "$@" || status=1
done
exit "$status"
