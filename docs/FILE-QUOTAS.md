# Optional per-account WebDAV file-byte ceiling (experimental)

GoreeCloud DAV can enforce a **separate WebDAV file-byte ceiling for each authenticated account**. This is an opt-in defense against unintended file-storage growth, not a complete per-account resource allocation or production DoS-control system.

## Configuration

Set `DAV_ACCOUNT_FILE_QUOTA_BYTES` to a nonnegative base-10 integer no larger than `PHP_INT_MAX`. The same limit applies **independently to each account**. Leave it empty/unset to disable this feature; `0` prohibits nonempty file uploads. For example, `1073741824` gives Alice and Bob separate 1 GiB budgets. The example Compose service passes the setting to PHP. Deploy the setting as an environment configuration managed by the operator, not as user input or an API parameter. An invalid value is rejected rather than silently removing the limit.

**This is not yet individually configurable by account**: all accounts use the same ceiling, with independent consumption. CalDAV/CardDAV SQLite metadata, lock rows, SQLite journals, directories, account count, backup storage and temporary filesystem overhead are **not** included. Global Apache `LimitRequestBody` remains 64 MiB per request regardless of this setting. Per-user limits, metadata quotas, object count limits, monitoring, and cleanup need separate design/release gates.

## Implemented enforcement

- New `PUT` and replacement `PUT` for WebDAV files are staged in the account's private metadata directory and committed by rename only after the complete byte stream fits. An over-budget attempt returns **HTTP 507 Insufficient Storage** without changing the old file or creating a partial target.
- The amount available on replacement includes the old file's bytes; deleting files frees capacity. `COPY` uses the same file-creation path and is subject to the limit; a cross-directory `MOVE` implemented as copy-then-delete can fail conservatively while near the ceiling.
- When quotas are enabled, WebDAV file deletion, file/directory renaming, directory creation and recursive directory deletion now acquire the same per-account exclusive file-quota lock as uploads. Recursive directory removal reuses the already-held lock for nested child deletions, avoiding deadlock. Cross-process unit coverage checks these operations block while a conflicting upload-style lock is held, then finish once released. This is a **cooperative lock among GoreeCloud request workers**, not protection from unrelated filesystem writers.
- A per-account `flock` file in `metadata/<username>/.file-quota.lock` serializes quota-controlled writes on the same local filesystem. Each account has a distinct lock and its usage is counted only under `files/<username>/`. Quota PROPFIND properties derive their used/available values from a fresh filesystem scan.
- Symlinks, unsafe objects, unexpected target changes and failed staging writes are rejected. A quota scan fails closed when it finds an unsafe filesystem entry.

## Threat and operational limitations

This design performs an O(number of files) scan before each upload. It is for small private deployments until a verified durable usage index is available. It assumes a service-owned private directory, a single shared volume and no untrusted host-side writers; an attacker able to mutate account paths independently can race PHP's path checks. The flock is cooperative among GoreeCloud HTTP workers and **does not lock other programs**. Streaming staging also requires temporary free disk space outside the counted WebDAV file tree; host disk exhaustion still needs monitoring, filesystem limits, backups, and rollback. Writes with the quota disabled continue to use the previously existing WebDAV adapter behavior.

Test a quota change on a disposable instance before enabling it for existing accounts. If a user's current files exceed a newly reduced ceiling, reads and deletes remain possible but subsequent writes must fail until space is freed. Do not delete account data to meet a quota without specific authorization. The quota lock is runtime metadata and can be recreated after restoration. The backup procedure still captures the account files and SQLite databases; record the separate environment quota setting in private deployment configuration.

**Evidence:** `php tests/QuotaMutationLockTest.php` exercises cross-process mutation-lock blocking, recursive deletion without deadlocks and preserved quota totals; `php tests/FileQuotaTest.php` exercises accounting, both accounts, stream uploads, failed replacements, invalid configurations and symlinks without Composer. `bash tests/QuotaHttpTest.sh` exercises real SabreDAV HTTP PUT/GET/DELETE responses with a throwaway database in CI. These are not independent real-device tests or a production admission policy.

**Simultaneous upload regression:** `php tests/ConcurrentQuotaUploadsTest.php` launches separate PHP workers behind a start barrier: two simultaneous 8-byte Alice uploads under a 10-byte account ceiling must yield exactly one success and one HTTP-equivalent storage rejection; Bob's separate 10-byte upload must succeed independently. A second 2-byte race admits only one upload to fill Alice's remaining space, rejected targets leave no partial files, and locked deletion safely frees capacity for reuse. This is a CLI concurrency test of the same FileQuota component used by HTTP, not a full Apache load/stress test.

## Safe default uploads without a configured quota

An unset `DAV_ACCOUNT_FILE_QUOTA_BYTES` still means **no GoreeCloud file-byte ceiling**. The authenticated WebDAV server now constructs an unmetered file writer that uses the same per-account exclusive flock and private temporary-file staging as quota-enabled uploads. This removes the previous direct `file_put_contents` replacement path: a failed stream or interrupted upload cannot truncate the old destination before the replacement is committed by rename. It also coordinates ordinary file and directory mutations within the single-node GoreeCloud service. Unmetered writes skip the O(number of files) quota scan and continue exposing the filesystem's ordinary DAV quota properties, not a fabricated per-account limit.

This does **not** make filesystem operations durable across power loss: the writer flushes the staged file before rename but does not call fsync on the file and containing directories. Host disk exhaustion, permission errors, network mounts with divergent rename/flock semantics, and external host processes still require operational controls. Do not confuse atomic visibility of the replacement with full crash durability or end-to-end file versioning.

The `UnmeteredAtomicWritesTest.php` regression checks stream replacement, private staging, failure rollback, unchanged quota reporting, and blocked concurrent mutations under the shared account lock. Real-world load and power-failure tests remain open.

### Same-filesystem staging and file flush

The staged writer now refuses a target whose parent directory has a different filesystem device ID than the private staging directory. PHP can otherwise treat a cross-device rename as a copy/unlink fallback, which is **not atomic**. The writer also calls PHP `fsync()` on its private staged file before committing the rename; a failed file sync fails the upload while leaving the previous target unchanged. Linux CI checks the split-device guard using a disposable `/dev/shm` root when a separate tmpfs is present.

**Durability boundary:** A successful file sync protects the staged bytes, but the containing directory entry is not separately synchronized after rename. Sudden power loss, NFS semantics, host storage failures, externally modified paths and filesystem corruption remain release risks. Operational snapshots and recovery testing are mandatory; this change must not be described as end-to-end crash-consistent storage.
