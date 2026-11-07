# Isolated whole-service recovery acceptance rehearsal

`bash tests/FullRestoreTest.sh` is a **disposable CI test**, not a production recovery procedure. It uses a new temporary directory, local-only PHP HTTP listener, three synthetic accounts, and throwaway passwords; it never reads an existing DAV data volume. The shell trap removes all temporary test files and terminates its test server on exit.

The rehearsal executes the **real operator commands** `php bin/dav backup:create` and `backup:verify`. It creates an iCalendar event, a vCard contact, separate WebDAV files for Alice and Bob, an empty WebDAV directory, and a disabled Carol account through the normal HTTP/CLI interfaces. The service is running while the cooperative snapshot is taken; concurrent external filesystem writers are **not simulated**. A copied backup with an unlisted file must fail verification before any restore.

For recovery, the test stops the original HTTP process, moves its entire live data tree out of the expected path, copies only the verified `dav.sqlite`, `metadata/`, and `files/` objects into a *new* isolated storage path, and checks every restored SQLite database with `PRAGMA integrity_check`. It then starts a fresh PHP process against **only the restored path** and verifies account count, calendar/contact readback, exact file bytes, per-account file isolation, disabled-account denial, empty directory preservation, and successful post-restore file writes under the quota ceiling.

## Boundaries and requirements

- Designed for a clean test runner with PHP 8.3+, PDO SQLite, Composer-installed dependencies, Python 3, Bash, curl, and an unoccupied loopback port (`DAV_RESTORE_TEST_PORT`, default `18832`). The test is included in the GitHub Actions `test` job. It does **not** require Docker, a public hostname, TLS certificates, or production accounts.
- This test verifies a coherent **single-node application-level recovery** using synthetic data, not encryption, off-host backup delivery, anti-tamper signatures, retention policy, an operator-independent DR exercise, real deployment rollback, restore-point objectives, migration compatibility, multiserver/host-writer coordination, or a production reverse proxy.
- The test uses `DAV_REQUIRE_HTTPS=0` only for the private loopback PHP listener and never changes a live deployment's TLS settings. Production HTTPS remains required.
- The manifest is a content-integrity check, **not cryptographic authenticity** against an actor who can replace the backup and manifest together. Retain trusted external digest/signature verification and encryption before approving real offsite recovery.
- This procedure intentionally does **not** copy `manifest.json` into the restored live data root; operators must verify the snapshot first, then restore only appropriate data objects with correct ownership, modes, and access controls.

A green CI test may close the *synthetic whole-service restoration* subgate. It must not be used to claim the broader real-world disaster recovery gate is complete.
