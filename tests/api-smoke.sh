#!/usr/bin/env bash
set -euo pipefail

BASE_URL="http://127.0.0.1:8787"
TMP_DIR="$(mktemp -d)"
export PROJECT_DASHBOARD_DB="$TMP_DIR/test.sqlite"
php -S 127.0.0.1:8787 -t . >"$TMP_DIR/server.log" 2>&1 &
SERVER_PID=$!
cleanup() {
  kill "$SERVER_PID" 2>/dev/null || true
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

for attempt in $(seq 1 30); do
  if curl -fsS "$BASE_URL/api.php?route=health" >/dev/null; then break; fi
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
echo "API smoke tests passed: health, first-admin registration, session, project create/read, task create."
