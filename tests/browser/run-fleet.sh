#!/usr/bin/env bash
# Runs the fleet web browser suite: one fresh disposable vault and server per project (theme x width), because the
# tests change state (a trusted key, a failed job) that the next project must not inherit.
# Never reaches a real host: the fixture's nodes point at a closed local port.
set -euo pipefail
cd "$(dirname "$0")"
[ -d node_modules ] || npm install --no-audit --no-fund
npx playwright install chromium
status=0
config=playwright.fleet.config.ts
for argument in "$@"; do
  if [[ "$argument" == *docs-screenshots.spec.ts ]]; then
    config=playwright.fleet-docs.config.ts
  fi
done
for project in light-desktop light-phone dark-desktop dark-phone; do
  npx playwright test -c "$config" --project="$project" "$@" || status=1
  if [[ "$#" == 0 ]]; then
    npx playwright test -c playwright.fleet-docs.config.ts --project="$project" || status=1
  fi
done
exit "$status"
