<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

use FilesystemIterator;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\FS\Directory;

/** Every nested directory retains the same account-root boundary. */
final class PrivateDirectory extends Directory
{
    private readonly PrivateFilesystemGuard $guard;
    private readonly bool $mountRoot;

    public function __construct(string $path, ?PrivateFilesystemGuard $guard = null)
    {
        $this->guard = $guard ?? new PrivateFilesystemGuard($path);
        $this->guard->assertSafePath($path);
        $this->mountRoot = $path === $this->guard->root();
        parent::__construct($path);
    }

    public function getName(): string
    {
        return $this->mountRoot ? 'files' : parent::getName();
    }

    public function getChild($name)
    {
        $path = $this->guard->childPath($this->path, (string) $name);
        if (!file_exists($path)) {
            throw new NotFound('WebDAV resource not found');
        }
        if (is_dir($path)) {
            return new self($path, $this->guard);
        }
        if (is_file($path)) {
            return new PrivateFile($path, $this->guard);
        }
        throw new Forbidden('Unsupported private WebDAV resource');
    }

    public function getChildren(): array
    {
        $this->guard->assertSafePath($this->path);
        $nodes = [];
        foreach (new FilesystemIterator($this->path, FilesystemIterator::SKIP_DOTS) as $entry) {
            // Hide local symlinks and unsupported resources; never traverse them.
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                continue;
            }
            $nodes[] = $this->getChild($entry->getFilename());
        }
        return $nodes;
    }

    public function childExists($name): bool
    {
        $path = $this->guard->childPath($this->path, (string) $name);
        return file_exists($path);
    }

    public function createFile($name, $data = null)
    {
        $path = $this->guard->childPath($this->path, (string) $name);
        if (file_exists($path)) {
            throw new Forbidden('Refusing to overwrite an existing WebDAV resource');
        }
        return parent::createFile($name, $data);
    }

    public function createDirectory($name)
    {
        $path = $this->guard->childPath($this->path, (string) $name);
        if (file_exists($path)) {
            throw new Forbidden('Refusing to overwrite an existing WebDAV resource');
        }
        return parent::createDirectory($name);
    }

    public function setName($name)
    {
        if ($this->mountRoot) {
            throw new Forbidden('The WebDAV mount root cannot be renamed');
        }
        $this->guard->assertSafePath($this->path);
        $target = $this->guard->childPath(dirname($this->path), (string) $name);
        if (file_exists($target)) {
            throw new Forbidden('WebDAV rename target already exists');
        }
        if (!rename($this->path, $target)) {
            throw new Forbidden('Unable to rename private WebDAV directory');
        }
        $this->path = $target;
    }

    public function delete()
    {
        if ($this->mountRoot) {
            throw new Forbidden('The WebDAV mount root cannot be deleted');
        }
        $this->guard->assertSafePath($this->path);
        parent::delete();
    }
}
