#!/usr/bin/env bash
# Post-deploy check: the live site must serve its pages, scripts and styles. Usage: smoke.sh https://example.com/folder/
set -u
base="${1:?site address required}"
base="${base%/}/"
ua="OffgridDeployCheck/1.0"
fail=0
check() {
  local path="$1" want="$2" code type
  read -r code type < <(curl -s -m 30 -A "$ua" -o /dev/null -w "%{http_code} %{content_type}\n" "${base}${path}")
  if [ "$code" = "200" ] && [[ "$type" == *"$want"* ]]; then
    echo "ok    $path ($code $type)"
  else
    echo "FAIL  $path (got $code $type, wanted 200 $want)"
    fail=1
  fi
}
check "" "text/html"
check "assets/styles.css" "text/css"
check "assets/catalog.js" "javascript"
check "catalog.php" "application/json"
check "admin.php" "text/html"
# The settings file must never be served.
code=$(curl -s -m 30 -A "$ua" -o /dev/null -w "%{http_code}" "${base}config.php")
if [ "$code" = "200" ]; then echo "FAIL  config.php is publicly readable (200)"; fail=1; else echo "ok    config.php is not served ($code)"; fi
exit $fail
