# GoreeCloud DAV — implemented and verified features

**Evidence: GitHub Actions on the `feat/goreecloud-dav-foundation-20261105` review branch (November 2026). The review branch is not merged or deployed.**

## Verified in automated tests

- Composer dependency resolution and vulnerability-advisory audit; PHP source syntax; credential and configuration security unit tests.
- SQLite account-registry initialization and local CLI lifecycle: account creation, status/listing, password rotation, disable, and re-enable.
- HTTP authenticated DAV root, default CalDAV calendar, and default CardDAV address book discovery through PROPFIND.
- CalDAV iCalendar event PUT/GET and CardDAV vCard contact PUT/GET using test resources.
- WebDAV file PUT/GET with per-user file separation: another user's access to the file returns 404.
- Cross-account calendar and address-book collection access returns 403 or 404 when the authenticated account is not the owner.
- No default account creation and no production deployments in this workstream.

## Present in local changes; remote CI validation pending

- Cooperative shared/exclusive locking between HTTP and CLI, an operator-only SQLite `VACUUM INTO`/file snapshot utility with integrity manifest, and guarded nested WebDAV nodes and SQLite metadata paths rejecting symlink/path traversal. Local PHP syntax, existing security unit tests, and new backup integrity and filesystem and SQLite storage-path guard unit tests pass. Expanded HTTP/TLS/sync/delete/MKCOL/MOVE/symlink-denial and snapshot/restore CI tests are authored but have not run on GitHub Actions.

## Present in repository; additional validation required

- Broader CalDAV recurrence, invitation/scheduling interoperability and tasks; CardDAV vCard compatibility with diverse clients; WebDAV locking and sync conformance.
- Docker container boot and TLS reverse proxy connectivity, production backup/restore, upgrades/migrations, observability, and quotas.
- Stable release, production deployment and supported-client matrix.

Do not conflate a green narrow CI suite with a production-ready service.

- **Local-only latest hardening:** Initial root canonicalization now rejects symlinked ancestors of WebDAV account roots and `DAV_DATA_DIR`; failed account provisioning cleans SQLite files within the account metadata directory. A local reproduction and six standalone unit suites passed, including backup destination symlink rejection. Not yet published or remotely integration-tested.

- Local WebDAV file ETag hardening uses SHA-256 of content, with a regression for same-size, preserved-mtime rewrites. CI HTTP conditional GET behavior remains unverified for this development patch.

## Independent offline recovery test (local verification only)

The development source includes `tests/SqliteRecoveryTest.py`: a disposable
SQLite WAL snapshot and recovery rehearsal using the actual checked-in schemas,
PHP manifest verification, restored SQLite integrity and data checks. It is
not a production backup implementation, a PHP PDO integration run, or a
substitute for independent encrypted restore and disaster-recovery approval.
