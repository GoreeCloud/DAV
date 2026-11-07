<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

use PDO;
use Sabre\CardDAV\Backend\PDO as UpstreamCardDavPDO;

/**
 * Preserve incremental CardDAV changes across bounded sync-collection pages.
 *
 * SabreDAV 4.6.0's PDO backend applies SQL LIMIT but returns the collection's
 * latest sync token even if changes remain. That can silently skip contacts.
 * Each partial page must return a token AFTER its last included change and
 * mark result_truncated, which SabreDAV exposes as a 507 multistatus item.
 */
final class PaginatedCardDavBackend extends UpstreamCardDavPDO
{
    public function getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit = null)
    {
        if ($syncToken === null || $syncToken === '' || $syncToken === '0' || $syncToken === 0) {
            return parent::getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit);
        }

        $stmt = $this->pdo->prepare(
            'SELECT synctoken FROM '.$this->addressBooksTableName.' WHERE id = ?'
        );
        $stmt->execute([$addressBookId]);
        $current = $stmt->fetchColumn();
        if ($current === false
            || !SyncTokenValidator::validIncremental($syncToken, (int) $current)) {
            return null;
        }

        $start = (int) $syncToken;
        $end = (int) $current;

        // Detect expired tokens after history pruning; never silently skip data.
        $earliest = $this->pdo->prepare(
            'SELECT MIN(synctoken) FROM '.$this->addressBookChangesTableName
            .' WHERE addressbookid = ?'
        );
        $earliest->execute([$addressBookId]);
        $min = $earliest->fetchColumn();
        if (($min === null && $start < $end)
            || ($min !== null && $start < (int) $min)) {
            return null;
        }

        // The limit is suggestive. Treat invalid/very large limits as unbounded.
        $bounded = is_int($limit) && $limit > 0 && $limit < 1000000;
        $query = 'SELECT uri, operation, synctoken FROM '.$this->addressBookChangesTableName
            .' WHERE synctoken >= ? AND synctoken < ? AND addressbookid = ?'
            .' ORDER BY synctoken ASC, id ASC';
        if ($bounded) {
            // One lookahead row establishes whether there is another page.
            $query .= ' LIMIT '.($limit + 1);
        }
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$start, $end, $addressBookId]);

        $changes = [];
        $lastToken = $start;
        $processed = 0;
        $truncated = false;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($bounded && $processed >= $limit) {
                $truncated = true;
                break;
            }
            ++$processed;
            $lastToken = max($lastToken, (int) $row['synctoken'] + 1);
            // Last operation wins for repeated changes to the same URI on a page.
            $changes[$row['uri']] = (int) $row['operation'];
        }

        $result = [
            'syncToken' => $truncated ? $lastToken : $end,
            'added' => [],
            'modified' => [],
            'deleted' => [],
            'result_truncated' => $truncated,
        ];
        foreach ($changes as $uri => $operation) {
            switch ($operation) {
                case 1:
                    $result['added'][] = $uri;
                    break;
                case 2:
                    $result['modified'][] = $uri;
                    break;
                case 3:
                    $result['deleted'][] = $uri;
                    break;
                default:
                    throw new \RuntimeException('Invalid CardDAV change-log operation');
            }
        }
        return $result;
    }
}
