# GoreeCloud DAV

**Status: experimental, not deployed or production-validated.** GoreeCloud DAV is the independently operated, standards-based back-end intended for GoreeCloud Calendar, Contacts, Tasks, Files, and other authorized clients. It supports CalDAV, CardDAV and WebDAV over one HTTP(S) service. It is a first-party integration and operations layer over [sabre/dav](https://github.com/sabre-io/dav), not a claim that GoreeCloud authored the protocol engine.

## Features present in source

- CalDAV calendar, event, recurrence and task protocol processing supplied by SabreDAV's CalDAV stack; CardDAV contacts and address books supplied by its CardDAV stack.
- WebDAV file handling under a per-authenticated-account `/files/` root; DAV locks; standard conditional requests and sync support provided by the framework.
- Single SQLite-backed metadata database, a local administrator CLI, Argon2id password hashes, no default users, per-user storage isolation, TLS enforcement, and reverse-proxy awareness limited to a trusted IP allowlist.
- Container build, loopback-only example Compose configuration, health probe, CI checks (including an HTTP smoke suite), and integration/operations/security docs.

**Not yet verified:** actual protocol compatibility across clients, end-to-end provisioning, container startup, database migration safety, backup/restore, performance, and production hardening. Code presence is not evidence of a stable service. This is not an SSO, file-sharing, public hosting or collaboration product yet.

## Quick start (local isolated test only)

Prerequisites: PHP 8.3+, Composer 2, PHP SQLite/DOM extensions. Production should use TLS termination, private networking and tested backups. The container example binds only to loopback. **Do not expose port 8787 to the public internet.**

```sh
composer install --no-interaction --prefer-dist --no-scripts
export DAV_DATA_DIR="$PWD/var"
php bin/dav init
printf '%s\n' 'put-a-long-unique-test-password-here' | php bin/dav user:add alice --password-stdin
php bin/dav status --json
```

For a local-only HTTP development test, set `DAV_REQUIRE_HTTPS=0` in a shell **only on a private test machine** and serve the `public/` directory using a supported PHP webserver. Do not send real credentials or personal data over plain HTTP. Production should use a hardened TLS reverse proxy; see [Operations](docs/OPERATIONS.md).

See [third-party notices](docs/THIRD-PARTY-NOTICES.md) for attribution and licensing.

## Client contract

Assuming an assigned backend host, such as `https://dav.example.invalid/` (illustrative, **not a deployed hostname**):

| Purpose | URL template |
| --- | --- |
| CalDAV discovery | `/.well-known/caldav` -> `/` |
| CardDAV discovery | `/.well-known/carddav` -> `/` |
| Principal | `/principals/{username}/` |
| Calendar | `/calendars/{username}/default/` |
| Address book | `/addressbooks/{username}/default/` |
| Personal files | `/files/` (scoped to the authenticated user) |
| Health probe | `/healthz` (no credentials, no private data) |

All DAV resources except the health probe and redirects require Basic credentials sent **only over HTTPS**. The `/files/` namespace is intentionally per-authenticated-user, not a shared public repository. Frontends may be separate applications and should use established DAV client libraries; do not assume JSON REST endpoints are present. Read [Client integration](docs/CLIENT-INTEGRATION.md) and [Security](docs/SECURITY.md).

## Operator CLI

`php bin/dav help` documents `init`, `status`, `user:add`, `user:rotate`, `user:disable`, `user:enable`, and `user:list`. User secrets are accepted via standard input only. Run administrative commands as the data-directory owner, **never through a public HTTP endpoint**.

## Architecture and limitations

- [Architecture](docs/ARCHITECTURE.md)
- [Operations, deployment and recovery](docs/OPERATIONS.md)
- [Security, threat model and release gates](docs/SECURITY.md)
- [Client integration contract](docs/CLIENT-INTEGRATION.md)
- [Planned features and acceptance gates](docs/ROADMAP.md)

This repo is public; **never commit real contact records, calendar data, passwords, tokens, certificates, database files, or infrastructure secrets**. The `.gitignore` is defense in depth, not an excuse to store secrets in the checkout.

## Dependency provenance

Protocol engine: `sabre/dav` v4.6 series, BSD-3-Clause licensed upstream; upstream examples are consumed at setup time, and their seeded demo accounts are intentionally ignored. Review upstream licensing, advisories, and transitive dependencies before releases. The application code and docs are GoreeCloud-maintained. License choice for the GoreeCloud-owned code is pending the founder's explicit open-source licensing decision; public source visibility does not itself grant redistribution rights.
