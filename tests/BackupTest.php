<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Config.php';
require dirname(__DIR__).'/src/Credentials.php';
require dirname(__DIR__).'/src/Backup.php';

use GoreeCloud\DAV\Backup;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectFailure(callable $attempt, string $text): void
{
    try {
        $attempt();
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), $text), 'Expected rejection: '.$text.'; got '.$error->getMessage());
        return;
    }
    throw new LogicException('Backup verifier unexpectedly accepted: '.$text);
}

$base = sys_get_temp_dir().'/goreecloud-dav-backup-test-'.bin2hex(random_bytes(12));
mkdir($base.'/files/alice', 0700, true);
mkdir($base.'/metadata/alice', 0700, true);
try {
    mkdir($base.'/files/alice/empty/child', 0700, true);
    // Real SQLite schemas are required so the backup verifier can reject a
    // checksummed-but-corrupt database, not just incomplete file inventories.
    $registry = new PDO('sqlite:'.$base.'/dav.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $metadata = new PDO('sqlite:'.$base.'/metadata/alice/dav.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $registry->exec(file_get_contents(dirname(__DIR__).'/resources/sql/002-accounts.sql'));
    $metadata->exec(file_get_contents(dirname(__DIR__).'/resources/sql/001-initial.sql'));
    $registry->exec("INSERT INTO goreecloud_accounts(username,password_hash,created_at) VALUES ('alice','test-placeholder-hash',1)");
    $registry = null;
    $metadata = null;
    file_put_contents($base.'/files/alice/contact.txt', 'test-content');
    $entries = [
        'dav.sqlite' => hash_file('sha256', $base.'/dav.sqlite'),
        'metadata/alice/dav.sqlite' => hash_file('sha256', $base.'/metadata/alice/dav.sqlite'),
        'files/alice/contact.txt' => hash_file('sha256', $base.'/files/alice/contact.txt'),
    ];
    $manifest = [
        'format' => 'goreecloud-dav-backup-v2',
        'users' => ['alice'],
        'directories' => ['files/alice', 'files/alice/empty', 'files/alice/empty/child'],
        'sha256' => $entries,
    ];
    $save = static function (array $value) use ($base): void {
        file_put_contents($base.'/manifest.json', json_encode($value, JSON_THROW_ON_ERROR));
    };
    $save($manifest);
    $result = Backup::verify($base);
    check($result['files'] === 3 && $result['bytes'] > 0, 'valid backup is complete');

    file_put_contents($base.'/files/alice/contact.txt', 'modified');
    expectFailure(static fn() => Backup::verify($base), 'checksum mismatch');
    file_put_contents($base.'/files/alice/contact.txt', 'test-content');

    file_put_contents($base.'/files/alice/unlisted.txt', 'untracked');
    expectFailure(static fn() => Backup::verify($base), 'Unlisted resource');
    unlink($base.'/files/alice/unlisted.txt');

    // Preserve empty directory structure, not just checksummed file payloads.
    rmdir($base.'/files/alice/empty/child');
    expectFailure(static fn() => Backup::verify($base), 'Missing, linked or unlisted account directory');
    mkdir($base.'/files/alice/empty/child', 0700);
    mkdir($base.'/files/alice/unlisted-empty', 0700);
    expectFailure(static fn() => Backup::verify($base), 'Unlisted directory');
    rmdir($base.'/files/alice/unlisted-empty');

    $missing = $manifest;
    unset($missing['sha256']['metadata/alice/dav.sqlite']);
    $save($missing);
    expectFailure(static fn() => Backup::verify($base), 'missing account storage');

    $incomplete = $manifest;
    $incomplete['directories'] = ['files/alice'];
    $save($incomplete);
    expectFailure(static fn() => Backup::verify($base), 'Unlisted directory');

    $duplicate = $manifest;
    $duplicate['users'] = ['alice', 'alice'];
    $save($duplicate);
    expectFailure(static fn() => Backup::verify($base), 'duplicate backup account');

    $traversal = $manifest;
    $traversal['sha256']['../outside'] = hash('sha256', 'content');
    $save($traversal);
    expectFailure(static fn() => Backup::verify($base), 'Invalid backup manifest entry');

    $unknown = $manifest;
    $unknown['sha256']['files/bob/secret.txt'] = hash('sha256', 'content');
    $save($unknown);
    expectFailure(static fn() => Backup::verify($base), 'unlisted account');

    $save($manifest);
    unlink($base.'/files/alice/contact.txt');
    symlink($base.'/dav.sqlite', $base.'/files/alice/contact.txt');
    expectFailure(static fn() => Backup::verify($base), 'linked');
    unlink($base.'/files/alice/contact.txt');
    file_put_contents($base.'/files/alice/contact.txt', 'test-content');

    symlink($base.'/dav.sqlite', $base.'/files/alice/unlisted.txt');
    expectFailure(static fn() => Backup::verify($base), 'Symlink in backup');
    unlink($base.'/files/alice/unlisted.txt');

    check(Backup::verify($base)['files'] === 3, 'snapshot accepted again after repairing fixture');

    // A maliciously revised manifest can have perfectly matching SHA-256
    // entries while the database it describes is unusable. Reject that too.
    $originalRegistry = file_get_contents($base.'/dav.sqlite');
    $originalMetadata = file_get_contents($base.'/metadata/alice/dav.sqlite');
    file_put_contents($base.'/metadata/alice/dav.sqlite', 'not-an-SQLite-database');
    $corrupt = $manifest;
    $corrupt['sha256']['metadata/alice/dav.sqlite'] =
        hash_file('sha256', $base.'/metadata/alice/dav.sqlite');
    $save($corrupt);
    expectFailure(static fn() => Backup::verify($base), 'SQLite backup validation failed');
    file_put_contents($base.'/metadata/alice/dav.sqlite', $originalMetadata);

    // Registry and manifest must describe the exact same collection of users.
    $pdo = new PDO('sqlite:'.$base.'/dav.sqlite');
    $pdo->exec("DELETE FROM goreecloud_accounts WHERE username = 'alice'");
    $pdo = null;
    $mismatch = $manifest;
    $mismatch['sha256']['dav.sqlite'] = hash_file('sha256', $base.'/dav.sqlite');
    $save($mismatch);
    expectFailure(static fn() => Backup::verify($base), 'SQLite backup validation failed');
    file_put_contents($base.'/dav.sqlite', $originalRegistry);

    // A syntactically valid SQLite database with no CalDAV/CardDAV tables
    // must not pass the snapshot verifier simply because its hash matches.
    $emptyMetadata = $base.'/empty-metadata.sqlite';
    $pdo = new PDO('sqlite:'.$emptyMetadata);
    $pdo->exec('CREATE TABLE unrelated (id INTEGER PRIMARY KEY)');
    $pdo = null;
    copy($emptyMetadata, $base.'/metadata/alice/dav.sqlite');
    unlink($emptyMetadata);
    $wrongSchema = $manifest;
    $wrongSchema['sha256']['metadata/alice/dav.sqlite'] =
        hash_file('sha256', $base.'/metadata/alice/dav.sqlite');
    $save($wrongSchema);
    expectFailure(static fn() => Backup::verify($base), 'SQLite backup validation failed');
    file_put_contents($base.'/metadata/alice/dav.sqlite', $originalMetadata);

    // Foreign-key consistency is checked independently of SQLite's page check.
    $pdo = new PDO('sqlite:'.$base.'/metadata/alice/dav.sqlite');
    $pdo->exec('CREATE TABLE ci_parent (id INTEGER PRIMARY KEY)');
    $pdo->exec('CREATE TABLE ci_child (parent_id INTEGER REFERENCES ci_parent(id))');
    $pdo->exec('PRAGMA foreign_keys=OFF');
    $pdo->exec('INSERT INTO ci_child(parent_id) VALUES (999)');
    $pdo = null;
    $badForeignKey = $manifest;
    $badForeignKey['sha256']['metadata/alice/dav.sqlite'] =
        hash_file('sha256', $base.'/metadata/alice/dav.sqlite');
    $save($badForeignKey);
    expectFailure(static fn() => Backup::verify($base), 'SQLite backup validation failed');
    file_put_contents($base.'/metadata/alice/dav.sqlite', $originalMetadata);

    $save($manifest);
    check(Backup::verify($base)['files'] === 3, 'repaired SQLite and manifest accepted');
    echo "Backup completeness, SQLite integrity, schema, account inventory and foreign-key tests passed.\n";
} finally {
    // The test owns the unique temporary tree and never follows symlinks.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($base);
}
