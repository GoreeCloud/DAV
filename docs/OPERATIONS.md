# Operator runbook — experimental

## Initial deployment preparation

This repository does **not** deploy the service or configure DNS. Proposed service identity: `dav.goreecloud.com` and isolated development identity `dav-dev.goreecloud.com`, subject to verified uniqueness, existing GoreeCloud DNS inventory, appropriate authorization and private-vs-public network policy. Do not assume either resolves.

Run behind Caddy or GoreeCloud Gateway with valid HTTPS, rate limits, `X-Forwarded-Proto` sanitization, request body-size limits and appropriate VPN boundary. The supplied Apache Docker image enforces `LimitRequestBody 67108864` (64 MiB) and `RequestReadTimeout` for slow headers/body reads. The PHP front controller rejects declared `Content-Length` values exceeding this bound before authentication; Apache separately enforces the actual streamed size for chunked uploads. The cap is deliberately fixed in this experimental build—larger file uploads are not yet supported, and an upstream proxy should enforce the same or a smaller limit. This is **not** an account quota or a request-rate/brute-force control. The example `compose.yaml` publishes only `127.0.0.1:8787` and requires an allowlisted direct proxy address. A different network topology will require a verified port/route configuration. Avoid CDN/proxy transformations that drop custom DAV methods, WebDAV lock headers or `Authorization`.

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

Back up (1) a **consistent SQLite snapshot** of the global credential registry `dav.sqlite`, (2) all per-account calendar/contact/principal/lock databases at `metadata/<username>/dav.sqlite`, and (3) the corresponding `files/` subtrees while writes are stopped or via application-consistent snapshots. Never copy only the live `.sqlite` file while WAL updates are pending and assume the backup is consistent. Encrypt off-host backups, restrict access, verify integrity, and practice restoration to an isolated service before approving retention automation. Keep file/DB snapshots coordinated.

On restore, stop writes, snapshot the current state for rollback, restore coordinated metadata and files to a separate directory, validate permissions and run end-to-end DAV tests on private URLs. Do not overwrite live data without explicit authorization and a rollback plan.

## Operator-only consistent snapshots (development foundation)

`backup:create` creates a **new** private destination directory outside `DAV_DATA_DIR` and the HTTP document root. Its existing parent path must not contain symlinks, so a backup destination cannot silently resolve into a different location. It uses `VACUUM INTO` to snapshot the credential registry and each account's metadata database, copies personal files, and writes a version 2 manifest containing file SHA-256 checksums and directory inventory, verified before success. Both the DAV HTTP request handler and administrative CLI participate in the same `flock` lock: HTTP requests take a shared lock for their full duration, while snapshots use an exclusive lock to block those requests until the snapshot completes. The lock **does not coordinate unknown third-party writers, direct host filesystem edits or multiple servers using different storage/lock files**. In production, stop/drain the HTTP service and pause external writers before running a snapshot; do not rely only on cooperative locking.

```sh
# Example only, run with a protected private data volume and correct filesystem owner.
# The destination must be new; never snapshot into a web-served directory.
php bin/dav backup:create /secure-backup-stage/dav-2026-11-05 --json
php bin/dav backup:verify /secure-backup-stage/dav-2026-11-05 --json
```

The destination contains **plaintext user credentials hashes, contacts, calendar events, and file data**; it is not encrypted and it must be restricted (mode 0700), encrypted before transfer and protected by a retention policy. If a snapshot fails, treat its destination as sensitive partial data and review/remove it only under a safe recovery procedure. Backups containing symlinks, incomplete user directories, or inconsistent account roots are rejected. The verifier checks file hashes, required per-account databases, exact file inventory, and recorded WebDAV directories (including empty directories). It additionally validates each SQLite snapshot's database integrity and foreign keys, required DAV schema tables, and exact registry-account consistency with the manifest. These checks run offline during `backup:verify` and also after `backup:create`; a checksummed corrupt database must fail verification. A verifier result does not establish authenticity against someone who can rewrite files and manifest together; retain a trusted external manifest digest or signature. The v2 verifier intentionally rejects older experimental v1 manifests, which lacked directory completeness checks. Review any older snapshots separately rather than assuming compatibility.

**Independent SQLite recovery rehearsal:** `python3 tests/SqliteRecoveryTest.py` builds
throwaway credential, calendar and contact databases from the pinned SQL schemas,
keeps live WAL connections open, snapshots them with `VACUUM INTO`, validates the
version 2 manifest using GoreeCloud's PHP verifier, restores to a separate
directory, checks SQLite `PRAGMA integrity_check` and recovered records, and
rejects altered files and symlinked snapshot-parent paths. This test requires
Python 3 and PHP CLI only; it does **not** execute the PHP PDO implementation,
SabreDAV, Docker, or a production backup/restore and must not be represented as
those release gates passing.

Restore rehearsals should copy the snapshot to a *disposable new* data directory, preserve owner/mode, then run `php bin/dav status --json`, SQLite integrity checks, and authenticated DAV smoke tests on a private test listener. Never restore over live files in place. An isolated CI test stages restoration at CLI level; it has passed in disposable runners; a complete production restore with representative client data and recovery point objectives is still outstanding.

## Encrypted archive staging for isolated recovery

The offline Python 3 standard-library utility bin/dav-backup-stage.py accepts a DECRYPTED tar stream on standard input and creates a NEW, non-symlinked private staging directory. Its parent must already exist with private (0700) permissions. Only ordinary files and directories beneath snapshot/ are accepted. Absolute paths, traversal, symlinks, hardlinks, device entries, duplicate members, oversized archives and attempts to overwrite an existing destination are rejected; any newly created partial destination is removed after failure.

For a separately obtained and trusted encrypted archive, use the private operator host and the approved GPG agent. Do not put a real passphrase into command arguments, environment variables, documentation, CI, logs, or the repository. The following is schematic only:

```sh
set -o pipefail
gpg --batch --decrypt /private/offsite/dav-backup.tar.gpg | python3 bin/dav-backup-stage.py --destination /private/stage/NEW-DAV-RESTORE
php bin/dav backup:verify /private/stage/NEW-DAV-RESTORE/snapshot --json
# Stop here: staging and verifying never authorize copying into live DAV_DATA_DIR.
```

Both pipeline commands and the subsequent PHP verification must succeed. The staged contents remain sensitive plaintext. The staging tool defaults to 100 GiB maximum total file payload and 200,000 archive members; size the limits for the expected archive and available private disk. It does not authenticate the archive's creator: independently protect signatures, key custody, destination storage, offsite transport and retention. The disposable CI tests exercise archive path/link/duplicate/size rejection, encrypted transport, wrong-key denial and ciphertext tampering. An operator-independent restore on a real offsite device is still unverified.
## Updating

Review SabreDAV changelog/schema and dependency advisories; pin and review `composer.lock`, capture backups, test migrations and interoperability against supported clients, stage on private development host, and plan rollback. `init` is **not** a migration command; it refuses to overwrite any existing database.

## Filesystem storage precautions

The WebDAV adapter rejects pre-existing symlinks and traversal inside personal file trees, including nested resources, but local writers with permission to mutate the volume may still race application-level checks. Keep account directories writable only by the service account, do not mount shared/untrusted folders into `DAV_DATA_DIR/files`, and reject archives that restore symlinks. Verify imported data with an offline tree scan, perform an isolated client regression for nested MKCOL/MOVE/PROPFIND/LOCK/COPY, and run `backup:verify` on a separately protected snapshot. The current guard passed the disposable GitHub Actions integration suite on the previous review commit; independent real-client testing remains outstanding.

### Root paths and provisioning rollback

Use direct, non-symlinked absolute host paths for `DAV_DATA_DIR`, `DAV_DATA_DIR/files`, and each account directory. Mount an ordinary private volume rather than adding symlinked ancestors, including before the data directory is created. A linked ancestor intentionally causes an error rather than following the link. For a failed `user:add`, the operator should inspect logs before retrying; the rollback cleanup is limited to the newly created account SQLite files (`dav.sqlite`, `dav.sqlite-wal`, `dav.sqlite-shm`) and its empty directories. If account directories remain nonempty, preserve them for authorized review rather than deleting data. The corrected rollback has passed CI unit coverage; independent administrative failure/recovery scenarios remain pending.

## WebDAV file ETag correctness and cost

GoreeCloud's guarded WebDAV `PrivateFile` computes a quoted SHA-256 digest over the
current file bytes for a strong ETag. Unlike upstream inode/size/whole-second
mtime validators, this does not mistakenly report a matching ETag for an
in-place same-size edit within a second. This requires reading the file on
ETag-producing requests: benchmark large collections and use size/rate limits
before exposing the service. Do not replace the digest with a coarse metadata
hash without an independently durable, updated revision store. Conditional GET and stale-update behavior now pass disposable SabreDAV HTTP integration checks in CI, including current/stale ETags; large-file hashing performance and true concurrent out-of-band writers remain unverified.

## Disposable container acceptance test (CI verified; not deployed)

The review-only `tests/DockerSmokeTest.sh` is designed for a disposable Docker-capable CI runner, **not a production server**. It builds the checked-in Dockerfile, checks `docker compose config`, starts the Apache/PHP image with a loopback-only listener and fresh disposable volume, and runs `php bin/dav init` and account provisioning as `www-data`. The test first requires plaintext and spoofed forwarded-TLS requests to fail. It then restarts the disposable container, trusting only the observed Docker-bridge gateway for a simulated TLS-terminating proxy, and checks an authenticated CalDAV `PROPFIND`. Finally it runs `backup:create` and `backup:verify` inside the container and independently checks the resulting SQLite registry integrity. A trap destroys the test container and volume on exit.

A Docker-bridge gateway is allowlisted **only inside this isolated smoke test**. The test's `X-Forwarded-Proto: https` simulates an upstream TLS terminator; no certificate is issued or verified, and it must not be used as evidence of production TLS security. In production only the verified direct proxy address may be trusted, and real external TLS, HSTS, gateway rate/body controls, and network isolation require separate checks. This test also does not replace the full CalDAV/CardDAV/WebDAV interoperability, independent backup/restore or rollback gates.

To run in a disposable checkout on a development machine with Docker and curl, from the repository root:

```sh
bash tests/DockerSmokeTest.sh
```

Use a free local port or set `DAV_SMOKE_PORT` to a free nonprivileged port; do not run alongside production containers or reuse an existing volume.

**Current verification:** [CI #42504233601](https://github.com/GoreeCloud/DAV/actions/runs/42504233601) passed all four jobs, including the immutable-base Docker smoke, real disposable TLS gateway, independent vdirsyncer client, offline SQLite recovery and GPG transport/staging checks. This does not establish deployment, production key custody or end-user device acceptance.

## PHP dependency lock and update procedure

The committed `composer.lock` pins the PHP package graph. `composer install --no-dev --no-interaction --prefer-dist --no-scripts` in the image uses that lock; a missing/stale lock fails CI validation instead of silently resolving new versions. Periodic upgrades should happen on a separate reviewed branch using PHP 8.3 and Composer 2: run `composer update --no-interaction --no-scripts` in a trusted disposable environment, inspect package diffs and license/advisory changes, then rerun the complete CI/Docker matrix before approving a new lockfile. Never run an unreviewed dependency-update script on production. The lock does not pin Debian APT packages or OS repository snapshots. Docker base-image manifest digests and GitHub Actions have separately been pinned and verified; image signatures, package provenance and release operations remain open.
