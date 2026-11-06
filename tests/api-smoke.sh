#!/usr/bin/env bash
set -euo pipefail

BASE_URL="http://127.0.0.1:8787"
TMP_DIR="$(mktemp -d)"
export PROJECT_DASHBOARD_DB="$TMP_DIR/test.sqlite"
php -S 127.0.0.1:8787 router.php >"$TMP_DIR/server.log" 2>&1 &
SERVER_PID=$!
cleanup() {
  kill "$SERVER_PID" 2>/dev/null || true
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

for attempt in $(seq 1 30); do
  if curl -fsS "$BASE_URL/api/health" >/dev/null; then break; fi
  sleep 1
done

health="$(curl -fsS "$BASE_URL/api.php?route=health")"
echo "$health" | grep -q '"ok":true'

register="$(curl -fsS -c "$TMP_DIR/cookies" -H 'Content-Type: application/json' \
  -d '{"name":"Test Admin","email":"admin@example.test","password":"correct-horse-battery"}' \
  "$BASE_URL/api.php?route=auth/register")"
csrf="$(printf '%s' "$register" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["csrf_token"] ?? "";')"
test -n "$csrf"

me="$(curl -fsS -b "$TMP_DIR/cookies" "$BASE_URL/api.php?route=auth/me")"
echo "$me" | grep -q '"role":"admin"'

project="$(curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"name":"Smoke Test Project","status":"running","next_action":"Verify API"}' \
  "$BASE_URL/api.php?route=projects")"
project_id="$(printf '%s' "$project" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["id"] ?? "";')"
test -n "$project_id"

task="$(curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"title":"Smoke Test Task","priority":"high"}' \
  "$BASE_URL/api.php?route=projects/$project_id/tasks")"
task_id="$(printf '%s' "$task" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["id"] ?? "";')"
test -n "$task_id"

curl -fsS -b "$TMP_DIR/cookies" "$BASE_URL/api.php?route=projects/$project_id" | grep -q 'Smoke Test Project'
# A write without the session CSRF token must be rejected.
status="$(curl -sS -o "$TMP_DIR/csrf.json" -w '%{http_code}' -b "$TMP_DIR/cookies" -H 'Content-Type: application/json' \
  -d '{"name":"Should Not Exist","status":"planned"}' "$BASE_URL/api.php?route=projects")"
test "$status" = "419"

# Admin can create a user and assign that user to a project.
new_user="$(curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"name":"Test Viewer","email":"viewer@example.test","password":"viewer-password-123","role":"viewer"}' \
  "$BASE_URL/api.php?route=users")"
user_id="$(printf '%s' "$new_user" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["id"] ?? "";')"
test -n "$user_id"
curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$user_id,\"member_role\":\"viewer\"}" \
  "$BASE_URL/api.php?route=projects/$project_id/members" | grep -q '"ok":true'

approval="$(curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"title":"Smoke approval","details":"Check approval list route"}' \
  "$BASE_URL/api.php?route=projects/$project_id/approvals")"
approval_id="$(printf '%s' "$approval" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["id"] ?? "";')"
test -n "$approval_id"
curl -fsS -b "$TMP_DIR/cookies" "$BASE_URL/api.php?route=projects/$project_id/approvals" | grep -q 'Smoke approval'

# Logout invalidates the session.
curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -X POST "$BASE_URL/api.php?route=auth/logout" | grep -q '"ok":true'
status="$(curl -sS -o "$TMP_DIR/me.json" -w '%{http_code}' -b "$TMP_DIR/cookies" "$BASE_URL/api.php?route=auth/me")"
test "$status" = "401"

# Viewer can read an assigned project but cannot perform writes.
curl -fsS -c "$TMP_DIR/viewer-cookies" -H 'Content-Type: application/json' \
  -d '{"email":"viewer@example.test","password":"viewer-password-123"}' \
  "$BASE_URL/api.php?route=auth/login" >"$TMP_DIR/viewer-login.json"
viewer_csrf="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["csrf_token"] ?? "";' "$TMP_DIR/viewer-login.json")"
test -n "$viewer_csrf"
curl -fsS -b "$TMP_DIR/viewer-cookies" "$BASE_URL/api.php?route=projects/$project_id" | grep -q 'Smoke Test Project'
status="$(curl -sS -o "$TMP_DIR/viewer-write.json" -w '%{http_code}' -b "$TMP_DIR/viewer-cookies" \
  -H "X-CSRF-Token: $viewer_csrf" -H 'Content-Type: application/json' \
  -d '{"name":"Viewer Must Not Create"}' "$BASE_URL/api.php?route=projects")"
test "$status" = "403"

echo "API smoke tests passed: health, registration, CSRF, sessions, projects, tasks, users, memberships, approvals, logout, and viewer permissions."
