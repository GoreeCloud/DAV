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
    file_put_contents($base.'/dav.sqlite', 'synthetic-registry');
    file_put_contents($base.'/metadata/alice/dav.sqlite', 'synthetic-metadata');
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
    echo "Backup completeness and integrity tests passed.\n";
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
