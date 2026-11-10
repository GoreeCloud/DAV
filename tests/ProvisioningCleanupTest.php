<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Provisioner.php';

use GoreeCloud\DAV\Provisioner;

$base = sys_get_temp_dir().'/dav-provisioning-cleanup-'.bin2hex(random_bytes(12));
mkdir($base.'/files/alice', 0700, true);
mkdir($base.'/metadata/alice', 0700, true);
mkdir($base.'/metadata/bob', 0700, true);
try {
    file_put_contents($base.'/metadata/alice/dav.sqlite', 'test-database');
    file_put_contents($base.'/metadata/alice/dav.sqlite-wal', 'test-wal');
    file_put_contents($base.'/metadata/alice/dav.sqlite-shm', 'test-shm');
    file_put_contents($base.'/metadata/bob/dav.sqlite', 'unrelated-account');
    // Exercise the actual rollback cleanup method without requiring PDO SQLite.
    $cleanup = new ReflectionMethod(Provisioner::class, 'cleanupFailedAdd');
    $cleanup->invoke(null, $base.'/files/alice', $base.'/metadata/alice');
    if (is_dir($base.'/files/alice') || is_dir($base.'/metadata/alice')) {
        throw new RuntimeException('Failed provisioning left an orphaned account directory');
    }
    if (file_get_contents($base.'/metadata/bob/dav.sqlite') !== 'unrelated-account') {
        throw new RuntimeException('Provisioning rollback modified a different account');
    }
    echo "Provisioning rollback cleanup tests passed.\n";
} finally {
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
