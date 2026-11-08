#!/usr/bin/env python3
"""Stage a decrypted DAV snapshot tar safely without modifying live storage."""
from __future__ import annotations
import argparse
import os
from pathlib import Path
import shutil
import stat
import sys
import tarfile

class UnsafeArchive(Exception):
    pass

def create_destination(value: str) -> Path:
    if not value.startswith("/") or value == "/" or "\0" in value:
        raise UnsafeArchive("Expected a new absolute destination")
    if any(part in ("", ".", "..") for part in value[1:].split("/")):
        raise UnsafeArchive("Unsafe destination path")
    dest = Path(value)
    if dest.exists() or dest.is_symlink():
        raise UnsafeArchive("Destination already exists")
    ancestor = dest.parent
    while True:
        if ancestor.is_symlink() or (ancestor.exists() and not ancestor.is_dir()):
            raise UnsafeArchive("Linked or unsafe destination ancestor")
        if ancestor == ancestor.parent:
            break
        ancestor = ancestor.parent
    if not dest.parent.is_dir() or stat.S_IMODE(dest.parent.stat().st_mode) & 0o077:
        raise UnsafeArchive("Destination parent must exist and have private permissions")
    dest.mkdir(mode=0o700)
    return dest

def parts_for(member: tarfile.TarInfo) -> tuple[str, ...]:
    name = member.name[:-1] if member.isdir() and member.name.endswith("/") else member.name
    if not name or name.startswith("/") or "\\" in name or "\0" in name:
        raise UnsafeArchive("Unsafe archive member")
    parts = tuple(name.split("/"))
    if parts[0] != "snapshot" or any(p in ("", ".", "..") for p in parts):
        raise UnsafeArchive("Archive path escapes snapshot")
    if not (member.isdir() or member.isfile()):
        raise UnsafeArchive("Links and special archive entries are forbidden")
    if member.isfile() and len(parts) < 2:
        raise UnsafeArchive("Invalid snapshot file")
    return parts

def make_directories(root: Path, parts: tuple[str, ...]) -> None:
    current = root
    for part in parts:
        current = current / part
        if current.is_symlink():
            raise UnsafeArchive("Linked staging directory")
        if current.exists():
            if not current.is_dir():
                raise UnsafeArchive("Non-directory in archive path")
        else:
            current.mkdir(mode=0o700)

def stage(stream, root: Path, max_bytes: int, max_members: int) -> tuple[int, int]:
    count = 0
    total = 0
    seen: set[tuple[str, ...]] = set()
    files: set[tuple[str, ...]] = set()
    with tarfile.open(fileobj=stream, mode="r|*") as archive:
        for item in archive:
            count += 1
            if count > max_members:
                raise UnsafeArchive("Too many archive entries")
            parts = parts_for(item)
            if parts in seen:
                raise UnsafeArchive("Duplicate archive member")
            seen.add(parts)
            if item.isdir():
                make_directories(root, parts)
                continue
            if item.size < 0 or item.size > max_bytes - total:
                raise UnsafeArchive("Archive size limit exceeded")
            make_directories(root, parts[:-1])
            flags = os.O_CREAT | os.O_EXCL | os.O_WRONLY
            if hasattr(os, "O_NOFOLLOW"):
                flags |= os.O_NOFOLLOW
            source = archive.extractfile(item)
            if source is None:
                raise UnsafeArchive("Missing file bytes")
            with source, os.fdopen(os.open(root.joinpath(*parts), flags, 0o600), "wb") as output:
                left = item.size
                while left:
                    block = source.read(min(1024 * 1024, left))
                    if not block:
                        raise UnsafeArchive("Truncated archive")
                    output.write(block)
                    left -= len(block)
                output.flush()
                os.fsync(output.fileno())
            files.add(parts)
            total += item.size
    if ("snapshot", "manifest.json") not in files or ("snapshot", "dav.sqlite") not in files:
        raise UnsafeArchive("Required snapshot files missing")
    return count, total

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--destination", required=True)
    parser.add_argument("--max-bytes", type=int, default=100 * 1024**3)
    parser.add_argument("--max-members", type=int, default=200000)
    args = parser.parse_args()
    if args.max_bytes < 1 or args.max_members < 2:
        parser.error("Limits must be positive")
    os.umask(0o077)
    created = None
    try:
        created = create_destination(args.destination)
        members, size = stage(sys.stdin.buffer, created, args.max_bytes, args.max_members)
        print(f"Unverified snapshot staged: {members} members, {size} bytes.")
        print("Next: run PHP backup:verify against the snapshot directory.")
        return 0
    except (UnsafeArchive, OSError, tarfile.TarError, EOFError, ValueError):
        if created is not None:
            shutil.rmtree(created)
        print("Unsafe or corrupt backup staging rejected; no partial directory retained.", file=sys.stderr)
        return 1

if __name__ == "__main__":
    raise SystemExit(main())
