<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use TYPO3\CMS\Backend\Tree\Repository\PageTreeRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Turns "this page, at this depth" (or "this workspace's own root pages") into the
 * page-uid list the board query needs.
 *
 * Depth follows the same convention EXT:workspaces' own module UI uses: 0 = just
 * the selected page, 1-4 = that many levels of subpages, 999 = the whole subtree.
 * Built on core's own PageTreeRepository::getFlattenedPages() - the same helper
 * the classic Recordlist module uses for its "search in subtree" - rather than
 * hand-rolling a tree walk.
 *
 * getFlattenedPages() itself is permission-unaware (it fetches "all non-deleted
 * pages" - see its own docblock), so every result here is filtered through
 * BackendUtility::readPageAccess() before it reaches the board query. Depth/root
 * scanning must never surface a task on a page the current editor cannot see.
 */
final class BoardScopeResolver
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @param list<int> $workspaceUids workspaces whose NEW pages count as part of
     *        the tree - see addWorkspaceBornPages(). Only ever workspaces the
     *        user may access; the caller already knows which those are.
     * @return list<int>
     */
    public function resolvePageUids(int $pageUid, int $depth, BackendUserAuthentication $backendUser, array $workspaceUids = []): array
    {
        if ($pageUid < 1) {
            return [];
        }
        if ($depth < 1) {
            return $this->filterByAccess([$pageUid], $backendUser);
        }

        $repository = GeneralUtility::makeInstance(PageTreeRepository::class);
        $pages = $repository->getFlattenedPages([$pageUid], $depth);
        $pageUids = array_values(array_unique(array_map(static fn (array $page): int => (int)$page['uid'], $pages)));
        $pageUids = $this->addWorkspaceBornPages($pageUids, $workspaceUids);

        return $this->filterByAccess($pageUids, $backendUser);
    }

    /**
     * The root scope of several workspaces at once: everything below the mount
     * points of each of them, including the pages created inside them.
     *
     * This is what lets one board show a coach every team they look after. It
     * used to be the active workspace's roots only, so a coach owning three
     * team workspaces saw one team's work and had to switch to find the rest.
     *
     * @param list<int> $workspaceUids
     * @return list<int>
     */
    public function resolveRootPageUidsForWorkspaces(array $workspaceUids, BackendUserAuthentication $backendUser): array
    {
        $pageUids = [];
        foreach ($workspaceUids as $workspaceUid) {
            $pageUids = array_merge($pageUids, $this->resolveWorkspaceRootPageUids($workspaceUid, $backendUser));
        }

        return array_values(array_unique($pageUids));
    }

    /**
     * @return list<int>
     */
    public function resolveWorkspaceRootPageUids(int $workspaceUid, BackendUserAuthentication $backendUser): array
    {
        if ($workspaceUid < 1) {
            return [];
        }
        $workspace = BackendUtility::getRecord('sys_workspace', $workspaceUid, 'db_mountpoints');
        $mountpoints = GeneralUtility::intExplode(',', (string)($workspace['db_mountpoints'] ?? ''), true);
        if ($mountpoints === []) {
            // No db_mountpoints configured (the common case) - fall back to the
            // installation's actual root pages instead of surfacing nothing.
            return $this->resolveFallbackRootPageUids($backendUser, $workspaceUid);
        }

        $pageUids = [];
        foreach ($mountpoints as $mountpoint) {
            // 999 = the whole subtree below each mount point, matching this class's
            // own "root" depth convention.
            $pageUids = array_merge($pageUids, $this->resolvePageUids($mountpoint, 999, $backendUser, [$workspaceUid]));
        }

        return array_values(array_unique($pageUids));
    }

    /**
     * @return list<int>
     */
    private function resolveFallbackRootPageUids(BackendUserAuthentication $backendUser, int $workspaceUid): array
    {
        $pageUids = $this->queryRootCandidates('pid');
        if ($pageUids === []) {
            $pageUids = $this->queryRootCandidates('is_siteroot');
        }

        $subtreeUids = [];
        foreach ($pageUids as $pageUid) {
            $subtreeUids = array_merge($subtreeUids, $this->resolvePageUids($pageUid, 999, $backendUser, [$workspaceUid]));
        }

        return array_values(array_unique($subtreeUids));
    }

    /**
     * @return list<int>
     */
    private function queryRootCandidates(string $criterion): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $condition = $criterion === 'pid'
            ? $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            : $queryBuilder->expr()->eq('is_siteroot', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT));

        $rows = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $condition,
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_unique(array_map(static fn (array $row): int => (int)$row['uid'], $rows)));
    }

    /**
     * Adds the pages that exist only inside one of $workspaceUids below the
     * given (live) pages, level by level.
     *
     * PageTreeRepository is asked in live context on purpose: in a workspace
     * it answers with VERSION uids for changed pages, and tasks are keyed by
     * live uids, so every edited page would drop off the board. A page created
     * in a workspace has no live uid to lose - it is one row with t3ver_state 1
     * and its own uid - so it is added here instead. Without this, a new blog
     * post had a task but no board would ever show it: its subject_pid was not
     * in any page list the board queried.
     *
     * @param list<int> $pageUids
     * @param list<int> $workspaceUids
     * @return list<int>
     */
    private function addWorkspaceBornPages(array $pageUids, array $workspaceUids): array
    {
        $workspaceUids = array_values(array_filter($workspaceUids, static fn (int $uid): bool => $uid > 0));
        if ($workspaceUids === [] || $pageUids === []) {
            return $pageUids;
        }

        $known = array_fill_keys($pageUids, true);
        $parents = $pageUids;
        // Bounded like the tree walk it extends; a new page nested ten levels
        // deep inside other new pages is not a board scenario.
        for ($level = 0; $level < 10 && $parents !== []; $level++) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll();
            $children = $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($parents, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->in('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceUids, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('t3ver_state', $queryBuilder->createNamedParameter(VersionState::NEW_PLACEHOLDER->value, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->executeQuery()
                ->fetchFirstColumn();

            $parents = [];
            foreach ($children as $child) {
                $child = (int)$child;
                if (!isset($known[$child])) {
                    $known[$child] = true;
                    $parents[] = $child;
                }
            }
        }

        return array_keys($known);
    }

    /**
     * @param list<int> $pageUids
     * @return list<int>
     */
    private function filterByAccess(array $pageUids, BackendUserAuthentication $backendUser): array
    {
        if ($backendUser->isAdmin()) {
            return $pageUids;
        }
        $permsClause = $backendUser->getPagePermsClause(Permission::PAGE_SHOW);

        return array_values(array_filter(
            $pageUids,
            static fn (int $pageUid): bool => BackendUtility::readPageAccess($pageUid, $permsClause) !== false,
        ));
    }
}
