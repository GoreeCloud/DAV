# Bounded CalDAV/CardDAV sync safety

SabreDAV 4.6.0 CardDAV PDO's getChangesForAddressBook() limits change rows but returned the address book's latest sync token even when rows remained unsent. A client paging from that token could silently omit contacts. GoreeCloud's PaginatedCardDavBackend returns the token after the final included row for a truncated page and sets result_truncated. SabreDAV then emits a 507 collection marker inside HTTP 207 Multi-Status, instructing the client to continue. The CalDAV backend already pages correctly and is wrapped to reject future or expired tokens as well.

The disposable tests/BoundedSyncTest.sh creates five synthetic events and five contacts, requests limit=2, requires every change to be received over multiple pages without token jumps, and verifies the other account remains unchanged. It also rejects invalid future tokens instead of silently advancing past updates. Production data, public endpoints and real clients are never accessed.

Limitations: this is a CI-only single-node, low-volume correctness test. Large collection load, long offline periods, client-specific pagination behavior, simultaneous writes during paging, changes retained across migrations and independent security review remain release gates. Do not use a passing test as evidence of full production synchronization readiness.
