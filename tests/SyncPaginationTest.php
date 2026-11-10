<?php

declare(strict_types=1);

// Disposable PDO SQLite change-history test: synthetic data, no HTTP/network.
require dirname(__DIR__).'/vendor/autoload.php';

use GoreeCloud\DAV\PaginatedCardDavBackend;
use GoreeCloud\DAV\ValidatedCalendarBackend;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('CREATE TABLE addressbooks (id INTEGER PRIMARY KEY, synctoken INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE addressbookchanges (
    id INTEGER PRIMARY KEY, uri TEXT, synctoken INTEGER NOT NULL,
    addressbookid INTEGER NOT NULL, operation INTEGER NOT NULL
)');
$pdo->exec('CREATE INDEX addressbookid_synctoken ON addressbookchanges (addressbookid, synctoken)');
$pdo->exec('INSERT INTO addressbooks (id, synctoken) VALUES (1, 521), (2, 1)');
$insert = $pdo->prepare(
    'INSERT INTO addressbookchanges (uri, synctoken, addressbookid, operation) VALUES (?, ?, 1, ?)'
);
for ($n = 1; $n <= 450; ++$n) {
    $insert->execute([sprintf('contact-%03d.vcf', $n), $n, 1]);
}
for ($n = 1; $n <= 60; ++$n) {
    $insert->execute([sprintf('contact-%03d.vcf', $n), 450 + $n, 2]);
}
for ($n = 1; $n <= 10; ++$n) {
    $insert->execute([sprintf('contact-%03d.vcf', 440 + $n), 510 + $n, 3]);
}

$backend = new PaginatedCardDavBackend($pdo);
$one = $backend->getChangesForAddressBook(1, '1', 1, 1);
check($one !== null && $one['result_truncated'], 'One-item page not marked as incomplete');
check((int) $one['syncToken'] === 2 && count($one['added']) === 1, 'One-item page advanced too far');

$token = 1;
$states = [];
$pages = 0;
while (true) {
    $result = $backend->getChangesForAddressBook(1, (string) $token, 1, 17);
    check(is_array($result), 'Unexpected invalid pagination token');
    ++$pages;
    check($pages < 100, 'Change-history pagination failed to complete');
    $batchSize = count($result['added']) + count($result['modified']) + count($result['deleted']);
    check($batchSize > 0 && $batchSize <= 17, 'Bounded change response exceeded limit or was empty');
    $next = (int) $result['syncToken'];
    check($next > $token && $next <= 521, 'Change page advanced to an unsafe token');
    foreach (['added', 'modified', 'deleted'] as $operation) {
        foreach ($result[$operation] as $uri) {
            $states[$uri] = $operation;
        }
    }
    $token = $next;
    if (!$result['result_truncated']) {
        break;
    }
}
check($token === 521, 'Final sync token incorrect');
check(count($states) === 450, 'Bounded pages omitted distinct contacts');
for ($n = 1; $n <= 450; ++$n) {
    $uri = sprintf('contact-%03d.vcf', $n);
    $expected = $n <= 60 ? 'modified' : ($n > 440 ? 'deleted' : 'added');
    check(($states[$uri] ?? null) === $expected, 'Wrong final state for '.$uri);
}
$bob = $backend->getChangesForAddressBook(2, '1', 1, 17);
check($bob !== null && !$bob['added'] && !$bob['modified'] && !$bob['deleted'], 'Other account leaked changes');
check($backend->getChangesForAddressBook(1, '999999999', 1, 17) === null, 'Future CardDAV token accepted');
check($backend->getChangesForAddressBook(1, '1 OR 1=1', 1, 17) === null, 'Malformed CardDAV token accepted');

// Simulate authorized history pruning in an isolated in-memory fixture only.
$pdo->exec('DELETE FROM addressbookchanges WHERE addressbookid = 1 AND synctoken < 400');
check($backend->getChangesForAddressBook(1, '1', 1, 17) === null, 'Expired CardDAV token accepted');
check($backend->getChangesForAddressBook(1, '400', 1, 17) !== null, 'Retained CardDAV history rejected');
check($backend->getChangesForAddressBook(1, '521', 1, 17) !== null, 'Current CardDAV token rejected');

// Test the CalDAV wrapper with a minimal synthetic change table.
$pdo->exec('CREATE TABLE calendars (id INTEGER PRIMARY KEY, synctoken INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE calendarchanges (
    id INTEGER PRIMARY KEY, uri TEXT, synctoken INTEGER NOT NULL,
    calendarid INTEGER NOT NULL, operation INTEGER NOT NULL
)');
$pdo->exec("INSERT INTO calendars (id, synctoken) VALUES (3, 50)");
$pdo->exec("INSERT INTO calendarchanges (uri, synctoken, calendarid, operation) VALUES ('event.ics', 49, 3, 2)");
$calendar = new ValidatedCalendarBackend($pdo);
check($calendar->getChangesForCalendar([3, 0], '999999999', 1, 2) === null, 'Future CalDAV token accepted');
check($calendar->getChangesForCalendar([3, 0], '1', 1, 2) === null, 'Expired CalDAV token accepted');
$current = $calendar->getChangesForCalendar([3, 0], '50', 1, 2);
check($current !== null && (int) $current['syncToken'] === 50, 'Current CalDAV token rejected');

echo "Sync pagination test passed: {$pages} CardDAV pages, 520 changes, 450 unique contacts, expiry guards.\n";
