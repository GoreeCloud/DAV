<?php

declare(strict_types=1);

// Standalone fixture; real CI resolves the SabreDAV exception classes through Composer.
if (!class_exists(\Sabre\DAV\Exception\Forbidden::class)) {
    class_alias(\RuntimeException::class, \Sabre\DAV\Exception\Forbidden::class);
}
if (!class_exists(\Sabre\DAV\Exception\InsufficientStorage::class)) {
    class_alias(\RuntimeException::class, \Sabre\DAV\Exception\InsufficientStorage::class);
}

foreach (['Config', 'Credentials', 'Database', 'PrivateFilesystemGuard', 'FileQuota'] as $class) {
    require dirname(__DIR__).'/src/'.$class.'.php';
}

use GoreeCloud\DAV\FileQuota;
use GoreeCloud\DAV\PrivateFilesystemGuard;

function quotaExpectFailure(callable $callback, string $scenario): void
{
    try {
        $callback();
    } catch (\RuntimeException $e) {
        return;
    }
    throw new \RuntimeException('Quota unexpectedly accepted: '.$scenario);
}

$base = sys_get_temp_dir().'/dav-quota-'.bin2hex(random_bytes(8));
mkdir($base.'/files/alice', 0700, true);
mkdir($base.'/files/bob', 0700);
mkdir($base.'/metadata/alice', 0700, true);
mkdir($base.'/metadata/bob', 0700);
putenv('DAV_DATA_DIR='.$base);
$previous = getenv('DAV_ACCOUNT_FILE_QUOTA_BYTES');
try {
    $guardAlice = new PrivateFilesystemGuard($base.'/files/alice');
    $guardBob = new PrivateFilesystemGuard($base.'/files/bob');
    putenv('DAV_ACCOUNT_FILE_QUOTA_BYTES');
    if (FileQuota::forAccount($guardAlice, 'alice') !== null) {
        throw new RuntimeException('Quota must be opt-in');
    }
    foreach (['-1', '+1', '1.5', '9e2', '9223372036854775808', 'hello'] as $invalid) {
        putenv('DAV_ACCOUNT_FILE_QUOTA_BYTES='.$invalid);
        quotaExpectFailure(static fn() => FileQuota::forAccount($guardAlice, 'alice'), 'invalid limit '.$invalid);
    }
    putenv('DAV_ACCOUNT_FILE_QUOTA_BYTES=10');
    $alice = FileQuota::forAccount($guardAlice, 'alice');
    $bob = FileQuota::forAccount($guardBob, 'bob');
    if ($alice === null || $bob === null || $alice->info() !== [0, 10]) {
        throw new RuntimeException('Unexpected initial quota state');
    }
    $alice->put($base.'/files/alice/note.txt', '12345678', false);
    if ($alice->info() !== [8, 2] || $bob->info() !== [0, 10]) {
        throw new RuntimeException('Per-account quota accounting failed');
    }
    quotaExpectFailure(static fn() => $alice->put($base.'/files/alice/over.txt', '123', false), 'new file beyond quota');
    if (file_exists($base.'/files/alice/over.txt')) {
        throw new RuntimeException('Rejected upload left a partial file');
    }
    quotaExpectFailure(static fn() => $alice->put($base.'/files/alice/note.txt', '01234567890', true), 'oversize replacement');
    if (file_get_contents($base.'/files/alice/note.txt') !== '12345678') {
        throw new RuntimeException('Rejected replacement destroyed the old data');
    }
    $input = fopen('php://temp', 'w+b');
    fwrite($input, 'abcdefghi');
    rewind($input);
    quotaExpectFailure(static fn() => $alice->put($base.'/files/alice/stream.txt', $input, false), 'stream exceeds remaining quota');
    fclose($input);
    $alice->put($base.'/files/alice/note.txt', 'abc', true);
    $alice->put($base.'/files/alice/another.txt', '1234567', false);
    if ($alice->info() !== [10, 0]) {
        throw new RuntimeException('Quota exact-boundary upload failed');
    }
    unlink($base.'/files/alice/another.txt');
    $bob->put($base.'/files/bob/other.txt', '1234567890', false);
    if ($alice->info() !== [3, 7] || $bob->info() !== [10, 0]) {
        throw new RuntimeException('Quota usage was not isolated after file removal');
    }
    symlink($base.'/files/bob/other.txt', $base.'/files/alice/link.txt');
    quotaExpectFailure(static fn() => $alice->put($base.'/files/alice/new.txt', '1', false), 'account containing symlink');
    unlink($base.'/files/alice/link.txt');
    symlink($base.'/files/alice/note.txt', $base.'/files/alice/replace-link.txt');
    quotaExpectFailure(static fn() => $alice->put($base.'/files/alice/replace-link.txt', '1', true), 'symlink target');
    unlink($base.'/files/alice/replace-link.txt');
    $lockFile = $base.'/metadata/alice/.file-quota.lock';
    unlink($lockFile);
    symlink($base.'/files/bob/other.txt', $lockFile);
    quotaExpectFailure(static fn() => $alice->put($base.'/files/alice/unsafe.txt', '1', false), 'symlinked quota lock');
    unlink($lockFile);
    if (file_get_contents($base.'/files/bob/other.txt') !== '1234567890') {
        throw new RuntimeException('Quota test modified the other account');
    }
    echo "Per-account WebDAV file quota tests passed.\n";
} finally {
    if ($previous === false) {
        putenv('DAV_ACCOUNT_FILE_QUOTA_BYTES');
    } else {
        putenv('DAV_ACCOUNT_FILE_QUOTA_BYTES='.$previous);
    }
    putenv('DAV_DATA_DIR');
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($walk as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($base);
}
