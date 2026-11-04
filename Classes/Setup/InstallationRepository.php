<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Setup;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Reads and writes the core records an editorial setup consists of: backend
 * groups, users, pages and workspace access.
 *
 * Writes go through DataHandler, so the result is indistinguishable from what
 * an integrator would have clicked together - with one exception, page
 * permissions, which are a bulk update on purpose (see grantUnownedPages()).
 *
 * Every write here ADDS. Nothing in this class removes a module, a member, a
 * subgroup or a page permission an installation already has: setup is run
 * against live installations, and an editor losing access because a command
 * "normalised" their group is the failure this class is built to rule out.
 */
final class InstallationRepository
{
    /**
     * What a group created by setup may edit - the same set the demo users
     * have, see DemoEnvironmentSeeder::ensureGroup().
     */
    public const EDITOR_TABLES = 'pages,tt_content,sys_file_reference,sys_category';

    /**
     * Show, edit, delete, new subpage, edit content - everything an editor
     * needs on a page, nothing about its permissions.
     */
    public const EDITOR_PAGE_PERMISSIONS = Permission::ALL;

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

    /**
     * @return list<int>
     */
    public function findRootPageUids(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $uids = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                // Only live pages: a workspace placeholder for a new root page
                // is not something an editor can be mounted on.
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(intval(...), $uids);
    }

    public function createRootPage(string $title): int
    {
        return $this->insert('pages', [
            'pid' => 0,
            'title' => $title,
            'doktype' => 1,
            'is_siteroot' => 1,
            'hidden' => 0,
        ]);
    }

    /**
     * A group by its title or, when the reference is all digits, by its uid -
     * so "--attach-to=12" works for a tree where several groups share a title.
     *
     * @return array<string, mixed>|null
     */
    public function findGroup(string $reference): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $queryBuilder->getRestrictions()->removeAll();

        $field = ctype_digit($reference) ? 'uid' : 'title';
        $value = $field === 'uid'
            ? $queryBuilder->createNamedParameter((int)$reference, Connection::PARAM_INT)
            : $queryBuilder->createNamedParameter($reference);

        $row = $queryBuilder
            ->select('*')
            ->from('be_groups')
            ->where(
                $queryBuilder->expr()->eq($field, $value),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Groups whose OWN module list contains $module, not the ones that get it
     * through a subgroup. That still reaches everyone who has the module:
     * rights travel from a subgroup up to the groups including it, so a group
     * that inherits the Page module from S also inherits whatever is hung
     * into S.
     *
     * @return list<array<string, mixed>>
     */
    public function findGroupsWithModule(string $module): array
    {
        // Filtered here rather than with inSet(): SQLite's inSet() takes no
        // placeholders, and a group table is small enough to read whole.
        return array_values(array_filter(
            $this->findAllGroups(),
            static fn (array $group): bool => in_array($module, CommaList::parse((string)$group['groupMods']), true),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllGroups(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('be_groups')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param list<int> $mounts
     */
    public function createEditorGroup(string $title, string $modules, array $mounts): int
    {
        return $this->insert('be_groups', [
            'pid' => 0,
            'title' => $title,
            'description' => 'Created by editorialflow:setup.',
            'groupMods' => $modules,
            'tables_select' => self::EDITOR_TABLES,
            'tables_modify' => self::EDITOR_TABLES,
            'db_mountpoints' => implode(',', $mounts),
            // Not "may edit Live". An editor who may edit Live never gets a
            // workspace version, so no task ever opens for them - the board
            // stays empty and nothing says why. 0 is the be_groups default;
            // stated anyway, because the be_users form defaults to 1 and the
            // two are ORed - see InstallationCheck's live-editing finding.
            'workspace_perms' => 0,
        ]);
    }

    public function createAccessGroup(string $title, string $modules): int
    {
        return $this->insert('be_groups', [
            'pid' => 0,
            'title' => $title,
            'description' => 'Board access only, created by editorialflow:setup. Pages, tables and mounts come from the groups this one is a subgroup of.',
            'groupMods' => $modules,
        ]);
    }

    /**
     * @param list<string> $modules
     */
    public function addModulesToGroup(int $groupUid, string $currentModules, array $modules): void
    {
        $this->update('be_groups', $groupUid, ['groupMods' => CommaList::merge($currentModules, $modules)]);
    }

    public function addSubgroup(int $parentUid, string $currentSubgroups, int $childUid): void
    {
        $this->update('be_groups', $parentUid, ['subgroup' => CommaList::merge($currentSubgroups, [(string)$childUid])]);
    }

    /**
     * Pages nobody's group owns yet, handed to $groupUid.
     *
     * Only those: a page already owned by another group belongs to someone's
     * permission concept, and taking it over would lock that group out. On a
     * fresh installation every page is unowned, so this is "all of them".
     *
     * A plain UPDATE, not DataHandler: a tree with tens of thousands of pages
     * would otherwise mean as many DataHandler runs, and permissions are not
     * versioned, logged or hooked into by anything this extension relies on.
     *
     * @return int how many pages were handed over
     */
    public function grantUnownedPages(int $groupUid): int
    {
        if ($groupUid < 1) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->update('pages')
            ->set('perms_groupid', $groupUid, true, Connection::PARAM_INT)
            ->set('perms_group', self::EDITOR_PAGE_PERMISSIONS, true, Connection::PARAM_INT)
            ->where(
                $queryBuilder->expr()->eq('perms_groupid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    public function countPagesOwnedByOtherGroups(int $groupUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->gt('perms_groupid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('perms_groupid', $queryBuilder->createNamedParameter($groupUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    public function findUserUid(string $username): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();

        $uid = $queryBuilder
            ->select('uid')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return (int)($uid ?: 0);
    }

    /**
     * Enabled non-admin users that may edit Live, by their own flag.
     *
     * be_users.workspace_perms is ticked by default in the backend user form
     * (TCA default 1), and a user's flag is ORed with their groups' - so an
     * editor created by hand may edit Live however carefully their group was
     * set up.
     *
     * @return list<array<string, mixed>>
     */
    public function findUsersWhoMayEditLive(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 'username', 'usergroup', 'workspace_perms')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('admin', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('workspace_perms', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ((int)$row['workspace_perms'] & 1) === 1,
        ));
    }

    /**
     * A new editor who works in $workspaceUid from the first login on.
     *
     * The password goes through DataHandler, which hashes it and applies the
     * installation's password policy - a password the policy rejects leaves
     * no user behind, and the caller gets 0.
     */
    public function createEditor(string $username, string $password, int $groupUid, int $workspaceUid): int
    {
        $uid = $this->insert('be_users', [
            'pid' => 0,
            'username' => $username,
            'password' => $password,
            'usergroup' => (string)$groupUid,
            // "May edit Live" is the one setting that keeps every change this
            // editor makes off the board. DataHandler leaves it at 0 when it
            // is not given, but the TCA default is 1, so it is not left to
            // which of the two applies.
            'workspace_perms' => 0,
            'admin' => 0,
            // Not left to the defaults: a user created through DataHandler
            // without it comes out disabled, and the only symptom is a login
            // form saying the password is wrong.
            'disable' => 0,
        ]);

        // workspace_id has no TCA, so DataHandler drops it. Without it core
        // would still pick the first workspace on login (the user may not be
        // in Live); setting it makes that the workspace setup meant.
        if ($uid > 0 && $workspaceUid > 0) {
            $this->connectionPool->getConnectionForTable('be_users')
                ->update('be_users', ['workspace_id' => $workspaceUid], ['uid' => $uid]);
        }

        return $uid;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUser(string $username): ?array
    {
        $uid = $this->findUserUid($username);

        return $uid > 0 ? $this->findRow('be_users', $uid) : null;
    }

    public function addUserToGroup(int $userUid, string $currentGroups, int $groupUid): void
    {
        $this->update('be_users', $userUid, ['usergroup' => CommaList::merge($currentGroups, [(string)$groupUid])]);
    }

    public function disallowLiveEditing(int $userUid, int $currentPermissions): void
    {
        $this->update('be_users', $userUid, ['workspace_perms' => $currentPermissions & ~1]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWorkspaceRow(int $workspaceUid): ?array
    {
        return $this->findRow('sys_workspace', $workspaceUid);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllWorkspaces(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('sys_workspace')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Adds members and owners to a workspace, keeping the ones it has.
     *
     * Members and owners are core group fields over be_users AND be_groups,
     * stored as `be_users_3,be_groups_7` - so a group can be a member as a
     * whole, and everyone put into that group later is in the workspace too,
     * without anybody coming back to this record.
     *
     * @param list<string> $members references like be_groups_7
     * @param list<string> $owners references like be_users_3
     */
    public function addWorkspaceAccess(int $workspaceUid, array $members, array $owners): void
    {
        $row = $this->findWorkspaceRow($workspaceUid);
        if ($row === null) {
            return;
        }

        $values = [];
        if (!CommaList::containsAll((string)$row['members'], $members)) {
            $values['members'] = CommaList::merge((string)$row['members'], $members);
        }
        if (!CommaList::containsAll((string)$row['adminusers'], $owners)) {
            $values['adminusers'] = CommaList::merge((string)$row['adminusers'], $owners);
        }
        if ($values !== []) {
            $this->update('sys_workspace', $workspaceUid, $values);
        }
    }

    public function findStageResponsible(int $stageUid): string
    {
        return (string)($this->findRow('sys_workspace_stage', $stageUid)['responsible_persons'] ?? '');
    }

    /**
     * @param list<string> $references like be_groups_7
     */
    public function addStageResponsible(int $stageUid, array $references): void
    {
        $row = $this->findRow('sys_workspace_stage', $stageUid);
        if ($row === null || CommaList::containsAll((string)$row['responsible_persons'], $references)) {
            return;
        }

        $this->update('sys_workspace_stage', $stageUid, [
            'responsible_persons' => CommaList::merge((string)$row['responsible_persons'], $references),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(string $table, int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function insert(string $table, array $values): int
    {
        $placeholder = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$placeholder => $values]], []);
        $dataHandler->process_datamap();

        return (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function update(string $table, int $uid, array $values): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $values]], []);
        $dataHandler->process_datamap();
    }
}
