<?php

declare(strict_types=1);

// This test intentionally runs without Composer/PDO SQLite. In isolated local
// environments, alias the exception solely for testing the pure guard class.
if (!class_exists(\Sabre\DAV\Exception\Forbidden::class)) {
    class_alias(\RuntimeException::class, \Sabre\DAV\Exception\Forbidden::class);
}
require dirname(__DIR__).'/src/PrivateFilesystemGuard.php';

use GoreeCloud\DAV\PrivateFilesystemGuard;

function reject(callable $attempt, string $label): void
{
    try {
        $attempt();
    } catch (\Sabre\DAV\Exception\Forbidden $e) {
        return;
    }
    throw new \RuntimeException('Guard accepted forbidden resource: '.$label);
}

$base = sys_get_temp_dir().'/dav-filesystem-guard-'.bin2hex(random_bytes(12));
mkdir($base.'/alice/folder', 0700, true);
mkdir($base.'/bob', 0700);
file_put_contents($base.'/alice/folder/note.txt', 'private-alice');
file_put_contents($base.'/bob/secret.txt', 'private-bob');
try {
    $guard = new PrivateFilesystemGuard($base.'/alice');
    $guard->assertSafePath($guard->root());
    $guard->assertSafePath($guard->childPath($guard->root(), 'folder'));
    $guard->assertSafePath($guard->childPath($guard->root().'/folder', 'note.txt'));
    reject(static fn() => $guard->assertSafePath($base.'/bob/secret.txt'), 'other account');
    foreach (['', '.', '..', '../bob', '/etc', 'sub/file', "bad\0name", 'back\\slash'] as $name) {
        reject(static fn() => $guard->childPath($guard->root(), $name), 'bad name');
    }
    reject(static fn() => $guard->assertSafePath($base.'/alice/folder/../folder/note.txt'), 'parent traversal');
    reject(static fn() => $guard->assertSafePath($base.'/alice/folder/note.txt/child'), 'file as ancestor');

    symlink($base.'/bob/secret.txt', $base.'/alice/leak.txt');
    reject(static fn() => $guard->childPath($guard->root(), 'leak.txt'), 'file symlink');
    symlink($base.'/bob', $base.'/alice/redirect');
    reject(static fn() => $guard->assertSafePath($base.'/alice/redirect/secret.txt'), 'directory symlink');
    reject(static fn() => $guard->childPath($guard->root().'/redirect', 'secret.txt'), 'symlink parent');
    unlink($base.'/alice/leak.txt');
    unlink($base.'/alice/redirect');

    // New files are valid, but cannot resolve through an existing foreign path.
    $guard->assertSafePath($guard->childPath($guard->root(), 'new.txt'));
    reject(static fn() => new PrivateFilesystemGuard($base.'/alice/folder/note.txt'), 'non-directory root');
    symlink($base.'/alice', $base.'/linked-alice');
    reject(static fn() => new PrivateFilesystemGuard($base.'/linked-alice'), 'linked root');
    // A linked ancestor must also be rejected even when the final alice
    // directory is real; realpath() alone would hide the linked parent.
    symlink($base, $base.'/alias-container');
    reject(static fn() => new PrivateFilesystemGuard($base.'/alias-container/alice'), 'linked ancestor root');
    echo "Private WebDAV filesystem boundary tests passed.\n";
} finally {
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
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
