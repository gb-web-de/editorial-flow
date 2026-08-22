<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Pulls the content of a page into that page's task.
 *
 * "Wenn eine Seite einen geplanten Task bekommt, werden alle Inhalte und Records
 * die auf der Seite liegen dazu hinzugefügt" - so a card means "this page and
 * everything on it", not one card per content element.
 *
 * Records already claimed by another open task are skipped, which is exactly what
 * makes an editor's detach permanent: re-syncing cannot take the element back.
 */
final class TaskMemberSynchronizer
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TaskSubjectRegistry $subjectRegistry,
        private readonly ReferenceInspector $referenceInspector,
        private readonly TaskRepository $taskRepository,
    ) {
    }

    /**
     * Attach every trackable record sitting on the page to the task.
     *
     * Runs one query per workspace-aware table. That is a handful of dozens of
     * cheap pid-indexed lookups, and it happens when a task is created or
     * explicitly resynced - not on every board render.
     *
     * @return int number of records newly claimed
     */
    public function syncPageMembers(int $taskUid, int $pageUid): int
    {
        if ($pageUid < 1) {
            return 0;
        }

        $claimed = 0;
        foreach ($this->subjectRegistry->getAggregatableTables() as $table) {
            $recordUids = $this->findRecordUidsOnPage($table, $pageUid);
            if ($recordUids === []) {
                continue;
            }

            // Resolve reuse for the whole table at once. Asking per record would
            // fire a refindex query per element - dozens of round-trips on a page
            // with a normal amount of content.
            $sharedFlags = $this->referenceInspector->findSharedFlags($table, $recordUids, $pageUid);

            foreach ($recordUids as $recordUid) {
                if ($this->taskRepository->addMemberIfUnclaimed(
                    $taskUid,
                    $table,
                    $recordUid,
                    TaskRepository::ORIGIN_AUTO,
                    $pageUid,
                    $sharedFlags[$recordUid] ?? false,
                )) {
                    $claimed++;
                }
            }
        }
        return $claimed;
    }

    /**
     * Does any member of this task still have an unpublished version?
     *
     * A task covers a page and everything on it, so publishing one content element
     * must not close it. The task is done when nothing it covers is pending any more.
     */
    public function hasPendingVersions(int $taskUid, int $workspaceUid): bool
    {
        if ($workspaceUid < 1) {
            return false;
        }
        foreach ($this->taskRepository->findMembers($taskUid) as $member) {
            $table = (string)$member['record_table'];
            if (!$this->subjectRegistry->isTrackable($table)) {
                continue;
            }
            if ($this->findVersionUid($table, (int)$member['record_uid'], $workspaceUid) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The pending version of one live record in one workspace, or 0.
     *
     * Public because the Visual Editor's markers need the same answer for a
     * single member without asking for a whole task's pairs: the frontend
     * renders workspace-overlaid records, so an element there may carry either
     * uid (see TaskAjaxController::listMemberTaskMarkersForPageAction()).
     *
     * Delegated to core rather than queried here, so that this extension has
     * exactly ONE answer to "is there a pending version". Core covers both
     * cases in one helper: the ordinary `t3ver_oid = $liveUid` version, and the
     * placeholder-less record created directly inside the workspace (this
     * extension's own "materialize a pending page" flow reaches that one), whose
     * own uid IS the pending version because there is no live counterpart to
     * point back at.
     *
     * The hand-rolled pair this replaces got the second case subtly wrong: it
     * accepted any `t3ver_oid = 0 && t3ver_wsid = N` row, while core also
     * requires `t3ver_state = NEW_PLACEHOLDER` (BackendUtility.php:2820-2831).
     * WorkspaceIntegrationService::decorateMembers() already went through core,
     * so publish/stage/close could see a version the ticket denied - one record,
     * two answers. Rows that only the loose predicate matched stop counting as
     * pending now; core would have refused to act on them anyway, and
     * RepairTaskDataCommand reports them rather than leaving them invisible.
     *
     * The DBAL try/catch this replaces existed for tables whose schema lacks the
     * t3ver_* columns; core checks the TCA workspace capability up front and
     * returns false, which is the same outcome without the query.
     */
    public function findVersionUid(string $table, int $liveUid, int $workspaceUid): int
    {
        if ($workspaceUid < 1) {
            return 0;
        }

        $version = BackendUtility::getWorkspaceVersionOfRecord($workspaceUid, $table, $liveUid, 'uid');

        return is_array($version) ? (int)$version['uid'] : 0;
    }

    /**
     * Live records on a page. Workspace versions are excluded: a version is not a
     * separate piece of work, it is the in-progress state of its live record, and
     * claiming both would double-count the same element.
     *
     * @return list<int>
     */
    private function findRecordUidsOnPage(string $table, int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        try {
            $rows = $queryBuilder
                ->select('uid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception) {
            // A table declared workspace-aware in TCA but missing its columns in the
            // database (stale schema, half-installed extension) must not break the
            // whole sync - skip it.
            return [];
        }

        return array_map(static fn (array $row): int => (int)$row['uid'], $rows);
    }

    /**
     * The workspace versions this task covers, grouped by table.
     *
     * A stage change applies to the versions, not to the live records - core's
     * setStage command operates on the offline version - so the board has to
     * resolve them before it can hand the move to DataHandler.
     *
     * @return array<string, list<int>> table => version uids
     */
    public function findPendingVersionsByTable(int $taskUid, int $workspaceUid): array
    {
        $versions = [];
        foreach ($this->findPendingVersionPairsByTable($taskUid, $workspaceUid) as $table => $pairs) {
            $versions[$table] = array_map(static fn (array $pair): int => $pair['version'], $pairs);
        }
        return $versions;
    }

    /**
     * The same pending versions as findPendingVersionsByTable(), but keeping the
     * live uid each one belongs to.
     *
     * setStage/discard are keyed by the version uid alone, which is all
     * findPendingVersionsByTable() needs to give DataHandler. Publishing is keyed
     * by the LIVE uid instead, with the version uid as `swapWith` - the opposite
     * order (see WORKSPACE-STAGES.md) - so publishTaskAction() needs both sides
     * of the pair, not just the version.
     *
     * @return array<string, list<array{live: int, version: int}>>
     */
    public function findPendingVersionPairsByTable(int $taskUid, int $workspaceUid): array
    {
        if ($workspaceUid < 1) {
            return [];
        }

        $pairs = [];
        foreach ($this->taskRepository->findMembers($taskUid) as $member) {
            $table = (string)$member['record_table'];
            if (!$this->subjectRegistry->isTrackable($table)) {
                continue;
            }
            $liveUid = (int)$member['record_uid'];
            $versionUid = $this->findVersionUid($table, $liveUid, $workspaceUid);
            if ($versionUid > 0) {
                $pairs[$table][] = ['live' => $liveUid, 'version' => $versionUid];
            }
        }

        return $pairs;
    }
}
