#!/usr/bin/env bash
# Disposable concurrency-safety precondition tests for CalDAV, CardDAV and WebDAV.
# All credentials and data are synthetic; never target a deployed service.
set -euo pipefail
umask 077
PHP_BINARY=php
work="$(mktemp -d -t goreecloud-dav-preconditions-XXXXXXXX)"
base='http://127.0.0.1:18891'
server_pid=''
cleanup() {
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  rm -rf -- "$work"
}
trap cleanup EXIT

export DAV_DATA_DIR="$work/data" DAV_REQUIRE_HTTPS=0 DAV_BASE_URI=/
unset DAV_ACCOUNT_FILE_QUOTA_BYTES || true
"$PHP_BINARY" bin/dav init >/dev/null
printf 'alice-conditional-test-password-12345\n' | "$PHP_BINARY" bin/dav user:add alice --password-stdin >/dev/null
printf 'bob-conditional-test-password-67890\n' | "$PHP_BINARY" bin/dav user:add bob --password-stdin >/dev/null
"$PHP_BINARY" -S 127.0.0.1:18891 -t public public/index.php >"$work/http.log" 2>&1 &
server_pid=$!
ready=0
for _ in $(seq 1 50); do
  if curl --silent --fail --max-time 2 "$base/healthz" >/dev/null 2>&1; then ready=1; break; fi
  sleep 0.2
done
if [[ "$ready" != 1 ]]; then
  echo 'DAV disposable HTTP server failed to start' >&2
  tail -n 30 "$work/http.log" >&2 || true
  exit 1
fi

alice='alice:alice-conditional-test-password-12345'
bob='bob:bob-conditional-test-password-67890'
status() {
  curl --silent --show-error --max-time 15 --output "$work/response" --write-out '%{http_code}' "$@"
}
expect() {
  if [[ "$1" != "$2" ]]; then
    printf 'Conditional-write test %s: expected HTTP %s, got %s\n' "$3" "$2" "$1" >&2
    tail -n 25 "$work/http.log" >&2 || true
    exit 1
  fi
}
expected_updated() {
  case "$1" in 200|204) return 0 ;; esac
  echo "Conditional PUT expected HTTP 200 or 204; got $1" >&2
  exit 1
}
read_etag() {
  local url="$1" code etag
  code="$(status -u "$alice" -D "$work/etag-headers" "$url")"
  expect "$code" 200 'ETag GET'
  etag="$(awk 'tolower($1)=="etag:" {gsub("\r", "", $2); print $2; exit}' "$work/etag-headers")"
  if [[ ! "$etag" =~ ^\"[^\"]+\"$ ]]; then
    echo "Missing strong quoted ETag for $url" >&2
    exit 1
  fi
  printf '%s' "$etag"
}
assert_body() {
  local url="$1" marker="$2"
  expect "$(status -u "$alice" "$url")" 200 'record preserved'
  if ! grep -Fq "$marker" "$work/response"; then
    echo "Missing expected content marker: $marker" >&2
    exit 1
  fi
}
check_conditional_writes() {
  local kind="$1" url="$2" type="$3" initial="$4" newer="$5" newest="$6" marker_b="$7" marker_c="$8"
  local old_tag current_tag newest_tag
  expect "$(status -u "$alice" -X PUT -H "Content-Type: $type" \
    -H 'If-None-Match: *' --data-binary @"$initial" "$url")" 201 "$kind create-only PUT"
  old_tag="$(read_etag "$url")"
  expected_updated "$(status -u "$alice" -X PUT -H "Content-Type: $type" \
    --data-binary @"$newer" "$url")"
  current_tag="$(read_etag "$url")"
  if [[ "$current_tag" == "$old_tag" ]]; then
    echo "$kind ETag did not change after an edit" >&2
    exit 1
  fi
  # Simulated offline client holds old_tag while another client has updated.
  expect "$(status -u "$alice" -X PUT -H "Content-Type: $type" \
    -H "If-Match: $old_tag" --data-binary @"$newest" "$url")" 412 "$kind stale PUT denied"
  assert_body "$url" "$marker_b"
  expect "$(status -u "$alice" -X DELETE -H "If-Match: $old_tag" "$url")" 412 "$kind stale DELETE denied"
  assert_body "$url" "$marker_b"
  expect "$(status -u "$alice" -X PUT -H "Content-Type: $type" \
    -H 'If-None-Match: *' --data-binary @"$newest" "$url")" 412 "$kind duplicate create-only PUT denied"
  assert_body "$url" "$marker_b"
  expected_updated "$(status -u "$alice" -X PUT -H "Content-Type: $type" \
    -H "If-Match: $current_tag" --data-binary @"$newest" "$url")"
  newest_tag="$(read_etag "$url")"
  if [[ "$newest_tag" == "$current_tag" ]]; then
    echo "$kind ETag failed to advance on matching conditional PUT" >&2
    exit 1
  fi
  assert_body "$url" "$marker_c"
  expect "$(status -u "$alice" -X DELETE -H "If-Match: $newest_tag" "$url")" 204 "$kind matching conditional DELETE"
  expect "$(status -u "$alice" "$url")" 404 "$kind deleted record"
  printf 'Passed conditional create/update/delete for %s\n' "$kind"
}

cat >"$work/event-a.ics" <<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//GoreeCloud Conditional Writes CI//EN
BEGIN:VEVENT
UID:conditional-event@example.invalid
DTSTAMP:20261107T120000Z
DTSTART:20261107T130000Z
DTEND:20261107T140000Z
SUMMARY:Initial-CI-Event
END:VEVENT
END:VCALENDAR
ICS
sed 's/Initial-CI-Event/Second-CI-Event/' "$work/event-a.ics" >"$work/event-b.ics"
sed 's/Initial-CI-Event/Final-CI-Event/' "$work/event-a.ics" >"$work/event-c.ics"
check_conditional_writes 'CalDAV event' "$base/calendars/alice/default/conditional.ics" \
  'text/calendar; charset=utf-8' "$work/event-a.ics" "$work/event-b.ics" "$work/event-c.ics" \
  'SUMMARY:Second-CI-Event' 'SUMMARY:Final-CI-Event'

cat >"$work/card-a.vcf" <<'VCF'
BEGIN:VCARD
VERSION:3.0
UID:urn:uuid:55555555-5555-4555-8555-555555555555
FN:Initial-CI-Contact
N:Contact;Initial;;;
END:VCARD
VCF
sed 's/Initial-CI-Contact/Second-CI-Contact/' "$work/card-a.vcf" >"$work/card-b.vcf"
sed 's/Initial-CI-Contact/Final-CI-Contact/' "$work/card-a.vcf" >"$work/card-c.vcf"
check_conditional_writes 'CardDAV contact' "$base/addressbooks/alice/default/conditional.vcf" \
  'text/vcard; charset=utf-8' "$work/card-a.vcf" "$work/card-b.vcf" "$work/card-c.vcf" \
  'FN:Second-CI-Contact' 'FN:Final-CI-Contact'

printf 'Initial-CI-File\n' >"$work/file-a"
printf 'Second-CI-File\n' >"$work/file-b"
printf 'Final-CI-File\n' >"$work/file-c"
check_conditional_writes 'WebDAV file' "$base/files/conditional.txt" \
  'text/plain; charset=utf-8' "$work/file-a" "$work/file-b" "$work/file-c" \
  'Second-CI-File' 'Final-CI-File'

# Cross-account resource URLs are never readable by another authenticated user.
for path in calendars/alice/default/conditional.ics addressbooks/alice/default/conditional.vcf; do
  code="$(status -u "$bob" "$base/$path")"
  if [[ "$code" != 403 && "$code" != 404 ]]; then
    echo "Cross-account access leaked through $path (HTTP $code)" >&2
    exit 1
  fi
done
expect "$(status -u "$bob" "$base/files/conditional.txt")" 404 'other account WebDAV root'
echo 'CalDAV, CardDAV and WebDAV conditional writes rejected stale updates and preserved data.'
