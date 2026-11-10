<?php

declare(strict_types=1);

// Standalone cross-process coverage for the same shared/exclusive file lock
// used by DAV HTTP requests, local CLI operations and operator snapshots.
// No Composer, database, network connection or pre-existing storage is used.
require dirname(__DIR__).'/src/Config.php';
require dirname(__DIR__).'/src/ServiceLock.php';

use GoreeCloud\DAV\ServiceLock;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function waitFor(string $path, string $label, float $seconds = 5.0): void
{
    $deadline = microtime(true) + $seconds;
    while (!file_exists($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for '.$label);
        }
        usleep(10000);
    }
}

function worker(array $argv): never
{
    if (count($argv) !== 6 || !in_array($argv[2], ['shared', 'exclusive'], true)) {
        fwrite(STDERR, "Invalid service-lock test worker arguments\n");
        exit(2);
    }
    [, , $mode, $attempt, $acquired, $release] = $argv;
    try {
        assertTrue(touch($attempt), 'Worker could not record lock attempt');
        $lock = $mode === 'shared' ? ServiceLock::shared() : ServiceLock::exclusive();
        assertTrue(touch($acquired), 'Worker could not record acquisition');
        waitFor($release, 'worker release', 9.0);
        $lock->release();
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
        exit(1);
    }
}

if (($argv[1] ?? '') === '--worker') {
    worker($argv);
}

function launch(string $directory, string $name, string $mode): array
{
    $attempt = $directory.'/'.$name.'.attempt';
    $acquired = $directory.'/'.$name.'.acquired';
    $release = $directory.'/'.$name.'.release';
    $stderr = $directory.'/'.$name.'.stderr';
    $command = [PHP_BINARY, __FILE__, '--worker', $mode, $attempt, $acquired, $release];
    $proc = proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $directory.'/'.$name.'.stdout', 'w'],
        2 => ['file', $stderr, 'w'],
    ], $pipes);
    assertTrue(is_resource($proc), 'Unable to start worker: '.$name);
    return compact('proc', 'attempt', 'acquired', 'release', 'stderr');
}

function joinWorker(array $worker, string $name): void
{
    assertTrue(touch($worker['release']), 'Cannot release worker '.$name);
    $code = proc_close($worker['proc']);
    assertTrue($code === 0, 'Worker '.$name.' failed (exit '.$code.'): '.(file_get_contents($worker['stderr']) ?: ''));
}

function assertBlocked(array $worker, string $name): void
{
    waitFor($worker['attempt'], $name.' attempting lock');
    // If the worker can acquire during this interval while the parent retains
    // an incompatible lock, the actual cross-process exclusion is broken.
    usleep(300000);
    assertTrue(!file_exists($worker['acquired']), $name.' acquired an incompatible lock');
    $status = proc_get_status($worker['proc']);
    assertTrue($status['running'], $name.' exited while expected to be blocked');
}

$base = sys_get_temp_dir().'/goreecloud-dav-service-lock-'.bin2hex(random_bytes(8));
assertTrue(mkdir($base, 0700), 'Could not create isolated lock test directory');
putenv('DAV_DATA_DIR='.$base);
$workers = [];
try {
    $shared = ServiceLock::shared();
    assertTrue(is_file($base.'/.service.lock'), 'Expected service lock file');
    assertTrue((fileperms($base.'/.service.lock') & 0777) === 0600, 'Service lock permissions must be private');

    $workers[] = launch($base, 'parallel-reader', 'shared');
    waitFor($workers[0]['acquired'], 'concurrent shared lock');
    joinWorker($workers[0], 'parallel-reader');
    unset($workers[0]);

    $workers[] = launch($base, 'waiting-backup', 'exclusive');
    $snapshot = end($workers);
    assertBlocked($snapshot, 'exclusive snapshot');
    $shared->release();
    waitFor($snapshot['acquired'], 'snapshot after DAV request exits');
    joinWorker($snapshot, 'waiting-backup');
    array_pop($workers);

    $exclusive = ServiceLock::exclusive();
    $workers[] = launch($base, 'waiting-request', 'shared');
    $request = end($workers);
    assertBlocked($request, 'shared DAV request');
    $exclusive->release();
    waitFor($request['acquired'], 'DAV request after snapshot exits');
    joinWorker($request, 'waiting-request');
    array_pop($workers);

    $exclusive = ServiceLock::exclusive();
    $exclusive->release();
    $exclusive->release(); // Idempotent explicit unlock.
    $shared = ServiceLock::shared();
    $shared->release();

    unlink($base.'/.service.lock');
    $outside = $base.'/outside-target';
    file_put_contents($outside, 'outside-target-intact');
    symlink($outside, $base.'/.service.lock');
    $rejected = false;
    try {
        ServiceLock::shared();
    } catch (RuntimeException $e) {
        $rejected = $e->getMessage() === 'Refusing symlinked DAV service lock';
    }
    assertTrue($rejected, 'Symlinked service lock was not rejected for the expected reason');
    assertTrue(file_get_contents($outside) === 'outside-target-intact', 'Linked target was modified');

    echo "Service lock shared/exclusive cross-process concurrency tests passed.\n";
} finally {
    foreach ($workers as $worker) {
        @touch($worker['release']);
        if (is_resource($worker['proc'])) {
            @proc_terminate($worker['proc']);
            @proc_close($worker['proc']);
        }
    }
    foreach (new FilesystemIterator($base, FilesystemIterator::SKIP_DOTS) as $item) {
        unlink($item->getPathname());
    }
    rmdir($base);
    putenv('DAV_DATA_DIR');
}
