# Independent CalDAV/CardDAV client interoperability — CI gate

**Experimental, isolated test only.** `tests/VdirsyncerInteropTest.sh` starts a fresh local-only GoreeCloud DAV service with synthetic accounts and uses the **separate upstream vdirsyncer 0.21.0 client** to synchronize a calendar and an address book. Neither application uses production data or any remote hostname. The test explicitly disables HTTPS only for this disposable local PHP HTTP server; production TLS requirements are unchanged.

## Scope

The client exercises creation of one iCalendar event and one vCard 3.0 contact from vdir local storage, local edits propagated to the DAV server, server-originated edits downloaded back to vdir, and rejection of access by another account. It checks results in the isolated SQLite metadata databases and compares local bytes. It uses the normal SabreDAV protocol endpoints, not the server's in-process integration API. The test runs in its own GitHub Actions job with a disposable virtual environment, private temporary config/status storage and a fixed localhost port. All files, test credentials and local HTTP processes are cleaned up on exit.

The test depends on Python 3, curl, PHP CLI 8.3+, SQLite, Composer-installed PHP packages, and vdirsyncer 0.21.0. The CI job installs the **exact vdirsyncer release version** in a temporary virtual environment. Its transitive Python dependencies are not fully locked or hash-pinned yet; CI uses read-only repository permissions. This remains a supply-chain limitation, and the upstream package was **not** independently security-audited. Upstream project's BSD-3-Clause license and published Python dependency manifest were inspected before introducing this disposable tool. Do not include this tool in the shipping DAV container.

## Exclusions

A passing test is evidence of one independent command-line client, **not** DAVx5, Apple Calendar/Contacts, Thunderbird, Evolution or GoreeCloud native-client compatibility. This test does not validate vCard photos/groups, complex recurring events, time zones, free/busy, client app UX, multi-user sharing, scheduling or certificate-validation behavior on a real device. It does not authorize public ingress or production data use.

References: https://vdirsyncer.pimutils.org/en/stable/config.html and https://github.com/pimutils/vdirsyncer/tree/v0.21.0 .