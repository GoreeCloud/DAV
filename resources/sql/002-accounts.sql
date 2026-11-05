-- GoreeCloud DAV global account credential registry.
-- CalDAV/CardDAV data is stored in separate per-account databases.
CREATE TABLE goreecloud_accounts (
 username TEXT PRIMARY KEY NOT NULL,
 password_hash TEXT NOT NULL,
 enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0,1)),
 created_at INTEGER NOT NULL
);
