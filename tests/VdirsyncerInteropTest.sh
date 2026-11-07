#!/usr/bin/env bash
# Disposable *independent* CalDAV/CardDAV client interoperability smoke.
# Requires the separately reviewed and pinned vdirsyncer client in an isolated venv.
# Never point this script at an existing service, real account or production volume.
set -euo pipefail
umask 077
: "${PHP_BINARY:=php}"
: "${DAV_VDIRSYNCER_BIN:?Point DAV_VDIRSYNCER_BIN at the pinned vdirsyncer 0.21.0 executable}"
[[ -x "$DAV_VDIRSYNCER_BIN" ]] || { echo 'Missing standalone vdirsyncer executable' >&2; exit 1; }

work="$(mktemp -d -t goreecloud-dav-vdirsyncer-XXXXXXXX)"
base="http://127.0.0.1:${DAV_VDIRSYNCER_PORT:-18854}"
server_pid=''
cleanup() {
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  rm -rf -- "$work"
}
trap cleanup EXIT

# Synthetic credentials exist only in the private disposable fixture.
export DAV_DATA_DIR="$work/data" DAV_BASE_URI=/ DAV_REQUIRE_HTTPS=0
unset DAV_ACCOUNT_FILE_QUOTA_BYTES || true
"$PHP_BINARY" bin/dav init >/dev/null
printf 'alice-vdirsyncer-test-password-12345\n' | "$PHP_BINARY" bin/dav user:add alice --password-stdin >/dev/null
printf 'bob-vdirsyncer-test-password-67890\n' | "$PHP_BINARY" bin/dav user:add bob --password-stdin >/dev/null
"$PHP_BINARY" -S "${base#http://}" -t public public/index.php >"$work/http.log" 2>&1 &
server_pid=$!
ready=0
for _ in $(seq 1 50); do
  if curl -fsS --max-time 2 "$base/healthz" > /dev/null 2>&1; then ready=1; break; fi
  sleep 0.2
done
if [[ "$ready" != 1 ]]; then
  echo 'Disposable DAV service did not start' >&2
  tail -n 30 "$work/http.log" >&2
  exit 1
fi

mkdir -p "$work/calendar" "$work/contacts" "$work/status"
cat > "$work/calendar/vdir-event.ics" <<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//GoreeCloud DAV Independent Client Smoke//EN
BEGIN:VEVENT
UID:vdir-event-001@example.invalid
DTSTAMP:20261107T120000Z
DTSTART:20261107T130000Z
DTEND:20261107T140000Z
SUMMARY:Independent Client Initial Event
END:VEVENT
END:VCALENDAR
ICS
cat > "$work/contacts/vdir-person.vcf" <<'VCF'
BEGIN:VCARD
VERSION:3.0
N:Person;Independent;;;
FN:Independent Client Initial Contact
UID:urn:uuid:33333333-3333-4333-8333-333333333333
END:VCARD
VCF

# A direct collection URL and collections=null isolate a single pair each;
# no discovery of unrelated user collections and no real credentials.
cat > "$work/vdirsyncer.conf" <<CONFIG
[general]
status_path = "$work/status"

[pair calendar_pair]
a = "calendar_local"
b = "calendar_remote"
collections = null

[storage calendar_local]
type = "filesystem"
path = "$work/calendar"
fileext = ".ics"

[storage calendar_remote]
type = "caldav"
url = "$base/calendars/alice/default/"
username = "alice"
password = "alice-vdirsyncer-test-password-12345"
auth = "basic"

[pair contact_pair]
a = "contact_local"
b = "contact_remote"
collections = null

[storage contact_local]
type = "filesystem"
path = "$work/contacts"
fileext = ".vcf"

[storage contact_remote]
type = "carddav"
url = "$base/addressbooks/alice/default/"
username = "alice"
password = "alice-vdirsyncer-test-password-12345"
auth = "basic"
CONFIG
chmod 0600 "$work/vdirsyncer.conf"
export VDIRSYNCER_CONFIG="$work/vdirsyncer.conf"

# The independent client uploads real iCalendar/vCard data through its own
# CalDAV/CardDAV implementation, not SabreDAV's test transport.
"$DAV_VDIRSYNCER_BIN" sync calendar_pair contact_pair
python3 - "$DAV_DATA_DIR" <<'PY'
import sqlite3, sys
from pathlib import Path
root = Path(sys.argv[1])
for user, count in [('alice', 1), ('bob', 0)]:
    with sqlite3.connect(root/'metadata'/user/'dav.sqlite') as db:
        cal = db.execute('SELECT calendardata FROM calendarobjects').fetchall()
        cards = db.execute('SELECT carddata FROM cards').fetchall()
        assert len(cal) == len(cards) == count, (user, len(cal), len(cards))
        if user == 'alice':
            def raw(value):
                return value.encode('utf-8') if isinstance(value, str) else bytes(value)
            assert b'Independent Client Initial Event' in raw(cal[0][0])
            assert b'Independent Client Initial Contact' in raw(cards[0][0])
PY

# Client-local edits must update remote storage, with the same object UIDs.
sed -i 's/Independent Client Initial Event/Independent Client Local Edit/' "$work/calendar/vdir-event.ics"
sed -i 's/Independent Client Initial Contact/Independent Client Local Edit/' "$work/contacts/vdir-person.vcf"
"$DAV_VDIRSYNCER_BIN" sync calendar_pair contact_pair
python3 - "$DAV_DATA_DIR" <<'PY'
import sqlite3, sys
from pathlib import Path
with sqlite3.connect(Path(sys.argv[1])/'metadata'/'alice'/'dav.sqlite') as db:
    def raw(value):
        return value.encode('utf-8') if isinstance(value, str) else bytes(value)
    assert b'Independent Client Local Edit' in raw(db.execute('SELECT calendardata FROM calendarobjects').fetchone()[0])
    assert b'Independent Client Local Edit' in raw(db.execute('SELECT carddata FROM cards').fetchone()[0])
PY

# Finally modify the remote resources via ordinary DAV PUT and require the
# *different implementation* to download both changes on its next sync.
read -r calendar_name contact_name < <(python3 - "$DAV_DATA_DIR" <<'PY'
import sqlite3, sys
from pathlib import Path
with sqlite3.connect(Path(sys.argv[1])/'metadata'/'alice'/'dav.sqlite') as db:
    c = db.execute('SELECT uri FROM calendarobjects').fetchone()[0]
    a = db.execute('SELECT uri FROM cards').fetchone()[0]
    assert '/' not in c and '/' not in a and c.endswith('.ics') and a.endswith('.vcf')
    print(c, a)
PY
)
sed -i 's/Independent Client Local Edit/Independent Client Remote Edit/' "$work/calendar/vdir-event.ics" "$work/contacts/vdir-person.vcf"
# Source files now represent the remote edits; do not leave the same edits in
# local storage, or the client correctly reports a two-sided conflict.
cp "$work/calendar/vdir-event.ics" "$work/new-remote-event.ics"
cp "$work/contacts/vdir-person.vcf" "$work/new-remote-contact.vcf"
sed -i 's/Independent Client Remote Edit/Independent Client Local Edit/' "$work/calendar/vdir-event.ics" "$work/contacts/vdir-person.vcf"
for spec in "calendar:$calendar_name:$work/new-remote-event.ics" "contact:$contact_name:$work/new-remote-contact.vcf"; do
  kind="${spec%%:*}"
  rest="${spec#*:}"
  name="${rest%%:*}"
  path="${rest#*:}"
  if [[ "$kind" == calendar ]]; then
    url="$base/calendars/alice/default/$name"
    type='text/calendar'
  else
    url="$base/addressbooks/alice/default/$name"
    type='text/vcard'
  fi
  status="$(curl --silent --show-error --max-time 12 --output "$work/put-response" --write-out '%{http_code}' \
    --user 'alice:alice-vdirsyncer-test-password-12345' --request PUT \
    -H "Content-Type: $type" --data-binary @"$path" "$url")"
  case "$status" in 200|201|204) ;; *) echo "Remote $kind edit failed: HTTP $status" >&2; exit 1;; esac
done
"$DAV_VDIRSYNCER_BIN" sync calendar_pair contact_pair
grep -Fq 'Independent Client Remote Edit' "$work/calendar/vdir-event.ics"
grep -Fq 'Independent Client Remote Edit' "$work/contacts/vdir-person.vcf"

# Cross-account resource access must remain forbidden during client sync.
for uri in "calendars/alice/default/$calendar_name" "addressbooks/alice/default/$contact_name"; do
  status="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 10 \
      --user 'bob:bob-vdirsyncer-test-password-67890' "$base/$uri")"
  if [[ "$status" != 403 && "$status" != 404 ]]; then
    echo "Bob accessed Alice's independent-client object (HTTP $status)" >&2
    exit 1
  fi
done
printf 'Independent vdirsyncer 0.21.0 client passed CalDAV/CardDAV create, update, remote refresh and account isolation.\n'