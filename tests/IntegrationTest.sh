#!/usr/bin/env bash
# Isolated CI-only HTTP smoke checks. Never use real account passwords here.
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
base='http://127.0.0.1:18799'

"$PHP_BINARY" bin/dav init >/dev/null
printf 'alice-test-password-12345\n' | "$PHP_BINARY" bin/dav user:add alice --password-stdin >/dev/null
printf 'bob-test-password-67890\n' | "$PHP_BINARY" bin/dav user:add bob --password-stdin >/dev/null
"$PHP_BINARY" -S 127.0.0.1:18799 -t public public/index.php >"$work/server.log" 2>&1 &
server_pid=$!
for _ in {1..30}; do
  if curl -fsS --max-time 2 "$base/healthz" >"$work/health" 2>/dev/null; then break; fi
  sleep 0.25
done

status() { curl -sS --max-time 12 -o "$work/response" -w '%{http_code}' "$@"; }
expect() {
  if [[ "$1" != "$2" ]]; then
    printf 'Expected HTTP %s for %s; got %s\n' "$2" "$3" "$1" >&2
    tail -n 30 "$work/server.log" >&2
    exit 1
  fi
}
expect "$(status "$base/healthz")" 200 'health probe'
expect "$(status -X PROPFIND -H 'Depth: 0' "$base/")" 401 'anonymous DAV access'
expect "$(status -u 'alice:wrong-password' -X PROPFIND -H 'Depth: 0' "$base/")" 401 'wrong password'
expect "$(status -u 'alice:alice-test-password-12345' -X PROPFIND -H 'Depth: 0' "$base/")" 207 'authenticated DAV root'
expect "$(status -u 'alice:alice-test-password-12345' -X PROPFIND -H 'Depth: 0' "$base/calendars/alice/default/")" 207 'CalDAV default calendar'
expect "$(status -u 'alice:alice-test-password-12345' -X PROPFIND -H 'Depth: 0' "$base/addressbooks/alice/default/")" 207 'CardDAV default address book'

# Exercise actual interoperable iCalendar and vCard resource writes, not just collection metadata.
cat >"$work/event.ics" <<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//GoreeCloud DAV Integration Test//EN
BEGIN:VEVENT
UID:smoke-event-001@example.invalid
DTSTAMP:20261105T000000Z
DTSTART:20261105T120000Z
DTEND:20261105T130000Z
SUMMARY:Example Calendar Event
END:VEVENT
END:VCALENDAR
ICS
calendar_url="$base/calendars/alice/default/test.ics"
calendar_code="$(status -u 'alice:alice-test-password-12345' -X PUT -H 'Content-Type: text/calendar; charset=utf-8' --data-binary @"$work/event.ics" "$calendar_url")"
if [[ "$calendar_code" != 201 && "$calendar_code" != 204 ]]; then expect "$calendar_code" 201 'CalDAV event PUT'; fi
expect "$(status -u 'alice:alice-test-password-12345' "$calendar_url")" 200 'CalDAV event GET'
grep -q 'SUMMARY:Example Calendar Event' "$work/response"

cat >"$work/contact.vcf" <<'VCF'
BEGIN:VCARD
VERSION:3.0
FN:Example Contact
N:Contact;Example;;;
UID:urn:uuid:11111111-1111-4111-8111-111111111111
END:VCARD
VCF
contact_url="$base/addressbooks/alice/default/test.vcf"
contact_code="$(status -u 'alice:alice-test-password-12345' -X PUT -H 'Content-Type: text/vcard; charset=utf-8' --data-binary @"$work/contact.vcf" "$contact_url")"
if [[ "$contact_code" != 201 && "$contact_code" != 204 ]]; then expect "$contact_code" 201 'CardDAV contact PUT'; fi
expect "$(status -u 'alice:alice-test-password-12345' "$contact_url")" 200 'CardDAV contact GET'
grep -q 'FN:Example Contact' "$work/response"

# Accounts get distinct physical WebDAV roots despite the same /files URL.
printf 'confidential-test-content' >"$work/payload.txt"
file_url="$base/files/account-separation.txt"
put_code="$(status -u 'alice:alice-test-password-12345' -X PUT --data-binary @"$work/payload.txt" "$file_url")"
if [[ "$put_code" != 201 && "$put_code" != 204 ]]; then expect "$put_code" 201 'WebDAV PUT'; fi
expect "$(status -u 'alice:alice-test-password-12345' "$file_url")" 200 'WebDAV GET owner'
grep -qx 'confidential-test-content' "$work/response"
expect "$(status -u 'bob:bob-test-password-67890' "$file_url")" 404 'WebDAV cross-account separation'

for kind in calendars addressbooks; do
  code="$(status -u 'bob:bob-test-password-67890' -X PROPFIND -H 'Depth: 0' "$base/$kind/alice/default/")"
  if [[ "$code" != 403 && "$code" != 404 ]]; then
    printf 'Cross-account %s access must be denied (403/404), got %s\n' "$kind" "$code" >&2
    exit 1
  fi
done

printf 'GoreeCloud DAV HTTP integration tests passed.\n'
