# GoreeCloud DAV — Architecture

**State:** implementation foundation written; full runtime verification pending.

## Design principles

1. **Protocol-native backend, UI-independent:** CalDAV/CardDAV/WebDAV and DAV discovery are the contracts, allowing independent mobile, desktop and web clients. No frontend is bundled with this repository.
2. **Reuse mature standards:** SabreDAV handles DAV method semantics, iCalendar/vCard parsing, ETags, sync reports and CalDAV/CardDAV collections. GoreeCloud owns configuration, accounts, operational interface and isolated file mounts.
3. **Private by default:** a request is authenticated before a user-specific file tree is constructed. SabreDAV rechecks Basic authentication; the credential lookup uses a local SQLite account registry with Argon2id hashes. A username is constrained to safe lowercase ASCII identifiers.
4. **Coordinated backup contract:** HTTP and CLI processes share a reader lock for their operation lifetime; snapshot operations take an exclusive lock. SQLite databases are copied via `VACUUM INTO` and file contents are checksummed. This contract is cooperative only and does not cover out-of-band filesystem writes.
5. **Strict storage:** calendars, contacts, principals and locks live in SQLite via upstream PDO backends. Uploaded personal files live in `DAV_DATA_DIR/files/<username>/`, never under the HTTP document root; nested WebDAV nodes propagate a canonical account-root guard and reject pre-existing symlinks and out-of-root paths. The actual database is not stored in Git.
6. **Explicit operational boundary:** the administrative CLI is local only; no remote account provisioning endpoints, public shares, or anonymous file resources.

## Request path

```text
client DAV method (PROPFIND, REPORT, PUT, DELETE, MKCOL, MKCALENDAR, LOCK ...)
  -> TLS reverse proxy + rate limits / size limits
  -> PHP public/index.php (HTTPS required; Basic parsed; account verified)
  -> SabreDAV Server + Auth, ACL, Sync, Locks, CalDAV, CardDAV plugins
      /principals/   -> SQLite principal backend
      /calendars/    -> SQLite calendar backend
      /addressbooks/ -> SQLite address book backend
      /files/        -> guarded filesystem mounted ONLY for authenticated username
```

`/healthz` is an intentionally shallow liveness check, not a readiness or DB-health guarantee. Public discovery paths redirect to the server base URI.

## Storage and trust boundaries

- Account credentials: local Argon2id hashes, separate from SabreDAV's sample digest `users` table. Demo SQL inserts are not executed. No default admin password exists.
- Identity: `principals/{username}`; the CLI provisions a default calendar and address book and calendar proxy principal records.
- File space: backend chooses `files/{username}` only **after** successful credential verification. This deliberately does not implement multi-user file collaboration. Existing ACL permissions on CalDAV/CardDAV nodes are delegated to SabreDAV.
- Reverse proxy: only an exact allowlisted peer IP can assert `X-Forwarded-Proto: https`. The operator must configure Caddy/Gateway, private networking and trusted proxy IPs before production exposure.
- Database: SQLite WAL and a busy timeout are appropriate for initial self-hosted single-instance trials; multi-instance and high-concurrency deployments require a reviewed database migration and different file-lock/storage design.

## Architectural exclusions / limitations

Not implemented: OAuth/OIDC or GoreeCloud identity federation; scopes or app-specific token revocation; server-side encrypted file content; complex family sharing; cross-account ACLs for files; REST/GraphQL APIs; versioning/trash; email scheduling/invitations; object storage; resumable uploads; search/indexing; cross-region replication; centralized metrics/audit-log pipeline; asynchronous task workers. Do not infer these from SabreDAV's generic capabilities. The SabreDAV sharing/scheduling plugins are intentionally not loaded until policy, identity and interoperability tests justify them.

## Future integrations

GoreeCloud Calendar and Tasks should target the same CalDAV principal and calendar collections. Contacts should use CardDAV and preserve exact vCard fields. GoreeCloud Files/Sync clients should use ETags/preconditions and WebDAV locks. GoreeCloud Backup should capture metadata plus file contents consistently, not back up database WAL files individually without a SQLite backup procedure. An identity bridge must map verified GoreeCloud account identities to DAV principals without changing stable principal URIs.

## Account isolation (November 2026 hardening)

The global `DAV_DATA_DIR/dav.sqlite` stores authentication data only. Each account's calendar, contact, principal and DAV lock metadata resides in its own `DAV_DATA_DIR/metadata/<username>/dav.sqlite` database. The authenticated user's file tree is `DAV_DATA_DIR/files/<username>` and contains no database files. The server opens only the authenticated user's metadata database. This provides an additional tenant boundary even where protocol-level ACLs might otherwise permit access. Integration tests check that Bob cannot read Alice's resources. There is no cross-user sharing in this initial release. Backups must capture all three storage classes atomically or use consistent snapshots.

## Snapshot and restoration boundary

`bin/dav backup:create /absolute/new-directory` produces a private backup tree with the registry and each account metadata SQLite snapshot, owned files and a v2 manifest recording SHA-256 checksums plus WebDAV directory structure. It rejects orphan account roots and symlinks. `backup:verify` checks hashes and snapshot membership, including empty directories; it cannot authenticate an editable manifest. It does not restore or encrypt data; operators must stage the snapshot in an isolated environment and validate clients before any production recovery. Lock operations require all deployed instances to share one filesystem/lock; multi-instance clusters are unsupported.

## Guarded filesystem implementation (local hardening, pending remote CI)

`PrivateDirectory` replaces the generic SabreDAV directory recursively; it never returns an unguarded `Sabre\DAV\FS\Directory` or `File` for descendants. `PrivateFile` checks the private account root before read, write, delete, metadata, and rename operations. `PrivateFilesystemGuard` checks strict path segments, rejects symlinks at each path component **including ancestors of the account root before canonicalization**, and forbids crossing the canonical root. `Config::dataDir` rejects symlinked ancestors of the data root; `Database::assertPrivateStoragePath` separately rejects SQLite paths with symlinked intermediate metadata directories or linked database files. The mounted `/files/` node cannot be renamed or deleted. All operations remain dependent on operating-system ownership preventing untrusted simultaneous local filesystem manipulation; the PHP guard is not equivalent to race-free `openat`/`O_NOFOLLOW`. `tests/FilesystemGuardTest.php` covers pure PHP boundary checks; expanded HTTP checks passed in isolated GitHub Actions on commit `4730bba`; production threat validation is still pending.

**Provisioning rollback:** `Provisioner` removes only the newly attempted account metadata SQLite files inside `metadata/<username>/` before attempting to remove empty account directories. A previous path concatenation could leave orphaned databases; the correction has a standalone regression test.
