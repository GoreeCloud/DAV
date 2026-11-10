<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\FS\File;

/** Guard reads, writes, deletes and moves of every nested WebDAV file. */
final class PrivateFile extends File
{
    public function __construct(string $path, private readonly PrivateFilesystemGuard $guard, private readonly ?FileQuota $quota = null)
    {
        $this->guard->assertSafePath($path);
        parent::__construct($path);
    }

    public function put($data)
    {
        $this->guard->assertSafePath($this->path);
        if ($this->quota !== null) {
            $this->quota->put($this->path, $data, true);
            return null;
        }
        return parent::put($data);
    }

    public function get()
    {
        $this->guard->assertSafePath($this->path);
        return parent::get();
    }

    public function delete()
    {
        if ($this->quota !== null) {
            $this->quota->mutate(function (): void {
                $this->guard->assertSafePath($this->path);
                parent::delete();
            });
            return;
        }
        $this->guard->assertSafePath($this->path);
        return parent::delete();
    }

    public function getSize()
    {
        $this->guard->assertSafePath($this->path);
        return parent::getSize();
    }

    public function getETag()
    {
        $this->guard->assertSafePath($this->path);
        // Sabre\DAV\FS\File hashes inode, size and second-resolution mtime.
        // A same-size rewrite within one second therefore keeps its old ETag,
        // making DAV clients incorrectly reuse stale contents. Strong ETags
        // must change whenever the resource bytes change, including when an
        // authorized maintenance tool preserves the original modification time.
        $digest = @hash_file('sha256', $this->path);
        if ($digest === false) {
            throw new Forbidden('Cannot calculate WebDAV file ETag');
        }
        return '"'.$digest.'"';
    }

    public function getLastModified()
    {
        $this->guard->assertSafePath($this->path);
        return parent::getLastModified();
    }

    public function setName($name)
    {
        if ($this->quota !== null) {
            $this->quota->mutate(fn() => $this->renameWithinAccount((string) $name));
            return;
        }
        $this->renameWithinAccount((string) $name);
    }

    private function renameWithinAccount(string $name): void
    {
        $this->guard->assertSafePath($this->path);
        $target = $this->guard->childPath(dirname($this->path), $name);
        if (file_exists($target)) {
            throw new Forbidden('WebDAV rename target already exists');
        }
        if (!rename($this->path, $target)) {
            throw new Forbidden('Unable to rename private WebDAV file');
        }
        $this->path = $target;
    }
}
