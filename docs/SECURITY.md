# Security model and release gate

**Release posture:** experimental; not suitable for Internet exposure without further verification and hardening.

## Implemented source-level controls

- No default user, password, self-signup, admin web UI, anonymous DAV file access or public shares.
- Argon2id hashes and minimum 12-byte passwords, non-argument standard-input CLI credentials, user enable/disable and rotation.
- Pre-authentication before filesystem selection; per-account storage directories; guarded WebDAV file/directory nodes reject symlinks (including linked account-root ancestors), traversal, and moves outside the assigned account root, including in nested subdirectories; read-only repo container filesystem with named data volume in example Compose.
- HTTPS enforcement defaults on, including CalDAV/CardDAV `/.well-known/` discovery redirects; the unauthenticated local GET `/healthz` probe is intentionally exempt and must remain private at the gateway. `X-Forwarded-Proto` is honored only for explicitly trusted direct proxy IPs. Containers bind to host loopback only by default.
- Docker/Apache enforces a fixed 64 MiB request-body ceiling (including chunked transfers), plus request-read timeouts. PHP rejects oversized declared Content-Length before authentication. This is not an allocation quota, authentication brute-force defense or request rate limit.
- Custom paginated CardDAV SQLite backend prevents a bounded sync-collection response from advertising the final collection token while later change rows remain undelivered. CalDAV and CardDAV now reject future and expired incremental tokens rather than silently implying an up-to-date collection. Disposable CI verifies five-item pages and synthetic history expiration; high-volume/concurrent paging and real client recovery remain unverified.
- WebDAV file uploads now use a private staged writer and per-account cooperative lock even when the optional file-byte quota is disabled. This avoids direct overwrite/truncation on failed streams; it verifies staging and destination share a filesystem, and synchronizes staged bytes with fsync before rename. Parent-directory fsync and true power-loss durability are still not verified. It does not coordinate unrelated host writers. The unmetered path retains filesystem quota property reporting; the optional quota path keeps its existing 507 enforcement.
- Private WebDAV file ETags now use a content SHA-256 digest. The upstream inode/size/second-resolution-mtime ETag can fail to change after same-sized rapid writes, leading to stale conditional HTTP responses. File hashing is intentionally correctness-first; benchmark large files and consider persistent revision metadata before production scale.
- Strict username allowlist, SQLite prepared statements, rejection of symlinked `DAV_DATA_DIR` ancestors and registry/metadata database paths, restrictive local permissions, protected document root, disabled directory listing, no detailed HTTP exception output.
- Cooperative request/administration locking and a local-only snapshot/verification command preserve an atomic copy of the SQLite registry, per-account metadata, and file tree under controlled writers. Snapshots are sensitive plaintext and require separate encryption/offsite handling. SHA-256 verification detects accidental damage or missing/untracked resources but does not authenticate an attacker-controlled manifest; an independently protected digest/signature and restore rehearsal remain release requirements.
- `composer.lock` pins exact PHP package versions and the Docker dependency layer copies that file before `composer install`; CI checks lockfile metadata, runs syntax tests and performs Composer audit. CI actions are pinned by immutable commit SHAs. Both Docker build stages now pin verified official multi-platform base-image manifest digests, including PHP 8.3.36 Apache Bookworm and Composer 2. The exact digests were resolved and logged in [GitHub Actions run #42402396542](https://github.com/GoreeCloud/DAV/actions/runs/42402396542); the immutable digest build must still pass CI before acceptance. **This does not pin Debian apt packages or OS repository snapshots, guarantee image provenance/signatures, or provide unattended security updates.** Refresh the digests deliberately after reviewing upstream image updates, then rebuild and verify the full disposable CI suite.

## Threat model and open risks

| Threat | Current posture / needed follow-up |
| --- | --- |
| Network credential theft | TLS-only setting; verify proxy topology and client certificate checks; never enable HTTP mode in production |
| Password brute force | **No in-app throttle.** Caddy/Gateway must enforce rate limits and alerts; prefer private VPN-only exposure initially |
| Credential theft / stolen device | A password has full account access. App-specific revocable tokens and optional MFA/OIDC are a future milestone |
| Cross-user calendar/contact access | Physical per-account SQLite stores plus two-user HTTP isolation smoke tests; broader REPORT/ACL audit required |
| Cross-user file access | Pre-authenticated per-user FS mount; local boundary tests reject path traversal and symlink escapes; WebDAV symlink/MOVE/MKCOL HTTP regression tests passed in CI on commit 4730bba; a broader independent audit is pending |
| Unsafe file uploads | Optional same-ceiling-per-account file-byte quotas and staged atomic writes are implemented, including when the quota is disabled; no per-user customized ceilings, metadata quotas, antivirus scanner, content inspection or file versioning. Apache hard-caps bodies at 64 MiB; also apply reverse-proxy limits and storage monitoring |
| Storage loss | Offline snapshot command plus manifest integrity check added; **off-site encryption, independent full restore drills and retention remain outstanding** |
| Dependency or supply-chain exploit | `composer.lock` pins PHP packages, generated on the PHP 8.3 CI runner. CI validates/audits it. Container base-image digests, OS-package versions and release provenance still need pinning/review. CI actions are now pinned to verified upstream commit SHAs. |
| Service denial of service | Apache request-size/read time bounds added; rate limiting, per-user quotas, concurrency bounds, alerting and gateway limits still required before live exposure |
| Sensitive audit logs | Avoid credential and DAV payload logging; define redacted request IDs and privacy-preserving audit logging before production |
| Public health probe | Limited to process liveness, no user/database/version information; restrict monitoring exposure at gateway |

## Required acceptance before any deployment or Stable label

1. Dependency installation and audit, full PHP 8.3 runtime test with SQLite and container startup, no default sample principals.
2. End-to-end DAV method and interoperability matrix; forbidden cross-user reads/writes across all three DAV surfaces.
3. Preauth `Authorization` header pass-through and malicious `X-Forwarded-Proto` negative tests; TLS/HSTS at verified proxy.
4. Preserve the passed CI snapshot/hash-verifier and database integrity tests; complete an independent whole-service restore, encrypted offsite storage, and upgrade/rollback rehearsal (pending).
5. Review storage permissions, privilege separation, container read-only execution and resource limits with real target host configuration.
6. Validate the 64 MiB body ceiling and request-read timeouts under real deployment; add gateway throttling, per-user quotas, metrics, redacted logs and an incident response path. Conduct independent security review.
7. Document supported client matrix, version pinning, migration approach, operations runbook and release approval.

For security reports, avoid posting credentials or live data in public issues. Contact the GoreeCloud owner using an authorized private channel.

## Hardened tenant data partitioning

Global credentials and tenant DAV objects are stored in separate SQLite databases. Each authenticated request opens only that principal's DAV metadata database and WebDAV file root. No cross-user calendar or address book sharing is supported in this foundation. Preserve permissions on `metadata/` and `files/`, and verify tenant boundaries using CI's integration suite. This is defense in depth; audit future scheduling/sharing plugins before enabling them.

## Cooperative-lock threat boundary

`ServiceLock` synchronizes requests and CLI operations using an advisory file lock inside the private data directory. It is a defense against in-process writes during `backup:create`, **not** a substitute for service drain, filesystem permissions or isolated host backups. Other writers that ignore the lock can still corrupt a backup. The backup target is intentionally a new private directory whose existing parent path is checked for symlinks; anyone with access to its files can see personal data and password hashes. Never place the backup under a web root or commit it to Git.

## Filesystem adapter boundary (CI-verified experimental review branch)

Upstream `Sabre\DAV\FS\Directory` creates ordinary child directories and files that follow PHP filesystem semantics. An imported or externally introduced symlink could otherwise lead the WebDAV adapter outside its assigned filesystem root. GoreeCloud's `PrivateDirectory`, `PrivateFile`, and `PrivateFilesystemGuard` classes propagate a canonical account-root guard to nested nodes and reject linked resource paths, path traversal, and attempts to move or delete the mount root. Linked entries are hidden in directory listings. The guard also checks every ancestor of the initial account root before calling `realpath()`, preventing a symlinked parent such as `files -> /external` from silently relocating the root. `Config::dataDir()` rejects linked ancestors outside the web root as well. The guard prevents expected escapes for a trusted host filesystem, **not** symlink-swap time-of-check/time-of-use races by malicious local processes; ownership and volume permissions must prohibit untrusted concurrent writers. Revalidate against installed SabreDAV v4.6, including COPY/MOVE/LOCK in HTTP CI and an isolated deployment before enabling client access.

### Verified review-branch evidence, November 5, 2026

A pure-PHP regression reproduced an accepted root below a symlinked ancestor. The guard rejects it, and failed-account cleanup is limited to the new account's SQLite paths. The revised filesystem/backup tests, WebDAV method checks and container startup passed GitHub Actions on commit `4730bba` (run #42034079943). Request-size/timeout controls passed the disposable Docker test in run #42039073000. PHP package locking passed run #42040242187, and SHA-pinned GitHub Actions passed run #42040456214. Symlink-swap races by malicious local processes, resource exhaustion and independent client interoperability remain separate security-review topics.

## Container smoke test and TLS evidence

The disposable `container-smoke` job passed on the previous published commit. It built the read-only Apache/PHP container, provisioned a disposable account, rejected plaintext and spoofed forwarded-TLS requests, simulated a trusted proxy address, and exercised PHP SQLite snapshot/verification. **This does not establish real end-to-end TLS, valid public certificates, HSTS, production rate limits or a safe external network route.** The updated smoke test passed HTTP 413 oversize-upload denial on the review branch in run #42039073000; real production networking, rate limiting and release approval remain separate gates.
