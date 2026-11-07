<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

use Sabre\DAV\Exception\Forbidden;

/**
 * Filesystem boundary for GoreeCloud's private per-user WebDAV roots.
 *
 * Prevents a pre-existing symlink or path traversal from reaching outside the
 * assigned user root. This guard assumes the containing data volume has no
 * untrusted concurrent local writers (there is no race-free PHP openat API).
 */
final class PrivateFilesystemGuard
{
    private readonly string $root;

    public function __construct(string $directory)
    {
        if ($directory === '' || $directory[0] !== '/' || !is_dir($directory)) {
            throw new Forbidden('Private WebDAV root must be an absolute directory');
        }
        // realpath() resolves linked *parents* as well as a linked final node.
        // Checking only is_link($directory) lets /data/files -> /outside map
        // /data/files/alice to an arbitrary external account root.
        $ancestor = rtrim($directory, '/');
        while ($ancestor !== '' && $ancestor !== '/') {
            clearstatcache(true, $ancestor);
            if (is_link($ancestor)) {
                throw new Forbidden('Symlinked parent of private WebDAV root');
            }
            $ancestor = dirname($ancestor);
        }
        $real = realpath($directory);
        if ($real === false || $real === '/') {
            throw new Forbidden('Invalid private WebDAV root');
        }
        $this->root = $real;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function childPath(string $directory, string $name): string
    {
        if ($name === '' || $name === '.' || $name === '..'
            || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new Forbidden('Invalid WebDAV resource name');
        }
        $this->assertSafePath($directory);
        if (!is_dir($directory)) {
            throw new Forbidden('Parent is not a directory');
        }
        $path = $directory.'/'.$name;
        $this->assertSafePath($path);
        return $path;
    }

    public function assertSafePath(string $path): void
    {
        if ($path === $this->root) {
            if (!is_dir($path) || is_link($path)) {
                throw new Forbidden('Private WebDAV root is unavailable');
            }
            return;
        }
        if (!str_starts_with($path, $this->root.'/')) {
            throw new Forbidden('WebDAV resource is outside the private root');
        }
        $relative = substr($path, strlen($this->root) + 1);
        $segments = explode('/', $relative);
        $cursor = $this->root;
        foreach ($segments as $index => $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '\\') || str_contains($segment, "\0")) {
                throw new Forbidden('Invalid WebDAV resource path');
            }
            $cursor .= '/'.$segment;
            clearstatcache(true, $cursor);
            if (is_link($cursor)) {
                throw new Forbidden('Symlinks are forbidden in private WebDAV storage');
            }
            if ($index < count($segments) - 1 && file_exists($cursor) && !is_dir($cursor)) {
                throw new Forbidden('Non-directory WebDAV ancestor');
            }
        }
    }
}
