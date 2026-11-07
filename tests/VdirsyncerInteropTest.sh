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
# vdirsyncer 0.21 creates its local pair state through discover, even when
# both storages are already mapped to specific, existing collections.
"$DAV_VDIRSYNCER_BIN" discover calendar_pair contact_pair

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
# A second independent vdirsyncer profile must discover collections from the
# service root using standard CalDAV/CardDAV principal/home-set properties.
# This is deliberately distinct from the direct collection URL test above.
mkdir -p "$work/discovered-calendars" "$work/discovered-contacts" "$work/discovered-status"
cat > "$work/discovered.conf" <<CONFIG
[general]
status_path = "$work/discovered-status"

[pair discovered_calendar_pair]
a = "discovered_calendar_local"
b = "discovered_calendar_remote"
collections = ["from b"]
implicit = "create"

[storage discovered_calendar_local]
type = "filesystem"
path = "$work/discovered-calendars"
fileext = ".ics"

[storage discovered_calendar_remote]
type = "caldav"
url = "$base/"
username = "alice"
password = "alice-vdirsyncer-test-password-12345"
auth = "basic"

[pair discovered_contact_pair]
a = "discovered_contact_local"
b = "discovered_contact_remote"
collections = ["from b"]
implicit = "create"

[storage discovered_contact_local]
type = "filesystem"
path = "$work/discovered-contacts"
fileext = ".vcf"

[storage discovered_contact_remote]
type = "carddav"
url = "$base/"
username = "alice"
password = "alice-vdirsyncer-test-password-12345"
auth = "basic"
CONFIG
chmod 0600 "$work/discovered.conf"
export VDIRSYNCER_CONFIG="$work/discovered.conf"
"$DAV_VDIRSYNCER_BIN" discover discovered_calendar_pair discovered_contact_pair
"$DAV_VDIRSYNCER_BIN" sync discovered_calendar_pair discovered_contact_pair
grep -RqF 'Independent Client Remote Edit' "$work/discovered-calendars"
grep -RqF 'Independent Client Remote Edit' "$work/discovered-contacts"
python3 - "$DAV_DATA_DIR" <<'PY'
from pathlib import Path
import sqlite3, sys
root = Path(sys.argv[1])
for account, count in [('alice', 1), ('bob', 0)]:
    with sqlite3.connect(root/'metadata'/account/'dav.sqlite') as db:
        assert db.execute('SELECT COUNT(*) FROM calendarobjects').fetchone()[0] == count
        assert db.execute('SELECT COUNT(*) FROM cards').fetchone()[0] == count
PY

# Independently synchronize CalDAV VTODO tasks and a recurring VEVENT,
# without flattening RRULEs or reusing a preconfigured resource list.
cat > "$work/new-task.ics" <<'TODOICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//GoreeCloud DAV Independent Client Task Smoke//EN
BEGIN:VTODO
UID:vdir-task-001@example.invalid
DTSTAMP:20261107T120000Z
DTSTART:20261108T120000Z
DUE:20261108T140000Z
STATUS:NEEDS-ACTION
SUMMARY:Independent Client Task
END:VTODO
END:VCALENDAR
TODOICS
cat > "$work/new-recurring.ics" <<'RECURICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//GoreeCloud DAV Independent Client Recurrence Smoke//EN
BEGIN:VEVENT
UID:vdir-recurring-001@example.invalid
DTSTAMP:20261107T120000Z
DTSTART:20261108T130000Z
DTEND:20261108T140000Z
RRULE:FREQ=WEEKLY;COUNT=3
SUMMARY:Independent Client Recurring Event
END:VEVENT
END:VCALENDAR
RECURICS
for kind in task recurring; do
  status="$(curl --silent --show-error --max-time 12 --output "$work/task-response" --write-out '%{http_code}' \
    --user 'alice:alice-vdirsyncer-test-password-12345' --request PUT \
    -H 'Content-Type: text/calendar; charset=utf-8' --data-binary @"$work/new-$kind.ics" \
    "$base/calendars/alice/default/vdir-$kind.ics")"
  if [[ "$status" != 201 && "$status" != 204 ]]; then
    echo "CalDAV $kind upload failed: HTTP $status" >&2
    exit 1
  fi
done
"$DAV_VDIRSYNCER_BIN" sync discovered_calendar_pair
grep -RqF 'BEGIN:VTODO' "$work/discovered-calendars"
grep -RqF 'SUMMARY:Independent Client Task' "$work/discovered-calendars"
grep -RqF 'RRULE:FREQ=WEEKLY;COUNT=3' "$work/discovered-calendars"
python3 - "$DAV_DATA_DIR" <<'PY'
from pathlib import Path
import sqlite3, sys
root = Path(sys.argv[1])
with sqlite3.connect(root/'metadata'/'alice'/'dav.sqlite') as db:
    rows = db.execute('SELECT calendardata FROM calendarobjects').fetchall()
    assert len(rows) == 3, len(rows)
    payloads = [r[0].encode() if isinstance(r[0], str) else bytes(r[0]) for r in rows]
    assert any(b'BEGIN:VTODO' in data for data in payloads)
    assert any(b'RRULE:FREQ=WEEKLY;COUNT=3' in data for data in payloads)
with sqlite3.connect(root/'metadata'/'bob'/'dav.sqlite') as db:
    assert db.execute('SELECT COUNT(*) FROM calendarobjects').fetchone()[0] == 0
PY

# The independent client must also *edit* VTODOs in both directions, not only download them.
# The discovered local filename may be an encoded UID rather than vdir-task.ics.
task_path="$(grep -RlF 'UID:vdir-task-001@example.invalid' "$work/discovered-calendars" | head -n 1)"
[[ -n "$task_path" && -f "$task_path" ]] || { echo 'vdirsyncer failed to discover VTODO local file' >&2; exit 1; }
sed -i \
  -e 's/SUMMARY:Independent Client Task/SUMMARY:Independent Client Task Local Edit/' \
  -e 's/STATUS:NEEDS-ACTION/STATUS:IN-PROCESS/' "$task_path"
"$DAV_VDIRSYNCER_BIN" sync discovered_calendar_pair
python3 - "$DAV_DATA_DIR" <<'PY'
from pathlib import Path
import sqlite3, sys
with sqlite3.connect(Path(sys.argv[1])/'metadata'/'alice'/'dav.sqlite') as db:
    rows = db.execute('SELECT calendardata FROM calendarobjects').fetchall()
    assert len(rows) == 3, len(rows)
    tasks = [v[0].encode() if isinstance(v[0], str) else bytes(v[0]) for v in rows
             if b'UID:vdir-task-001@example.invalid' in (v[0].encode() if isinstance(v[0], str) else bytes(v[0]))]
    assert len(tasks) == 1, len(tasks)
    assert b'SUMMARY:Independent Client Task Local Edit' in tasks[0]
    assert b'STATUS:IN-PROCESS' in tasks[0]
PY
# Modify only the remote copy, then synchronize back without a local conflict.
cp -- "$task_path" "$work/task-remotely-edited.ics"
sed -i \
  -e 's/SUMMARY:Independent Client Task Local Edit/SUMMARY:Independent Client Task Remote Edit/' \
  -e 's/STATUS:IN-PROCESS/STATUS:COMPLETED/' "$work/task-remotely-edited.ics"
task_status="$(curl --silent --show-error --max-time 12 --output "$work/task-update-response" --write-out '%{http_code}' \
  --user 'alice:alice-vdirsyncer-test-password-12345' --request PUT \
  -H 'Content-Type: text/calendar; charset=utf-8' --data-binary @"$work/task-remotely-edited.ics" \
  "$base/calendars/alice/default/vdir-task.ics")"
if [[ "$task_status" != 204 && "$task_status" != 200 ]]; then
  echo "Server-side VTODO completion failed: HTTP $task_status" >&2
  exit 1
fi
"$DAV_VDIRSYNCER_BIN" sync discovered_calendar_pair
grep -Fq 'SUMMARY:Independent Client Task Remote Edit' "$task_path"
grep -Fq 'STATUS:COMPLETED' "$task_path"
python3 - "$DAV_DATA_DIR" <<'PY'
from pathlib import Path
import sqlite3, sys
with sqlite3.connect(Path(sys.argv[1])/'metadata'/'alice'/'dav.sqlite') as db:
    assert db.execute('SELECT COUNT(*) FROM calendarobjects').fetchone()[0] == 3
with sqlite3.connect(Path(sys.argv[1])/'metadata'/'bob'/'dav.sqlite') as db:
    assert db.execute('SELECT COUNT(*) FROM calendarobjects').fetchone()[0] == 0
PY

# A recurrence exception must retain its timezone, excluded date and overridden instance
# when an independent client downloads and subsequently edits the event.
cat > "$work/advanced-recurrence.ics" <<'ADVANCEDICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//GoreeCloud DAV Advanced Recurrence CI//EN
BEGIN:VTIMEZONE
TZID:America/New_York
BEGIN:STANDARD
DTSTART:19701101T020000
TZOFFSETFROM:-0400
TZOFFSETTO:-0500
RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU
END:STANDARD
BEGIN:DAYLIGHT
DTSTART:19700308T020000
TZOFFSETFROM:-0500
TZOFFSETTO:-0400
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU
END:DAYLIGHT
END:VTIMEZONE
BEGIN:VEVENT
UID:vdir-exception-001@example.invalid
DTSTAMP:20261107T120000Z
DTSTART;TZID=America/New_York:20261108T090000
DTEND;TZID=America/New_York:20261108T100000
RRULE:FREQ=WEEKLY;COUNT=4
EXDATE;TZID=America/New_York:20261115T090000
SUMMARY:Independent Client Exception Series
END:VEVENT
BEGIN:VEVENT
UID:vdir-exception-001@example.invalid
DTSTAMP:20261107T120000Z
RECURRENCE-ID;TZID=America/New_York:20261122T090000
DTSTART;TZID=America/New_York:20261122T110000
DTEND;TZID=America/New_York:20261122T120000
SUMMARY:Independent Client Moved Occurrence
END:VEVENT
END:VCALENDAR
ADVANCEDICS
advanced_code="$(curl --silent --show-error --max-time 12 --output "$work/advanced-response" --write-out '%{http_code}' \
  --user 'alice:alice-vdirsyncer-test-password-12345' --request PUT \
  -H 'Content-Type: text/calendar; charset=utf-8' --data-binary @"$work/advanced-recurrence.ics" \
  "$base/calendars/alice/default/vdir-advanced.ics")"
if [[ "$advanced_code" != 201 && "$advanced_code" != 204 ]]; then
  echo "Advanced recurrence PUT was rejected: HTTP $advanced_code" >&2
  exit 1
fi
"$DAV_VDIRSYNCER_BIN" sync discovered_calendar_pair
advanced_local="$(grep -RlF 'UID:vdir-exception-001@example.invalid' "$work/discovered-calendars" | head -n 1)"
[[ -n "$advanced_local" && -f "$advanced_local" ]] || { echo 'Independent client did not download recurring exception resource' >&2; exit 1; }
for marker in 'BEGIN:VTIMEZONE' 'TZID:America/New_York' 'RRULE:FREQ=WEEKLY;COUNT=4' \
              'EXDATE;TZID=America/New_York:20261115T090000' \
              'RECURRENCE-ID;TZID=America/New_York:20261122T090000' \
              'DTSTART;TZID=America/New_York:20261122T110000'; do
  grep -Fq "$marker" "$advanced_local" || { echo "Independent client lost recurrence property: $marker" >&2; exit 1; }
done
# A local update to the master event must not remove the exception or timezone.
sed -i 's/SUMMARY:Independent Client Exception Series/SUMMARY:Independent Client Series Edited Locally/' "$advanced_local"
"$DAV_VDIRSYNCER_BIN" sync discovered_calendar_pair
python3 - "$DAV_DATA_DIR" <<'PY'
from pathlib import Path
import sqlite3, sys
root = Path(sys.argv[1])
with sqlite3.connect(root/'metadata'/'alice'/'dav.sqlite') as db:
    rows = db.execute('SELECT uri, calendardata FROM calendarobjects').fetchall()
    assert len(rows) == 4, (len(rows), [r[0] for r in rows])
    found = [(u, payload.encode('utf-8') if isinstance(payload, str) else bytes(payload)) for u, payload in rows if u == 'vdir-advanced.ics']
    assert len(found) == 1, found
    body = found[0][1]
    for marker in [b'SUMMARY:Independent Client Series Edited Locally', b'BEGIN:VTIMEZONE',
                   b'RRULE:FREQ=WEEKLY;COUNT=4', b'EXDATE;TZID=America/New_York:20261115T090000',
                   b'RECURRENCE-ID;TZID=America/New_York:20261122T090000',
                   b'DTSTART;TZID=America/New_York:20261122T110000']:
        assert marker in body, f'Lost recurrence byte sequence: {marker!r}'
with sqlite3.connect(root/'metadata'/'bob'/'dav.sqlite') as db:
    assert db.execute('SELECT COUNT(*) FROM calendarobjects').fetchone()[0] == 0
PY

printf 'Independent client passed DAV discovery, two-way task edits, timezone recurrence exceptions and contacts.\n'