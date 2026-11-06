# Optional per-account WebDAV file-byte ceiling (experimental)

GoreeCloud DAV can enforce a **separate WebDAV file-byte ceiling for each authenticated account**. This is an opt-in defense against unintended file-storage growth, not a complete per-account resource allocation or production DoS-control system.

## Configuration

Set `DAV_ACCOUNT_FILE_QUOTA_BYTES` to a nonnegative base-10 integer no larger than `PHP_INT_MAX`. The same limit applies **independently to each account**. Leave it empty/unset to disable this feature; `0` prohibits nonempty file uploads. For example, `1073741824` gives Alice and Bob separate 1 GiB budgets. The example Compose service passes the setting to PHP. Deploy the setting as an environment configuration managed by the operator, not as user input or an API parameter. An invalid value is rejected rather than silently removing the limit.

**This is not yet individually configurable by account**: all accounts use the same ceiling, with independent consumption. CalDAV/CardDAV SQLite metadata, lock rows, SQLite journals, directories, account count, backup storage and temporary filesystem overhead are **not** included. Global Apache `LimitRequestBody` remains 64 MiB per request regardless of this setting. Per-user limits, metadata quotas, object count limits, monitoring, and cleanup need separate design/release gates.

## Implemented enforcement

- New `PUT` and replacement `PUT` for WebDAV files are staged in the account's private metadata directory and committed by rename only after the complete byte stream fits. An over-budget attempt returns **HTTP 507 Insufficient Storage** without changing the old file or creating a partial target.
- The amount available on replacement includes the old file's bytes; deleting files frees capacity. `COPY` uses the same file-creation path and is subject to the limit; a cross-directory `MOVE` implemented as copy-then-delete can fail conservatively while near the ceiling.
- A per-account `flock` file in `metadata/<username>/.file-quota.lock` serializes quota-controlled writes on the same local filesystem. Each account has a distinct lock and its usage is counted only under `files/<username>/`. Quota PROPFIND properties derive their used/available values from a fresh filesystem scan.
- Symlinks, unsafe objects, unexpected target changes and failed staging writes are rejected. A quota scan fails closed when it finds an unsafe filesystem entry.

## Threat and operational limitations

This design performs an O(number of files) scan before each upload. It is for small private deployments until a verified durable usage index is available. It assumes a service-owned private directory, a single shared volume and no untrusted host-side writers; an attacker able to mutate account paths independently can race PHP's path checks. The flock is cooperative among GoreeCloud HTTP workers and **does not lock other programs**. Streaming staging also requires temporary free disk space outside the counted WebDAV file tree; host disk exhaustion still needs monitoring, filesystem limits, backups, and rollback. Writes with the quota disabled continue to use the previously existing WebDAV adapter behavior.

Test a quota change on a disposable instance before enabling it for existing accounts. If a user's current files exceed a newly reduced ceiling, reads and deletes remain possible but subsequent writes must fail until space is freed. Do not delete account data to meet a quota without specific authorization. The quota lock is runtime metadata and can be recreated after restoration. The backup procedure still captures the account files and SQLite databases; record the separate environment quota setting in private deployment configuration.

**Evidence:** `php tests/FileQuotaTest.php` exercises accounting, both accounts, stream uploads, failed replacements, invalid configurations and symlinks without Composer. `bash tests/QuotaHttpTest.sh` exercises real SabreDAV HTTP PUT/GET/DELETE responses with a throwaway database in CI. These are not independent real-device tests or a production admission policy.
