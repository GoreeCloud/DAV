<?php

declare(strict_types=1);

// Local unit test without a Composer install. Mimic the upstream SabreDAV
// filesystem file's inode+size+mtime ETag to demonstrate its collision.
namespace Sabre\DAV\Exception {
    class Forbidden extends \RuntimeException {}
}

namespace Sabre\DAV\FS {
    class File
    {
        protected string $path;
        public function __construct(string $path) { $this->path = $path; }
        public function getETag()
        {
            return '"'.sha1(fileinode($this->path).filesize($this->path).filemtime($this->path)).'"';
        }
    }
}

namespace {
    require dirname(__DIR__).'/src/PrivateFilesystemGuard.php';
    require dirname(__DIR__).'/src/PrivateFile.php';

    use GoreeCloud\DAV\PrivateFilesystemGuard;
    use GoreeCloud\DAV\PrivateFile;

    $root = sys_get_temp_dir().'/dav-etag-'.bin2hex(random_bytes(9));
    if (!mkdir($root, 0700)) { throw new \RuntimeException('Cannot create test directory'); }
    $path = $root.'/same-length.txt';
    try {
        file_put_contents($path, 'A12');
        $guard = new PrivateFilesystemGuard($root);
        $file = new PrivateFile($path, $guard);
        $legacy = new \Sabre\DAV\FS\File($path);
        $firstLegacy = $legacy->getETag();
        $first = $file->getETag();
        if ($first !== '"'.hash('sha256', 'A12').'"') {
            throw new \RuntimeException('Initial strong ETag does not match resource bytes');
        }
        $mtime = filemtime($path);
        // Simulate replacement of the same-sized file with a preserved mtime.
        file_put_contents($path, 'B34');
        touch($path, $mtime);
        clearstatcache(true, $path);
        if ($legacy->getETag() !== $firstLegacy) {
            throw new \RuntimeException('Legacy metadata ETag collision did not reproduce');
        }
        $next = $file->getETag();
        if ($next === $first || $next !== '"'.hash('sha256', 'B34').'"') {
            throw new \RuntimeException('Strong ETag did not change when bytes changed');
        }
        // Pre-existing symlinks are never followed for metadata reads.
        $outside = $root.'-outside';
        file_put_contents($outside, 'foreign');
        unlink($path);
        symlink($outside, $path);
        try {
            $file->getETag();
            throw new \RuntimeException('File ETag followed a symlink');
        } catch (\Sabre\DAV\Exception\Forbidden $expected) {
            // expected
        }
        unlink($outside);
        echo "WebDAV strong content ETag regression tests passed.\n";
    } finally {
        if (is_file($path) || is_link($path)) { unlink($path); }
        if (is_dir($root)) { rmdir($root); }
    }
}
