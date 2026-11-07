<?php

declare(strict_types=1);

use GoreeCloud\DAV\Auth;
use GoreeCloud\DAV\Config;
use GoreeCloud\DAV\Credentials;
use GoreeCloud\DAV\Database;
use GoreeCloud\DAV\ServerFactory;
use GoreeCloud\DAV\ServiceLock;

require dirname(__DIR__).'/vendor/autoload.php';
date_default_timezone_set('UTC');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

try {
    $uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $uri = is_string($uri) ? $uri : '/';
    $base = Config::baseUri();
    if (Config::requestBodyTooLarge($_SERVER)) {
        http_response_code(413);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'DAV request body exceeds 64 MiB limit.';
        exit;
    }
    if ($uri === '/healthz' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        header('Content-Type: application/json');
        echo json_encode(['service' => 'goreecloud-dav', 'status' => 'up'], JSON_THROW_ON_ERROR);
        exit;
    }
    // Discovery must obey the same transport policy as all DAV operations.
    // Keep only the local GET health probe outside this TLS gate.
    if (Config::httpsRequired() && !Config::isSecure($_SERVER)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'TLS is required for DAV access.';
        exit;
    }
    if ($uri === '/.well-known/caldav' || $uri === '/.well-known/carddav') {
        header('Location: '.$base, true, 308);
        exit;
    }
    // A shared lock lasts for the entire DAV request, including streamed writes.
    // Operator snapshots hold an exclusive lock until metadata and files are copied.
    $serviceLock = ServiceLock::shared();
    $pdo = Database::connect();
    $auth = new Auth($pdo);
    $credentials = Credentials::basicCredentials($_SERVER);
    if ($credentials === null || !$auth->verify(...$credentials)) {
        header('WWW-Authenticate: Basic realm="GoreeCloud DAV", charset="UTF-8"');
        http_response_code(401);
        exit;
    }
    $server = ServerFactory::create($pdo, $auth, $credentials[0]);
    $server->start();
} catch (Throwable $e) {
    error_log('GoreeCloud DAV request failed: '.get_class($e));
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'DAV service error';
}
