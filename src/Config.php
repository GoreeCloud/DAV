<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

final class Config
{
    public static function dataDir(): string
    {
        $value = getenv('DAV_DATA_DIR');
        $path = rtrim($value !== false && $value !== '' ? $value : dirname(__DIR__).'/var', '/');
        if ($path === '' || $path[0] !== '/' || $path === '/var/www/html' || str_contains($path, '..')) {
            throw new \RuntimeException('DAV_DATA_DIR must be an absolute private data directory');
        }
        return $path;
    }

    public static function baseUri(): string
    {
        $value = getenv('DAV_BASE_URI');
        $base = $value !== false && $value !== '' ? $value : '/';
        if ($base[0] !== '/' || str_contains($base, '?') || str_contains($base, '#') || str_contains($base, '//') || str_contains($base, '..')) {
            throw new \RuntimeException('Invalid DAV_BASE_URI');
        }
        return rtrim($base, '/').'/';
    }

    public static function isSecure(array $server): bool
    {
        if (($server['HTTPS'] ?? '') === 'on' || ($server['HTTPS'] ?? '') === '1') {
            return true;
        }
        $proxies = array_filter(array_map('trim', explode(',', getenv('DAV_TRUSTED_PROXIES') ?: '')));
        if (in_array($server['REMOTE_ADDR'] ?? '', $proxies, true)) {
            return strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        }
        return false;
    }

    public static function httpsRequired(): bool
    {
        return getenv('DAV_REQUIRE_HTTPS') !== '0';
    }
}
