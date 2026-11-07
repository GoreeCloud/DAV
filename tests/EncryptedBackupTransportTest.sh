#!/usr/bin/env bash
# CI-only disposable encrypted snapshot test; never use a real DAV data volume.
set -euo pipefail
umask 077
PHP_BINARY=php
for cmd in gpg tar python3; do
  command -v "$cmd" >/dev/null || { echo "Required test tool missing: $cmd" >&2; exit 1; }
done
work="$(mktemp -d -t goreecloud-dav-crypto-XXXXXXXX)"
trap 'rm -rf -- "$work"' EXIT
export DAV_DATA_DIR="$work/live" DAV_BASE_URI=/ DAV_REQUIRE_HTTPS=1
unset DAV_ACCOUNT_FILE_QUOTA_BYTES || true
"$PHP_BINARY" bin/dav init >/dev/null
printf 'disposable-crypto-test-password-12345\n' | "$PHP_BINARY" bin/dav user:add alice --password-stdin >/dev/null
mkdir -m 0700 -- "$DAV_DATA_DIR/files/alice/empty-folder"
printf 'synthetic encrypted-backup DAV file\n' > "$DAV_DATA_DIR/files/alice/protected.txt"
"$PHP_BINARY" bin/dav backup:create "$work/snapshot" --json > "$work/created.json"
"$PHP_BINARY" bin/dav backup:verify "$work/snapshot" --json > "$work/verified.json"
python3 - "$work/verified.json" <<'PY'
import json,sys
with open(sys.argv[1],encoding='utf-8') as f:
    result=json.load(f)
assert result['files']>=3, result
PY
# Private, ephemeral test key; a production operator must manage keys separately.
mkdir -m 0700 -- "$work/gnupg"
head -c 48 /dev/urandom | base64 | tr -d '\n' > "$work/passphrase"
chmod 0600 "$work/passphrase"
tar -C "$work" -cf "$work/backup.tar" snapshot
gpg --homedir "$work/gnupg" --batch --yes --quiet --no-tty \
  --pinentry-mode loopback --passphrase-file "$work/passphrase" \
  --symmetric --cipher-algo AES256 --s2k-digest-algo SHA512 \
  --output "$work/backup.tar.gpg" "$work/backup.tar"
[[ -s "$work/backup.tar.gpg" ]] || { echo 'Encrypted archive missing' >&2; exit 1; }
rm -- "$work/backup.tar"
mv -- "$work/snapshot" "$work/source-quarantined"
mkdir -m 0700 -- "$work/recovered"
gpg --homedir "$work/gnupg" --batch --yes --quiet --no-tty \
  --pinentry-mode loopback --passphrase-file "$work/passphrase" \
  --decrypt --output "$work/recovered.tar" "$work/backup.tar.gpg"
tar -C "$work/recovered" -xf "$work/recovered.tar"
"$PHP_BINARY" bin/dav backup:verify "$work/recovered/snapshot" --json > "$work/reverified.json"
cmp -s "$work/source-quarantined/manifest.json" "$work/recovered/snapshot/manifest.json" || {
  echo 'Manifest differs after encryption transport' >&2; exit 1;
}
cmp -s "$work/source-quarantined/files/alice/protected.txt" "$work/recovered/snapshot/files/alice/protected.txt" || {
  echo 'File bytes differ after encryption transport' >&2; exit 1;
}
[[ -d "$work/recovered/snapshot/files/alice/empty-folder" ]] || { echo 'Empty folder lost' >&2; exit 1; }
python3 - "$work/recovered/snapshot" <<'PY'
import pathlib, sqlite3, sys
root=pathlib.Path(sys.argv[1])
with sqlite3.connect(f'file:{root / "dav.sqlite"}?mode=ro',uri=True) as db:
    assert db.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
    assert db.execute('SELECT username FROM goreecloud_accounts').fetchall()==[('alice',)]
with sqlite3.connect(f'file:{root / "metadata/alice/dav.sqlite"}?mode=ro',uri=True) as db:
    assert db.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
PY
# Wrong-key and ciphertext-integrity checks must fail closed.
printf 'deliberately-wrong-disposable-key\n' > "$work/wrong-key"
chmod 0600 "$work/wrong-key"
if gpg --homedir "$work/gnupg" --batch --yes --quiet --no-tty \
  --pinentry-mode loopback --passphrase-file "$work/wrong-key" \
  --decrypt --output "$work/wrong.tar" "$work/backup.tar.gpg" >/dev/null 2>&1; then
  echo 'Wrong key decrypted the archive' >&2; exit 1
fi
cp -- "$work/backup.tar.gpg" "$work/tampered.gpg"
python3 - "$work/tampered.gpg" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
b=bytearray(p.read_bytes())
assert len(b)>100
b[-12]^=0x80
p.write_bytes(b)
PY
if gpg --homedir "$work/gnupg" --batch --yes --quiet --no-tty \
  --pinentry-mode loopback --passphrase-file "$work/passphrase" \
  --decrypt --output "$work/tampered.tar" "$work/tampered.gpg" >/dev/null 2>&1; then
  echo 'Tampered archive was accepted' >&2; exit 1
fi
echo 'Disposable encrypted DAV backup transport: restored SQLite/data, rejected wrong key and tampering.'
