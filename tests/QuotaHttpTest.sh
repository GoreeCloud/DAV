#!/usr/bin/env bash
# Isolated HTTP regression. Never targets an existing data volume or public DAV service.
set -euo pipefail
: "${PHP_BINARY:=php}"
work="$(mktemp -d)"
server_pid=''
cleanup() {
  if [[ -n "$server_pid" ]]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi
  rm -rf -- "$work"
}
trap cleanup EXIT
export DAV_DATA_DIR="$work/data" DAV_REQUIRE_HTTPS=0 DAV_BASE_URI=/
export DAV_ACCOUNT_FILE_QUOTA_BYTES=10
base='http://127.0.0.1:18821'
"$PHP_BINARY" bin/dav init >/dev/null
printf 'alice-disposable-password-12345\n' | "$PHP_BINARY" bin/dav user:add alice --password-stdin >/dev/null
printf 'bob-disposable-password-12345\n' | "$PHP_BINARY" bin/dav user:add bob --password-stdin >/dev/null
"$PHP_BINARY" -S 127.0.0.1:18821 -t public public/index.php >"$work/server.log" 2>&1 &
server_pid=$!
for attempt in $(seq 1 30); do
  if curl -fsS --max-time 2 "$base/healthz" > /dev/null 2>&1; then break; fi
  sleep 0.25
done
status() { curl --silent --show-error --max-time 12 --output "$work/response" --write-out '%{http_code}' "$@"; }
expect() {
  if [[ "$1" != "$2" ]]; then
    echo "Quota HTTP error for $3: expected $2 got $1" >&2
    tail -n 30 "$work/server.log" >&2
    exit 1
  fi
}
alice='alice:alice-disposable-password-12345'
bob='bob:bob-disposable-password-12345'
printf '12345678' > "$work/eight"
printf 'abcdefghijk' > "$work/eleven"
printf 'ABCD' > "$work/four"
printf '123456' > "$work/six"
expect "$(status -u "$alice" -X PUT --data-binary @"$work/eight" "$base/files/note.txt")" 201 'alice initial upload'
expect "$(status -u "$bob" -X PUT --data-binary @"$work/eight" "$base/files/bob.txt")" 201 'bob independent quota'
expect "$(status -u "$alice" -X PUT --data-binary @"$work/six" "$base/files/too-much.txt")" 507 'alice quota rejection'
expect "$(status -u "$alice" "$base/files/too-much.txt")" 404 'no partial rejected upload'
expect "$(status -u "$alice" -X PUT --data-binary @"$work/eleven" "$base/files/note.txt")" 507 'rejected replacement'
expect "$(status -u "$alice" "$base/files/note.txt")" 200 'unchanged file after failed replacement'
[[ "$(cat "$work/response")" == '12345678' ]] || { echo 'Failed overwrite corrupted old file' >&2; exit 1; }
expect "$(status -u "$alice" -X PUT --data-binary @"$work/four" "$base/files/note.txt")" 204 'smaller replacement'
expect "$(status -u "$alice" -X PUT --data-binary @"$work/six" "$base/files/another.txt")" 201 'exact limit'
expect "$(status -u "$alice" -X PUT --data-binary @"$work/four" "$base/files/over.txt")" 507 'no extra capacity'
expect "$(status -u "$alice" -X DELETE "$base/files/another.txt")" 204 'delete frees capacity'
expect "$(status -u "$alice" -X PUT --data-binary @"$work/six" "$base/files/new.txt")" 201 'capacity reused'
expect "$(status -u "$bob" "$base/files/bob.txt")" 200 'bob unaffected by alice'
[[ "$(cat "$work/response")" == '12345678' ]] || { echo 'Bob data changed across accounts' >&2; exit 1; }
echo 'Per-account quota HTTP 507, overwrite rollback and isolation tests passed.'
