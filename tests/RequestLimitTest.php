<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Config.php';

use GoreeCloud\DAV\Config;

function assertBodyLimit(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

assertBodyLimit(Config::MAX_REQUEST_BODY_BYTES === 64 * 1024 * 1024, 'fixed size cap');
foreach ([[], ['CONTENT_LENGTH' => ''], ['CONTENT_LENGTH' => '0'],
    ['CONTENT_LENGTH' => '1'], ['CONTENT_LENGTH' => '67108864'],
    ['CONTENT_LENGTH' => 67108864]] as $allowed) {
    assertBodyLimit(!Config::requestBodyTooLarge($allowed), 'valid in-range Content-Length');
}
foreach ([['CONTENT_LENGTH' => '67108865'], ['CONTENT_LENGTH' => '99999999999999999999'],
    ['CONTENT_LENGTH' => '-1'], ['CONTENT_LENGTH' => 'abc'], ['CONTENT_LENGTH' => '1,2'],
    ['CONTENT_LENGTH' => ['20']], ['CONTENT_LENGTH' => '1.5'], ['CONTENT_LENGTH' => 67108865]] as $rejected) {
    assertBodyLimit(Config::requestBodyTooLarge($rejected), 'reject invalid or too-large Content-Length');
}
// Absent Content-Length is valid for chunked requests: Apache's LimitRequestBody
// must enforce the actual streamed byte count. PHP alone cannot guarantee it.
assertBodyLimit(!Config::requestBodyTooLarge(['HTTP_TRANSFER_ENCODING' => 'chunked']), 'chunked delegated to Apache');

echo "Request body limit tests passed.\n";
