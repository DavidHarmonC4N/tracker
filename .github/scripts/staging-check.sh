#!/usr/bin/env bash
# Post-deploy check for the private staging copy. Usage: staging-check.sh https://example.com/staging/
# 1. Without a password every page must be refused (HTTP 401): staging must never be public.
# 2. With STAGING_USER and STAGING_PASSWORD set, the staging site must serve its pages and say it is staging.
set -u
base="${1:?staging address required}"
base="${base%/}/"
ua="OffgridStagingCheck/1.0"
fail=0

for path in "" "catalog.php" "admin.php" "robots.txt" "assets/catalog.js"; do
  code=$(curl -s -m 30 -A "$ua" -o /dev/null -w "%{http_code}" "${base}${path}")
  if [ "$code" = "401" ]; then
    echo "ok    ${path:-/} is closed to the public (401)"
  else
    echo "FAIL  ${path:-/} answered $code without a password (wanted 401). Staging is not protected."
    fail=1
  fi
done

if [ -n "${STAGING_USER:-}" ] && [ -n "${STAGING_PASSWORD:-}" ]; then
  auth=(-u "${STAGING_USER}:${STAGING_PASSWORD}")
  check() {
    local path="$1" want="$2" code type
    read -r code type < <(curl -s -m 30 -A "$ua" "${auth[@]}" -o /dev/null -w "%{http_code} %{content_type}\n" "${base}${path}")
    if [ "$code" = "200" ] && [[ "$type" == *"$want"* ]]; then echo "ok    $path ($code $type)"; else echo "FAIL  $path (got $code $type, wanted 200 $want)"; fail=1; fi
  }
  check "" "text/html"
  check "assets/styles.css" "text/css"
  check "assets/catalog.js" "javascript"
  check "prices.html" "text/html"
  check "catalog.php" "application/json"
  check "admin.php" "text/html"
  if curl -s -m 30 -A "$ua" "${auth[@]}" "${base}catalog.php" | grep -q '"staging":true'; then
    echo "ok    catalog.php reports staging:true"
  else
    echo "FAIL  catalog.php does not say staging:true. Set 'staging' => true in the staging config.php."
    fail=1
  fi
  if curl -s -m 30 -A "$ua" "${auth[@]}" "${base}robots.txt" | grep -qi "^Disallow: /"; then
    echo "ok    robots.txt blocks crawlers"
  else
    echo "FAIL  robots.txt does not block crawlers"
    fail=1
  fi
  code=$(curl -s -m 30 -A "$ua" "${auth[@]}" -o /dev/null -w "%{http_code}" "${base}config.php")
  if [ "$code" = "200" ]; then echo "FAIL  config.php is readable (200)"; fail=1; else echo "ok    config.php is not served ($code)"; fi
else
  echo "note  STAGING_USER and STAGING_PASSWORD are not set, so the signed-in checks were skipped."
fi
exit $fail
