#!/usr/bin/env bash
# Runs the whole test suite: PHP unit tests, then browser tests against throwaway copies of the site.
#
#   bash tests/run.sh            everything
#   bash tests/run.sh php        only the PHP tests
#   bash tests/run.sh browser    only the browser tests
#   bash tests/run.sh browser prices   only browser tests whose file name contains "prices"
#
# Needs PHP 8.3 with curl, dom and mbstring, and Node 20+ with Playwright (cd tests && npm ci && npx playwright install chromium).
# Nothing here touches your real config.php, data folder or host: the site is copied to a temporary folder.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WHAT="${1:-all}"
FILTER="${2:-}"
PUBLIC_PORT="${PUBLIC_PORT:-8071}"
ADMIN_PORT="${ADMIN_PORT:-8072}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/offgrid-tests.XXXXXX")"
PIDS=()
STATUS=0

cleanup() {
  for pid in "${PIDS[@]:-}"; do [ -n "$pid" ] && kill "$pid" 2>/dev/null; done
  case "$WORK" in /tmp/offgrid-tests.*|"${TMPDIR:-/tmp}"/offgrid-tests.*) rm -rf -- "$WORK" ;; esac
}
trap cleanup EXIT

# make_site NAME DATA_DIR [staging]  ->  copies the site (no tests, no git) and writes a test config.php
make_site() {
  local dest="$WORK/$1" data="$2" staging="${3:-false}" token=""
  # Only the staging copy gets a (fake) GitHub token, to prove the staging guard ignores it. Other copies must never call GitHub.
  [ "$staging" = "true" ] && token="not-a-real-token"
  mkdir -p "$dest"
  tar -C "$ROOT" --exclude=.git --exclude=.github --exclude=tests --exclude=node_modules --exclude=runner -cf - . | tar -C "$dest" -xf -
  cat > "$dest/config.php" <<PHP
<?php
if (!defined('OFFGRID_APP')) { http_response_code(404); exit; }
return [
    'data_dir' => '$data', 'setup_key' => '0123456789abcdef0123456789abcdef0123', 'base_url' => '', 'interval_minutes' => 60,
    'batch_size' => 6, 'notify_email' => '', 'notify_webhook' => '',
    'ingest_token' => '0123456789abcdef0123456789abcdef0123abcd', 'github_repo' => 'example/example', 'github_token' => '$token',
    'github_workflow' => 'check.yml', 'github_ref' => 'main', 'staging' => $staging, 'local_test' => true,
];
PHP
}

serve() { # serve DIR PORT
  if curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$2/"; then
    echo "Port $2 is already in use. Stop whatever is serving it, or set PUBLIC_PORT and ADMIN_PORT to free ports." >&2
    return 1
  fi
  # exec makes the background job php itself, so cleanup stops the server and not just a shell around it.
  (cd "$1" && PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$2" > "$WORK/server-$2.log" 2>&1) &
  PIDS+=($!)
  for _ in $(seq 1 40); do
    curl -s -o /dev/null "http://127.0.0.1:$2/" && return 0
    sleep 0.25
  done
  echo "Could not start the PHP test server on port $2" >&2
  cat "$WORK/server-$2.log" >&2
  return 1
}

run_php() {
  echo "== PHP tests"
  mkdir -p "$WORK/unit-data" "$WORK/staging-data"
  make_site unit "$WORK/unit-data"
  make_site staging "$WORK/staging-data" true
  for f in "$ROOT"/tests/php/*.test.php; do
    [ -n "$FILTER" ] && [[ "$f" != *"$FILTER"* ]] && continue
    echo "-- $(basename "$f")"
    SITE_DIR="$WORK/unit" STAGING_SITE_DIR="$WORK/staging" php "$f" || STATUS=1
  done
}

run_browser() {
  echo "== Browser tests"
  # Public site: no data folder, so the catalog is served from the starter list. Tests mock catalog.php and history.php as needed.
  mkdir -p "$WORK/public-data" "$WORK/admin-data"
  make_site public ""
  # Admin site: a real data folder. Its store fetch is replaced by a fixture so no test touches a real shop.
  make_site admin "$WORK/admin-data"
  python3 - "$WORK/admin/admin.php" "$ROOT/tests/fixtures/rokland-wismesh.json" <<'PY' || { echo "Could not stub the store fetch in the admin copy" >&2; STATUS=1; return; }
import sys
path, fixture = sys.argv[1], sys.argv[2]
src = open(path).read()
needle = "static fn($u) => guarded_fetch($u, $dir)"
if src.count(needle) != 1:
    sys.exit("admin.php no longer contains the store fetch call the test stub replaces")
open(path, "w").write(src.replace(needle, "static fn($u) => [200, (string)file_get_contents('" + fixture + "')]"))
PY
  serve "$WORK/public" "$PUBLIC_PORT" || { STATUS=1; return; }
  serve "$WORK/admin" "$ADMIN_PORT" || { STATUS=1; return; }
  local modules="$ROOT/tests/node_modules"
  [ -d "$modules" ] && export NODE_PATH="$modules${NODE_PATH:+:$NODE_PATH}"
  for f in "$ROOT"/tests/browser/*.test.js; do
    [ -n "$FILTER" ] && [[ "$f" != *"$FILTER"* ]] && continue
    echo "-- $(basename "$f")"
    SITE_URL="http://127.0.0.1:$PUBLIC_PORT/" ADMIN_URL="http://127.0.0.1:$ADMIN_PORT/admin.php" ROOT_DIR="$ROOT" node "$f" || STATUS=1
  done
}

case "$WHAT" in
  php) run_php ;;
  browser) run_browser ;;
  all) run_php; run_browser ;;
  *) echo "Usage: bash tests/run.sh [all|php|browser] [file-name-filter]" >&2; exit 2 ;;
esac

if [ "$STATUS" -eq 0 ]; then echo; echo "ALL TEST FILES PASSED"; else echo; echo "SOME TESTS FAILED"; fi
exit "$STATUS"
