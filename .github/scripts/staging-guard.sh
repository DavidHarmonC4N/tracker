#!/usr/bin/env bash
# Decides whether the staging deploy may run. Reads these environment variables:
#   HOST USER_NAME PASS PROD_DIR STAGING_DIR STAGING_URL
# Prints "ok=true" or "ok=false" (to $GITHUB_OUTPUT when set). Exits 1, with a message, if the settings would be dangerous.
set -u
out() { if [ -n "${GITHUB_OUTPUT:-}" ]; then echo "$1" >> "$GITHUB_OUTPUT"; else echo "$1"; fi; }

missing=""
[ -n "${HOST:-}" ] || missing="$missing DEPLOY_HOST"
[ -n "${USER_NAME:-}" ] || missing="$missing DEPLOY_USER"
[ -n "${PASS:-}" ] || missing="$missing DEPLOY_PASSWORD"
[ -n "${PROD_DIR:-}" ] || missing="$missing DEPLOY_DIR"
[ -n "${STAGING_DIR:-}" ] || missing="$missing STAGING_DEPLOY_DIR"
[ -n "${STAGING_URL:-}" ] || missing="$missing STAGING_URL"
if [ -n "$missing" ]; then
  echo "::notice::Staging is not set up yet. Missing secrets:$missing. See the README section 'Staging site'."
  out "ok=false"
  exit 0
fi

# Compare folders without trailing slashes, and never let staging be (or contain) the live folder.
prod="${PROD_DIR%/}"
stg="${STAGING_DIR%/}"
if [ -z "$stg" ]; then
  echo "::error::STAGING_DEPLOY_DIR must be a folder, not the top of the FTP account."
  exit 1
fi
if [ "$prod" = "$stg" ]; then
  echo "::error::STAGING_DEPLOY_DIR is the same folder as DEPLOY_DIR. Staging would overwrite the live site. Stopping."
  exit 1
fi
case "$prod/" in
  "$stg/"*)
    echo "::error::The live folder (DEPLOY_DIR) lies inside STAGING_DEPLOY_DIR. Stopping."
    exit 1 ;;
esac
# The staging address must not be the live address.
if [ -n "${OFFGRID_URL:-}" ] && [ "${OFFGRID_URL%/}" = "${STAGING_URL%/}" ]; then
  echo "::error::STAGING_URL is the same address as OFFGRID_URL (the live site). Stopping."
  exit 1
fi
out "ok=true"
