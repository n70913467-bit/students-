#!/usr/bin/env bash
set -euo pipefail

BASE_URL="http://127.0.0.1:8787"
TMP_DIR="$(mktemp -d)"
export PROJECT_DASHBOARD_DB="$TMP_DIR/test.sqlite"
export PROJECT_DASHBOARD_SETUP_KEY="ci-setup-secret"
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
curl -fsS "$BASE_URL/" -o "$TMP_DIR/index.html"
grep -q 'First Admin Setup' "$TMP_DIR/index.html"
curl -fsS "$BASE_URL/app.js" -o "$TMP_DIR/app.js"
grep -q 'createApiClient' "$TMP_DIR/app.js"
storage_status="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/storage/dashboard.sqlite")"
test "$storage_status" = "404"
unauth_status="$(curl -sS -o "$TMP_DIR/unauth.json" -w '%{http_code}' "$BASE_URL/api/projects")"
test "$unauth_status" = "401"

wrong_setup="$(curl -sS -o "$TMP_DIR/wrong-setup.json" -w '%{http_code}' -H 'Content-Type: application/json' \
  -d '{"name":"Test Admin","email":"admin@example.test","password":"correct-horse-battery","setup_key":"wrong"}' "$BASE_URL/api/auth/register")"
test "$wrong_setup" = "403"
register="$(curl -fsS -D "$TMP_DIR/register.headers" -c "$TMP_DIR/cookies" -H 'Content-Type: application/json' \
  -d '{"name":"Test Admin","email":"admin@example.test","password":"correct-horse-battery","setup_key":"ci-setup-secret"}' \
  "$BASE_URL/api/auth/register")"
grep -qi 'HttpOnly' "$TMP_DIR/register.headers"
grep -qi 'SameSite=Strict' "$TMP_DIR/register.headers"
stored_hash="$(php -r '$p=new PDO("sqlite:".$argv[1]); $s=$p->prepare("SELECT password_hash FROM users WHERE email=?"); $s->execute(["admin@example.test"]); echo $s->fetchColumn();' "$TMP_DIR/test.sqlite")"
test "$stored_hash" != "correct-horse-battery"
php -r 'exit(password_verify("correct-horse-battery", $argv[1]) ? 0 : 1);' "$stored_hash"
repeat_register="$(curl -sS -o "$TMP_DIR/repeat-register.json" -w '%{http_code}' -H 'Content-Type: application/json' \
  -d '{"name":"Second Admin","email":"second@example.test","password":"another-long-password","setup_key":"ci-setup-secret"}' "$BASE_URL/api/auth/register")"
test "$repeat_register" = "403"

# First-admin registration must fail closed if the setup key is missing.
env -u PROJECT_DASHBOARD_SETUP_KEY php -S 127.0.0.1:8788 router.php >"$TMP_DIR/no-key-server.log" 2>&1 &
NO_KEY_SERVER_PID=$!
for attempt in $(seq 1 30); do
  if curl -fsS "http://127.0.0.1:8788/api/health" >/dev/null; then break; fi
  sleep 1
done
missing_key_status="$(curl -sS -o "$TMP_DIR/missing-key.json" -w '%{http_code}' -H 'Content-Type: application/json' \
  -d '{"name":"Unconfigured Admin","email":"unconfigured@example.test","password":"correct-horse-battery"}' 
  "http://127.0.0.1:8788/api/auth/register")"
kill "$NO_KEY_SERVER_PID" 2>/dev/null || true
test "$missing_key_status" = "503"
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
viewer_hash="$(php -r '$p=new PDO("sqlite:".$argv[1]); $s=$p->prepare("SELECT password_hash FROM users WHERE email=?"); $s->execute(["viewer@example.test"]); echo $s->fetchColumn();' "$TMP_DIR/test.sqlite")"
test "$viewer_hash" != "viewer-password-123"
php -r 'exit(password_verify("viewer-password-123", $argv[1]) ? 0 : 1);' "$viewer_hash"
echo "stage: admin user creation and password hashing"
curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$user_id,\"member_role\":\"viewer\"}" \
  "$BASE_URL/api/projects/$project_id/members" | grep -q '"ok":true'
manager_user="$(curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"name":"Test Manager","email":"manager@example.test","password":"manager-password-123","role":"member"}' "$BASE_URL/api/users")"
manager_id="$(printf '%s' "$manager_user" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["id"] ?? "";')"
test -n "$manager_id"
curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d "{\"user_id\":$manager_id,\"member_role\":\"manager\"}" "$BASE_URL/api/projects/$project_id/members" | grep -q '"ok":true'
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
assigned_approval="$(curl -fsS -b "$TMP_DIR/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d "{\"title\":\"Assigned approval\",\"approver_id\":$manager_id}" "$BASE_URL/api/projects/$project_id/approvals")"
assigned_approval_id="$(printf '%s' "$assigned_approval" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["id"] ?? "";')"
test -n "$assigned_approval_id"
curl -fsS -c "$TMP_DIR/manager-cookies" -H 'Content-Type: application/json' \
  -d '{"email":"manager@example.test","password":"manager-password-123"}' "$BASE_URL/api/auth/login" >"$TMP_DIR/manager-login.json"
manager_csrf="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["csrf_token"] ?? "";' "$TMP_DIR/manager-login.json")"
test -n "$manager_csrf"
curl -fsS -b "$TMP_DIR/manager-cookies" "$BASE_URL/api/projects/$project_id/members" | grep -q 'manager@example.test'
manager_user_list="$(curl -sS -o "$TMP_DIR/manager-users.json" -w '%{http_code}' -b "$TMP_DIR/manager-cookies" "$BASE_URL/api/users")"
test "$manager_user_list" = "403"
curl -fsS -b "$TMP_DIR/manager-cookies" -H "X-CSRF-Token: $manager_csrf" -H 'Content-Type: application/json' \
  -X PATCH -d '{"status":"approved"}' "$BASE_URL/api/approvals/$assigned_approval_id" | grep -q '"ok":true'

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

# Project deletion is tested only after the viewer access check.
curl -fsS -b "$TMP_DIR/admin-again" -H "X-CSRF-Token: $admin_csrf" -X DELETE "$BASE_URL/api/projects/$project_id" | grep -q '"ok":true'
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$TMP_DIR/admin-again" "$BASE_URL/api/projects/$project_id")"
test "$status" = "404"

echo "API smoke tests passed: health, registration, CSRF, sessions, projects, tasks, users, memberships, approvals, logout, and viewer permissions."
