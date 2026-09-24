<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Service;

use GbWeb\EditorialFlow\Service\BoardScopeResolver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Page tree: 1 "Home" (root) -> 2 "About us" (child) - see Fixtures/pages.csv.
 */
final class BoardScopeResolverTest extends FunctionalTestCase
{
    /**
     * @var string[]
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-workspaces',
        'typo3/cms-dashboard',
    ];

    /**
     * @var string[]
     */
    protected array $testExtensionsToLoad = [
        'gb-web/editorial-flow',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
    }

    private function subject(): BoardScopeResolver
    {
        return new BoardScopeResolver($this->getConnectionPool());
    }

    #[Test]
    public function resolvePageUidsWithZeroDepthReturnsOnlyTheSelectedPage(): void
    {
        self::assertSame([1], $this->subject()->resolvePageUids(1, 0, $GLOBALS['BE_USER']));
    }

    #[Test]
    public function resolvePageUidsWithDepthIncludesSubpages(): void
    {
        self::assertEqualsCanonicalizing(
            [1, 2],
            $this->subject()->resolvePageUids(1, 999, $GLOBALS['BE_USER']),
        );
    }

    #[Test]
    public function resolvePageUidsForAnInvalidPageReturnsNothing(): void
    {
        self::assertSame([], $this->subject()->resolvePageUids(0, 0, $GLOBALS['BE_USER']));
    }

    #[Test]
    public function resolveWorkspaceRootPageUidsFallsBackToPidZeroPagesWhenNoMountpointsAreConfigured(): void
    {
        // Fixture workspace 1 "Editorial" has no db_mountpoints set - the common
        // case, per BoardScopeResolver's own docblock. Falls back to page 1
        // "Home" (pid=0) and its subtree, rather than surfacing nothing.
        self::assertEqualsCanonicalizing(
            [1, 2],
            $this->subject()->resolveWorkspaceRootPageUids(1, $GLOBALS['BE_USER']),
        );
    }

    #[Test]
    public function resolveWorkspaceRootPageUidsReturnsNothingForAnInvalidWorkspace(): void
    {
        self::assertSame([], $this->subject()->resolveWorkspaceRootPageUids(0, $GLOBALS['BE_USER']));
    }

    #[Test]
    public function resolveWorkspaceRootPageUidsExpandsEachConfiguredMountpointsSubtree(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_workspace');
        $connection->insert('sys_workspace', [
            'title' => 'Marketing',
            'db_mountpoints' => '1',
        ]);
        $workspaceUid = (int)$connection->lastInsertId();

        self::assertEqualsCanonicalizing(
            [1, 2],
            $this->subject()->resolveWorkspaceRootPageUids($workspaceUid, $GLOBALS['BE_USER']),
        );
    }

    /**
     * A page created inside a workspace has a task but no live uid in the
     * page tree. Unless the scope adds it, no board ever queries its
     * subject_pid - the new blog post had a card nobody could see.
     */
    #[Test]
    public function aPageBornInAnAccessibleWorkspaceIsPartOfTheScope(): void
    {
        $newPage = $this->insertWorkspaceBornPage(2, 1);
        $nestedPage = $this->insertWorkspaceBornPage($newPage, 1);

        self::assertEqualsCanonicalizing(
            [1, 2, $newPage, $nestedPage],
            $this->subject()->resolvePageUids(1, 999, $GLOBALS['BE_USER'], [1]),
        );
        self::assertEqualsCanonicalizing(
            [1, 2, $newPage, $nestedPage],
            $this->subject()->resolveRootPageUidsForWorkspaces([1], $GLOBALS['BE_USER']),
        );
    }

    #[Test]
    public function aPageBornInAWorkspaceOutsideTheGivenOnesStaysOut(): void
    {
        $this->insertWorkspaceBornPage(2, 7);

        self::assertEqualsCanonicalizing([1, 2], $this->subject()->resolvePageUids(1, 999, $GLOBALS['BE_USER'], [1]));
        self::assertEqualsCanonicalizing([1, 2], $this->subject()->resolvePageUids(1, 999, $GLOBALS['BE_USER']));
    }

    /**
     * The root scope of several workspaces is their union - one board for a
     * coach who looks after several teams.
     */
    #[Test]
    public function theRootScopeOfSeveralWorkspacesIsTheirUnion(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', ['uid' => 3, 'pid' => 0, 'title' => 'Team B root', 'doktype' => 1]);
        $workspaces = $this->getConnectionPool()->getConnectionForTable('sys_workspace');
        $workspaces->update('sys_workspace', ['db_mountpoints' => '2'], ['uid' => 1]);
        $workspaces->insert('sys_workspace', ['uid' => 2, 'title' => 'Team B', 'db_mountpoints' => '3']);

        self::assertEqualsCanonicalizing(
            [2, 3],
            $this->subject()->resolveRootPageUidsForWorkspaces([1, 2], $GLOBALS['BE_USER']),
        );
    }

    private function insertWorkspaceBornPage(int $pid, int $workspaceUid): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', [
            'pid' => $pid,
            'title' => 'New in workspace ' . $workspaceUid,
            'doktype' => 1,
            't3ver_wsid' => $workspaceUid,
            't3ver_oid' => 0,
            't3ver_state' => 1,
        ]);

        return (int)$connection->lastInsertId();
    }
}
