# CalDAV/CardDAV incremental sync-token and deletion-tombstone test

The disposable `tests/IncrementalSyncTest.sh` script creates a private loopback-only DAV service with synthetic Alice and Bob accounts. It obtains initial `DAV:sync-token` values from `sync-collection` REPORT responses, then creates, updates and deletes an event and a contact through HTTP. After each operation, an incremental REPORT from the previous token must return exactly the affected resource and a *new* token; deletion must produce an HTTP 404 tombstone in the multistatus response. The physically separate Bob calendar and address book must report no Alice changes.

**Scope:** This is a synthetic CI protocol test, not proof of every DAV client application, token expiry after long outages, large collections, pagination/limits, parallel update ordering, time-zone scheduling semantics, or offsite disaster recovery. HTTP is permitted only for the disposable 127.0.0.1 test listener; production DAV continues to require validated TLS. No real account, public endpoint, DNS, or production data is touched.

Run with `bash tests/IncrementalSyncTest.sh` from a checkout with PHP CLI and PDO SQLite, Composer-installed SabreDAV packages, Python 3 and curl.
