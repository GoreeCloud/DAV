<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

use PDO;

final class Database
{
    /** Account credential registry. Never expose this database through WebDAV. */
    public static function connect(bool $mustExist = true): PDO
    {
        return self::sqlite(Config::dataDir().'/dav.sqlite', $mustExist);
    }

    /** The verified account's own DAV metadata, physically separate from every other account. */
    public static function userDatabase(string $username): PDO
    {
        if (!Credentials::validUsername($username)) {
            throw new \InvalidArgumentException('Invalid account');
        }
        return self::sqlite(self::userDbPath($username), true);
    }

    private static function userDbPath(string $username): string
    {
        return Config::dataDir().'/metadata/'.$username.'/dav.sqlite';
    }

    private static function sqlite(string $path, bool $mustExist): PDO
    {
        self::assertPrivateStoragePath($path);
        if (!is_dir(dirname($path)) || is_link($path) || ($mustExist && !is_file($path))) {
            throw new \RuntimeException('DAV database missing; initialize service or account first');
        }
        $pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        return $pdo;
    }

    /**
     * Reject linked SQLite registry/metadata components, not just a linked
     * database file. This must be checked before opening SQLite, which would
     * otherwise follow an ancestor symlink to an untrusted database.
     * Host storage permissions must also prevent local symlink-swap races.
     */
    public static function assertPrivateStoragePath(string $path): void
    {
        $root = Config::dataDir();
        if (is_link($root) || !is_dir($root) || !str_starts_with($path, $root.'/')) {
            throw new \RuntimeException('DAV database path is outside private storage');
        }
        $relative = substr($path, strlen($root) + 1);
        $cursor = $root;
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                throw new \RuntimeException('Invalid DAV database path');
            }
            $cursor .= '/'.$segment;
            clearstatcache(true, $cursor);
            if (is_link($cursor)) {
                throw new \RuntimeException('Symlink in DAV database path');
            }
        }
    }

    public static function initialize(): void
    {
        $dir = Config::dataDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create data directory');
        }
        @chmod($dir, 0700);
        self::createDatabase($dir.'/dav.sqlite', dirname(__DIR__).'/resources/sql/002-accounts.sql');
    }

    /** Creates an isolated metadata database for a newly provisioned user. */
    public static function createUserDatabase(string $username): PDO
    {
        if (!Credentials::validUsername($username)) {
            throw new \InvalidArgumentException('Invalid account');
        }
        $path = self::userDbPath($username);
        $dir = dirname($path);
        if (file_exists($dir)) {
            throw new \RuntimeException('Account metadata already exists; refusing to reuse it');
        }
        if (!mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Could not create account metadata directory');
        }
        try {
            return self::createDatabase($path, dirname(__DIR__).'/resources/sql/001-initial.sql');
        } catch (\Throwable $e) {
            @rmdir($dir);
            throw $e;
        }
    }

    private static function createDatabase(string $path, string $schemaPath): PDO
    {
        if (file_exists($path)) {
            throw new \RuntimeException('Database already exists; refusing to overwrite it');
        }
        $pdo = self::sqlite($path, false);
        @chmod($path, 0600);
        try {
            $sql = @file_get_contents($schemaPath);
            if ($sql === false) {
                throw new \RuntimeException('Missing pinned GoreeCloud DAV schema');
            }
            $pdo->beginTransaction();
            $sql = preg_replace('/--[^\r\n]*/', '', $sql);
            foreach (explode(';', $sql) as $statement) {
                $statement = trim($statement);
                if ($statement === '') {
                    continue;
                }
                if (!preg_match('/^CREATE\s+(?:TABLE|(?:UNIQUE\s+)?INDEX)\b/i', $statement)) {
                    throw new \RuntimeException('Unexpected operation in schema');
                }
                $pdo->exec($statement);
            }
            $pdo->commit();
            return $pdo;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo = null;
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
            throw $e;
        }
    }
}
