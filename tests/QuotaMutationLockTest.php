<?php

declare(strict_types=1);

// Synthetic cross-process quota mutation lock coverage; no network or live data.
require dirname(__DIR__).'/vendor/autoload.php';

use GoreeCloud\DAV\FileQuota;
use GoreeCloud\DAV\PrivateDirectory;
use GoreeCloud\DAV\PrivateFile;
use GoreeCloud\DAV\PrivateFilesystemGuard;

function ensure(bool $state, string $message): void
{
    if (!$state) {
        throw new RuntimeException($message);
    }
}

function waitMarker(string $filename, string $label): void
{
    $deadline = microtime(true) + 7.0;
    while (!file_exists($filename)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timeout waiting for '.$label);
        }
        usleep(10000);
    }
}

if (($argv[1] ?? '') === '--worker') {
    try {
        [, , $action, $root, $attempt, $done] = $argv;
        $guard = new PrivateFilesystemGuard($root);
        $quota = FileQuota::forAccount($guard, 'alice');
        ensure($quota !== null, 'Worker quota missing');
        $file = static fn(string $name) => new PrivateFile($root.'/'.$name, $guard, $quota);
        $directory = static fn(string $name) => new PrivateDirectory($root.'/'.$name, $guard, $quota);
        ensure(touch($attempt), 'Worker could not mark attempt');
        switch ($action) {
            case 'file-rename':
                $file('one.txt')->setName('renamed.txt');
                break;
            case 'file-delete':
                $file('renamed.txt')->delete();
                break;
            case 'directory-create':
                (new PrivateDirectory($root, $guard, $quota))->createDirectory('created');
                break;
            case 'directory-rename':
                $directory('tree')->setName('moved');
                break;
            case 'directory-delete':
                $directory('moved')->delete();
                break;
            default:
                throw new RuntimeException('Unexpected worker action');
        }
        ensure(touch($done), 'Worker could not mark completion');
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
        exit(1);
    }
}

$base = sys_get_temp_dir().'/goreecloud-dav-quota-mutations-'.bin2hex(random_bytes(8));
$root = $base.'/files/alice';
ensure(mkdir($root, 0700, true), 'Cannot initialize synthetic file root');
ensure(mkdir($base.'/metadata/alice', 0700, true), 'Cannot initialize synthetic quota metadata');
ensure(mkdir($base.'/markers', 0700), 'Cannot initialize private test marker directory');
putenv('DAV_DATA_DIR='.$base);
putenv('DAV_ACCOUNT_FILE_QUOTA_BYTES=50');
$running = [];
try {
    $guard = new PrivateFilesystemGuard($root);
    $quota = FileQuota::forAccount($guard, 'alice');
    ensure($quota !== null, 'Quota test setup failed');

    $checkBlocking = function (string $action) use ($base, $root, $quota, &$running): void {
        $attempt = $base.'/markers/'.$action.'.attempt';
        $done = $base.'/markers/'.$action.'.done';
        $error = $base.'/markers/'.$action.'.stderr';
        $process = null;
        try {
            $quota->mutate(function () use ($action, $root, $attempt, $done, $error, &$process): void {
                $process = proc_open(
                    [PHP_BINARY, __FILE__, '--worker', $action, $root, $attempt, $done],
                    [0 => ['file', '/dev/null', 'r'],
                     1 => ['file', '/dev/null', 'w'],
                     2 => ['file', $error, 'w']],
                    $pipes
                );
                ensure(is_resource($process), 'Could not launch '.$action.' worker');
                waitMarker($attempt, $action.' attempt');
                usleep(260000);
                ensure(!file_exists($done), $action.' bypassed the account write lock');
                ensure(proc_get_status($process)['running'], $action.' worker exited prematurely');
            });
            waitMarker($done, $action.' completion after lock release');
            $code = proc_close($process);
            $process = null;
            ensure($code === 0, $action.' worker failed: '.(@file_get_contents($error) ?: ''));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    };

    ensure(file_put_contents($root.'/one.txt', '12345') === 5, 'File fixture write failed');
    $checkBlocking('file-rename');
    ensure(!file_exists($root.'/one.txt') && file_get_contents($root.'/renamed.txt') === '12345', 'Rename corrupted bytes');
    $checkBlocking('file-delete');
    ensure(!file_exists($root.'/renamed.txt'), 'Locked file deletion failed');
    $checkBlocking('directory-create');
    ensure(is_dir($root.'/created'), 'Locked directory creation failed');
    ensure(mkdir($root.'/tree/nested', 0700, true), 'Nested fixture directory failed');
    ensure(file_put_contents($root.'/tree/nested/data.txt', 'abcdef') === 6, 'Nested file write failed');
    $checkBlocking('directory-rename');
    ensure(is_file($root.'/moved/nested/data.txt') && !file_exists($root.'/tree'), 'Directory rename corrupted tree');
    $checkBlocking('directory-delete');
    ensure(!file_exists($root.'/moved'), 'Recursive directory delete failed/deadlocked');
    ensure($quota->info() === [0, 50], 'Quota changed unexpectedly after locked mutations');
    echo "Quota mutation lock concurrency test passed for create/rename/delete, including reentrant recursive deletion.\n";
} finally {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($base);
    putenv('DAV_DATA_DIR');
    putenv('DAV_ACCOUNT_FILE_QUOTA_BYTES');
}
