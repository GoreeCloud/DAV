<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Config.php';
require dirname(__DIR__).'/src/Credentials.php';

use GoreeCloud\DAV\Credentials;
use GoreeCloud\DAV\Config;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expect(Credentials::validUsername('alice-1.a'), 'normal username');
foreach (['', '../admin', 'Alice', '..', '/root', 'x/y', str_repeat('a', 65)] as $bad) {
    expect(!Credentials::validUsername($bad), 'rejected dangerous username');
}
expect(Credentials::basicCredentials(['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('alice:strong:secret')]) === ['alice', 'strong:secret'], 'colon in password');
expect(Credentials::basicCredentials(['HTTP_AUTHORIZATION' => 'Bearer abc']) === null, 'reject bearer');
expect(Credentials::basicCredentials(['HTTP_AUTHORIZATION' => 'Basic ?']) === null, 'reject invalid base64');
expect(Credentials::basicCredentials(['HTTP_AUTHORIZATION' => 'Basic '.str_repeat('A', 8193)]) === null, 'reject oversized auth header');
putenv('DAV_DATA_DIR=relative');
try {
    Config::dataDir();
    throw new RuntimeException('relative data dir accepted');
} catch (RuntimeException $e) {
    expect(str_contains($e->getMessage(), 'DAV_DATA_DIR'), 'reject relative data dir');
}
putenv('DAV_DATA_DIR');
expect(!Config::isSecure(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https']), 'do not trust arbitrary proxy headers');
expect(Config::isSecure(['HTTPS' => 'on']), 'recognize direct TLS');
putenv('DAV_BASE_URI=/dav/');
expect(Config::baseUri() === '/dav/', 'base URI');
putenv('DAV_BASE_URI=//evil');
try {
    Config::baseUri();
    throw new RuntimeException('invalid URI was accepted');
} catch (RuntimeException $e) {
    expect($e->getMessage() === 'Invalid DAV_BASE_URI', 'reject unsafe URI');
}
putenv('DAV_BASE_URI');
echo "Security unit tests passed.\n";
