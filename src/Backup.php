<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/** Operator-only consistent snapshot of all local GoreeCloud DAV state. */
final class Backup
{
    /** @return array{files:int,users:int,bytes:int} */
    public static function create(string $destination): array
    {
        $source = realpath(Config::dataDir());
        if ($source === false || !is_file($source.'/dav.sqlite')) {
            throw new \RuntimeException('Initialized DAV data directory required');
        }
        self::validateDestination($destination, $source);
        // Exclusive only blocks HTTP/CLI processes participating in ServiceLock.
        // Operators must stop outside writers and stop/drain the service for a true offline backup.
        $lock = ServiceLock::exclusive();
        try {
            $accounts = Database::connect()->query('SELECT username FROM goreecloud_accounts ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);
            if (!is_array($accounts)) {
                throw new \RuntimeException('Unable to enumerate DAV accounts');
            }
            foreach ($accounts as $account) {
                if (!is_string($account) || !Credentials::validUsername($account)) {
                    throw new \RuntimeException('Invalid account name in storage');
                }
                if (!is_file($source.'/metadata/'.$account.'/dav.sqlite') || !is_dir($source.'/files/'.$account)) {
                    throw new \RuntimeException('Incomplete account storage; aborting backup');
                }
            }
            self::verifyStorageIndex($source, $accounts);
            if (!mkdir($destination, 0700)) {
                throw new \RuntimeException('Cannot create destination');
            }
            $entries = [];
            $directories = [];
            self::snapshotSqlite(Database::connect(), $destination.'/dav.sqlite', 'dav.sqlite', $entries);
            foreach ($accounts as $account) {
                $metadataName = 'metadata/'.$account.'/dav.sqlite';
                self::makePrivateDir($destination.'/metadata/'.$account);
                self::snapshotSqlite(Database::userDatabase($account), $destination.'/'.$metadataName, $metadataName, $entries);
                self::copyFiles($source.'/files/'.$account, $destination.'/files/'.$account, 'files/'.$account, $entries, $directories);
            }
            ksort($entries);
            sort($directories, SORT_STRING);
            $manifest = [
                'format' => 'goreecloud-dav-backup-v2',
                'created_utc' => gmdate('c'),
                'users' => $accounts,
                'directories' => $directories,
                'sha256' => $entries,
            ];
            if (file_put_contents($destination.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write backup manifest');
            }
            @chmod($destination.'/manifest.json', 0600);
            $result = self::verify($destination);
            $result['users'] = count($accounts);
            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Verify checksum integrity AND completeness of a pristine offline snapshot.
     * This is not authenticity verification: an attacker who can modify the manifest
     * can recalculate unkeyed hashes. Keep trusted copies of manifests externally.
     *
     * @return array{files:int,bytes:int}
     */
    public static function verify(string $directory): array
    {
        // Do not certify a snapshot whose apparent parent redirects through a
        // symlink. realpath() would otherwise hide the redirection, allowing an
        // operator to verify (and later restore) the wrong directory.
        if ($directory === '' || $directory[0] !== '/' || str_contains($directory, "\0")
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $directory)) {
            throw new \RuntimeException('Backup verification requires an absolute directory without dot segments');
        }
        $cursor = rtrim($directory, '/');
        while ($cursor !== '' && $cursor !== '/') {
            clearstatcache(true, $cursor);
            if (is_link($cursor)) {
                throw new \RuntimeException('Symlink in backup directory path');
            }
            $cursor = dirname($cursor);
        }
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('Backup directory missing');
        }
        $raw = @file_get_contents($root.'/manifest.json');
        if ($raw === false || strlen($raw) > 4000000 || is_link($root.'/manifest.json')) {
            throw new \RuntimeException('Missing, linked or oversized backup manifest');
        }
        $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'goreecloud-dav-backup-v2'
            || !is_array($manifest['sha256'] ?? null) || !is_array($manifest['users'] ?? null)
            || !is_array($manifest['directories'] ?? null)) {
            throw new \RuntimeException('Unsupported backup manifest');
        }
        $users = $manifest['users'];
        $known = [];
        foreach ($users as $user) {
            if (!is_string($user) || !Credentials::validUsername($user) || isset($known[$user])) {
                throw new \RuntimeException('Invalid or duplicate backup account');
            }
            $known[$user] = true;
        }
        $directories = [];
        foreach ($manifest['directories'] as $directoryName) {
            if (!is_string($directoryName)
                || !preg_match('~^files/[a-z0-9][a-z0-9._-]{0,63}(?:/[^/]+)*$~D', $directoryName)
                || str_contains('/'.$directoryName.'/', '/../')
                || str_contains('/'.$directoryName.'/', '/./')
                || isset($directories[$directoryName])) {
                throw new \RuntimeException('Invalid or duplicate backup directory entry');
            }
            $segments = explode('/', $directoryName, 3);
            if (!isset($known[$segments[1]]) || !is_dir($root.'/'.$directoryName)
                || !self::hasNoSymlinkAncestor($root, $directoryName)) {
                throw new \RuntimeException('Missing, linked or unlisted account directory');
            }
            $directories[$directoryName] = true;
        }
        $entries = $manifest['sha256'];
        if (!array_key_exists('dav.sqlite', $entries)) {
            throw new \RuntimeException('Backup manifest missing registry database');
        }
        foreach ($users as $user) {
            if (!isset($entries['metadata/'.$user.'/dav.sqlite']) || !isset($directories['files/'.$user])) {
                throw new \RuntimeException('Backup manifest missing account storage');
            }
        }
        $bytes = 0;
        foreach ($entries as $name => $expected) {
            if (!is_string($name) || !preg_match('~^(?:dav\.sqlite|metadata/[a-z0-9][a-z0-9._-]{0,63}/dav\.sqlite|files/[a-z0-9][a-z0-9._-]{0,63}(?:/[^/]+)+)$~D', $name)
                || str_contains('/'.$name.'/', '/../') || str_contains('/'.$name.'/', '/./')
                || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D', $expected)) {
                throw new \RuntimeException('Invalid backup manifest entry');
            }
            if (str_starts_with($name, 'metadata/') || str_starts_with($name, 'files/')) {
                $segments = explode('/', $name, 3);
                if (!isset($known[$segments[1]])) {
                    throw new \RuntimeException('Backup resource belongs to an unlisted account');
                }
            }
            // Every nested file needs a corresponding recorded parent directory.
            if (str_starts_with($name, 'files/')) {
                $parent = dirname($name);
                while ($parent !== 'files' && $parent !== '.') {
                    if (!isset($directories[$parent])) {
                        throw new \RuntimeException('Backup manifest missing file parent directory');
                    }
                    $parent = dirname($parent);
                }
            }
            $path = $root.'/'.$name;
            if (is_link($path) || !is_file($path) || !self::hasNoSymlinkAncestor($root, $name)) {
                throw new \RuntimeException('Backup resource missing or linked: '.$name);
            }
            if (!hash_equals($expected, hash_file('sha256', $path))) {
                throw new \RuntimeException('Backup checksum mismatch: '.$name);
            }
            $bytes += filesize($path);
        }
        // SHA-256 checks alone cannot detect an omitted resource if the manifest was
        // truncated. Also inspect the actual tree for unlisted files and symlinks.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new \RuntimeException('Symlink in backup directory');
            }
            $relative = substr($item->getPathname(), strlen($root) + 1);
            if ($item->isDir()) {
                $metadataUser = str_starts_with($relative, 'metadata/')
                    ? substr($relative, strlen('metadata/')) : '';
                if ($relative !== 'metadata' && $relative !== 'files'
                    && !($metadataUser !== '' && !str_contains($metadataUser, '/') && isset($known[$metadataUser]))
                    && !isset($directories[$relative])) {
                    throw new \RuntimeException('Unlisted directory in backup: '.$relative);
                }
            }
            if ($item->isFile() && $relative !== 'manifest.json' && !array_key_exists($relative, $entries)) {
                throw new \RuntimeException('Unlisted resource in backup directory: '.$relative);
            }
            if (!$item->isDir() && !$item->isFile()) {
                throw new \RuntimeException('Unsupported resource in backup directory');
            }
        }
        // A correct SHA-256 inventory can still describe a corrupted SQLite
        // database, or a registry whose accounts disagree with the manifest.
        // Do not approve restoration solely from hash/inventory checks.
        self::verifySqliteSnapshots($root, $users);
        return ['files' => count($entries), 'bytes' => $bytes];
    }

    /**
     * Verify that every offline SQLite file is readable, structurally sound,
     * has the expected DAV schema, and agrees with the account manifest.
     * This is a consistency check, not a cryptographic authenticity proof.
     * Never open a running service data directory here.
     */
    private static function verifySqliteSnapshots(string $root, array $manifestUsers): void
    {
        $required = [
            'dav.sqlite' => ['goreecloud_accounts'],
        ];
        foreach ($manifestUsers as $user) {
            $required['metadata/'.$user.'/dav.sqlite'] = [
                'principals', 'calendars', 'calendarinstances',
                'calendarobjects', 'calendarchanges', 'addressbooks',
                'cards', 'addressbookchanges', 'locks',
            ];
        }

        $sortedManifestUsers = $manifestUsers;
        sort($sortedManifestUsers, SORT_STRING);
        foreach ($required as $relative => $tables) {
            $pdo = null;
            try {
                $pdo = new PDO('sqlite:'.$root.'/'.$relative, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
                $pdo->exec('PRAGMA query_only = ON');
                if ($pdo->query('PRAGMA integrity_check(1)')->fetchColumn() !== 'ok') {
                    throw new \RuntimeException('SQLite integrity_check failed');
                }
                if ($pdo->query('PRAGMA foreign_key_check')->fetch(PDO::FETCH_NUM) !== false) {
                    throw new \RuntimeException('SQLite foreign_key_check failed');
                }

                $present = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")
                    ->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    if (!in_array($table, $present, true)) {
                        throw new \RuntimeException('SQLite required schema is incomplete');
                    }
                }

                if ($relative === 'dav.sqlite') {
                    $actual = $pdo->query('SELECT username FROM goreecloud_accounts ORDER BY username')
                        ->fetchAll(PDO::FETCH_COLUMN);
                    if ($actual !== $sortedManifestUsers) {
                        throw new \RuntimeException('SQLite account registry disagrees with backup manifest');
                    }
                }
            } catch (\Throwable $error) {
                throw new \RuntimeException('SQLite backup validation failed for '.$relative, 0, $error);
            } finally {
                $pdo = null;
            }
        }
    }

    private static function validateDestination(string $path, string $source): void
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '/./') || str_contains($path, '/../') || str_ends_with($path, '/..') || str_ends_with($path, '/.')) {
            throw new \InvalidArgumentException('Backup destination must be a new absolute directory without dot segments');
        }
        // realpath() silently follows symlinked ancestors. A snapshot requested
        // under /backups/alias/snapshot must not end up in the alias target.
        // Reject all linked path components *before* resolving the parent.
        $ancestor = dirname($path);
        while ($ancestor !== '/' && $ancestor !== '') {
            clearstatcache(true, $ancestor);
            if (is_link($ancestor)) {
                throw new \InvalidArgumentException('Backup destination parent must not contain symlinks');
            }
            $ancestor = dirname($ancestor);
        }
        $parent = realpath(dirname($path));
        if ($parent === false || !is_dir($parent)) {
            throw new \InvalidArgumentException('Backup destination parent must already exist');
        }
        $resolved = $parent.'/'.basename($path);
        if (file_exists($resolved) || is_link($resolved) || $parent === $source || str_starts_with($parent, $source.'/')) {
            throw new \InvalidArgumentException('Backup destination must not exist or be inside the DAV data directory');
        }
        $webroot = realpath(dirname(__DIR__).'/public');
        if ($webroot !== false && ($parent === $webroot || str_starts_with($parent, $webroot.'/'))) {
            throw new \InvalidArgumentException('Backup destination must not be inside the HTTP document root');
        }
    }

    private static function verifyStorageIndex(string $source, array $accounts): void
    {
        $known = array_fill_keys($accounts, true);
        foreach (['metadata', 'files'] as $type) {
            $root = $source.'/'.$type;
            // A newly initialized instance has no accounts or per-user roots yet.
            if ($accounts === [] && !file_exists($root) && !is_link($root)) {
                continue;
            }
            if (!is_dir($root) || is_link($root)) {
                throw new \RuntimeException('Account storage root is missing or symlinked');
            }
            foreach (new \DirectoryIterator($root) as $item) {
                if ($item->isDot()) {
                    continue;
                }
                if ($item->isLink() || !$item->isDir() || !isset($known[$item->getFilename()])) {
                    throw new \RuntimeException('Unexpected or orphaned account storage; manual review required');
                }
            }
        }
    }

    private static function snapshotSqlite(PDO $db, string $path, string $name, array &$entries): void
    {
        $db->exec('VACUUM INTO '.$db->quote($path));
        @chmod($path, 0600);
        $entries[$name] = hash_file('sha256', $path);
    }

    private static function makePrivateDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Could not create backup subdirectory');
        }
        @chmod($path, 0700);
    }

    private static function copyFiles(string $source, string $target, string $prefix, array &$entries, array &$directories): void
    {
        if (is_link($source)) {
            throw new \RuntimeException('Refusing linked user file directory');
        }
        self::makePrivateDir($target);
        $directories[] = $prefix;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new \RuntimeException('Refusing symlinks in WebDAV data');
            }
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $name = $prefix.'/'.$relative;
            if ($item->isDir()) {
                self::makePrivateDir($target.'/'.$relative);
                $directories[] = $name;
            } elseif ($item->isFile()) {
                self::makePrivateDir(dirname($target.'/'.$relative));
                if (!copy($item->getPathname(), $target.'/'.$relative)) {
                    throw new \RuntimeException('Could not copy WebDAV file');
                }
                @chmod($target.'/'.$relative, 0600);
                $entries[$name] = hash_file('sha256', $target.'/'.$relative);
            } else {
                throw new \RuntimeException('Unsupported filesystem entry in WebDAV data');
            }
        }
    }

    private static function hasNoSymlinkAncestor(string $root, string $relative): bool
    {
        $prefix = $root;
        foreach (explode('/', $relative) as $part) {
            $prefix .= '/'.$part;
            if (is_link($prefix)) {
                return false;
            }
        }
        return true;
    }
}
