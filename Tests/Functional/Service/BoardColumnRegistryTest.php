<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Service;

use GbWeb\EditorialFlow\Service\BoardColumnRegistry;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Every workspace's own stage chain always starts with the built-in "Editing"
 * stage (uid 0) and always ends with "Ready to publish" (uid -10) - core
 * prepends/appends them unconditionally in WorkspaceStageRepository::
 * findAllStagesByWorkspace(), regardless of what an integrator configures. That
 * makes those two stages a reliable, title-independent way to prove the
 * "shared when 2+ workspaces contribute" rule: they are shared by construction
 * the instant a second workspace is merged in at all, with no fixture stage
 * needed. Only genuinely custom `sys_workspace_stage` rows can produce an
 * 'own'-colored or an uncoloured column, so those are what the fixtures below
 * add.
 */
final class BoardColumnRegistryTest extends FunctionalTestCase
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
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER']);
        $this->createWorkspace(1, 'Editorial');
    }

    private function subject(): BoardColumnRegistry
    {
        return $this->get(BoardColumnRegistry::class);
    }

    private function createWorkspace(int $uid, string $title, string $color = 'orange'): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => $uid,
            'pid' => 0,
            'title' => $title,
            'color' => $color,
            'deleted' => 0,
        ]);
    }

    private function createCustomStage(int $uid, int $workspaceUid, string $title, int $sorting = 1): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_workspace_stage')->insert('sys_workspace_stage', [
            'uid' => $uid,
            'pid' => 0,
            'parentid' => $workspaceUid,
            'title' => $title,
            'sorting' => $sorting,
            'deleted' => 0,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $columns
     */
    private function findColumnByStageUid(array $columns, int $stageUid): ?array
    {
        foreach ($columns as $column) {
            if ($column['stageUid'] === $stageUid) {
                return $column;
            }
        }
        return null;
    }

    /**
     * @param list<array<string, mixed>> $columns
     */
    private function findColumnByLabel(array $columns, string $label): ?array
    {
        foreach ($columns as $column) {
            if ($column['label'] === $label) {
                return $column;
            }
        }
        return null;
    }

    #[Test]
    public function withNoOtherWorkspacesNothingIsColored(): void
    {
        $columns = $this->subject()->getColumns($GLOBALS['BE_USER'], 1, []);

        $editing = $this->findColumnByStageUid($columns, 0);
        self::assertNotNull($editing);
        self::assertFalse($editing['colorShared']);
        self::assertFalse($editing['colorOwn']);
        self::assertSame('', $editing['style']);
    }

    #[Test]
    public function editingAndReadyAreSharedTheMomentAnotherWorkspaceIsMerged(): void
    {
        $this->createWorkspace(2, 'Legal');

        $columns = $this->subject()->getColumns($GLOBALS['BE_USER'], 1, [2]);

        $editing = $this->findColumnByStageUid($columns, 0);
        $ready = $this->findColumnByStageUid($columns, -10);
        self::assertNotNull($editing);
        self::assertNotNull($ready);
        self::assertTrue($editing['colorShared']);
        self::assertTrue($ready['colorShared']);
        self::assertFalse($editing['colorOwn']);
        self::assertStringContainsString('Editorial', $editing['contributingWorkspaceTitles']);
        self::assertStringContainsString('Legal', $editing['contributingWorkspaceTitles']);
    }

    #[Test]
    public function aCustomStageUniqueToOneOtherWorkspaceGetsThatWorkspacesOwnColor(): void
    {
        $this->createWorkspace(2, 'Legal', 'magenta');
        $this->createCustomStage(100, 2, 'Legal review');

        $columns = $this->subject()->getColumns($GLOBALS['BE_USER'], 1, [2]);

        $legalReview = $this->findColumnByLabel($columns, 'Legal review');
        self::assertNotNull($legalReview);
        self::assertFalse($legalReview['colorShared']);
        self::assertTrue($legalReview['colorOwn']);
        self::assertSame('Legal', $legalReview['contributingWorkspaceTitles']);
        self::assertStringContainsString('magenta', $legalReview['style']);
        // The active workspace (1) has no such stage - nothing to drop onto.
        self::assertNull($legalReview['stageUid']);
        self::assertFalse($legalReview['acceptsDrop']);
    }

    #[Test]
    public function aStageSharedByTwoOtherWorkspacesIsSharedEvenWhenTheActiveWorkspaceDoesNotHaveIt(): void
    {
        $this->createWorkspace(2, 'Legal');
        $this->createWorkspace(3, 'Marketing');
        $this->createCustomStage(100, 2, 'Legal review');
        $this->createCustomStage(101, 3, 'Legal review');

        $columns = $this->subject()->getColumns($GLOBALS['BE_USER'], 1, [2, 3]);

        $legalReview = $this->findColumnByLabel($columns, 'Legal review');
        self::assertNotNull($legalReview);
        self::assertTrue($legalReview['colorShared']);
        self::assertFalse($legalReview['colorOwn']);
        self::assertNull($legalReview['stageUid']);
        self::assertFalse($legalReview['acceptsDrop']);
    }

    #[Test]
    public function theOtherWorkspacesSentinelColumnNoLongerExists(): void
    {
        $this->createWorkspace(2, 'Legal');

        $columns = $this->subject()->getColumns($GLOBALS['BE_USER'], 1, [2]);

        foreach ($columns as $column) {
            self::assertNotSame('other-workspaces', $column['key']);
        }
    }

    /**
     * Index.html only folds the contributing-workspace list behind a
     * details/summary disclosure once contributingWorkspaceCount passes its
     * threshold - a real installation with many workspaces (18, on the one
     * that surfaced this) otherwise grew the "Editing" column to the width
     * of the whole board. This locks in the count the template branches on;
     * the full title string must still carry every name, since the
     * disclosure's expanded content is what renders it.
     */
    #[Test]
    public function contributingWorkspaceCountReflectsEveryContributorEvenPastTheInlineDisplayThreshold(): void
    {
        for ($workspaceUid = 2; $workspaceUid <= 6; $workspaceUid++) {
            $this->createWorkspace($workspaceUid, 'Team ' . $workspaceUid);
        }

        $columns = $this->subject()->getColumns($GLOBALS['BE_USER'], 1, [2, 3, 4, 5, 6]);

        $editing = $this->findColumnByStageUid($columns, 0);
        self::assertNotNull($editing);
        // The active workspace (1, "Editorial") plus the five just created.
        self::assertSame(6, $editing['contributingWorkspaceCount']);
        foreach (range(2, 6) as $workspaceUid) {
            self::assertStringContainsString('Team ' . $workspaceUid, $editing['contributingWorkspaceTitles']);
        }
    }

    /**
     * Which column a card lands in.
     *
     * The rule was private in EditorialFlowController and therefore untested:
     * the board render test hand-builds its columns, so it never ran this at
     * all. That is why the closed-task placement below - the one thing an editor
     * notices immediately - had no coverage.
     */
    #[Test]
    public function aClosedTaskBelongsInDoneAndNowhereElse(): void
    {
        $columns = $this->subject()->getColumns($GLOBALS['BE_USER'], 1, []);
        // A task closed out of the Editing stage: close() keeps the workspace it
        // was finished in, so stage_uid 0 still matches the Editing column's
        // shape and only the `closed` short-circuit keeps it out.
        $task = ['closed' => 1, 'state' => 'done', 'workspace_uid' => 1, 'stage_uid' => 0];

        $matched = array_values(array_filter(
            array_map(
                fn (array $column): ?string => $this->subject()->belongsInColumn($task, $column)
                    ? (string)$column['key']
                    : null,
                $columns,
            ),
        ));

        self::assertSame(['done'], $matched);
    }

    #[Test]
    public function anOpenTaskInAStageBelongsToThatStagesColumn(): void
    {
        $columns = $this->subject()->getColumns($GLOBALS['BE_USER'], 1, []);
        $task = ['closed' => 0, 'state' => 'in_progress', 'workspace_uid' => 1, 'stage_uid' => 0];

        $matched = array_values(array_filter(
            array_map(
                fn (array $column): ?string => $this->subject()->belongsInColumn($task, $column)
                    ? (string)$column['key']
                    : null,
                $columns,
            ),
        ));

        self::assertSame(['stage-0'], $matched);
    }

    #[Test]
    public function anUnversionedTaskBelongsToItsOwnStateColumn(): void
    {
        $columns = $this->subject()->getColumns($GLOBALS['BE_USER'], 1, []);
        $task = ['closed' => 0, 'state' => 'planned', 'workspace_uid' => 0, 'stage_uid' => 0];

        $matched = array_values(array_filter(
            array_map(
                fn (array $column): ?string => $this->subject()->belongsInColumn($task, $column)
                    ? (string)$column['key']
                    : null,
                $columns,
            ),
        ));

        self::assertSame(['planned'], $matched);
    }
}
