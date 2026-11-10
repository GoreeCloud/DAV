<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

/**
 * Validate incremental sync tokens against collection sequence numbers.
 *
 * A future or malformed token must not silently advance a client past changes.
 * Zero/empty tokens are handled separately by SabreDAV as initial full sync.
 */
final class SyncTokenValidator
{
    public static function validIncremental(mixed $token, int $current): bool
    {
        if ((!is_string($token) && !is_int($token)) || $current < 1) {
            return false;
        }
        $value = (string) $token;
        if (!preg_match('/^[1-9][0-9]*$/D', $value)) {
            return false;
        }
        $max = (string) $current;
        return strlen($value) < strlen($max)
            || (strlen($value) === strlen($max) && strcmp($value, $max) <= 0);
    }
}
