<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * The write half of `editorialflow:democontent`.
 *
 * Split off the command so the command is left with what a console command is
 * for - asking, reporting, exit codes - and so this can be driven from a test
 * without a console. Every step is idempotent: the demo environment is
 * (re)established on every DDEV start, and doing that twice must not produce two
 * of anything.
 *
 * Everything goes through DataHandler rather than direct INSERTs, so the records
 * are indistinguishable from hand-made ones. That matters more here than
 * anywhere else in this extension: the auto-creation hook these demo users exist
 * to exercise is itself a DataHandler hook.
 */
final class DemoEnvironmentSeeder
{
    private const CRITERIA_TABLE = 'tx_editorialflow_stage_checklist_item';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function countPages(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    public function findWorkspace(string $title): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
        $queryBuilder->getRestrictions()->removeAll();

        $uid = $queryBuilder
            ->select('uid')
            ->from('sys_workspace')
            ->where(
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter($title)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return (int)($uid ?: 0);
    }

    public function deleteWorkspace(int $workspaceUid): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['sys_workspace' => [$workspaceUid => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();
    }

    public function createWorkspace(string $title, string $description): int
    {
        $placeholder = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'sys_workspace' => [
                $placeholder => [
                    'pid' => 0,
                    'title' => $title,
                    'description' => $description,
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        return (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
    }

    /**
     * The workspace's custom stages, created where they are missing, in the
     * order given.
     *
     * @param list<string> $titles
     * @return array<string, int> stage title => uid
     */
    public function ensureStages(int $workspaceUid, array $titles): array
    {
        $existing = $this->findStages($workspaceUid);

        foreach ($titles as $title) {
            if (isset($existing[$title])) {
                continue;
            }

            $placeholder = StringUtility::getUniqueId('NEW');
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([
                'sys_workspace_stage' => [
                    // `parentid` alone. Core dropped `parenttable` from this
                    // table; writing it did nothing but look like it worked.
                    $placeholder => ['pid' => 0, 'parentid' => $workspaceUid, 'title' => $title],
                ],
            ], []);
            $dataHandler->process_datamap();

            $stageUid = (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
            if ($stageUid > 0) {
                $existing[$title] = $stageUid;
            }
        }

        // Ordered the way the caller asked for, so the board's columns follow the
        // editorial sequence rather than insertion order after a partial rerun.
        $ordered = [];
        foreach ($titles as $title) {
            if (isset($existing[$title])) {
                $ordered[$title] = $existing[$title];
            }
        }

        $this->syncCustomStagesCounter($workspaceUid, array_values($ordered));

        return $ordered;
    }

    /**
     * @return array<string, int> stage title => uid
     */
    public function findStages(int $workspaceUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace_stage');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 'title')
            ->from('sys_workspace_stage')
            ->where(
                $queryBuilder->expr()->eq('parentid', $queryBuilder->createNamedParameter($workspaceUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $byTitle = [];
        foreach ($rows as $row) {
            $byTitle[(string)$row['title']] = (int)$row['uid'];
        }

        return $byTitle;
    }

    /**
     * `sys_workspace.custom_stages` looks like a flag but is core's own child
     * counter for the workspace's `sys_workspace_stage` inline relation, and
     * BackendUserAuthentication::workspaceCheckStageForCurrent() gates the whole
     * responsible_persons mechanism behind it being > 0. A stage created by
     * writing `parentid` directly never runs core's counter-maintenance step, so
     * the column stays 0 and every non-owner member is stuck at stage 0 no matter
     * what responsible_persons says - a permission bug with no visible cause.
     * Submitting the uids as the parent's own field value is what a real IRRE
     * submission would have done. See WORKSPACE-STAGES.md.
     *
     * @param list<int> $stageUids
     */
    public function syncCustomStagesCounter(int $workspaceUid, array $stageUids): void
    {
        if ($stageUids === []) {
            return;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'sys_workspace' => [
                $workspaceUid => ['custom_stages' => implode(',', $stageUids)],
            ],
        ], []);
        $dataHandler->process_datamap();
    }

    /**
     * The stage's acceptance criteria, added only where the stage has none at
     * all - so an integrator who edited the demo criteria keeps their edits
     * across the next `ddev start`.
     *
     * @param list<string> $titles
     * @return int how many were added
     */
    public function ensureCriteria(int $workspaceUid, int $stageUid, array $titles): int
    {
        if ($titles === [] || $this->countCriteria($workspaceUid, $stageUid) > 0) {
            return 0;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::CRITERIA_TABLE);
        foreach ($titles as $sorting => $title) {
            $connection->insert(self::CRITERIA_TABLE, [
                'pid' => 0,
                'workspace_uid' => $workspaceUid,
                'stage_uid' => $stageUid,
                'title' => $title,
                'sorting' => $sorting,
                'crdate' => $GLOBALS['EXEC_TIME'],
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ]);
        }

        return count($titles);
    }

    /**
     * Scoped exactly the way TaskChecklistRepository::findItemsForStage() reads:
     * a real stage uid is globally unique and stands on its own, while core's
     * fixed stages (0, -10, -20) repeat in every workspace and only the pair
     * tells them apart. Counting stage 0 without the workspace would find
     * another workspace's criteria and skip seeding this one's.
     */
    private function countCriteria(int $workspaceUid, int $stageUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::CRITERIA_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $conditions = [
            $queryBuilder->expr()->eq('stage_uid', $queryBuilder->createNamedParameter($stageUid, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
        ];
        if ($stageUid <= 0) {
            $conditions[] = $queryBuilder->expr()->eq(
                'workspace_uid',
                $queryBuilder->createNamedParameter($workspaceUid, Connection::PARAM_INT),
            );
        }

        return (int)$queryBuilder
            ->count('uid')
            ->from(self::CRITERIA_TABLE)
            ->where(...$conditions)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * The one group every demo user is in.
     *
     * Four things have to line up before a non-admin can so much as open a page,
     * and each of them fails silently on its own - see WORKSPACE-STAGES.md for
     * how long that took to find out. `db_mountpoints` is the first:
     * BackendUserAuthentication::calcPerms() calls isInWebMount() before it ever
     * looks at perms_groupid, so without a mount no page permission is consulted
     * at all.
     */
    public function ensureGroup(string $title, string $modules): int
    {
        $values = [
            'title' => $title,
            'groupMods' => $modules,
            'tables_select' => 'pages,tt_content,sys_file_reference,sys_category',
            'tables_modify' => 'pages,tt_content,sys_file_reference,sys_category',
            'db_mountpoints' => implode(',', $this->findRootPageUids()),
        ];

        $existing = $this->findRecordUid('be_groups', 'title', $title);
        if ($existing > 0) {
            $this->write(['be_groups' => [$existing => $values]]);

            return $existing;
        }

        $placeholder = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['be_groups' => [$placeholder => ['pid' => 0] + $values]], []);
        $dataHandler->process_datamap();

        return (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
    }

    /**
     * The demo fixture ships `perms_everybody = 0` and no group on any page, so
     * without this no be_group has access to a single one. Applied to every page,
     * not just the root: TYPO3 does not cascade page permissions to subpages.
     */
    public function grantPageAccessToGroup(int $groupUid): void
    {
        if ($groupUid < 1) {
            return;
        }

        $this->connectionPool->getConnectionForTable('pages')->update(
            'pages',
            [
                'perms_groupid' => $groupUid,
                // show, edit, delete, new-subpage, new-content - the same value
                // the demo fixture already grants perms_user for uid 1.
                'perms_group' => 31,
            ],
            ['deleted' => 0],
        );
    }

    public function ensureUser(string $username, string $realName, int $groupUid, int $workspaceUid, string $password): int
    {
        $values = [
            'realName' => $realName,
            'email' => $username . '@example.org',
            'usergroup' => (string)$groupUid,
            'workspace_id' => $workspaceUid,
            'admin' => 0,
            'disable' => 0,
        ];

        $existing = $this->findRecordUid('be_users', 'username', $username);
        if ($existing > 0) {
            // No password on an update: rewriting it every run would undo a
            // password an integrator changed on their own instance.
            $this->write(['be_users' => [$existing => $values]]);

            return $existing;
        }

        $placeholder = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'be_users' => [
                $placeholder => $values + ['pid' => 0, 'username' => $username, 'password' => $password],
            ],
        ], []);
        $dataHandler->process_datamap();

        return (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
    }

    /**
     * @param list<int> $memberUids
     * @param list<int> $ownerUids
     */
    public function setWorkspaceMembers(int $workspaceUid, array $memberUids, array $ownerUids): void
    {
        $this->write([
            'sys_workspace' => [
                $workspaceUid => [
                    'members' => $this->asUserReferences($memberUids),
                    'adminusers' => $this->asUserReferences($ownerUids),
                ],
            ],
        ]);
    }

    /**
     * @param list<int> $userUids
     */
    public function setStageResponsible(int $stageUid, array $userUids): void
    {
        $this->write([
            'sys_workspace_stage' => [
                $stageUid => ['responsible_persons' => $this->asUserReferences($userUids)],
            ],
        ]);
    }

    /**
     * @param list<int> $userUids
     */
    private function asUserReferences(array $userUids): string
    {
        return implode(',', array_map(
            static fn (int $uid): string => 'be_users_' . $uid,
            array_filter($userUids, static fn (int $uid): bool => $uid > 0),
        ));
    }

    private function findRecordUid(string $table, string $field, string $value): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $uid = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return (int)($uid ?: 0);
    }

    /**
     * @return list<int>
     */
    private function findRootPageUids(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): int => (int)$row['uid'], $rows);
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     */
    private function write(array $datamap): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();
    }
}
