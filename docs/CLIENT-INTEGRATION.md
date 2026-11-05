# Integration contract — Calendar, Contacts, Tasks, Files

**Version:** experimental v0; verify against real clients before declaring interoperability.

## Discovery

A single service origin hosts standard DAV collections. Clients supporting discovery should contact `/.well-known/caldav` or `/.well-known/carddav`, then query `{DAV:}current-user-principal` and `calendar-home-set` / `addressbook-home-set` via PROPFIND. Do not assume the example host exists. Configure DNS and TLS only after authorized service assignments.

Default collection templates after `user:add`:

- Principal: `/principals/{username}/`
- CalDAV home: `/calendars/{username}/`; default calendar `/calendars/{username}/default/`
- CardDAV home: `/addressbooks/{username}/`; default contacts `/addressbooks/{username}/default/`
- WebDAV root: `/files/`, mapped per authenticated principal. Do **not** put a username into the file URL.

## Client behavior

- Use Basic credentials only over certificate-validated HTTPS; do not persist them in plain text. Prefer OS credential vault/secure storage. Treat account credentials as broadly privileged until app-specific tokens/scopes exist.
- Support XML DAV `PROPFIND` and `REPORT`, precondition headers (`If-Match` / `If-None-Match`), ETags, `sync-token`, and 207 Multi-Status. Handle 401/403/409/412/423 without destructive automatic retries.
- CalDAV: upload valid UTF-8 `text/calendar` iCalendar 2.0 resources with unique UIDs; preserve recurrence rules, exception instances, timezones, alarms and attachments as supported by the actual engine. Never assume an event can be losslessly flattened to start/end dates.
- CardDAV: upload `text/vcard` and preserve unknown contact properties, photos, multiple phone/email values, groups and normalization carefully. Beware duplicate contacts due to client UID changes.
- WebDAV: use `MKCOL`, `PUT`, `GET`, `DELETE`, `MOVE`, `COPY`, `LOCK`, `UNLOCK` as supported; test uploads, collisions, renames and lock conflict behavior. No server-side file version history/trash in v0.
- Frontend calendars, contacts and files can use independent UI branding while sharing authenticated DAV storage; never assume same-origin browser cookies or CORS support (none is configured by default). Browser clients should use a secure backend-for-frontend proxy where appropriate.

## Contract tests to complete

1. DAV discovery and principal resolution for a newly provisioned account.
2. Event and recurrence round-trip; CalDAV sync token incremental updates; incorrect ETag conditional writes.
3. vCard 3 and 4 round-trip, photo preservation, CardDAV address-book sync.
4. WebDAV binary upload/download, directory operations, move/rename, lock expiry, large-file boundaries.
5. Two accounts: denied cross-principal calendar/address-book access and isolated `/files/` content under concurrent requests.
6. Mobile/desktop client interoperability (DAVx5, Apple Calendar/Contacts, Thunderbird, GNOME Evolution, native GoreeCloud clients as developed).

All items above are acceptance tests, **not claimed test results**.
