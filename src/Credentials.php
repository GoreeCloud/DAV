<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

final class Credentials
{
    public static function validUsername(string $username): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $username);
    }

    /** @return array{0:string,1:string}|null */
    public static function basicCredentials(array $server): ?array
    {
        // Apache rewrites may set both keys. A conflicting value is ambiguous:
        // never select an account credential from one competing header.
        $hasDirect = array_key_exists('HTTP_AUTHORIZATION', $server);
        $hasRedirected = array_key_exists('REDIRECT_HTTP_AUTHORIZATION', $server);
        if ($hasDirect && $hasRedirected) {
            $direct = $server['HTTP_AUTHORIZATION'];
            $redirected = $server['REDIRECT_HTTP_AUTHORIZATION'];
            if (!is_string($direct) || !is_string($redirected)
                || !hash_equals($direct, $redirected)) {
                return null;
            }
        }
        $header = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($header) || strlen($header) > 8192 || !preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/iD', $header, $matches)) {
            return null;
        }
        $decoded = base64_decode($matches[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }
        [$user, $pass] = explode(':', $decoded, 2);
        return self::validUsername($user) ? [$user, $pass] : null;
    }
}
