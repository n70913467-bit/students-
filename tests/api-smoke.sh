#!/usr/bin/env bash
set -euo pipefail

BASE_URL="http://127.0.0.1:8787"
TMP_DIR="$(mktemp -d)"
export PROJECT_DASHBOARD_DB="$TMP_DIR/test.sqlite"
php -S 127.0.0.1:8787 router.php >"$TMP_DIR/server.log" 2>&1 &
SERVER_PID=$!
cleanup() {
  result=$?
  if [ "$result" -ne 0 ]; then
    echo "API smoke test failed; PHP server log follows:"
    cat "$TMP_DIR/server.log"
  fi
  kill "$SERVER_PID" 2>/dev/null || true
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

for attempt in $(seq 1 30); do
  if curl -fsS "$BASE_URL/api/health" >/dev/null; then break; fi
  sleep 1
done

health="$(curl -fsS "$BASE_URL/api/health")"
echo "$health" | grep -q '"ok":true'
curl -fsS "$BASE_URL/" | grep -q 'First Admin Setup'
curl -fsS "$BASE_URL/app.js" | grep -q 'createApiClient'
storage_status="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/storage/dashboard.sqlite")"
test "$storage_status" = "404"

register="$(curl -fsS -D "$TMP_DIR/register.headers" -c "$TMP_DIR/cookies" -H 'Content-Type: application/json' \
  -d '{"name":"Test Admin","email":"admin@example.test","password":"correct-horse-battery"}' \
  "$BASE_URL/api/auth/register")"
grep -qi 'HttpOnly' "$TMP_DIR/register.headers"
grep -qi 'SameSite=Strict' "$TMP_DIR/register.headers"
stored_hash="$(php -r '$p=new PDO("sqlite:".$argv[1]); echo $p->query("SELECT password_hash FROM users WHERE email=\'admin@example.test\'")->fetchColumn();' "$TMP_DIR/test.sqlite")"
test "$stored_hash" != "correct-horse-battery"
php -r 'exit(password_verify("correct-horse-battery", $argv[1]) ? 0 : 1);' "$stored_hash"
repeat_register="$(curl -sS -o "$TMP_DIR/repeat-register.json" -w '%{http_code}' -H 'Content-Type: application/json' \
  -d '{"name":"Second Admin","email":"second@example.test","password":"another-long-password"}' "$BASE_URL/api/auth/register")"
test "$repeat_register" = "403"
csrf="$(printf '%s' "$register" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["csrf_token"] ?? "";')"
test -n "$csrf"
echo "stage: first admin registration"

me="$(curl -fsS -b "$TMP_DIR/cookies" "$BASE_URL/api/auth/me")"
echo "$me" | grep -q '"role":"admin"'

project="$(curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"name":"Smoke Test Project","status":"running","next_action":"Verify API"}' \
  "$BASE_URL/api/projects")"
project_id="$(printf '%s' "$project" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["id"] ?? "";')"
test -n "$project_id"
echo "stage: project creation"

task="$(curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"title":"Smoke Test Task","priority":"high"}' \
  "$BASE_URL/api/projects/$project_id/tasks")"
task_id="$(printf '%s' "$task" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["id"] ?? "";')"
test -n "$task_id"
echo "stage: task creation"

curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"message":"Smoke activity entry"}' "$BASE_URL/api/projects/$project_id/activities" | grep -q '"ok":true'
curl -fsS -b "$TMP_DIR/cookies" "$BASE_URL/api/projects/$project_id" | grep -q 'Smoke Test Project'
curl -fsS -b "$TMP_DIR/cookies" "$BASE_URL/api/projects/$project_id" | grep -q '"access":"manager"'
# A write without the session CSRF token must be rejected.
status="$(curl -sS -o "$TMP_DIR/csrf.json" -w '%{http_code}' -b "$TMP_DIR/cookies" -H 'Content-Type: application/json' \
  -d '{"name":"Should Not Exist","status":"planned"}' "$BASE_URL/api/projects")"
test "$status" = "419"
echo "stage: CSRF rejection"

# Admin can create a user and assign that user to a project.
new_user="$(curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"name":"Test Viewer","email":"viewer@example.test","password":"viewer-password-123","role":"viewer"}' \
  "$BASE_URL/api/users")"
user_id="$(printf '%s' "$new_user" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["id"] ?? "";')"
test -n "$user_id"
echo "stage: admin user creation"
curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$user_id,\"member_role\":\"viewer\"}" \
  "$BASE_URL/api/projects/$project_id/members" | grep -q '"ok":true'
curl -fsS -b "$TMP_DIR/cookies" "$BASE_URL/api/users" | grep -q 'viewer@example.test'
curl -fsS -b "$TMP_DIR/cookies" "$BASE_URL/api/projects/$project_id/members" | grep -q '"member_role":"viewer"'

invalid_approver="$(curl -sS -o "$TMP_DIR/invalid-approver.json" -w '%{http_code}' -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"title":"Invalid approver","approver_id":999999}' "$BASE_URL/api/projects/$project_id/approvals")"
test "$invalid_approver" = "422"
approval="$(curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"title":"Smoke approval","details":"Check approval list route"}' \
  "$BASE_URL/api/projects/$project_id/approvals")"
approval_id="$(printf '%s' "$approval" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["id"] ?? "";')"
test -n "$approval_id"
echo "stage: approval creation"
curl -fsS -b "$TMP_DIR/cookies" "$BASE_URL/api/projects/$project_id/approvals" | grep -q 'Smoke approval'
curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -X PATCH -d '{"status":"approved"}' "$BASE_URL/api/approvals/$approval_id" | grep -q '"ok":true'
curl -fsS -b "$TMP_DIR/cookies" "$BASE_URL/api/projects/$project_id/approvals" | grep -q '"status":"approved"'

# Logout invalidates the session.
curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -X POST "$BASE_URL/api/auth/logout" | grep -q '"ok":true'
status="$(curl -sS -o "$TMP_DIR/me.json" -w '%{http_code}' -b "$TMP_DIR/cookies" "$BASE_URL/api/auth/me")"
test "$status" = "401"
echo "stage: logout"

# Invalid credentials stay generic; repeated failures are throttled.
bad_status="$(curl -sS -o "$TMP_DIR/bad-login.json" -w '%{http_code}' -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.test","password":"wrong-password"}' "$BASE_URL/api/auth/login")"
test "$bad_status" = "401"
for attempt in $(seq 1 10); do
  bad_status="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' \
    -d '{"email":"blocked@example.test","password":"wrong-password"}' "$BASE_URL/api/auth/login")"
  test "$bad_status" = "401"
done
locked_status="$(curl -sS -o "$TMP_DIR/locked.json" -w '%{http_code}' -H 'Content-Type: application/json' \
  -d '{"email":"blocked@example.test","password":"wrong-password"}' "$BASE_URL/api/auth/login")"
test "$locked_status" = "429"

# Verify task deletion and admin-only project deletion.
curl -fsS -c "$TMP_DIR/admin-again" -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.test","password":"correct-horse-battery"}' "$BASE_URL/api/auth/login" >"$TMP_DIR/admin-again.json"
admin_csrf="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["csrf_token"] ?? "";' "$TMP_DIR/admin-again.json")"
test -n "$admin_csrf"
curl -fsS -b "$TMP_DIR/admin-again" -H "X-CSRF-Token: $admin_csrf" -X DELETE "$BASE_URL/api/tasks/$task_id" | grep -q '"ok":true'
curl -fsS -b "$TMP_DIR/admin-again" -H "X-CSRF-Token: $admin_csrf" -X DELETE "$BASE_URL/api/projects/$project_id" | grep -q '"ok":true'
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$TMP_DIR/admin-again" "$BASE_URL/api/projects/$project_id")"
test "$status" = "404"

# Viewer can read an assigned project but cannot perform writes.
curl -fsS -c "$TMP_DIR/viewer-cookies" -H 'Content-Type: application/json' \
  -d '{"email":"viewer@example.test","password":"viewer-password-123"}' \
  "$BASE_URL/api/auth/login" >"$TMP_DIR/viewer-login.json"
viewer_csrf="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["csrf_token"] ?? "";' "$TMP_DIR/viewer-login.json")"
test -n "$viewer_csrf"
curl -fsS -b "$TMP_DIR/viewer-cookies" "$BASE_URL/api/projects/$project_id" | grep -q 'Smoke Test Project'
status="$(curl -sS -o "$TMP_DIR/viewer-write.json" -w '%{http_code}' -b "$TMP_DIR/viewer-cookies" \
  -H "X-CSRF-Token: $viewer_csrf" -H 'Content-Type: application/json' \
  -d '{"name":"Viewer Must Not Create"}' "$BASE_URL/api/projects")"
test "$status" = "403"

echo "API smoke tests passed: health, registration, CSRF, sessions, projects, tasks, users, memberships, approvals, logout, and viewer permissions."
