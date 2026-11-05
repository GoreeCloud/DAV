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
            $sqlDir = dirname(__DIR__).'/vendor/sabre/dav/examples/sql';
            foreach (['principals', 'calendars', 'addressbooks', 'locks'] as $area) {
                $schemaPath = $sqlDir.'/sqlite.'.$area.'.sql';
                $sql = @file_get_contents($schemaPath);
                if ($sql === false) {
                    throw new \RuntimeException('Missing SabreDAV schema: '.$schemaPath);
                }
                // Dependency example schemas contain demo users. Only execute CREATE statements.
                $sql = preg_replace('/--[^\r\n]*/', '', $sql);
                foreach (explode(';', $sql) as $statement) {
                    $statement = trim($statement);
                    if ($statement === '' || preg_match('/^INSERT\s+INTO\b/i', $statement)) {
                        continue;
                    }
                    if (!preg_match('/^CREATE\s+(?:TABLE|(?:UNIQUE\s+)?INDEX)\b/i', $statement)) {
                        throw new \RuntimeException('Unexpected operation in vendor schema for '.$area);
                    }
                    $pdo->exec($statement);
                }
            }
            $pdo->exec('CREATE TABLE goreecloud_accounts (username TEXT PRIMARY KEY NOT NULL, password_hash TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0,1)), created_at INTEGER NOT NULL)');
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
