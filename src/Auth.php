<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

use PDO;
use Sabre\DAV\Auth\Backend\AbstractBasic;

final class Auth extends AbstractBasic
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function verify(string $username, string $password): bool
    {
        if (!Credentials::validUsername($username)) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT password_hash FROM goreecloud_accounts WHERE username = ? AND enabled = 1');
        $stmt->execute([$username]);
        $hash = $stmt->fetchColumn();
        // Dummy check limits account-existence timing differences.
        return password_verify($password, is_string($hash) ? $hash : '$argon2id$v=19$m=65536,t=4,p=1$dGt4MG40WDZqeEdUODNzWQ$1y68RQZE95dv+esIIf4HTXCgWjufdBQQPeL669qkZZg') && is_string($hash);
    }

    protected function validateUserPass($username, $password)
    {
        return $this->verify((string) $username, (string) $password);
    }
}
