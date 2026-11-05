# Implemented features — source vs runtime

**Source-implemented and locally syntax-reviewed (2026-11-04):** HTTP DAV bootstrap, basic-credential parsing and username validation, account database and CLI scaffolding, SabreDAV CalDAV/CardDAV/WebDAV composition, per-user file root wiring, default collection provisioning logic, HTTPS/trusted proxy guard, container/example CI and documentation.

**Locally verified:** PHP 8.4 syntax of all authored PHP files; dependency-free credential/configuration security unit tests.

**Not verified functional:** Composer install; full runtime, SQLite migration, actual authenticated DAV operations, container boot, client interoperability, backup and production deployment. These are not yet classified as delivered functionality.
