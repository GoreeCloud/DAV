# GoreeCloud DAV — remaining work

The following is **not implemented or independently verified**, or needs broader validation beyond passing GitHub Actions:

1. Broader CalDAV/CardDAV resource CRUD, recurrence/group support, synchronization-token and interoperability testing across representative native clients beyond basic iCalendar/vCard PUT/GET.
2. DAV protocol conformance/security fuzzing, large payload testing, rate limiting, input quotas, storage quotas and attachment limits.
3. TLS reverse-proxy and authenticated multi-client integration in private staging; formal ACL/permission review including REPORT/href edge cases.
4. Deterministic dependency lockfile, Docker build/run verification, non-root daemon/Apache least-privilege review, migration strategy, and reproducible releases.
5. Transaction-consistent backups of global and per-account SQLite databases plus files; isolated restore drill.
6. Future delegated access, sharing, SSO or application-password issuance only after security review and explicit product requirements.
7. DNS/service-hostname assignment and production deployment only after authorization and acceptance gates.

See [Roadmap](ROADMAP.md) and [Security](SECURITY.md).
