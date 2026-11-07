<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Config.php';
require dirname(__DIR__).'/src/Credentials.php';
require dirname(__DIR__).'/src/Backup.php';

use GoreeCloud\DAV\Backup;

function expectInvalidDestination(ReflectionMethod $validate, string $destination, string $source, string $reason): void
{
    try {
        $validate->invoke(null, $destination, $source);
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Unsafe backup destination accepted: '.$reason);
}

$base = sys_get_temp_dir().'/goreecloud-dav-backup-destination-'.bin2hex(random_bytes(12));
$source = $base.'/data';
$realParent = $base.'/valid-backups';
mkdir($source, 0700, true);
mkdir($realParent, 0700);
try {
    $validate = new ReflectionMethod(Backup::class, 'validateDestination');
    $validate->invoke(null, $realParent.'/snapshot', $source);

    expectInvalidDestination($validate, 'relative/snapshot', $source, 'relative destination');
    expectInvalidDestination($validate, $base.'/../outside', $source, 'dot segment');
    expectInvalidDestination($validate, $source.'/inside', $source, 'inside the source directory');
    expectInvalidDestination($validate, $base.'/missing/snapshot', $source, 'missing parent');

    symlink($realParent, $base.'/linked');
    expectInvalidDestination($validate, $base.'/linked/snapshot', $source, 'linked immediate parent');
    mkdir($realParent.'/nested', 0700);
    expectInvalidDestination($validate, $base.'/linked/nested/snapshot', $source, 'linked ancestor');
    symlink($realParent, $base.'/linked-snapshot');
    expectInvalidDestination($validate, $base.'/linked-snapshot', $source, 'existing symbolic link destination');

    $webroot = realpath(dirname(__DIR__).'/public');
    if ($webroot !== false) {
        expectInvalidDestination($validate, $webroot.'/private-backup-test', $source, 'HTTP document root');
    }
    echo "Backup destination path validation tests passed.\n";
} finally {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($base);
}
