<?php

declare(strict_types=1);

// Disposable default/unmetered WebDAV storage acceptance test.
require dirname(__DIR__).'/vendor/autoload.php';

use GoreeCloud\DAV\FileQuota;
use GoreeCloud\DAV\PrivateDirectory;
use GoreeCloud\DAV\PrivateFile;
use GoreeCloud\DAV\PrivateFilesystemGuard;
use Sabre\DAV\Exception\Forbidden;

function verifyAtomic(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException($reason);
    }
}

function waitAtomic(string $path, string $reason): void
{
    $end = microtime(true) + 8.0;
    while (!file_exists($path)) {
        if (microtime(true) > $end) {
            throw new RuntimeException('Timed out waiting for '.$reason);
        }
        usleep(10000);
    }
}

// A read stream that provides a partial upload, then fails. Never reads real data.
class SyntheticFailingDavStream
{
    public $context;
    private int $reads = 0;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $length): string
    {
        if ($this->reads++ === 0) {
            return 'partial-new-file';
        }
        throw new RuntimeException('Synthetic upload source failed');
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }
}

verifyAtomic(stream_wrapper_register('dav-synthetic-fail', SyntheticFailingDavStream::class),
    'Could not register synthetic failure stream');

if (($argv[1] ?? '') === '--worker') {
    try {
        [, , $path, $attempt, $done] = $argv;
        $root = getenv('DAV_DATA_DIR').'/files/alice';
        $guard = new PrivateFilesystemGuard($root);
        $writer = FileQuota::unmeteredForAccount($guard, 'alice');
        verifyAtomic(touch($attempt), 'Could not mark worker attempting PUT');
        (new PrivateFile($path, $guard, $writer))->put('updated-after-lock');
        verifyAtomic(touch($done), 'Could not mark worker completion');
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
        exit(1);
    }
}

$base = sys_get_temp_dir().'/goreecloud-dav-unmetered-'.bin2hex(random_bytes(8));
$originalData = getenv('DAV_DATA_DIR');
$originalQuota = getenv('DAV_ACCOUNT_FILE_QUOTA_BYTES');
verifyAtomic(mkdir($base.'/files/alice', 0700, true), 'Cannot create private test files');
verifyAtomic(mkdir($base.'/metadata/alice', 0700, true), 'Cannot create private test metadata');
putenv('DAV_DATA_DIR='.$base);
putenv('DAV_ACCOUNT_FILE_QUOTA_BYTES');
$worker = null;
try {
    $root = $base.'/files/alice';
    $guard = new PrivateFilesystemGuard($root);
    verifyAtomic(FileQuota::forAccount($guard, 'alice') === null, 'Absent quota unexpectedly enabled');
    $writer = FileQuota::unmeteredForAccount($guard, 'alice');
    verifyAtomic(!$writer->isEnforced(), 'Unmetered writer must never enforce a file-byte ceiling');

    $directory = new PrivateDirectory($root, $guard, $writer);
    $properties = $directory->getQuotaInfo();
    verifyAtomic(count($properties) === 2 && $properties[0] >= 0 && $properties[1] >= 0,
        'Unmetered DAV properties must reflect disk rather than fake account quota');

    $directory->createFile('record.txt', 'original-intact');
    $file = new PrivateFile($root.'/record.txt', $guard, $writer);
    verifyAtomic(file_get_contents($root.'/record.txt') === 'original-intact',
        'Unmetered create failed');

    // This payload cannot be copied successfully; the old file must survive.
    $broken = fopen('dav-synthetic-fail://fixture', 'rb');
    verifyAtomic(is_resource($broken), 'Cannot open test failure stream');
    $failed = false;
    try {
        $file->put($broken);
    } catch (Throwable $e) {
        $failed = true;
    } finally {
        fclose($broken);
    }
    verifyAtomic($failed, 'Synthetic interrupted upload unexpectedly succeeded');
    verifyAtomic(file_get_contents($root.'/record.txt') === 'original-intact',
        'Interrupted upload destroyed the previous bytes');
    verifyAtomic(glob($base.'/metadata/alice/.dav-upload-*') === [],
        'Interrupted upload left temporary sensitive bytes on disk');

    $file->put('new-complete-content');
    verifyAtomic(file_get_contents($root.'/record.txt') === 'new-complete-content',
        'Valid replacement failed');
    $directory->createFile('not-capped.txt', str_repeat('x', 65536));
    verifyAtomic(filesize($root.'/not-capped.txt') === 65536, 'Unmetered upload was capped');
    $directory->createDirectory('nested');
    $nested = new PrivateDirectory($root.'/nested', $guard, $writer);
    $nested->createFile('child.txt', 'nest');
    verifyAtomic(file_get_contents($root.'/nested/child.txt') === 'nest', 'Nested unmetered PUT failed');

    $attempt = $base.'/attempt';
    $done = $base.'/done';
    $stderr = $base.'/worker.err';
    $writer->mutate(function () use ($root, $attempt, $done, $stderr, &$worker): void {
        $worker = proc_open(
            [PHP_BINARY, __FILE__, '--worker', $root.'/record.txt', $attempt, $done],
            [0 => ['file', '/dev/null', 'r'],
             1 => ['file', '/dev/null', 'w'],
             2 => ['file', $stderr, 'w']],
            $pipes
        );
        verifyAtomic(is_resource($worker), 'Could not start unmetered PUT worker');
        waitAtomic($attempt, 'worker attempting file replacement');
        usleep(260000);
        verifyAtomic(!file_exists($done), 'Unmetered upload bypassed per-account lock');
        verifyAtomic(proc_get_status($worker)['running'], 'Unmetered PUT worker exited prematurely');
    });
    waitAtomic($done, 'worker after lock release');
    $code = proc_close($worker);
    $worker = null;
    verifyAtomic($code === 0, 'Unmetered worker failed: '.(@file_get_contents($stderr) ?: ''));
    verifyAtomic(file_get_contents($root.'/record.txt') === 'updated-after-lock',
        'Unmetered locked PUT corrupted file');

    $nested->delete();
    verifyAtomic(!file_exists($root.'/nested'), 'Recursive unmetered delete failed');

    $file->delete();
    verifyAtomic(!file_exists($root.'/record.txt'), 'Unmetered file delete failed');


    // Exercise the split-filesystem guard without mounting or touching real
    // DAV data. Linux CI exposes a separate ephemeral /dev/shm tmpfs.
    $shm = '/dev/shm';
    $localStat = @stat($base);
    $shmStat = @stat($shm);
    if (is_array($localStat) && is_array($shmStat)
        && ($localStat['dev'] ?? null) !== ($shmStat['dev'] ?? null)
        && is_writable($shm)) {
        $other = $shm.'/goreecloud-dav-atomic-'.bin2hex(random_bytes(6));
        verifyAtomic(mkdir($other, 0700), 'Cannot create synthetic cross-device root');
        try {
            $otherGuard = new PrivateFilesystemGuard($other);
            $otherWriter = FileQuota::unmeteredForAccount($otherGuard, 'alice');
            $denied = false;
            try {
                $otherWriter->put($other.'/must-not-appear.txt', 'test', false);
            } catch (Forbidden $e) {
                $denied = str_contains($e->getMessage(), 'same filesystem');
            }
            verifyAtomic($denied, 'Cross-filesystem DAV staging was not rejected');
            verifyAtomic(!file_exists($other.'/must-not-appear.txt'),
                'Rejected cross-device upload left an incomplete file');
        } finally {
            rmdir($other);
        }
    } else {
        echo "Cross-device fixture unavailable on this runner; existing same-device path tested.\n";
    }
    echo "Unmetered private file staging, failed-stream rollback, quota reporting and cross-process lock passed.\n";
} finally {
    if (is_resource($worker)) {
        proc_terminate($worker);
        proc_close($worker);
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($base);
    foreach (['DAV_DATA_DIR' => $originalData, 'DAV_ACCOUNT_FILE_QUOTA_BYTES' => $originalQuota] as $key => $value) {
        if ($value === false) {
            putenv($key);
        } else {
            putenv($key.'='.$value);
        }
    }
    stream_wrapper_unregister('dav-synthetic-fail');
}
