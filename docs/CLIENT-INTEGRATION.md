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

## Independent-client verification (CI, November 7, 2026)

The disposable GitHub Actions [DAV checks run #42398873966](https://github.com/GoreeCloud/DAV/actions/runs/42398873966) **passed all four jobs**, including `independent-client-interop` using **upstream vdirsyncer 0.21.0** as an implementation independent of SabreDAV. The ephemeral Python wheel was checked against its published SHA-256 before installation.

The client synced a synthetic iCalendar event and vCard 3.0 contact from local folders into the server, propagated local changes, downloaded separately issued server-side edits, and verified that Bob could not access Alice's resources. **Root-level collection auto-discovery** was separately verified by another vdirsyncer profile: both discovered collections synchronized into new local directories, with no duplicate records. [Run #42399187437](https://github.com/GoreeCloud/DAV/actions/runs/42399187437) passed all four jobs for this additional case. The fixture [documents its supply-chain and scope limitations](INDEPENDENT-CLIENT-TEST.md). The local-only test deliberately disables TLS on its throwaway loopback PHP listener; the separately tested TLS gateway still has no production deployment.

**Not verified by this result:** DAVx5 on Android, Apple Calendar/Contacts, Thunderbird, Evolution, GoreeCloud native clients, device credential-store behavior, complex recurrence, attachments, group contacts, TLS trust on real devices, or production interoperability. Do not mark any of those as complete.


**VTODO and basic recurrence check:** [CI #42399558368](https://github.com/GoreeCloud/DAV/actions/runs/42399558368) additionally passed vdirsyncer downloads of a synthetic VTODO item and an event with `RRULE:FREQ=WEEKLY;COUNT=3`. This preserves those simple structures; exception dates, recurring overrides, VTIMEZONE edge cases, alarms and full two-way VTODO workflows remain untested.

**Two-way VTODO synchronization:** [CI #42400836993](https://github.com/GoreeCloud/DAV/actions/runs/42400836993) passed all four jobs. The independent client uploaded a task status/summary change, then downloaded a separate remote completion and summary change; the test checked unchanged object count and Bob's empty calendar. This is synthetic task round-trip evidence, not compatibility with a particular tasks app.

**Named-timezone recurrence with exceptions:** [CI #42401058201](https://github.com/GoreeCloud/DAV/actions/runs/42401058201) passed all four jobs. A test event included `America/New_York` `VTIMEZONE`, weekly `RRULE`, `EXDATE`, and a moved `RECURRENCE-ID` instance. After independent-client download and local master-summary edit, the server retained those structures and the other account remained isolated. The test does **not** prove all DST boundaries, alarms, scheduling semantics or recurrence expansion in real calendar UI clients.

**vCard 4.0 with richer contact fields:** [CI #42401433087](https://github.com/GoreeCloud/DAV/actions/runs/42401433087) passed all four jobs after the discovered CardDAV profile explicitly set vdirsyncer's `use_vcard_4 = true`. A synthetic photo data URI, two phone numbers, and two email addresses survived server-to-client sync and a local display-name edit back to the server, with Bob's card collection unchanged. [The initial run #42401250721](https://github.com/GoreeCloud/DAV/actions/runs/42401250721) failed the strict version assertion because the client default did not request vCard 4.0; it does not establish a server defect. Real device clients and larger photos, groups and varied vCard conversions remain unverified.

## Contract tests to complete

1. DAV discovery and principal resolution for a newly provisioned account.
2. Event and recurrence round-trip; CalDAV sync token incremental updates; incorrect ETag conditional writes.
3. vCard 3 and 4 round-trip, photo preservation, CardDAV address-book sync.
4. WebDAV binary upload/download, directory operations, move/rename, lock expiry, large-file boundaries.
5. Two accounts: denied cross-principal calendar/address-book access and isolated `/files/` content under concurrent requests.
6. Mobile/desktop client interoperability (DAVx5, Apple Calendar/Contacts, Thunderbird, GNOME Evolution, native GoreeCloud clients as developed).

These items remain release acceptance requirements except where the specific CI scope above has been verified. Tests of one independent command-line client do not close the real-device or production interoperability gate.
