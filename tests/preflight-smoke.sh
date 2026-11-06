#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
mkdir -p "$TMP_DIR/private-db"
export PROJECT_DASHBOARD_DB="$TMP_DIR/private-db/dashboard.sqlite"
export PROJECT_DASHBOARD_PUBLIC_ROOT="$ROOT"
export PROJECT_DASHBOARD_COOKIE_SECURE=1
unset PROJECT_DASHBOARD_SETUP_KEY || true

php "$ROOT/deployment/preflight.php" >"$TMP_DIR/pass.log"
grep -q 'Preflight passed' "$TMP_DIR/pass.log"

# Production must not pass with insecure session cookies.
if env -u PROJECT_DASHBOARD_COOKIE_SECURE php "$ROOT/deployment/preflight.php" >"$TMP_DIR/insecure.log" 2>&1; then
  echo "Expected preflight to reject insecure cookie configuration" >&2
  exit 1
fi
grep -q 'Secure session cookie' "$TMP_DIR/insecure.log"

# A database path inside the public root must be rejected.
if PROJECT_DASHBOARD_DB="$ROOT/dashboard-preflight-test.sqlite" php "$ROOT/deployment/preflight.php" >"$TMP_DIR/public-db.log" 2>&1; then
  echo "Expected preflight to reject a database inside the public root" >&2
  exit 1
fi
grep -q 'Database outside public root' "$TMP_DIR/public-db.log"

# A configured setup key must be long enough; no secret is printed.
if PROJECT_DASHBOARD_SETUP_KEY=short PROJECT_DASHBOARD_DB="$TMP_DIR/private-db/dashboard.sqlite" php "$ROOT/deployment/preflight.php" >"$TMP_DIR/short-key.log" 2>&1; then
  echo "Expected preflight to reject a short setup key" >&2
  exit 1
fi
grep -q 'First-admin setup key' "$TMP_DIR/short-key.log"

echo "Deployment preflight tests passed."
