<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

use Sabre\CalDAV\Backend\PDO as UpstreamCalendarPDO;

/**
 * Reject unknown, future and expired CalDAV incremental sync tokens.
 * SabreDAV's upstream PDO backend already pages calendar changes correctly.
 */
final class ValidatedCalendarBackend extends UpstreamCalendarPDO
{
    public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null)
    {
        if ($syncToken === null || $syncToken === '' || $syncToken === '0' || $syncToken === 0) {
            return parent::getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit);
        }
        if (!is_array($calendarId) || !array_key_exists(0, $calendarId)) {
            return null;
        }
        $id = $calendarId[0];
        $stmt = $this->pdo->prepare(
            'SELECT synctoken FROM '.$this->calendarTableName.' WHERE id = ?'
        );
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        if ($current === false
            || !SyncTokenValidator::validIncremental($syncToken, (int) $current)) {
            return null;
        }

        $oldest = $this->pdo->prepare(
            'SELECT MIN(synctoken) FROM '.$this->calendarChangesTableName
            .' WHERE calendarid = ?'
        );
        $oldest->execute([$id]);
        $min = $oldest->fetchColumn();
        if (($min === null && (int) $syncToken < (int) $current)
            || ($min !== null && (int) $syncToken < (int) $min)) {
            return null;
        }
        return parent::getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit);
    }
}
