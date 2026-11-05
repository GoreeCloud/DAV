<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

final class Config
{
    public static function dataDir(): string
    {
        $value = getenv('DAV_DATA_DIR');
        $path = rtrim($value !== false && $value !== '' ? $value : dirname(__DIR__).'/var', '/');
        if ($path === '' || $path[0] !== '/' || $path === '/' || str_contains($path, "\0")
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new \RuntimeException('DAV_DATA_DIR must be an absolute private data directory');
        }
        // Neither live DAV data nor backup state may be placed in the HTTP document root.
        $public = realpath(dirname(__DIR__).'/public');
        // Resolve the nearest existing ancestor even when the final directory has
        // not been created. Otherwise an uncreated path below a symlink into
        // public/ can evade the web-root check.
        $ancestor = $path;
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                break;
            }
            $ancestor = $parent;
        }
        $realAncestor = realpath($ancestor);
        if ($realAncestor === false || is_link($path)) {
            throw new \RuntimeException('DAV_DATA_DIR has an unsafe or unresolvable path');
        }
        $resolved = rtrim(rtrim($realAncestor, '/').'/'.ltrim(substr($path, strlen($ancestor)), '/'), '/');
        if ($public !== false && ($resolved === $public || str_starts_with($resolved, $public.'/'))) {
            throw new \RuntimeException('DAV_DATA_DIR must not be inside the HTTP document root');
        }
        // Do not accept private state whose apparent path traverses a symlink.
        // A linked intermediate parent is just as dangerous as a linked data root:
        // SQLite, backups and file mounts must resolve to the expected location.
        $cursor = $path;
        while ($cursor !== '' && $cursor !== '/') {
            clearstatcache(true, $cursor);
            if (is_link($cursor)) {
                throw new \RuntimeException('DAV_DATA_DIR has a symlinked ancestor');
            }
            $cursor = dirname($cursor);
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

    /**
     * Apache's LimitRequestBody is the authoritative enforcement for streamed
     * and chunked requests; this is only an early HTTP rejection for clients
     * that declare a Content-Length. Keep this constant aligned with Dockerfile.
     */
    public const MAX_REQUEST_BODY_BYTES = 67108864; // 64 MiB

    public static function requestBodyTooLarge(array $server): bool
    {
        if (!array_key_exists('CONTENT_LENGTH', $server) || $server['CONTENT_LENGTH'] === '') {
            return false;
        }
        $length = $server['CONTENT_LENGTH'];
        // Reject invalid/oversized numeric text without integer overflow.
        if (!is_string($length) && !is_int($length)) {
            return true;
        }
        $length = (string) $length;
        if (!preg_match('/^[0-9]{1,20}$/D', $length)) {
            return true;
        }
        return (float) $length > self::MAX_REQUEST_BODY_BYTES;
    }

    public static function httpsRequired(): bool
    {
        return getenv('DAV_REQUIRE_HTTPS') !== '0';
    }
}
