<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Finds live records that currently have a pending version in more than one
 * workspace at once - something core happily allows (t3ver_wsid is not unique
 * per t3ver_oid) but never itself flags. Two editors, each in their own
 * workspace, can independently version the same live record; whichever side
 * publishes second silently overwrites the first with no warning.
 *
 * Deliberately query-based rather than a maintained flag: core dispatches no
 * event for version creation, discard, publish, or "flush workspace", so a
 * cached flag would have no reliable way to invalidate itself and would go
 * stale on the one path (flush workspace) Editorial Flow does not intercept at
 * all. Reading t3ver_oid/t3ver_wsid fresh on every render can never be wrong.
 *
 * Modelled on TYPO3\CMS\Workspaces\Service\WorkspaceService::selectAllVersionsFromPages(),
 * minus its fixed single-workspace constraint.
 */
final class WorkspaceConflictDetector
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * One query per table, never per record - a page with a dozen members
     * costs one extra query, not a dozen, following the "one query per board"
     * rule the rest of this extension already applies.
     *
     * @param array<string, list<int>> $liveUidsByTable table => distinct live uids to check
     * @return array<string, array<int, list<int>>> table => live uid => distinct
     *         workspace uids holding a pending version of it, ascending. Only
     *         ever contains entries with 2+ workspace uids - anything with 0
     *         or 1 is not a conflict and is left out entirely.
     */
    public function findConflicts(array $liveUidsByTable): array
    {
        $conflicts = [];
        foreach ($this->findPendingWorkspacesForRecords($liveUidsByTable) as $table => $byLiveUid) {
            foreach ($byLiveUid as $liveUid => $workspaceUids) {
                if (count($workspaceUids) >= 2) {
                    $conflicts[$table][$liveUid] = $workspaceUids;
                }
            }
        }

        return $conflicts;
    }

    /**
     * The full picture findConflicts() narrows down: which workspaces hold a
     * pending version of each of these records, conflict or not.
     *
     * Public because "is there a pending version at all" is a different and
     * equally batched question - the page module's element badges need it to
     * tell an element somebody actually worked on from one merely swept onto a
     * task because it sits on the covered page. Answering that per element
     * would turn one query per table into one per element.
     *
     * @param array<string, list<int>> $liveUidsByTable table => distinct live uids to check
     * @return array<string, array<int, list<int>>> table => live uid => workspace uids, ascending
     */
    public function findPendingWorkspacesForRecords(array $liveUidsByTable): array
    {
        $pending = [];
        foreach ($liveUidsByTable as $table => $liveUids) {
            $liveUids = array_values(array_unique(array_filter($liveUids, static fn (int $uid): bool => $uid > 0)));
            if ($liveUids === []) {
                continue;
            }

            $byLiveUid = $this->fetchPendingWorkspaces($table, $liveUids);
            if ($byLiveUid !== []) {
                $pending[$table] = $byLiveUid;
            }
        }

        return $pending;
    }

    /**
     * Convenience for a single record - a ticket header or one badge lookup.
     * count() < 2 on the result means "no conflict" (0 or 1 pending version is
     * the normal case).
     *
     * @return list<int>
     */
    public function findPendingWorkspaces(string $table, int $liveUid): array
    {
        if ($liveUid < 1) {
            return [];
        }

        return $this->fetchPendingWorkspaces($table, [$liveUid])[$liveUid] ?? [];
    }

    /**
     * Workspace titles for a conflict badge's label - "also edited in
     * <title>". Falls back to "#<uid>" the same way EditorialFlowController's
     * existing foreign-workspace badge does, so a deleted/inaccessible
     * workspace still renders something rather than an empty label.
     *
     * @param list<int> $workspaceUids
     * @return array<int, string> workspace uid => title
     */
    public function resolveWorkspaceTitles(array $workspaceUids): array
    {
        $titles = [];
        foreach (array_unique($workspaceUids) as $workspaceUid) {
            $record = BackendUtility::getRecord('sys_workspace', $workspaceUid, 'title');
            $titles[$workspaceUid] = ($record['title'] ?? '') !== '' ? (string)$record['title'] : ('#' . $workspaceUid);
        }

        return $titles;
    }

    /**
     * Attributes findConflicts() results back to whichever task owns each
     * member record, and resolves a display-ready label (the other
     * workspaces' titles, excluding the task's own). Shared by every caller
     * that needs "does this task have a conflict, and what do I show for
     * it" - the Page module banner, the board and the ticket all attribute
     * conflicts to a task the same way, so that rule lives in exactly one
     * place instead of being re-derived per surface.
     *
     * A task with several conflicted members shows only the first one found
     * - one badge per task row is enough to say "look here"; the diff modal
     * it links to re-derives the full workspace list for that one record.
     *
     * @param array<int, list<array<string, mixed>>> $membersByTask task uid => member rows (each with record_table/record_uid)
     * @param array<int, int> $taskWorkspaceUids task uid => the task's own workspace_uid
     * @return array<int, array{hasConflict: bool, conflictLabel: string, conflictTable: string, conflictUid: int}>
     */
    public function findConflictsForTasks(array $membersByTask, array $taskWorkspaceUids): array
    {
        $liveUidsByTable = [];
        foreach ($membersByTask as $members) {
            foreach ($members as $member) {
                $liveUidsByTable[(string)$member['record_table']][] = (int)$member['record_uid'];
            }
        }
        $conflicts = $this->findConflicts($liveUidsByTable);
        if ($conflicts === []) {
            return [];
        }

        $result = [];
        foreach ($membersByTask as $taskUid => $members) {
            $taskWorkspaceUid = $taskWorkspaceUids[$taskUid] ?? 0;
            foreach ($members as $member) {
                $table = (string)$member['record_table'];
                $recordUid = (int)$member['record_uid'];
                $workspaceUids = $conflicts[$table][$recordUid] ?? null;
                if ($workspaceUids === null) {
                    continue;
                }

                $otherWorkspaceUids = array_values(array_diff($workspaceUids, [$taskWorkspaceUid]));
                $titles = $this->resolveWorkspaceTitles($otherWorkspaceUids ?: $workspaceUids);
                $result[$taskUid] = [
                    'hasConflict' => true,
                    'conflictLabel' => implode(', ', $titles),
                    'conflictTable' => $table,
                    'conflictUid' => $recordUid,
                ];
                continue 2;
            }
        }

        return $result;
    }

    /**
     * @param list<int> $liveUids
     * @return array<int, list<int>> live uid => distinct workspace uids, ascending
     */
    private function fetchPendingWorkspaces(string $table, array $liveUids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        // Workspace records aren't soft-delete aware in the normal sense (see
        // BackendUtility::getWorkspaceVersionOfRecord()'s own comment), but
        // restricting to deleted=0 still keeps out genuinely removed rows.
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $rows = $queryBuilder
            ->select('t3ver_oid', 't3ver_wsid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->in('t3ver_oid', $queryBuilder->createNamedParameter($liveUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $workspacesByLiveUid = [];
        foreach ($rows as $row) {
            $liveUid = (int)$row['t3ver_oid'];
            $workspaceUid = (int)$row['t3ver_wsid'];
            if (!in_array($workspaceUid, $workspacesByLiveUid[$liveUid] ?? [], true)) {
                $workspacesByLiveUid[$liveUid][] = $workspaceUid;
            }
        }
        foreach ($workspacesByLiveUid as &$workspaceUids) {
            sort($workspaceUids);
        }
        unset($workspaceUids);

        return $workspacesByLiveUid;
    }
}
