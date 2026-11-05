-- GoreeCloud DAV SQLite schema, adapted from sabre/dav 4.6.0 SQL examples.
-- Original project: https://github.com/sabre-io/dav/tree/4.6.0/examples/sql
-- License: BSD-3-Clause; see docs/THIRD-PARTY-NOTICES.md.
-- No example accounts, secrets, sample data, or default permissions are seeded.

CREATE TABLE principals (
 id INTEGER PRIMARY KEY ASC NOT NULL,
 uri TEXT NOT NULL UNIQUE,
 email TEXT,
 displayname TEXT
);
CREATE TABLE groupmembers (
 id INTEGER PRIMARY KEY ASC NOT NULL,
 principal_id INTEGER NOT NULL,
 member_id INTEGER NOT NULL,
 UNIQUE(principal_id, member_id)
);
CREATE TABLE calendars (
 id INTEGER PRIMARY KEY ASC NOT NULL,
 synctoken INTEGER DEFAULT 1 NOT NULL,
 components TEXT NOT NULL
);
CREATE TABLE calendarinstances (
 id INTEGER PRIMARY KEY ASC NOT NULL,
 calendarid INTEGER NOT NULL,
 principaluri TEXT NULL,
 access INTEGER NOT NULL DEFAULT 1,
 displayname TEXT,
 uri TEXT NOT NULL,
 description TEXT,
 calendarorder INTEGER,
 calendarcolor TEXT,
 timezone TEXT,
 transparent BOOL,
 share_href TEXT,
 share_displayname TEXT,
 share_invitestatus INTEGER DEFAULT 2,
 UNIQUE(principaluri, uri),
 UNIQUE(calendarid, principaluri),
 UNIQUE(calendarid, share_href)
);
CREATE TABLE calendarobjects (
 id INTEGER PRIMARY KEY ASC NOT NULL,
 calendardata BLOB NOT NULL,
 uri TEXT NOT NULL,
 calendarid INTEGER NOT NULL,
 lastmodified INTEGER NOT NULL,
 etag TEXT NOT NULL,
 size INTEGER NOT NULL,
 componenttype TEXT,
 firstoccurence INTEGER,
 lastoccurence INTEGER,
 uid TEXT,
 UNIQUE(calendarid, uri)
);
CREATE INDEX calendarid_time ON calendarobjects (calendarid, firstoccurence);
CREATE TABLE calendarchanges (
 id INTEGER PRIMARY KEY ASC NOT NULL,
 uri TEXT,
 synctoken INTEGER NOT NULL,
 calendarid INTEGER NOT NULL,
 operation INTEGER NOT NULL
);
CREATE INDEX calendarid_synctoken ON calendarchanges (calendarid, synctoken);
CREATE TABLE calendarsubscriptions (
 id INTEGER PRIMARY KEY ASC NOT NULL,
 uri TEXT NOT NULL,
 principaluri TEXT NOT NULL,
 source TEXT NOT NULL,
 displayname TEXT,
 refreshrate TEXT,
 calendarorder INTEGER,
 calendarcolor TEXT,
 striptodos BOOL,
 stripalarms BOOL,
 stripattachments BOOL,
 lastmodified INTEGER,
 UNIQUE(principaluri, uri)
);
CREATE TABLE schedulingobjects (
 id INTEGER PRIMARY KEY ASC NOT NULL,
 principaluri TEXT NOT NULL,
 calendardata BLOB,
 uri TEXT NOT NULL,
 lastmodified INTEGER,
 etag TEXT NOT NULL,
 size INTEGER NOT NULL,
 UNIQUE(principaluri, uri)
);
CREATE TABLE addressbooks (
 id INTEGER PRIMARY KEY ASC NOT NULL,
 principaluri TEXT NOT NULL,
 displayname TEXT,
 uri TEXT NOT NULL,
 description TEXT,
 synctoken INTEGER DEFAULT 1 NOT NULL,
 UNIQUE(principaluri, uri)
);
CREATE TABLE cards (
 id INTEGER PRIMARY KEY ASC NOT NULL,
 addressbookid INTEGER NOT NULL,
 carddata BLOB,
 uri TEXT NOT NULL,
 lastmodified INTEGER,
 etag TEXT,
 size INTEGER,
 UNIQUE(addressbookid, uri)
);
CREATE TABLE addressbookchanges (
 id INTEGER PRIMARY KEY ASC NOT NULL,
 uri TEXT,
 synctoken INTEGER NOT NULL,
 addressbookid INTEGER NOT NULL,
 operation INTEGER NOT NULL
);
CREATE INDEX addressbookid_synctoken ON addressbookchanges (addressbookid, synctoken);
CREATE TABLE locks (
 id INTEGER PRIMARY KEY ASC NOT NULL,
 owner TEXT,
 timeout INTEGER,
 created INTEGER,
 token TEXT,
 scope INTEGER,
 depth INTEGER,
 uri TEXT
);
CREATE INDEX idx_uri ON locks (uri);
CREATE TABLE goreecloud_accounts (
 username TEXT PRIMARY KEY NOT NULL,
 password_hash TEXT NOT NULL,
 enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0,1)),
 created_at INTEGER NOT NULL
);
