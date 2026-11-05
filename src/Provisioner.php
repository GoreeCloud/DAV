<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

use PDO;
use Sabre\CalDAV;
use Sabre\CardDAV;

final class Provisioner
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function add(string $username, string $password): void
    {
        if (!Credentials::validUsername($username)) {
            throw new \InvalidArgumentException('Username must be lowercase alphanumeric, optionally followed by . _ or -, max 64 characters');
        }
        if (strlen($password) < 12 || strlen($password) > 1024) {
            throw new \InvalidArgumentException('Password must contain 12–1024 bytes');
        }
        $files = Config::dataDir().'/files/'.$username;
        $metadata = Config::dataDir().'/metadata/'.$username;
        if (file_exists($files) || file_exists($metadata)) {
            throw new \RuntimeException('Account storage already exists; refusing to reuse it');
        }
        if (!mkdir($files, 0700, true)) {
            throw new \RuntimeException('Could not create account files directory');
        }

        $userPdo = null;
        try {
            $userPdo = Database::createUserDatabase($username);
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare('INSERT INTO goreecloud_accounts (username,password_hash,created_at) VALUES (?,?,?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_ARGON2ID), time()]);

            $principal = 'principals/'.$username;
            $userPdo->beginTransaction();
            $stmt = $userPdo->prepare('INSERT INTO principals (uri,displayname) VALUES (?,?)');
            $stmt->execute([$principal, $username]);
            $stmt->execute([$principal.'/calendar-proxy-read', $username.' calendar proxy read']);
            $stmt->execute([$principal.'/calendar-proxy-write', $username.' calendar proxy write']);
            $cal = new CalDAV\Backend\PDO($userPdo);
            $cal->createCalendar($principal, 'default', ['{DAV:}displayname' => 'Calendar']);
            $book = new CardDAV\Backend\PDO($userPdo);
            $book->createAddressBook($principal, 'default', ['{DAV:}displayname' => 'Contacts']);
            $userPdo->commit();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($userPdo !== null && $userPdo->inTransaction()) {
                $userPdo->rollBack();
            }
            $userPdo = null;
            // Cleanup only directories created during this failed account addition.
            foreach (['/dav.sqlite-shm', '/dav.sqlite-wal', '/dav.sqlite'] as $suffix) {
                @unlink($metadata.$suffix);
            }
            @rmdir($metadata);
            @rmdir($files);
            throw $e;
        }
    }

    public function rotate(string $username, string $password): void
    {
        if (!Credentials::validUsername($username) || strlen($password) < 12 || strlen($password) > 1024) {
            throw new \InvalidArgumentException('Invalid username or password length');
        }
        $stmt = $this->pdo->prepare('UPDATE goreecloud_accounts SET password_hash=? WHERE username=?');
        $stmt->execute([password_hash($password, PASSWORD_ARGON2ID), $username]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Unknown account');
        }
    }

    public function setEnabled(string $username, bool $enabled): void
    {
        if (!Credentials::validUsername($username)) {
            throw new \InvalidArgumentException('Invalid username');
        }
        $stmt = $this->pdo->prepare('UPDATE goreecloud_accounts SET enabled=? WHERE username=?');
        $stmt->execute([$enabled ? 1 : 0, $username]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Unknown account or status unchanged');
        }
    }

    public function users(): array
    {
        return $this->pdo->query('SELECT username, enabled, created_at FROM goreecloud_accounts ORDER BY username')->fetchAll();
    }
}
