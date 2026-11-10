<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

/**
 * Cooperative lock shared by the HTTP service, local CLI and snapshot utility.
 * All writers MUST take a shared lock; snapshots take an exclusive lock.
 * Does not protect against tools that modify the data directory out-of-band.
 */
final class ServiceLock
{
    /** @var resource */
    private $handle;

    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    public static function shared(): self
    {
        return self::acquire(LOCK_SH);
    }

    public static function exclusive(): self
    {
        return self::acquire(LOCK_EX);
    }

    private static function acquire(int $mode): self
    {
        $directory = Config::dataDir();
        if (!is_dir($directory)) {
            throw new \RuntimeException('DAV data directory is missing; run init first');
        }
        $path = $directory.'/.service.lock';
        if (is_link($path)) {
            throw new \RuntimeException('Refusing symlinked DAV service lock');
        }
        $handle = @fopen($path, 'c');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Unable to open DAV service lock');
        }
        @chmod($path, 0600);
        if (!flock($handle, $mode)) {
            fclose($handle);
            throw new \RuntimeException('Unable to acquire DAV service lock');
        }
        return new self($handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
