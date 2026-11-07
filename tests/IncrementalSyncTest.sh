#!/usr/bin/env bash
# Disposable CalDAV/CardDAV incremental sync-token and deletion-tombstone test.
set -euo pipefail
umask 077
PHP_BINARY=php
work="$(mktemp -d -t goreecloud-dav-sync-XXXXXXXX)"
server_pid=''
cleanup() {
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  rm -rf -- "$work"
}
trap cleanup EXIT
base='http://127.0.0.1:18892'
export DAV_DATA_DIR="$work/data" DAV_REQUIRE_HTTPS=0 DAV_BASE_URI=/
"$PHP_BINARY" bin/dav init >/dev/null
printf 'alice-sync-test-password-12345\n' | "$PHP_BINARY" bin/dav user:add alice --password-stdin >/dev/null
printf 'bob-sync-test-password-67890\n' | "$PHP_BINARY" bin/dav user:add bob --password-stdin >/dev/null
"$PHP_BINARY" -S 127.0.0.1:18892 -t public public/index.php >"$work/http.log" 2>&1 &
server_pid=$!
ready=0
for _ in $(seq 1 50); do
  if curl --silent --fail --max-time 2 "$base/healthz" >/dev/null 2>&1; then ready=1; break; fi
  sleep 0.2
done
if [[ "$ready" != 1 ]]; then echo 'DAV sync test HTTP server failed to start' >&2; exit 1; fi
status() { curl --silent --show-error --max-time 15 --output "$work/response" --write-out '%{http_code}' "$@"; }
expect() { if [[ "$1" != "$2" ]]; then echo "Sync $3: expected HTTP $2, got $1" >&2; tail -n 25 "$work/http.log" >&2; exit 1; fi; }
report() {
  local credentials="$1" url="$2" token="$3" code
  python3 - "$token" "$work/report.xml" <<'PY'
import sys
from xml.sax.saxutils import escape
s=escape(sys.argv[1])
with open(sys.argv[2], 'w', encoding='utf-8') as f:
    f.write(f'<?xml version="1.0" encoding="utf-8"?><d:sync-collection xmlns:d="DAV:">'
            f'<d:sync-token>{s}</d:sync-token><d:sync-level>1</d:sync-level>'
            '<d:prop><d:getetag/></d:prop></d:sync-collection>')
PY
  code="$(status -u "$credentials" -X REPORT -H 'Content-Type: application/xml; charset=utf-8' \
    --data-binary @"$work/report.xml" "$url")"
  expect "$code" 207 'sync-collection REPORT'
  python3 - "$work/response" <<'PY'
import sys, xml.etree.ElementTree as ET
root=ET.parse(sys.argv[1]).getroot()
assert root.tag=='{DAV:}multistatus', root.tag
value=root.findtext('{DAV:}sync-token')
assert value is not None and value.strip(), 'missing sync-token'
print(value.strip())
PY
}
assert_changes() {
  local uri="$1" state="$2" expected_count="$3"
  python3 - "$work/response" "$uri" "$state" "$expected_count" <<'PY'
import sys, urllib.parse, xml.etree.ElementTree as ET
root=ET.parse(sys.argv[1]).getroot(); uri=sys.argv[2]; state=sys.argv[3]; count=int(sys.argv[4]); ns='{DAV:}'
responses=root.findall(f'{ns}response')
assert len(responses)==count, f'Expected {count} changes, got {len(responses)}: {[x.findtext(ns+"href") for x in responses]}'
if count:
    response=responses[0]
    href=urllib.parse.unquote(urllib.parse.urlparse(response.findtext(ns+'href') or '').path)
    assert href.endswith('/'+uri), (href,uri)
    statuses=[(x.text or '') for x in response.iter(ns+'status')]
    if state=='deleted':
        assert any('404' in s for s in statuses), statuses
    else:
        assert not any('404' in s for s in statuses), statuses
        assert any(x.tag==ns+'getetag' for x in response.iter()), 'changed record missing ETag'
PY
}
check_sequence() {
  local kind="$1" ext="$2" content_type="$3" dir="$4" src="$5" old_label="$6" new_label="$7"
  local uri="ci-sync.$ext"
  local alice='alice:alice-sync-test-password-12345' bob='bob:bob-sync-test-password-67890' token initial next updated
  token="$(report "$alice" "$base/$dir/alice/default/" '')"
  assert_changes "$uri" 'none' 0
  initial="$(report "$bob" "$base/$dir/bob/default/" '')"
  assert_changes "$uri" 'none' 0
  expect "$(status -u "$alice" -X PUT -H "Content-Type: $content_type" --data-binary @"$src" \
    "$base/$dir/alice/default/$uri")" 201 "$kind create"
  next="$(report "$alice" "$base/$dir/alice/default/" "$token")"
  [[ "$next" != "$token" ]] || { echo "$kind token unchanged on create" >&2; exit 1; }
  assert_changes "$uri" 'changed' 1
  token="$next"
  sed "s/$old_label/$new_label/" "$src" >"$work/updated-$ext"
  updated="$(status -u "$alice" -X PUT -H "Content-Type: $content_type" \
    --data-binary @"$work/updated-$ext" "$base/$dir/alice/default/$uri")"
  [[ "$updated" == 204 || "$updated" == 200 ]] || expect "$updated" 204 "$kind update"
  next="$(report "$alice" "$base/$dir/alice/default/" "$token")"
  [[ "$next" != "$token" ]] || { echo "$kind token unchanged on edit" >&2; exit 1; }
  assert_changes "$uri" 'changed' 1
  token="$next"
  expect "$(status -u "$alice" -X DELETE "$base/$dir/alice/default/$uri")" 204 "$kind delete"
  next="$(report "$alice" "$base/$dir/alice/default/" "$token")"
  [[ "$next" != "$token" ]] || { echo "$kind token unchanged on delete" >&2; exit 1; }
  assert_changes "$uri" 'deleted' 1
  # Alice's writes cannot appear in the physically separate Bob collection.
  next="$(report "$bob" "$base/$dir/bob/default/" "$initial")"
  assert_changes "$uri" 'none' 0
  echo "Incremental sync and tombstones verified for $kind"
}
cat > "$work/event.ics" <<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//GoreeCloud Incremental Sync CI//EN
BEGIN:VEVENT
UID:ci-sync-event@example.invalid
DTSTAMP:20261107T120000Z
DTSTART:20261107T130000Z
DTEND:20261107T140000Z
SUMMARY:First-CI-Event
END:VEVENT
END:VCALENDAR
ICS
cat > "$work/contact.vcf" <<'VCF'
BEGIN:VCARD
VERSION:3.0
FN:First-CI-Contact
N:Contact;First;;;
UID:urn:uuid:66666666-6666-4666-8666-666666666666
END:VCARD
VCF
check_sequence CalDAV ics 'text/calendar; charset=utf-8' calendars "$work/event.ics" First-CI-Event Second-CI-Event
check_sequence CardDAV vcf 'text/vcard; charset=utf-8' addressbooks "$work/contact.vcf" First-CI-Contact Second-CI-Contact
echo 'Incremental CalDAV/CardDAV sync tokens and delete tombstones passed.'
