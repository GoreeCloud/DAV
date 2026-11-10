# Planned features and verified gaps

All rows are plans or verification gates, not released capabilities.

| Priority | Workstream | Acceptance |
| --- | --- | --- |
| P0 | Runtime build and provisioning | Composer dependency installation, CI and container startup pass; no seeded example users; correct principal/calendar/address book creation |
| P0 | Isolation and DAV conformance | Two-user tests with PROPFIND, REPORT, PUT, DELETE, MOVE, MKCOL, LOCK; cross-account access denied |
| P0 | TLS, limits and rate limiting | Verified trusted-proxy deployment, strict HTTP denial, header handling, request size/rate controls and private development network |
| P0 | Disaster recovery | SQLite+files consistent backup/restore drill and documented rollback |
| P1 | Standard interoperability | Test and document Android DAVx5, Apple Calendar/Contacts, Thunderbird and Linux Evolution |
| P1 | Better identity | OAuth/OIDC bridge, app-specific scoped/revocable credentials, MFA and stable principal mapping |
| P1 | Robust operations | Structured redacted logs, metrics, health/readiness probes, version-locked dependencies, secure releases |
| P1 | Database operations | Explicit migration tool, PostgreSQL option for concurrency, retention and maintenance policy |
| P2 | File enhancements | Quotas, version history, trash, safe sharing, antivirus scanning, previews and object storage adapters |
| P2 | Calendar collaboration | Explicitly authorized free/busy, scheduling/invitations, sharing/delegation, resource calendars |
| P2 | Contact enhancements | Shared directories, address-book permission roles, contact import/export and deduplication tooling |
| P2 | Ecosystem integration | GoreeCloud UI contracts for Calendar, Contacts, Tasks and Files, admin observability integrations |

The recommended first live deployment is **private/VPN-only**; external exposure requires security gates, verified DNS and explicit release authorization.
