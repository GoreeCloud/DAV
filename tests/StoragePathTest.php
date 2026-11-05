<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Config.php';
require dirname(__DIR__).'/src/Database.php';

use GoreeCloud\DAV\Database;

function storageReject(callable $call, string $reason): void
{
    try {
        $call();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Untrusted database path accepted: '.$reason);
}

$base = sys_get_temp_dir().'/dav-database-path-'.bin2hex(random_bytes(12));
mkdir($base.'/data/metadata/alice', 0700, true);
mkdir($base.'/data/metadata/bob', 0700, true);
try {
    putenv('DAV_DATA_DIR='.$base.'/data');
    Database::assertPrivateStoragePath($base.'/data/dav.sqlite');
    Database::assertPrivateStoragePath($base.'/data/metadata/alice/dav.sqlite');
    storageReject(static fn() => Database::assertPrivateStoragePath($base.'/not-data/dav.sqlite'), 'outside root');
    storageReject(static fn() => Database::assertPrivateStoragePath($base.'/data/metadata/../dav.sqlite'), 'relative traversal');
    symlink($base.'/data/metadata/bob', $base.'/data/metadata/linked');
    storageReject(static fn() => Database::assertPrivateStoragePath($base.'/data/metadata/linked/dav.sqlite'), 'linked metadata ancestor');
    symlink($base.'/data/metadata/bob/dav.sqlite', $base.'/data/metadata/alice/dav.sqlite');
    storageReject(static fn() => Database::assertPrivateStoragePath($base.'/data/metadata/alice/dav.sqlite'), 'linked SQLite file');
    echo "Private SQLite storage path tests passed.\n";
} finally {
    putenv('DAV_DATA_DIR');
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
