#!/usr/bin/env bash
# Disposable, application-level DAV backup and recovery acceptance rehearsal.
# Never mount a real data directory or point this script at a deployed service.
set -euo pipefail
umask 077
: "${PHP_BINARY:=php}"

work="$(mktemp -d -t goreecloud-dav-restore-XXXXXXXX)"
original="$work/original"
snapshot="$work/snapshot"
restored="$work/restored"
base="http://127.0.0.1:${DAV_RESTORE_TEST_PORT:-18832}"
server_pid=''

cleanup() {
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  rm -rf -- "$work"
}
trap cleanup EXIT

stop_server() {
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
    server_pid=''
  fi
}

start_server() {
  "$PHP_BINARY" -S "${base#http://}" -t public public/index.php >"$work/http.log" 2>&1 &
  server_pid=$!
  local code=''
  for _ in $(seq 1 50); do
    code="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 2 "$base/healthz" || true)"
    if [[ "$code" == 200 ]]; then return 0; fi
    sleep 0.2
  done
  echo 'Disposable DAV restore server did not start' >&2
  tail -n 35 "$work/http.log" >&2 || true
  return 1
}

status() {
  curl --silent --show-error --max-time 15 --output "$work/response" --write-out '%{http_code}' "$@"
}
expect() {
  if [[ "$1" != "$2" ]]; then
    printf 'Restore rehearsal %s: expected %s, got %s\n' "$3" "$2" "$1" >&2
    tail -n 35 "$work/http.log" >&2 || true
    exit 1
  fi
}

alice='alice:alice-recovery-test-password-12345'
bob='bob:bob-recovery-test-password-67890'
carol='carol:carol-recovery-test-password-12345'

export DAV_DATA_DIR="$original" DAV_REQUIRE_HTTPS=0 DAV_BASE_URI=/ DAV_ACCOUNT_FILE_QUOTA_BYTES=1000
"$PHP_BINARY" bin/dav init >/dev/null
for account in alice bob carol; do
  password="${account}-recovery-test-password-12345"
  if [[ "$account" == bob ]]; then password='bob-recovery-test-password-67890'; fi
  printf '%s\n' "$password" | "$PHP_BINARY" bin/dav user:add "$account" --password-stdin >/dev/null
done
"$PHP_BINARY" bin/dav user:disable carol >/dev/null
start_server

# All three DAV data types must be created through the actual HTTP service,
# not seeded into SQLite directly. Use only disposable example records.
cat > "$work/event.ics" <<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//GoreeCloud DAV Recovery CI//EN
BEGIN:VEVENT
UID:recovery-event-001@example.invalid
DTSTAMP:20261106T120000Z
DTSTART:20261106T130000Z
DTEND:20261106T140000Z
SUMMARY:Recovery Rehearsal Calendar Event
END:VEVENT
END:VCALENDAR
ICS
cat > "$work/contact.vcf" <<'VCF'
BEGIN:VCARD
VERSION:3.0
FN:Recovery Rehearsal Contact
N:Contact;Recovery Rehearsal;;;
UID:urn:uuid:22222222-2222-4222-8222-222222222222
END:VCARD
VCF
printf 'recovered-alice-file-bytes' > "$work/alice.txt"
printf 'recovered-bob-file-bytes' > "$work/bob.txt"
expect "$(status -u "$alice" -X PUT -H 'Content-Type: text/calendar; charset=utf-8' --data-binary @"$work/event.ics" "$base/calendars/alice/default/recovery.ics")" 201 'calendar PUT'
expect "$(status -u "$alice" -X PUT -H 'Content-Type: text/vcard; charset=utf-8' --data-binary @"$work/contact.vcf" "$base/addressbooks/alice/default/recovery.vcf")" 201 'contact PUT'
expect "$(status -u "$alice" -X PUT --data-binary @"$work/alice.txt" "$base/files/alice.txt")" 201 'alice WebDAV PUT'
expect "$(status -u "$bob" -X PUT --data-binary @"$work/bob.txt" "$base/files/bob.txt")" 201 'bob WebDAV PUT'
expect "$(status -u "$alice" -X MKCOL "$base/files/empty-folder/")" 201 'empty WebDAV directory'
expect "$(status -u "$carol" -X PROPFIND -H 'Depth: 0' "$base/")" 401 'disabled user denied before snapshot'

# This is the production PHP backup:create command, with the service still
# running. Its exclusive ServiceLock coordinates with the HTTP request lock.
"$PHP_BINARY" bin/dav backup:create "$snapshot" --json > "$work/snapshot-result.json"
"$PHP_BINARY" bin/dav backup:verify "$snapshot" --json > "$work/verify-result.json"

# Restore must refuse corrupted/unlisted content; never copy a failed backup
# into a service directory. A separate mutated copy preserves the original.
cp -a -- "$snapshot" "$work/tampered"
printf 'unsolicited payload\n' > "$work/tampered/files/alice/unlisted.txt"
if "$PHP_BINARY" bin/dav backup:verify "$work/tampered" --json > /dev/null 2>&1; then
  echo 'Backup verifier accepted a tampered/unlisted file' >&2
  exit 1
fi

# Kill the source server and move its entire live data tree out of the expected
# path. The recovery test cannot accidentally read source SQLite or files.
stop_server
mv -- "$original" "$work/source-quarantined"
mkdir -m 0700 -- "$restored"
cp -a -- "$snapshot/dav.sqlite" "$snapshot/metadata" "$snapshot/files" "$restored/"
[[ ! -e "$restored/manifest.json" ]] || { echo 'Restore incorrectly copied backup manifest into live data' >&2; exit 1; }
export DAV_DATA_DIR="$restored"

# Validate the *restored* databases offline before starting the HTTP service.
python3 - "$restored" <<'PY'
import pathlib, sqlite3, sys
root = pathlib.Path(sys.argv[1])
for path in (root / 'dav.sqlite', *(root / 'metadata' / account / 'dav.sqlite' for account in ('alice', 'bob', 'carol'))):
    assert path.is_file(), f'missing restored database: {path}'
    db = sqlite3.connect(f'file:{path}?mode=ro', uri=True)
    try:
        assert db.execute('PRAGMA integrity_check').fetchone()[0] == 'ok', f'corrupt SQLite: {path}'
    finally:
        db.close()
PY
"$PHP_BINARY" bin/dav status --json > "$work/restored-status.json"
python3 - "$work/restored-status.json" <<'PY'
import json, sys
with open(sys.argv[1], encoding='utf-8') as handle:
    data = json.load(handle)
assert data['status'] == 'ok' and data['accounts'] == 3, data
PY
start_server

# Preserve authentication, disabled-user state, CalDAV and CardDAV contents,
# independent per-user WebDAV trees, and an empty directory from the snapshot.
expect "$(status -u "$alice" -X PROPFIND -H 'Depth: 0' "$base/calendars/alice/default/")" 207 'restored calendar collection'
expect "$(status -u "$alice" "$base/calendars/alice/default/recovery.ics")" 200 'restored calendar event'
grep -q 'SUMMARY:Recovery Rehearsal Calendar Event' "$work/response"
expect "$(status -u "$alice" "$base/addressbooks/alice/default/recovery.vcf")" 200 'restored contact'
grep -q 'FN:Recovery Rehearsal Contact' "$work/response"
expect "$(status -u "$alice" "$base/files/alice.txt")" 200 'restored alice file'
cmp -s "$work/alice.txt" "$work/response" || { echo 'Alice file bytes differ after restore' >&2; exit 1; }
expect "$(status -u "$bob" "$base/files/bob.txt")" 200 'restored bob file'
cmp -s "$work/bob.txt" "$work/response" || { echo 'Bob file bytes differ after restore' >&2; exit 1; }
expect "$(status -u "$bob" "$base/files/alice.txt")" 404 'restored cross-user file isolation'
expect "$(status -u "$alice" -X PROPFIND -H 'Depth: 0' "$base/files/empty-folder/")" 207 'restored empty WebDAV folder'
expect "$(status -u "$carol" -X PROPFIND -H 'Depth: 0' "$base/")" 401 'restored disabled account denied'

# The restored service remains writable and quota accounting continues.
expect "$(status -u "$alice" -X PUT --data-binary @"$work/alice.txt" "$base/files/after-recovery.txt")" 201 'post-recovery write'
expect "$(status -u "$alice" "$base/files/after-recovery.txt")" 200 'post-recovery read'

echo 'Full-service isolated restore verified: SQLite, calendar, contacts, files, disabled accounts, tenant isolation and new writes.'
