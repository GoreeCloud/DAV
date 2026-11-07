<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\InsufficientStorage;

/**
 * Optional per-account WebDAV file-byte ceiling.
 *
 * All HTTP writes for the account share one flock. This coordinates only
 * GoreeCloud's filesystem adapter, not unrelated host-side writers. Temp
 * uploads are staged in private metadata, never in the WebDAV-served tree.
 */
final class FileQuota
{
    // Nested recursive directory removal reuses one exclusive lock in this request.
    private bool $exclusiveActive = false;

    private function __construct(
        private readonly PrivateFilesystemGuard $guard,
        private readonly string $lockPath,
        private readonly int $limit,
    ) {
    }

    public static function forAccount(PrivateFilesystemGuard $guard, string $username): ?self
    {
        $raw = getenv('DAV_ACCOUNT_FILE_QUOTA_BYTES');
        if ($raw === false || $raw === '') {
            return null; // opt in; never silently impose a limit on existing accounts.
        }
        if (!preg_match('/^[0-9]{1,19}$/D', $raw)
            || (strlen(ltrim($raw, '0')) > strlen((string) PHP_INT_MAX))
            || (strlen(ltrim($raw, '0')) === strlen((string) PHP_INT_MAX)
                && strcmp(ltrim($raw, '0'), (string) PHP_INT_MAX) > 0)) {
            throw new \RuntimeException('Invalid DAV_ACCOUNT_FILE_QUOTA_BYTES');
        }
        if (!Credentials::validUsername($username)) {
            throw new \InvalidArgumentException('Invalid account for file quota');
        }
        $lockPath = Config::dataDir().'/metadata/'.$username.'/.file-quota.lock';
        Database::assertPrivateStoragePath($lockPath);
        if (!is_dir(dirname($lockPath)) || is_link($lockPath)) {
            throw new \RuntimeException('Unsafe account quota lock directory');
        }
        return new self($guard, $lockPath, (int) $raw);
    }

    /** @return array{0: int, 1: int} used bytes and free bytes */
    public function info(): array
    {
        return $this->locked(LOCK_SH, function (): array {
            $used = $this->usageBytes();
            return [$used, max(0, $this->limit - $used)];
        });
    }

    /**
     * Serialize WebDAV filesystem mutations against quota-controlled uploads.
     * Nested file deletions during recursive directory removal use the same
     * account lock rather than deadlocking on a second flock descriptor.
     */
    public function mutate(callable $operation): mixed
    {
        return $this->locked(LOCK_EX, $operation);
    }

    /** Create or replace a WebDAV file; denied writes preserve the old file. */
    public function put(string $path, mixed $data, bool $replace): void
    {
        $this->locked(LOCK_EX, function () use ($path, $data, $replace): void {
            $this->guard->assertSafePath($path);
            if (is_dir($path) || (!$replace && file_exists($path))
                || ($replace && !is_file($path))) {
                throw new Forbidden('Invalid WebDAV quota write target');
            }
            $used = $this->usageBytes();
            $old = $replace ? filesize($path) : 0;
            if ($old === false || $used < $old) {
                throw new Forbidden('Cannot determine WebDAV storage usage');
            }
            $available = $this->limit - ($used - $old);
            if ($available < 0) {
                throw new InsufficientStorage('WebDAV account file quota exceeded');
            }
            $staged = tempnam(dirname($this->lockPath), '.dav-upload-');
            if ($staged === false) {
                throw new InsufficientStorage('Unable to stage WebDAV upload');
            }
            try {
                $out = @fopen($staged, 'wb');
                if (!is_resource($out)) {
                    throw new InsufficientStorage('Unable to open staged WebDAV upload');
                }
                $written = 0;
                try {
                    $input = is_resource($data) ? $data : null;
                    if ($data !== null && $input === null && !is_string($data)) {
                        throw new Forbidden('Invalid WebDAV upload stream');
                    }
                    $stringLength = is_string($data) ? strlen($data) : 0;
                    $offset = 0;
                    while ($input !== null ? !feof($input) : $offset < $stringLength) {
                        $chunk = $input !== null ? fread($input, 65536) : substr($data, $offset, 65536);
                        if ($chunk === false) {
                            throw new Forbidden('Unable to read WebDAV upload');
                        }
                        if ($chunk === '') {
                            if ($input !== null && feof($input)) {
                                break;
                            }
                            throw new Forbidden('WebDAV upload stream stalled');
                        }
                        $length = strlen($chunk);
                        if ($length > $available - $written) {
                            throw new InsufficientStorage('WebDAV account file quota exceeded');
                        }
                        for ($pos = 0; $pos < $length;) {
                            $part = fwrite($out, substr($chunk, $pos));
                            if ($part === false || $part === 0) {
                                throw new InsufficientStorage('Unable to write staged WebDAV upload');
                            }
                            $pos += $part;
                        }
                        $written += $length;
                        $offset += $length;
                    }
                    if (!fflush($out)) {
                        throw new InsufficientStorage('Unable to flush staged WebDAV upload');
                    }
                } finally {
                    fclose($out);
                }
                @chmod($staged, 0600);
                // Reject target replacement that became unsafe while streaming.
                $this->guard->assertSafePath($path);
                if (($replace && !is_file($path)) || (!$replace && file_exists($path))) {
                    throw new Forbidden('WebDAV target changed during upload');
                }
                if (!@rename($staged, $path)) {
                    throw new InsufficientStorage('Unable to commit staged WebDAV upload');
                }
            } finally {
                if (file_exists($staged)) {
                    @unlink($staged);
                }
            }
        });
    }

    private function usageBytes(): int
    {
        $root = $this->guard->root();
        $this->guard->assertSafePath($root);
        $bytes = 0;
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        ) as $entry) {
            $path = $entry->getPathname();
            $this->guard->assertSafePath($path);
            if (!is_file($path) || is_link($path)) {
                throw new Forbidden('Unsafe object in WebDAV account storage');
            }
            $size = filesize($path);
            if ($size === false || $size < 0 || $size > PHP_INT_MAX - $bytes) {
                throw new Forbidden('Cannot determine WebDAV account usage');
            }
            $bytes += $size;
        }
        return $bytes;
    }

    private function locked(int $mode, callable $callback): mixed
    {
        // PHP request execution is single-threaded; only nested callbacks in
        // this same FileQuota instance may bypass a second file-lock acquire.
        if ($this->exclusiveActive) {
            return $callback();
        }
        Database::assertPrivateStoragePath($this->lockPath);
        if (is_link($this->lockPath)) {
            throw new Forbidden('Symlinked WebDAV quota lock');
        }
        $lock = @fopen($this->lockPath, 'c');
        if ($lock === false) {
            throw new Forbidden('Unable to open WebDAV quota lock');
        }
        try {
            @chmod($this->lockPath, 0600);
            if (!flock($lock, $mode)) {
                throw new Forbidden('Unable to acquire WebDAV quota lock');
            }
            if ($mode === LOCK_EX) {
                $this->exclusiveActive = true;
            }
            return $callback();
        } finally {
            $this->exclusiveActive = false;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
