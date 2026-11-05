<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

use PDO;

final class Database
{
    public static function connect(bool $mustExist = true): PDO
    {
        $dir = Config::dataDir();
        $path = $dir.'/dav.sqlite';
        if ($mustExist && !is_file($path)) {
            throw new \RuntimeException('Database not initialized. Run: php bin/dav init');
        }
        if (!is_dir($dir)) {
            throw new \RuntimeException('Data directory does not exist');
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

    public static function initialize(): void
    {
        $dir = Config::dataDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create data directory');
        }
        @chmod($dir, 0700);
        $file = $dir.'/dav.sqlite';
        if (file_exists($file)) {
            throw new \RuntimeException('Database already exists; init refuses to overwrite it');
        }
        $pdo = self::connect(false);
        @chmod($file, 0600);
        try {
            $pdo->beginTransaction();
            $schemaPath = dirname(__DIR__).'/resources/sql/001-initial.sql';
            $sql = @file_get_contents($schemaPath);
            if ($sql === false) {
                throw new \RuntimeException('Missing GoreeCloud DAV schema');
            }
            // Source-controlled schema, pinned and reviewed; no bundled vendor examples.
            $sql = preg_replace('/--[^\r\n]*/', '', $sql);
            foreach (explode(';', $sql) as $statement) {
                $statement = trim($statement);
                if ($statement === '') {
                    continue;
                }
                if (!preg_match('/^CREATE\s+(?:TABLE|(?:UNIQUE\s+)?INDEX)\b/i', $statement)) {
                    throw new \RuntimeException('Unexpected operation in DAV schema');
                }
                $pdo->exec($statement);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo = null;
            @unlink($file);
            @unlink($file.'-wal');
            @unlink($file.'-shm');
            throw $e;
        }
    }
}
