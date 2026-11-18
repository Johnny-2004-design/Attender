#!/usr/bin/env bash
# Quick API smoke against a running server (default localhost/attender)
set -euo pipefail
BASE="${1:-http://127.0.0.1:8080/api/index.php}"
: "${SMOKE_EMAIL:?set SMOKE_EMAIL}" "${SMOKE_PASSWORD:?set SMOKE_PASSWORD}"
COOKIE=$(mktemp)
CSRF=$(curl -s -c "$COOKIE" -b "$COOKIE" "$BASE?r=csrf" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["csrf"];')
curl -s -c "$COOKIE" -b "$COOKIE" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -d "{\"email\":\"$SMOKE_EMAIL\",\"password\":\"$SMOKE_PASSWORD\",\"_csrf\":\"$CSRF\"}" \
  "$BASE?r=auth/login" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo ($j["ok"]??false)?"LOGIN OK\n":"LOGIN FAIL\n"; exit(($j["ok"]??false)?0:1);'
curl -s -b "$COOKIE" "$BASE?r=dashboard" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo "students=".$j["totals"]["students"]." sessions=".$j["totals"]["sessions"]."\n";'
rm -f "$COOKIE"
