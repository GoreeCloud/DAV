# Operator runbook — experimental

## Initial deployment preparation

This repository does **not** deploy the service or configure DNS. Proposed service identity: `dav.goreecloud.com` and isolated development identity `dav-dev.goreecloud.com`, subject to verified uniqueness, existing GoreeCloud DNS inventory, appropriate authorization and private-vs-public network policy. Do not assume either resolves.

Run behind Caddy or GoreeCloud Gateway with valid HTTPS, rate limits, `X-Forwarded-Proto` sanitization, request body-size limits and appropriate VPN boundary. The example `compose.yaml` publishes only `127.0.0.1:8787` and requires an allowlisted direct proxy address. A different network topology will require a verified port/route configuration. Avoid CDN/proxy transformations that drop custom DAV methods, WebDAV lock headers or `Authorization`.

## Build / data initialization

```sh
docker compose build
# Operate as the data-volume owner, not as a public HTTP client.
docker compose run --rm --no-deps --user www-data dav php bin/dav init
printf '%s\n' "$TEMPORARY_PASSWORD_FROM_SECURE_INPUT" | docker compose run -T --rm --no-deps --user www-data dav php bin/dav user:add alice --password-stdin
# Do not actually keep passwords in environment variables/shell history; see below.
```

The password line is schematic only: use a vault/interactive terminal with protected stdin and a private pipe, not a literal env variable or password embedded in a shell script. `user:add` creates default CalDAV/CardDAV collections and private `/files/` storage. No accounts are created automatically. The container uses a named `dav_data` volume; never discard it without a verified backup and explicit approval.

## Health and diagnostics

```sh
docker compose run --rm --no-deps --user www-data dav php bin/dav status --json
curl -i http://127.0.0.1:8787/healthz  # local probe only
```

`healthz` proves only that the HTTP process is accepting a basic request. It does not check the database or a real DAV round-trip. Check the authenticated service over **HTTPS** at the approved hostname before enabling clients.

## Credential administration

```sh
# Supply password through a protected stdin stream, without argv exposure.
php bin/dav user:rotate alice --password-stdin
php bin/dav user:disable alice
php bin/dav user:enable alice
php bin/dav user:list --json
```

Do not log stdin, passwords or bearer Authorization values. Disabling stops new authentication; assess active requests and clients separately. Rotated passwords do not support per-device revocation in the initial model.

## Backup and recovery

Back up (1) a **consistent SQLite snapshot** of `dav.sqlite` using SQLite's backup API or `VACUUM INTO`, including metadata and locks, and (2) the entire corresponding `files/` subtree while writes are stopped or via application-consistent snapshots. Never copy only the live `.sqlite` file while WAL updates are pending and assume the backup is consistent. Encrypt off-host backups, restrict access, verify integrity, and practice restoration to an isolated service before approving retention automation. Keep file/DB snapshots coordinated.

On restore, stop writes, snapshot the current state for rollback, restore coordinated metadata and files to a separate directory, validate permissions and run end-to-end DAV tests on private URLs. Do not overwrite live data without explicit authorization and a rollback plan.

## Updating

Review SabreDAV changelog/schema and dependency advisories; pin and review `composer.lock`, capture backups, test migrations and interoperability against supported clients, stage on private development host, and plan rollback. `init` is **not** a migration command; it refuses to overwrite any existing database.
