<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Updates;

use GbWeb\EditorialFlow\Service\TaskAutoCreationService;
use GbWeb\EditorialFlow\Service\TaskSubjectRegistry;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Upgrades\ChattyInterface;
use TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Core\Upgrades\ReferenceIndexUpdatedPrerequisite;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Backfills Editorial Flow tasks for workspace changes that already existed before
 * the extension was installed - "bring existing workspace changes to task as an
 * initial import, everything on one page in one task".
 *
 * Routes every still-pending version through the exact same logic a live edit
 * takes: TaskAutoCreationService::captureExistingVersion(), the DataHandler-free
 * sibling of captureEdit(). A page-bound record joins its page's task, a
 * page-like subject gets its own. Once a page task exists, syncPageMembers()
 * pulls in the rest of that page's content automatically - so processing every
 * pending version, not just the page ones, still ends up with one task per page.
 *
 * Not repeatable: this is a one-time catch-up for the gap between "the site has
 * workspace changes" and "Editorial Flow exists". Every version created afterwards
 * is captured live by TaskAutoCreationDataHandlerHook, so updateNecessary() goes
 * false as soon as the backlog is cleared and stays false.
 */
#[UpgradeWizard('editorialFlowMigrateExistingWorkspaceChanges')]
final class MigrateExistingWorkspaceChangesToTasksUpdate implements UpgradeWizardInterface, ChattyInterface
{
    private const TASK_ITEM_TABLE = 'tx_editorialflow_task_item';

    private ?OutputInterface $output = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TaskSubjectRegistry $subjectRegistry,
        private readonly TaskAutoCreationService $taskAutoCreationService,
    ) {
    }

    public function getTitle(): string
    {
        return 'Editorial Flow: create tasks for existing workspace changes';
    }

    public function getDescription(): string
    {
        return 'Workspace changes made before Editorial Flow was installed have no task yet. '
            . 'This creates one for each, grouping every change on the same page into a single '
            . 'task - exactly what happens automatically for edits made from now on.';
    }

    public function updateNecessary(): bool
    {
        return $this->findPendingVersions(1) !== [];
    }

    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
            ReferenceIndexUpdatedPrerequisite::class,
        ];
    }

    public function executeUpdate(): bool
    {
        $pending = $this->findPendingVersions();
        $total = count($pending);
        foreach ($pending as $index => $version) {
            $this->output?->writeln(sprintf(
                '  [%d/%d] %s:%d (workspace %d)',
                $index + 1,
                $total,
                $version['table'],
                $version['liveUid'],
                $version['workspaceUid'],
            ));
            $this->taskAutoCreationService->captureExistingVersion(
                $version['table'],
                $version['liveUid'],
                $version['versionUid'],
                $version['workspaceUid'],
            );
        }

        return true;
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    /**
     * Every version whose live record has no Editorial Flow task item yet, i.e. a
     * workspace change Editorial Flow has never seen.
     *
     * Not scoped to open task items only - a record whose task item already
     * closed (published/discarded) means Editorial Flow already processed it once,
     * and a published or discarded version row no longer satisfies `t3ver_oid > 0`
     * with `deleted = 0` anyway, so it would not be found here a second time.
     *
     * @return list<array{table: string, liveUid: int, versionUid: int, workspaceUid: int}>
     */
    private function findPendingVersions(int $limit = 0): array
    {
        $tables = array_unique(array_merge(
            $this->subjectRegistry->getSubjectTables(),
            $this->subjectRegistry->getAggregatableTables(),
        ));

        $result = [];
        foreach ($tables as $table) {
            foreach ($this->findWorkspaceBornRecords($table) as $row) {
                $result[] = $row;
                if ($limit > 0 && count($result) >= $limit) {
                    return $result;
                }
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $queryBuilder
                ->select('t.uid', 't.t3ver_oid', 't.t3ver_wsid')
                ->from($table, 't')
                ->leftJoin(
                    't',
                    self::TASK_ITEM_TABLE,
                    'ti',
                    (string)$queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq('ti.record_table', $queryBuilder->createNamedParameter($table)),
                        $queryBuilder->expr()->eq('ti.record_uid', $queryBuilder->quoteIdentifier('t.t3ver_oid')),
                        $queryBuilder->expr()->eq('ti.deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    ),
                )
                ->where(
                    $queryBuilder->expr()->gt('t.t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->isNull('ti.uid'),
                );
            if ($limit > 0) {
                $queryBuilder->setMaxResults($limit);
            }

            try {
                $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
            } catch (\Doctrine\DBAL\Exception) {
                // A table declared workspace-aware in TCA but missing its versioning
                // columns in the database (stale schema, half-installed extension)
                // must not break the whole migration - skip it.
                continue;
            }

            foreach ($rows as $row) {
                $result[] = [
                    'table' => $table,
                    'liveUid' => (int)$row['t3ver_oid'],
                    'versionUid' => (int)$row['uid'],
                    'workspaceUid' => (int)$row['t3ver_wsid'],
                ];
                if ($limit > 0 && count($result) >= $limit) {
                    return $result;
                }
            }
        }

        return $result;
    }

    /**
     * Records CREATED inside a workspace that no task covers.
     *
     * The query above only knows versions of live records (`t3ver_oid > 0`). A
     * record born in a workspace is a single row with t3ver_oid 0 and
     * t3ver_state 1 - its own uid is both "live" and "version" - and until
     * TaskAutoCreationService learned to capture 'new', every such record was
     * left without a task: new pages, new blog posts, and the content on them.
     * This is how those are picked up after the fact.
     *
     * @return list<array{table: string, liveUid: int, versionUid: int, workspaceUid: int}>
     */
    private function findWorkspaceBornRecords(string $table): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $queryBuilder
            ->select('t.uid', 't.t3ver_wsid')
            ->from($table, 't')
            ->leftJoin(
                't',
                self::TASK_ITEM_TABLE,
                'ti',
                (string)$queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('ti.record_table', $queryBuilder->createNamedParameter($table)),
                    $queryBuilder->expr()->eq('ti.record_uid', $queryBuilder->quoteIdentifier('t.uid')),
                    $queryBuilder->expr()->eq('ti.deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                ),
            )
            ->where(
                $queryBuilder->expr()->eq('t.t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('t.t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t.t3ver_state', $queryBuilder->createNamedParameter(VersionState::NEW_PLACEHOLDER->value, Connection::PARAM_INT)),
                $queryBuilder->expr()->isNull('ti.uid'),
            )
            // Pages first, so a new page's task exists before the content on it
            // is routed - the content then joins it instead of opening its own.
            ->orderBy('t.uid', 'ASC');

        try {
            $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'table' => $table,
            'liveUid' => (int)$row['uid'],
            'versionUid' => (int)$row['uid'],
            'workspaceUid' => (int)$row['t3ver_wsid'],
        ], $rows);
    }
}
