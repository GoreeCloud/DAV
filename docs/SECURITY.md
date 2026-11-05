# Security model and release gate

**Release posture:** experimental; not suitable for Internet exposure without further verification and hardening.

## Implemented source-level controls

- No default user, password, self-signup, admin web UI, anonymous DAV file access or public shares.
- Argon2id hashes and minimum 12-byte passwords, non-argument standard-input CLI credentials, user enable/disable and rotation.
- Pre-authentication before filesystem selection; per-account storage directories; read-only repo container filesystem with named data volume in example Compose.
- HTTPS enforcement defaults on; `X-Forwarded-Proto` is honored only for explicitly trusted direct proxy IPs. Containers bind to host loopback only by default.
- Strict username allowlist, SQLite prepared statements, restrictive local permissions, protected document root, disabled directory listing, no detailed HTTP exception output.
- Dependencies are installed through Composer; CI includes syntax tests and audit job. No guarantee until builds finish successfully.

## Threat model and open risks

| Threat | Current posture / needed follow-up |
| --- | --- |
| Network credential theft | TLS-only setting; verify proxy topology and client certificate checks; never enable HTTP mode in production |
| Password brute force | **No in-app throttle.** Caddy/Gateway must enforce rate limits and alerts; prefer private VPN-only exposure initially |
| Credential theft / stolen device | A password has full account access. App-specific revocable tokens and optional MFA/OIDC are a future milestone |
| Cross-user calendar/contact access | SabreDAV ACL expected; **not verified by actual two-user integration tests** |
| Cross-user file access | Pre-authenticated per-user FS mount; needs symlink/path traversal and concurrent-user security tests |
| Unsafe file uploads | No antivirus scanner, quota, per-account limits, content inspection or file versioning. Apply reverse-proxy upload size and timeout limits |
| Storage loss | Database and file volume need consistent off-site encrypted backups and tested restoration |
| Dependency or supply-chain exploit | Pin a reviewed Composer lockfile, use a trusted build pipeline and security scanning; current build can resolve within version constraints |
| Service denial of service | Rate/size/time/resource limits, alerting and bounded concurrency required before live exposure |
| Sensitive audit logs | Avoid credential and DAV payload logging; define redacted request IDs and privacy-preserving audit logging before production |
| Public health probe | Limited to process liveness, no user/database/version information; restrict monitoring exposure at gateway |

## Required acceptance before any deployment or Stable label

1. Dependency installation and audit, full PHP 8.3 runtime test with SQLite and container startup, no default sample principals.
2. End-to-end DAV method and interoperability matrix; forbidden cross-user reads/writes across all three DAV surfaces.
3. Preauth `Authorization` header pass-through and malicious `X-Forwarded-Proto` negative tests; TLS/HSTS at verified proxy.
4. Backup, database integrity check, full restore and upgrade/rollback rehearsal on disposable data.
5. Review storage permissions, privilege separation, container read-only execution and resource limits with real target host configuration.
6. Apply throttling, bounded body size, metrics, redacted logs and incident response path. Conduct independent security review.
7. Document supported client matrix, version pinning, migration approach, operations runbook and release approval.

For security reports, avoid posting credentials or live data in public issues. Contact the GoreeCloud owner using an authorized private channel.
