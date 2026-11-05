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

## Present in code; additional validation required

- Broader CalDAV recurrence, invitation/scheduling interoperability and tasks; CardDAV vCard compatibility with diverse clients; WebDAV locking and sync conformance.
- Docker container boot and TLS reverse proxy connectivity, production backup/restore, upgrades/migrations, observability, and quotas.
- Stable release, production deployment and supported-client matrix.

Do not conflate a green narrow CI suite with a production-ready service.
