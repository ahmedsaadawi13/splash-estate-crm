#!/usr/bin/env bash
# Smoke test: log in as a demo user and request pages, reporting HTTP status
# and any PHP errors/warnings rendered in the response.
#
# Usage: tests/smoke.sh [base_url] [email] [password] -- path1 path2 ...
#   tests/smoke.sh http://127.0.0.1:8080 john.smith@sunsetrealty.com password -- leads/index deals/index
set -u
BASE=${1:-http://127.0.0.1:8080}; EMAIL=${2:-john.smith@sunsetrealty.com}; PASS=${3:-password}
shift 3 2>/dev/null; [ "${1:-}" = "--" ] && shift
JAR=$(mktemp); trap 'rm -f "$JAR"' EXIT

page=$(curl -s -c "$JAR" -b "$JAR" "$BASE/auth/login")
token=$(printf '%s' "$page" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" \
  --data-urlencode "email=$EMAIL" --data-urlencode "password=$PASS" --data-urlencode "csrf_token=$token" \
  "$BASE/auth/login")
echo "login -> $code"

fail=0
for path in "$@"; do
  body=$(curl -s -c "$JAR" -b "$JAR" -w $'\n%{http_code}' "$BASE/$path")
  status=${body##*$'\n'}; body=${body%$'\n'*}
  errs=$(printf '%s' "$body" | grep -oiE '(Fatal error|Parse error|Warning|Notice|Deprecated|Uncaught|Application error|View does not exist)[^<]{0,160}' | head -3)
  if [ "$status" -ge 400 ] || [ -n "$errs" ]; then fail=1; echo "FAIL $status $path"; [ -n "$errs" ] && printf '     %s\n' "$errs"; else echo "ok   $status $path"; fi
done
exit $fail
