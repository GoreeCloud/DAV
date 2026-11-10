<?php

declare(strict_types=1);

// Simultaneous, separate-process account quota admission test. No live data or network.
require dirname(__DIR__).'/vendor/autoload.php';

use GoreeCloud\DAV\FileQuota;
use GoreeCloud\DAV\PrivateFile;
use GoreeCloud\DAV\PrivateFilesystemGuard;
use Sabre\DAV\Exception\InsufficientStorage;

function requireCondition(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function awaitFile(string $path, string $label): void
{
    $deadline = microtime(true) + 9.0;
    while (!file_exists($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timeout waiting for '.$label);
        }
        usleep(10000);
    }
}

if (($argv[1] ?? '') === '--worker') {
    try {
        if (count($argv) !== 8) {
            throw new RuntimeException('Unexpected worker arguments');
        }
        [, , $account, $file, $bytes, $ready, $go, $out] = $argv;
        requireCondition(in_array($account, ['alice', 'bob'], true), 'Invalid account');
        requireCondition(in_array($bytes, ['2', '8', '10'], true), 'Invalid byte count');
        $root = getenv('DAV_DATA_DIR').'/files/'.$account;
        $guard = new PrivateFilesystemGuard($root);
        $quota = FileQuota::forAccount($guard, $account);
        requireCondition($quota !== null, 'Per-account quota is not enabled');
        requireCondition(touch($ready), 'Could not signal worker ready');
        awaitFile($go, 'worker start');
        $body = str_repeat($account === 'bob' ? 'B' : 'A', (int) $bytes);
        try {
            $quota->put($root.'/'.$file, $body, false);
            requireCondition(file_put_contents($out, 'success') === 7, 'Could not write success marker');
        } catch (InsufficientStorage $e) {
            requireCondition(file_put_contents($out, 'rejected') === 8, 'Could not write rejection marker');
        }
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
        exit(1);
    }
}

$base = sys_get_temp_dir().'/goreecloud-quota-concurrent-'.bin2hex(random_bytes(8));
requireCondition(mkdir($base.'/files/alice', 0700, true), 'Cannot create Alice test root');
requireCondition(mkdir($base.'/files/bob', 0700, true), 'Cannot create Bob test root');
requireCondition(mkdir($base.'/metadata/alice', 0700, true), 'Cannot create Alice metadata');
requireCondition(mkdir($base.'/metadata/bob', 0700, true), 'Cannot create Bob metadata');
requireCondition(mkdir($base.'/markers', 0700), 'Cannot create marker directory');
$priorData = getenv('DAV_DATA_DIR');
$priorQuota = getenv('DAV_ACCOUNT_FILE_QUOTA_BYTES');
putenv('DAV_DATA_DIR='.$base);
putenv('DAV_ACCOUNT_FILE_QUOTA_BYTES=10');
$active = [];
try {
    $aliceRoot = $base.'/files/alice';
    $bobRoot = $base.'/files/bob';
    $aliceGuard = new PrivateFilesystemGuard($aliceRoot);
    $aliceQuota = FileQuota::forAccount($aliceGuard, 'alice');
    $bobQuota = FileQuota::forAccount(new PrivateFilesystemGuard($bobRoot), 'bob');
    requireCondition($aliceQuota !== null && $bobQuota !== null, 'Quotas not configured');

    $batch = function (array $uploads) use ($base, &$active): array {
        $group = bin2hex(random_bytes(5));
        $go = $base.'/markers/'.$group.'.start';
        $workers = [];
        try {
            foreach ($uploads as [$account, $name, $bytes]) {
                $tag = $group.'-'.$account.'-'.$name;
                $ready = $base.'/markers/'.$tag.'.ready';
                $out = $base.'/markers/'.$tag.'.result';
                $err = $base.'/markers/'.$tag.'.stderr';
                $proc = proc_open(
                    [PHP_BINARY, __FILE__, '--worker', $account, $name, (string) $bytes, $ready, $go, $out],
                    [
                        0 => ['file', '/dev/null', 'r'],
                        1 => ['file', '/dev/null', 'w'],
                        2 => ['file', $err, 'w'],
                    ],
                    $pipes
                );
                requireCondition(is_resource($proc), 'Could not spawn concurrent upload worker');
                $workers[] = compact('account', 'name', 'ready', 'out', 'err', 'proc');
            }
            foreach ($workers as $worker) {
                awaitFile($worker['ready'], $worker['name'].' ready');
            }
            requireCondition(touch($go), 'Cannot release concurrent upload barrier');
            $results = [];
            foreach ($workers as $index => $worker) {
                awaitFile($worker['out'], $worker['name'].' result');
                $exit = proc_close($worker['proc']);
                $workers[$index]['proc'] = null;
                requireCondition($exit === 0, $worker['name'].' failed: '.(@file_get_contents($worker['err']) ?: ''));
                $results[$worker['name']] = file_get_contents($worker['out']);
            }
            return $results;
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker['proc'])) {
                    proc_terminate($worker['proc']);
                    proc_close($worker['proc']);
                }
            }
        }
    };

    $first = $batch([
        ['alice', 'first-a.txt', 8],
        ['alice', 'first-b.txt', 8],
        ['bob', 'bob.txt', 10],
    ]);
    $aliceOutcomes = [$first['first-a.txt'], $first['first-b.txt']];
    requireCondition(count(array_filter($aliceOutcomes, fn($x) => $x === 'success')) === 1, 'Two 8-byte uploads oversubscribed Alice quota');
    requireCondition(count(array_filter($aliceOutcomes, fn($x) => $x === 'rejected')) === 1, 'Rejected Alice upload missing');
    requireCondition($first['bob.txt'] === 'success', 'Independent Bob quota blocked');
    requireCondition($aliceQuota->info() === [8, 2] && $bobQuota->info() === [10, 0], 'Initial quota usage invalid');
    $firstWinner = $first['first-a.txt'] === 'success' ? 'first-a.txt' : 'first-b.txt';
    $firstLoser = $firstWinner === 'first-a.txt' ? 'first-b.txt' : 'first-a.txt';
    requireCondition(!file_exists($aliceRoot.'/'.$firstLoser), 'Rejected upload left a partial file');
    requireCondition(file_get_contents($aliceRoot.'/'.$firstWinner) === 'AAAAAAAA', 'Winning upload data corrupted');
    requireCondition(file_get_contents($bobRoot.'/bob.txt') === 'BBBBBBBBBB', 'Other account file corrupted');

    $second = $batch([
        ['alice', 'second-a.txt', 2],
        ['alice', 'second-b.txt', 2],
    ]);
    requireCondition(count(array_filter($second, fn($x) => $x === 'success')) === 1, 'Two 2-byte uploads oversubscribed remaining space');
    requireCondition(count(array_filter($second, fn($x) => $x === 'rejected')) === 1, 'At-limit upload was not rejected');
    requireCondition($aliceQuota->info() === [10, 0], 'Alice full quota accounting incorrect');

    (new PrivateFile($aliceRoot.'/'.$firstWinner, $aliceGuard, $aliceQuota))->delete();
    requireCondition($aliceQuota->info() === [2, 8], 'Locked delete failed to free capacity');
    $aliceQuota->put($aliceRoot.'/after-delete.txt', 'AAAAAAAA', false);
    requireCondition($aliceQuota->info() === [10, 0], 'Capacity was not reusable after delete');
    requireCondition($bobQuota->info() === [10, 0], 'Bob quota changed after Alice delete');
    echo "Concurrent quota upload test passed: same-account workers serialize, reject overflow and isolate accounts.\n";
} finally {
    foreach (['DAV_DATA_DIR' => $priorData, 'DAV_ACCOUNT_FILE_QUOTA_BYTES' => $priorQuota] as $key => $value) {
        if ($value === false) {
            putenv($key);
        } else {
            putenv($key.'='.$value);
        }
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
}
