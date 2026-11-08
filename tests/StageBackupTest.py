#!/usr/bin/env python3
"""Security regression for offline DAV tar staging. Synthetic data only."""
import io
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile

REPO = Path(__file__).resolve().parent.parent
STAGER = REPO / "bin" / "dav-backup-stage.py"

def archive(entries):
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w") as out:
        for name, typ, data in entries:
            member = tarfile.TarInfo(name)
            if typ == "dir":
                member.type = tarfile.DIRTYPE
                out.addfile(member)
            elif typ == "symlink":
                member.type = tarfile.SYMTYPE
                member.linkname = "../../escape"
                out.addfile(member)
            elif typ == "hardlink":
                member.type = tarfile.LNKTYPE
                member.linkname = "../../escape"
                out.addfile(member)
            else:
                member.size = len(data)
                out.addfile(member, io.BytesIO(data))
    return buffer.getvalue()

def run(source, destination, *args):
    return subprocess.run(
        [sys.executable, str(STAGER), "--destination", str(destination), *args],
        input=source, capture_output=True, check=False, timeout=20,
    )

with tempfile.TemporaryDirectory(prefix="goreecloud-dav-stage-test-") as folder:
    root = Path(folder)
    contents = [
        ("snapshot/", "dir", b""),
        ("snapshot/manifest.json", "file", b'{"example":"synthetic"}'),
        ("snapshot/dav.sqlite", "file", b"synthetic-sqlite-placeholder"),
        ("snapshot/files/alice/", "dir", b""),
        ("snapshot/files/alice/note.txt", "file", b"private-test-fixture"),
        ("snapshot/files/alice/empty/", "dir", b""),
    ]
    valid = archive(contents)
    good = root / "safe"
    result = run(valid, good)
    assert result.returncode == 0, result.stderr.decode()
    assert (good / "snapshot/files/alice/note.txt").read_bytes() == b"private-test-fixture"
    assert (good / "snapshot/files/alice/empty").is_dir()
    assert not (good / "snapshot").is_symlink()
    assert (good.stat().st_mode & 0o077) == 0

    negative = {
        "traversal": archive([("snapshot/../../escape", "file", b"outside")]),
        "absolute": archive([("/tmp/goreecloud-dav-ci-outside", "file", b"outside")]),
        "symlink": archive([("snapshot/link", "symlink", b"")]),
        "hardlink": archive([("snapshot/link", "hardlink", b"")]),
        "duplicate": archive([
            ("snapshot/dav.sqlite", "file", b"one"),
            ("snapshot/dav.sqlite", "file", b"two"),
        ]),
        "missing-manifest": archive([("snapshot/dav.sqlite", "file", b"one")]),
        "unknown-root": archive([("elsewhere/dav.sqlite", "file", b"one")]),
    }
    for label, payload in negative.items():
        dest = root / ("rejected-" + label)
        result = run(payload, dest)
        assert result.returncode != 0, label
        assert not dest.exists(), f"Partial plaintext survived rejected archive: {label}"
    assert not (root / "escape").exists()

    for label, args in {
        "payload-cap": ("--max-bytes", "4"),
        "member-cap": ("--max-members", "3"),
    }.items():
        dest = root / ("rejected-" + label)
        result = run(valid, dest, *args)
        assert result.returncode != 0, label
        assert not dest.exists(), label

    preexisting = root / "preexisting"
    preexisting.mkdir()
    (preexisting / "sentinel").write_bytes(b"unchanged")
    result = run(valid, preexisting)
    assert result.returncode != 0
    assert (preexisting / "sentinel").read_bytes() == b"unchanged"

print("Safe DAV tar staging: normal files, empty dirs, private modes, traversal/link/duplicate/bomb rejection and cleanup passed.")
