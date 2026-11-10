#!/usr/bin/env python3
"""Isolated, dependency-free SQLite snapshot/recovery verification.

Uses the real checked-in GoreeCloud SQL schemas and SQLite VACUUM INTO, then
cross-checks the output with the PHP backup manifest verifier. This is *not*
a replacement for the PHP PDO, SabreDAV or operator CLI integration tests.
"""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile

REPO = Path(__file__).resolve().parent.parent
SQL = REPO / "resources" / "sql"


def checksum(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def check_integrity(path: Path, expected_table: str) -> None:
    connection = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
    try:
        assert connection.execute("PRAGMA integrity_check").fetchone() == ("ok",)
        assert connection.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name=?", (expected_table,)
        ).fetchone() == (expected_table,)
    finally:
        connection.close()


def snapshot(source: sqlite3.Connection, target: Path) -> None:
    target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    # Exercise the same SQLite primitive as GoreeCloud's PHP Backup::create.
    source.execute("VACUUM INTO ?", (str(target),))
    assert target.is_file()


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="goreecloud-dav-sqlite-recovery-") as temp:
        root = Path(temp)
        live = root / "live"
        backup = root / "snapshot"
        (live / "metadata" / "alice").mkdir(parents=True)
        (live / "files" / "alice" / "empty" / "nested").mkdir(parents=True)
        (live / "files" / "alice" / "notes.txt").write_text("non-secret isolated test fixture\n")

        registry = sqlite3.connect(live / "dav.sqlite")
        metadata = sqlite3.connect(live / "metadata" / "alice" / "dav.sqlite")
        try:
            registry.executescript((SQL / "002-accounts.sql").read_text())
            metadata.executescript((SQL / "001-initial.sql").read_text())
            # Use a non-authenticating placeholder, never a password or real hash.
            registry.execute(
                "INSERT INTO goreecloud_accounts(username,password_hash,created_at) VALUES (?,?,?)",
                ("alice", "fixture-no-login", 1),
            )
            metadata.execute("INSERT INTO principals(uri,displayname) VALUES (?,?)", ("principals/alice", "Alice"))
            metadata.execute("INSERT INTO calendars(id,components) VALUES (?,?)", (1, "VEVENT,VTODO"))
            metadata.execute(
                "INSERT INTO calendarinstances(calendarid,principaluri,uri) VALUES (?,?,?)",
                (1, "principals/alice", "default"),
            )
            ics = b"BEGIN:VCALENDAR\nEND:VCALENDAR\n"
            metadata.execute(
                "INSERT INTO calendarobjects(calendarid,calendardata,uri,lastmodified,etag,size) "
                "VALUES (?,?,?,?,?,?)", (1, ics, "test.ics", 1, '"etag"', len(ics)),
            )
            metadata.execute("INSERT INTO addressbooks(id,principaluri,uri) VALUES (?,?,?)", (1, "principals/alice", "default"))
            metadata.execute(
                "INSERT INTO cards(addressbookid,carddata,uri,lastmodified) VALUES (?,?,?,?)",
                (1, b"BEGIN:VCARD\nEND:VCARD\n", "test.vcf", 1),
            )
            registry.commit()
            metadata.commit()
            registry.execute("PRAGMA journal_mode = WAL")
            metadata.execute("PRAGMA journal_mode = WAL")
            registry.execute("UPDATE goreecloud_accounts SET created_at = 2 WHERE username = 'alice'")
            metadata.execute("UPDATE principals SET displayname = 'Alice Updated' WHERE uri = 'principals/alice'")
            registry.commit()
            metadata.commit()

            # Keep WAL-mode connections alive during SQLite-native snapshots.
            backup.mkdir(mode=0o700)
            snapshot(registry, backup / "dav.sqlite")
            snapshot(metadata, backup / "metadata" / "alice" / "dav.sqlite")
            shutil.copytree(live / "files" / "alice", backup / "files" / "alice")
            paths = [
                "dav.sqlite", "metadata/alice/dav.sqlite", "files/alice/notes.txt",
            ]
            manifest = {
                "format": "goreecloud-dav-backup-v2",
                "users": ["alice"],
                "directories": ["files/alice", "files/alice/empty", "files/alice/empty/nested"],
                "sha256": {name: checksum(backup / name) for name in paths},
            }
            (backup / "manifest.json").write_text(json.dumps(manifest))
            # Fixture has three files: registry, per-account DB and WebDAV note.
            verify_with_php_fixture(backup, 3)

            # Rehearse a restore into a separate path, never over live data.
            restored = root / "restored"
            shutil.copytree(backup, restored)
            verify_with_php_fixture(restored, 3)
            check_integrity(restored / "dav.sqlite", "goreecloud_accounts")
            check_integrity(restored / "metadata/alice/dav.sqlite", "calendarobjects")
            rc = sqlite3.connect(f"file:{restored / 'dav.sqlite'}?mode=ro", uri=True)
            mc = sqlite3.connect(f"file:{restored / 'metadata/alice/dav.sqlite'}?mode=ro", uri=True)
            try:
                assert rc.execute("SELECT created_at FROM goreecloud_accounts WHERE username='alice'").fetchone() == (2,)
                assert mc.execute("SELECT displayname FROM principals WHERE uri='principals/alice'").fetchone() == ("Alice Updated",)
                assert mc.execute("SELECT COUNT(*) FROM calendarobjects").fetchone() == (1,)
                assert mc.execute("SELECT COUNT(*) FROM cards").fetchone() == (1,)
            finally:
                rc.close()
                mc.close()
            assert (restored / "files/alice/empty/nested").is_dir()
            assert (restored / "files/alice/notes.txt").read_text() == "non-secret isolated test fixture\n"

            # Verify a linked ancestor is not accepted as a backup location.
            (root / "alias").symlink_to(root, target_is_directory=True)
            verify_with_php_fixture(root / "alias" / "snapshot", 3, should_pass=False)
            # A silent payload edit must break the manifest validation.
            (restored / "files/alice/notes.txt").write_text("changed payload")
            verify_with_php_fixture(restored, 3, should_pass=False)
        finally:
            registry.close()
            metadata.close()
    print("Pinned SQLite schemas, WAL-aware VACUUM snapshots, PHP manifest, staged restore, and tamper checks passed.")


def verify_with_php_fixture(folder: Path, expected_files: int, should_pass: bool = True) -> None:
    php_code = (
        "require $argv[1].'/src/Config.php';"
        "require $argv[1].'/src/Credentials.php';"
        "require $argv[1].'/src/Backup.php';"
        "echo json_encode(\\GoreeCloud\\DAV\\Backup::verify($argv[2]), JSON_THROW_ON_ERROR);"
    )
    result = subprocess.run(
        ["php", "-r", php_code, str(REPO), str(folder)],
        capture_output=True, text=True, check=False, timeout=20,
    )
    if should_pass:
        assert result.returncode == 0, result.stderr
        assert json.loads(result.stdout)["files"] == expected_files, result.stdout
    else:
        assert result.returncode != 0, "unsafe or corrupt snapshot was accepted"


if __name__ == "__main__":
    main()
