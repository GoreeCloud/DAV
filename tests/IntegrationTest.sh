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
expect "$(status "$base/.well-known/caldav")" 308 'local HTTP development CalDAV discovery when TLS disabled'
expect "$(status "$base/.well-known/carddav")" 308 'local HTTP development CardDAV discovery when TLS disabled'
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

# A separately versioned vCard 4 resource must retain multiple typed emails
# and an unrecognized X-property. All fixture domains are deliberately invalid.
cat >"$work/contact-v4.vcf" <<'VCARD4'
BEGIN:VCARD
VERSION:4.0
FN:Example Four Contact
N:Contact;Example;;;
UID:urn:uuid:22222222-2222-4222-8222-222222222222
EMAIL;TYPE=home:home@example.invalid
EMAIL;TYPE=work:work@example.invalid
X-GOREECLOUD-TEST:opaque-synthetic-property
END:VCARD
VCARD4
contact_v4_url="$base/addressbooks/alice/default/test-v4.vcf"
v4_code="$(status -u 'alice:alice-test-password-12345' -X PUT \
  -H 'Content-Type: text/vcard; charset=utf-8' --data-binary @"$work/contact-v4.vcf" "$contact_v4_url")"
if [[ "$v4_code" != 201 && "$v4_code" != 204 ]]; then
  expect "$v4_code" 201 'CardDAV vCard 4 PUT'
fi
expect "$(status -u 'alice:alice-test-password-12345' "$contact_v4_url")" 200 'CardDAV vCard 4 GET'
grep -q '^VERSION:4.0' "$work/response"
grep -q '^EMAIL;TYPE=home:home@example.invalid' "$work/response"
grep -q '^EMAIL;TYPE=work:work@example.invalid' "$work/response"
grep -q '^X-GOREECLOUD-TEST:opaque-synthetic-property' "$work/response"

# Accounts get distinct physical WebDAV roots despite the same /files URL.
printf 'confidential-test-content' >"$work/payload.txt"
file_url="$base/files/account-separation.txt"
put_code="$(status -u 'alice:alice-test-password-12345' -X PUT --data-binary @"$work/payload.txt" "$file_url")"
if [[ "$put_code" != 201 && "$put_code" != 204 ]]; then expect "$put_code" 201 'WebDAV PUT'; fi
expect "$(status -u 'alice:alice-test-password-12345' "$file_url")" 200 'WebDAV GET owner'
grep -qx 'confidential-test-content' "$work/response"

# ETags must change even for equal-sized content edits in the same second.
# Upstream FS\File uses only inode/size/mtime and can return a stale ETag.
expect "$(status -u 'alice:alice-test-password-12345' -D "$work/headers-before" "$file_url")" 200 'WebDAV initial ETag GET'
etag_before="$(awk 'tolower($1)=="etag:" {gsub("\r", "", $2); print $2; exit}' "$work/headers-before")"
etag_pattern='^"[a-f0-9]{64}"$'
if [[ ! "$etag_before" =~ $etag_pattern ]]; then
  echo 'Expected quoted SHA-256 WebDAV ETag in initial response' >&2
  exit 1
fi
printf 'CONFIDENTIAL-TEST-CONTENT' >"$work/new-payload.txt"
if [[ "$(wc -c <"$work/new-payload.txt")" -ne "$(wc -c <"$work/payload.txt")" ]]; then
  echo 'ETag fixture sizes must match' >&2
  exit 1
fi
replace_code="$(status -u 'alice:alice-test-password-12345' -X PUT --data-binary @"$work/new-payload.txt" "$file_url")"
if [[ "$replace_code" != 200 && "$replace_code" != 201 && "$replace_code" != 204 ]]; then
  expect "$replace_code" 204 'same-size WebDAV overwrite'
fi
expect "$(status -u 'alice:alice-test-password-12345' -D "$work/headers-after" "$file_url")" 200 'WebDAV updated ETag GET'
etag_after="$(awk 'tolower($1)=="etag:" {gsub("\r", "", $2); print $2; exit}' "$work/headers-after")"
if [[ ! "$etag_after" =~ $etag_pattern || "$etag_after" == "$etag_before" ]]; then
  echo 'WebDAV strong ETag did not change for same-size content change' >&2
  exit 1
fi
grep -qx 'CONFIDENTIAL-TEST-CONTENT' "$work/response"
# An older ETag is stale, not an invitation to serve a 304 cached response.
expect "$(status -u 'alice:alice-test-password-12345' -H "If-None-Match: $etag_before" "$file_url")" 200 'stale conditional ETag GET'
expect "$(status -u 'bob:bob-test-password-67890' "$file_url")" 404 'WebDAV cross-account separation'

for kind in calendars addressbooks; do
  code="$(status -u 'bob:bob-test-password-67890' -X PROPFIND -H 'Depth: 0' "$base/$kind/alice/default/")"
  if [[ "$code" != 403 && "$code" != 404 ]]; then
    printf 'Cross-account %s access must be denied (403/404), got %s\n' "$kind" "$code" >&2
    exit 1
  fi
done

# Cross-account security must cover resource-level GET, writes and deletes,
# not merely collection PROPFIND or different /files/ mounts.
deny_tenant_access() {
  if [[ "$1" != 403 && "$1" != 404 ]]; then
    printf 'Expected tenant denial (403/404) for %s; got HTTP %s\n' "$2" "$1" >&2
    exit 1
  fi
}
deny_tenant_access "$(status -u 'bob:bob-test-password-67890' "$calendar_url")" 'Bob GET Alice event'
deny_tenant_access "$(status -u 'bob:bob-test-password-67890' "$contact_url")" 'Bob GET Alice contact'
deny_tenant_access "$(status -u 'bob:bob-test-password-67890' -X PUT -H 'Content-Type: text/calendar' \
  --data-binary @"$work/event.ics" "$calendar_url")" 'Bob overwrite Alice event'
deny_tenant_access "$(status -u 'bob:bob-test-password-67890' -X PUT -H 'Content-Type: text/vcard' \
  --data-binary @"$work/contact.vcf" "$contact_url")" 'Bob overwrite Alice contact'
deny_tenant_access "$(status -u 'bob:bob-test-password-67890' -X DELETE "$calendar_url")" 'Bob delete Alice event'
deny_tenant_access "$(status -u 'bob:bob-test-password-67890' -X DELETE "$contact_url")" 'Bob delete Alice contact'
expect "$(status -u 'alice:alice-test-password-12345' "$calendar_url")" 200 'Alice event survived Bob writes'
grep -q '^SUMMARY:Example Calendar Event' "$work/response"
expect "$(status -u 'alice:alice-test-password-12345' "$contact_url")" 200 'Alice contact survived Bob writes'
grep -q '^FN:Example Contact' "$work/response"

# The second account also functions normally within its OWN CalDAV/CardDAV
# collections, proving the negative checks do not just deny a broken account.
sed 's/Example Calendar Event/Bob Owned Calendar Event/' "$work/event.ics" >"$work/bob-event.ics"
sed 's/Example Contact/Bob Owned Contact/' "$work/contact.vcf" >"$work/bob-contact.vcf"
bob_calendar_url="$base/calendars/bob/default/bob-event.ics"
bob_contact_url="$base/addressbooks/bob/default/bob-contact.vcf"
bob_event_code="$(status -u 'bob:bob-test-password-67890' -X PUT -H 'Content-Type: text/calendar' \
  --data-binary @"$work/bob-event.ics" "$bob_calendar_url")"
if [[ "$bob_event_code" != 201 && "$bob_event_code" != 204 ]]; then
  expect "$bob_event_code" 201 'Bob CalDAV own event PUT'
fi
bob_contact_code="$(status -u 'bob:bob-test-password-67890' -X PUT -H 'Content-Type: text/vcard' \
  --data-binary @"$work/bob-contact.vcf" "$bob_contact_url")"
if [[ "$bob_contact_code" != 201 && "$bob_contact_code" != 204 ]]; then
  expect "$bob_contact_code" 201 'Bob CardDAV own contact PUT'
fi
expect "$(status -u 'bob:bob-test-password-67890' "$bob_calendar_url")" 200 'Bob reads own event'
grep -q '^SUMMARY:Bob Owned Calendar Event' "$work/response"
expect "$(status -u 'bob:bob-test-password-67890' "$bob_contact_url")" 200 'Bob reads own contact'
grep -q '^FN:Bob Owned Contact' "$work/response"
deny_tenant_access "$(status -u 'alice:alice-test-password-12345' "$bob_calendar_url")" 'Alice GET Bob event'
deny_tenant_access "$(status -u 'alice:alice-test-password-12345' "$bob_contact_url")" 'Alice GET Bob contact'

# Verify resource deletion and change-token REPORT semantics, including real stored objects.
cat >"$work/sync.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<D:sync-collection xmlns:D="DAV:">
  <D:sync-token/>
  <D:sync-level>1</D:sync-level>
  <D:prop><D:getetag/></D:prop>
</D:sync-collection>
XML
for collection in "$base/calendars/alice/default/" "$base/addressbooks/alice/default/"; do
  expect "$(status -u 'alice:alice-test-password-12345' -X REPORT -H 'Content-Type: application/xml' \
    --data-binary @"$work/sync.xml" "$collection")" 207 'DAV sync-collection REPORT'
  grep -q 'sync-token' "$work/response"
done
# A non-owner must not obtain a change feed or sync token from a foreign
# calendar/address book, even when the request body is a valid REPORT.
for kind in calendars addressbooks; do
  deny_tenant_access "$(status -u 'bob:bob-test-password-67890' -X REPORT \
    -H 'Content-Type: application/xml' --data-binary @"$work/sync.xml" \
    "$base/$kind/alice/default/")" "Bob REPORT Alice $kind"
done

# A user cannot rename or delete the mounted WebDAV account root.
root_delete="$(status -u 'alice:alice-test-password-12345' -X DELETE "$base/files/")"
if [[ "$root_delete" != 403 && "$root_delete" != 405 ]]; then expect "$root_delete" 403 'WebDAV mounted root delete denied'; fi

# Nested collections must remain usable under the same private account root.
expect "$(status -u 'alice:alice-test-password-12345' -X MKCOL "$base/files/subfolder/")" 201 'WebDAV nested MKCOL'
printf 'nested-private-content' >"$work/nested-payload"
expect "$(status -u 'alice:alice-test-password-12345' -X PUT --data-binary @"$work/nested-payload" "$base/files/subfolder/old.txt")" 201 'WebDAV nested file PUT'
expect "$(status -u 'alice:alice-test-password-12345' "$base/files/subfolder/old.txt")" 200 'nested file GET'
grep -qx 'nested-private-content' "$work/response"
move_code="$(status -u 'alice:alice-test-password-12345' -X MOVE \
  -H "Destination: $base/files/subfolder/new.txt" "$base/files/subfolder/old.txt")"
if [[ "$move_code" != 201 && "$move_code" != 204 ]]; then expect "$move_code" 201 'WebDAV nested MOVE'; fi
expect "$(status -u 'alice:alice-test-password-12345' "$base/files/subfolder/new.txt")" 200 'moved nested file'
expect "$(status -u 'bob:bob-test-password-67890' "$base/files/subfolder/new.txt")" 404 'nested file tenant isolation'

# Verify WebDAV COPY and write-lock lifecycle on an existing private file.
copy_code="$(status -u 'alice:alice-test-password-12345' -X COPY \
  -H "Destination: $base/files/subfolder/copied.txt" "$base/files/subfolder/new.txt")"
if [[ "$copy_code" != 201 && "$copy_code" != 204 ]]; then
  expect "$copy_code" 201 'WebDAV nested COPY'
fi
expect "$(status -u 'alice:alice-test-password-12345' "$base/files/subfolder/copied.txt")" 200 'copied WebDAV file GET'
grep -qx 'nested-private-content' "$work/response"
cat >"$work/lock.xml" <<'LOCKXML'
<?xml version="1.0" encoding="UTF-8"?>
<D:lockinfo xmlns:D="DAV:">
  <D:lockscope><D:exclusive/></D:lockscope>
  <D:locktype><D:write/></D:locktype>
  <D:owner><D:href>mailto:ci-test@example.invalid</D:href></D:owner>
</D:lockinfo>
LOCKXML
lock_code="$(status -u 'alice:alice-test-password-12345' -X LOCK \
  -H 'Content-Type: application/xml' -H 'Depth: 0' -H 'Timeout: Second-60' \
  -D "$work/lock-headers" --data-binary @"$work/lock.xml" "$base/files/subfolder/copied.txt")"
if [[ "$lock_code" != 200 && "$lock_code" != 201 ]]; then
  expect "$lock_code" 200 'WebDAV exclusive LOCK'
fi
lock_token="$(awk 'tolower($1)=="lock-token:" {gsub("\r", "", $2); print $2; exit}' "$work/lock-headers")"
if [[ -z "$lock_token" ]]; then
  echo 'Expected WebDAV Lock-Token response header' >&2
  exit 1
fi
expect "$(status -u 'alice:alice-test-password-12345' -X PUT \
  --data-binary @"$work/nested-payload" "$base/files/subfolder/copied.txt")" 423 'WebDAV locked PUT denied'
expect "$(status -u 'alice:alice-test-password-12345' -X UNLOCK \
  -H "Lock-Token: $lock_token" "$base/files/subfolder/copied.txt")" 204 'WebDAV UNLOCK'
expect "$(status -u 'alice:alice-test-password-12345' -X PUT \
  --data-binary @"$work/nested-payload" "$base/files/subfolder/copied.txt")" 204 'WebDAV PUT after UNLOCK'

# Simulate a locally introduced symlink. The DAV server must not follow it,
# overwrite its target, or reveal it in collection listings.
printf 'fixture-outside-secret' >"$work/outside-secret"
ln -s "$work/outside-secret" "$DAV_DATA_DIR/files/alice/leak.txt"
leak_code="$(status -u 'alice:alice-test-password-12345' "$base/files/leak.txt")"
if [[ "$leak_code" != 403 && "$leak_code" != 404 ]]; then expect "$leak_code" 403 'linked resource GET denied'; fi
leak_put="$(status -u 'alice:alice-test-password-12345' -X PUT --data-binary @"$work/payload.txt" "$base/files/leak.txt")"
if [[ "$leak_put" != 403 && "$leak_put" != 404 ]]; then expect "$leak_put" 403 'linked resource PUT denied'; fi
grep -qx 'fixture-outside-secret' "$work/outside-secret"
ln -s "$work" "$DAV_DATA_DIR/files/alice/foreign-folder"
leak_dir="$(status -u 'alice:alice-test-password-12345' "$base/files/foreign-folder/outside-secret")"
if [[ "$leak_dir" != 403 && "$leak_dir" != 404 ]]; then expect "$leak_dir" 403 'linked directory traversal denied'; fi
expect "$(status -u 'alice:alice-test-password-12345' -X PROPFIND -H 'Depth: 1' "$base/files/")" 207 'safe WebDAV listing'
if grep -Eq 'leak\.txt|foreign-folder|fixture-outside-secret' "$work/response"; then
  echo 'WebDAV listing disclosed forbidden symlink or outside content' >&2
  exit 1
fi
rm "$DAV_DATA_DIR/files/alice/leak.txt" "$DAV_DATA_DIR/files/alice/foreign-folder"

# A symlinked *parent of the account root* must also be rejected. Previously,
# realpath() normalized /files -> /outside before the guard saw it, allowing
# the account root to silently relocate and expose unexpected content.
mv "$DAV_DATA_DIR/files" "$DAV_DATA_DIR/files-original"
ln -s "$DAV_DATA_DIR/files-original" "$DAV_DATA_DIR/files"
ancestor_code="$(status -u 'alice:alice-test-password-12345' "$base/files/subfolder/new.txt")"
if [[ "$ancestor_code" != 403 && "$ancestor_code" != 500 ]]; then
  expect "$ancestor_code" 403 'linked account-root ancestor denied'
fi
if grep -q 'nested-private-content' "$work/response"; then
  echo 'WebDAV returned file bytes through a linked account-root ancestor' >&2
  exit 1
fi
rm "$DAV_DATA_DIR/files"
mv "$DAV_DATA_DIR/files-original" "$DAV_DATA_DIR/files"
expect "$(status -u 'alice:alice-test-password-12345' "$base/files/subfolder/new.txt")" 200 'normal root restored'

# Disabling a user must immediately reject subsequent requests even if files already exist.
"$PHP_BINARY" bin/dav user:disable alice >/dev/null
expect "$(status -u 'alice:alice-test-password-12345' "$calendar_url")" 401 'disabled account authentication'
"$PHP_BINARY" bin/dav user:enable alice >/dev/null
expect "$(status -u 'alice:alice-test-password-12345' "$calendar_url")" 200 're-enabled account'

expect "$(status -u 'alice:alice-test-password-12345' -X DELETE "$calendar_url")" 204 'CalDAV event DELETE'
expect "$(status -u 'alice:alice-test-password-12345' "$calendar_url")" 404 'deleted event not found'
expect "$(status -u 'alice:alice-test-password-12345' -X DELETE "$contact_url")" 204 'CardDAV contact DELETE'
expect "$(status -u 'alice:alice-test-password-12345' "$contact_url")" 404 'deleted card not found'
expect "$(status -u 'alice:alice-test-password-12345' -X DELETE "$file_url")" 204 'WebDAV file DELETE'
expect "$(status -u 'alice:alice-test-password-12345' "$file_url")" 404 'deleted file not found'

# A second file persists through a consistent, operator-created snapshot.
printf 'backup-test-payload' >"$work/backup-payload"
backup_url="$base/files/backup-test.txt"
expect "$(status -u 'alice:alice-test-password-12345' -X PUT --data-binary @"$work/backup-payload" "$backup_url")" 201 'WebDAV backup fixture'

# Production TLS enforcement must ignore spoofed forwarded headers outside a trusted proxy allowlist.
kill "$server_pid"; wait "$server_pid" 2>/dev/null || true; server_pid=''
DAV_REQUIRE_HTTPS=1 "$PHP_BINARY" -S 127.0.0.1:18799 -t public public/index.php >"$work/secure-server.log" 2>&1 &
server_pid=$!
for _ in {1..30}; do
  if curl -fsS --max-time 2 "$base/healthz" >/dev/null 2>&1; then break; fi
  sleep 0.25
done
expect "$(status -u 'alice:alice-test-password-12345' -H 'X-Forwarded-Proto: https' -X PROPFIND -H 'Depth: 0' "$base/")" 403 'spoofed forwarded TLS'
expect "$(status -u 'alice:alice-test-password-12345' -X PROPFIND -H 'Depth: 0' "$base/")" 403 'plain HTTP rejected'
expect "$(status "$base/.well-known/caldav")" 403 'CalDAV discovery requires TLS'
expect "$(status "$base/.well-known/carddav")" 403 'CardDAV discovery requires TLS'
expect "$(status -H 'X-Forwarded-Proto: https' "$base/.well-known/caldav")" 403 'forged CalDAV discovery TLS denied'
expect "$(status -H 'X-Forwarded-Proto: https' "$base/.well-known/carddav")" 403 'forged CardDAV discovery TLS denied'
kill "$server_pid"; wait "$server_pid" 2>/dev/null || true; server_pid=''

# Snapshot metadata uses SQLite VACUUM INTO, not unsafe copying of live -wal files.
"$PHP_BINARY" bin/dav backup:create "$work/backup" --json >"$work/backup-result.json"
"$PHP_BINARY" bin/dav backup:verify "$work/backup" --json >"$work/verify-result.json"
test -f "$work/backup/dav.sqlite"
test -f "$work/backup/metadata/alice/dav.sqlite"
test -f "$work/backup/metadata/bob/dav.sqlite"
test -f "$work/backup/files/alice/backup-test.txt"
test ! -f "$work/backup/dav.sqlite-wal"
# Validate the snapshot as an isolated restoration, without overwriting existing data.
mkdir -p "$work/restored"
cp -a "$work/backup/." "$work/restored/"
DAV_DATA_DIR="$work/restored" "$PHP_BINARY" bin/dav status --json >"$work/restored-status.json"
grep -q '"accounts":2' "$work/restored-status.json"
# Check tampering is detected; never certify a modified snapshot.
printf 'tampered' >>"$work/backup/files/alice/backup-test.txt"
if "$PHP_BINARY" bin/dav backup:verify "$work/backup" --json >"$work/tamper-result" 2>/dev/null; then
  echo 'Backup verifier accepted corrupted payload' >&2
  exit 1
fi

printf 'GoreeCloud DAV extended HTTP, TLS and offline snapshot integration tests passed.\n'
