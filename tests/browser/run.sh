#!/usr/bin/env bash
# Runs the Playwright suite against a disposable sqlite stack with generated credentials.
# Never targets production and never touches the persistent development database or volumes.
set -euo pipefail
cd "$(dirname "$0")/../.."

export CDNF_BROWSER_ADMIN_PASSWORD="$(head -c 18 /dev/urandom | base64 | tr -d '/+=')"
export CDNF_BROWSER_USER_PASSWORD="$(head -c 18 /dev/urandom | base64 | tr -d '/+=')"
export CDNF_BROWSER_BASE_URL="${CDNF_BROWSER_BASE_URL:-http://127.0.0.1:18080}"
case "$CDNF_BROWSER_BASE_URL" in
  http://127.0.0.1:*|http://localhost:*) ;;
  *) echo "refusing to run the browser suite against $CDNF_BROWSER_BASE_URL" >&2; exit 2 ;;
esac

compose=(docker compose -f compose.dev.yml -f compose.browser.yml)
cleanup() { "${compose[@]}" rm -sf browser-app >/dev/null 2>&1 || true; }
trap cleanup EXIT

make dev-assets
"${compose[@]}" run --rm --no-deps vendor-init >/dev/null
"${compose[@]}" up -d --no-deps --build browser-app
for _ in $(seq 1 60); do
  curl -fsS -o /dev/null "$CDNF_BROWSER_BASE_URL/admin/login" && break
  sleep 2
done
curl -fsS -o /dev/null "$CDNF_BROWSER_BASE_URL/admin/login"

cd tests/browser
[ -d node_modules ] || npm install --no-audit --no-fund
npx playwright install chromium
npx playwright test "$@"
