#!/usr/bin/env bash
# CI-only bounded sync pagination and invalid token test for isolated DAV accounts.
set -euo pipefail
umask 077
PHP_BINARY=php
work="$(mktemp -d -t goreecloud-dav-bounded-XXXXXXXX)"
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
"$PHP_BINARY" bin/dav init >/dev/null
printf 'alice-bounded-sync-password-12345\n' | "$PHP_BINARY" bin/dav user:add alice --password-stdin >/dev/null
printf 'bob-bounded-sync-password-67890\n' | "$PHP_BINARY" bin/dav user:add bob --password-stdin >/dev/null
"$PHP_BINARY" -S 127.0.0.1:18902 -t public public/index.php >"$work/server.log" 2>&1 &
server_pid=$!
ready=0
for _ in $(seq 1 50); do
  if curl --silent --fail --max-time 2 http://127.0.0.1:18902/healthz >/dev/null 2>&1; then
    ready=1; break
  fi
  sleep 0.2
done
if [[ "$ready" != 1 ]]; then
  echo 'Disposable bounded-sync DAV server did not start' >&2
  tail -n 25 "$work/server.log" >&2
  exit 1
fi
python3 - <<'PY'
import base64
import html
import os
import sqlite3
from pathlib import Path
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

base = 'http://127.0.0.1:18902'
NS = '{DAV:}'

def request(who, method, path, data=None, content_type=None):
    password = {'alice':'alice-bounded-sync-password-12345',
                'bob':'bob-bounded-sync-password-67890'}[who]
    auth = base64.b64encode(f'{who}:{password}'.encode()).decode()
    headers = {'Authorization': f'Basic {auth}'}
    if content_type:
        headers['Content-Type'] = content_type
    req = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=15) as res:
            return res.status, res.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()

def report(who, collection, token=None, limit=None):
    token_xml = '<d:sync-token/>' if token is None else f'<d:sync-token>{html.escape(token)}</d:sync-token>'
    limit_xml = '' if limit is None else f'<d:limit><d:nresults>{limit}</d:nresults></d:limit>'
    payload = ('<?xml version="1.0" encoding="utf-8"?>'
               '<d:sync-collection xmlns:d="DAV:">' + token_xml
               + '<d:sync-level>1</d:sync-level>' + limit_xml
               + '<d:prop><d:getetag/></d:prop></d:sync-collection>').encode()
    code, body = request(who, 'REPORT', collection, payload, 'application/xml')
    if code != 207:
        return code, None, [], False, body
    root = ET.fromstring(body)
    assert root.tag == NS + 'multistatus', root.tag
    next_token = root.findtext(NS + 'sync-token')
    assert next_token, 'Missing sync token'
    changes = []
    truncated = False
    for item in root.findall(NS + 'response'):
        href = urllib.parse.urlparse(item.findtext(NS + 'href') or '').path
        statuses = [s.text or '' for s in item.iter(NS + 'status')]
        if any(' 507 ' in x for x in statuses):
            truncated = True
            continue
        assert not any(' 404 ' in x for x in statuses), (href, statuses)
        assert any(x.tag == NS + 'getetag' for x in item.iter()), (href, statuses)
        changes.append(urllib.parse.unquote(href).rsplit('/', 1)[-1])
    return code, next_token, changes, truncated, body

def payload(kind, i):
    if kind == 'calendar':
        text = ('BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//GoreeCloud Bounded Sync CI//EN\n'
                'BEGIN:VEVENT\nUID:bounded-event-%d@example.invalid\nDTSTAMP:20261107T120000Z\n'
                'DTSTART:20261107T130000Z\nDTEND:20261107T140000Z\n'
                'SUMMARY:Bounded-Event-%d\nEND:VEVENT\nEND:VCALENDAR\n' % (i, i))
        return text.encode(), 'text/calendar; charset=utf-8'
    text = ('BEGIN:VCARD\nVERSION:3.0\nUID:urn:uuid:77777777-7777-4777-8777-%012d\n'
            'FN:Bounded-Contact-%d\nN:Contact;Bounded;;;\nEND:VCARD\n' % (i, i))
    return text.encode(), 'text/vcard; charset=utf-8'

starting_tokens = {}
current_tokens = {}
for kind, collection, ext in [
    ('calendar', '/calendars/alice/default/', 'ics'),
    ('contact', '/addressbooks/alice/default/', 'vcf'),
]:
    bob_collection = collection.replace('/alice/', '/bob/')
    code, old, initial, _, _ = report('alice', collection)
    starting_tokens[kind] = old
    assert code == 207 and initial == [], (kind, code, initial)
    code, bob_token, bob_initial, _, _ = report('bob', bob_collection)
    assert code == 207 and bob_initial == [], (kind, code, bob_initial)

    expected = set()
    for i in range(1, 6):
        name = f'ci-bounded-{i}.{ext}'
        body, content_type = payload(kind, i)
        code, _ = request('alice', 'PUT', collection + name, body, content_type)
        assert code == 201, (kind, name, code)
        expected.add(name)

    code, final_token, all_changes, _, _ = report('alice', collection, old)
    assert code == 207 and set(all_changes) == expected, (kind, code, all_changes, expected)
    current_tokens[kind] = final_token
    page_token = old
    observed = []
    for page in range(10):
        code, next_token, names, truncated, _ = report('alice', collection, page_token, 2)
        assert code == 207, (kind, page, code)
        assert len(names) <= 2 and names, (kind, page, names)
        assert next_token != page_token, (kind, page, page_token)
        observed.extend(names)
        if next_token == final_token:
            assert not truncated, (kind, page, 'last page incorrectly truncated')
            break
        assert truncated, (kind, page, 'partial page did not signal 507')
        page_token = next_token
    else:
        raise AssertionError(f'{kind} paging did not finish')
    assert set(observed) == expected and len(observed) == 5, (kind, observed, expected)

    code, bob_after, bob_changes, _, _ = report('bob', bob_collection, bob_token)
    assert code == 207 and not bob_changes, (kind, code, bob_changes)

    # Unknown/future tokens must never silently skip subsequent changes.
    future = final_token.rsplit('/', 1)[0] + '/999999999'
    code, _, _, _, err = report('alice', collection, future)
    assert code == 403 and b'valid-sync-token' in err, (kind, code, err[:280])
    print(f'{kind}: 5 changes delivered in bounded pages without loss; future token rejected')

# Simulate an authorized maintenance process that has retired old change rows.
# Do not purge history from a real DAV instance or while requests are active.
db_path = Path(os.environ['DAV_DATA_DIR']) / 'metadata' / 'alice' / 'dav.sqlite'
with sqlite3.connect(db_path) as db:
    for table in ('calendarchanges', 'addressbookchanges'):
        count = db.execute(f'SELECT COUNT(*) FROM {table}').fetchone()[0]
        assert count >= 5, (table, count)
        db.execute(f'DELETE FROM {table} WHERE synctoken < (SELECT MAX(synctoken) FROM {table})')

for kind, collection in [('calendar','/calendars/alice/default/'),
                         ('contact','/addressbooks/alice/default/')]:
    code, _, _, _, message = report('alice', collection, starting_tokens[kind])
    assert code == 403 and b'valid-sync-token' in message, (kind, code, message[:280])
    code, last, changes, _, _ = report('alice', collection, current_tokens[kind])
    assert code == 207 and not changes, (kind, code, changes)
    print(f'{kind}: expired token rejected; latest token remains usable')
PY
echo 'Bounded CalDAV/CardDAV sync, future-token protection and account isolation passed.'
