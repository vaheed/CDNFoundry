#!/usr/bin/env bash
# Runs the challenge page browser suite against a disposable stack of real OpenResty cells (challenge_server.py): an origin and two
# cells on loopback ports with their own Docker network. It never touches the development stack, a named volume, or a real host.
set -euo pipefail
cd "$(dirname "$0")"
[ -d node_modules ] || npm install --no-audit --no-fund
npx playwright install chromium
node challenge_solver.test.mjs
npx playwright test -c playwright.challenge.config.ts "$@"
