<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Configuration;

use GbWeb\EditorialFlow\Domain\Repository\TaskChecklistRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Acceptance criteria are configured on the stage record itself.
 *
 * The point of the inline relation is that an integrator never has to find a
 * second place: the criteria sit on `sys_workspace_stage`, right where
 * `responsible_persons` already is. That only works if FormEngine can write the
 * relation AND the board can read what it wrote - two halves that a TCA
 * assertion alone would not connect, which is why the second test below drives a
 * real DataHandler datamap and then asks the repository, rather than checking
 * the array and calling it proven.
 */
final class StageCriteriaTcaTest extends FunctionalTestCase
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
    }

    #[Test]
    public function theStageRecordCarriesTheCriteriaRelation(): void
    {
        $column = $GLOBALS['TCA']['sys_workspace_stage']['columns']['tx_editorialflow_criteria'] ?? null;

        self::assertIsArray($column, 'The criteria field is not registered on sys_workspace_stage.');
        self::assertSame('inline', $column['config']['type']);
        self::assertSame('tx_editorialflow_stage_checklist_item', $column['config']['foreign_table']);
        self::assertSame('stage_uid', $column['config']['foreign_field']);

        // Registered but not shown is the failure mode a config assertion alone
        // misses - the field would exist and no integrator would ever see it.
        self::assertStringContainsString(
            'tx_editorialflow_criteria',
            (string)($GLOBALS['TCA']['sys_workspace_stage']['types']['0']['showitem'] ?? ''),
        );
    }

    #[Test]
    public function theChildTableIsKnownToFormEngine(): void
    {
        $childTca = $GLOBALS['TCA']['tx_editorialflow_stage_checklist_item'] ?? null;

        self::assertIsArray($childTca, 'The criteria table has no TCA, so no inline relation can render it.');
        self::assertSame('title', $childTca['ctrl']['label']);
        self::assertSame('deleted', $childTca['ctrl']['delete']);
        self::assertSame('sorting', $childTca['ctrl']['sortby']);
    }

    #[Test]
    public function criteriaWrittenThroughTheStageRecordAreFoundByTheBoard(): void
    {
        $workspaceUid = $this->createWorkspace();
        $stageUid = $this->createStage($workspaceUid, 'Review');

        $firstPlaceholder = StringUtility::getUniqueId('NEW');
        $secondPlaceholder = StringUtility::getUniqueId('NEW');

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'sys_workspace_stage' => [
                $stageUid => [
                    'tx_editorialflow_criteria' => $firstPlaceholder . ',' . $secondPlaceholder,
                ],
            ],
            'tx_editorialflow_stage_checklist_item' => [
                // pid 0 because the table is rootLevel, like the stage record it
                // hangs from - a new record in a datamap has to name its page.
                $firstPlaceholder => ['pid' => 0, 'title' => 'All links checked'],
                $secondPlaceholder => ['pid' => 0, 'title' => 'Images have alt text'],
            ],
        ], []);
        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog);

        // Asked for by workspace AND stage, the way every caller asks - proving
        // the read path is reached by items that carry no workspace_uid at all,
        // which is exactly what FormEngine writes here.
        $items = $this->get(TaskChecklistRepository::class)->findItemsForStage($workspaceUid, $stageUid);

        self::assertSame(
            ['All links checked', 'Images have alt text'],
            array_map(static fn (array $item): string => (string)$item['title'], $items),
        );
    }

    #[Test]
    public function aCriterionRemovedFromTheStageRecordIsGoneFromTheBoard(): void
    {
        $workspaceUid = $this->createWorkspace();
        $stageUid = $this->createStage($workspaceUid, 'Review');

        $placeholder = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'sys_workspace_stage' => [
                $stageUid => ['tx_editorialflow_criteria' => $placeholder],
            ],
            'tx_editorialflow_stage_checklist_item' => [
                $placeholder => ['pid' => 0, 'title' => 'Legal sign-off'],
            ],
        ], []);
        $dataHandler->process_datamap();
        $itemUid = (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
        self::assertGreaterThan(0, $itemUid);

        $deleteHandler = GeneralUtility::makeInstance(DataHandler::class);
        $deleteHandler->start([], [
            'tx_editorialflow_stage_checklist_item' => [$itemUid => ['delete' => 1]],
        ]);
        $deleteHandler->process_cmdmap();

        // The `delete` column is what makes this a soft delete rather than a row
        // vanishing, and the repository has to honour it either way.
        self::assertSame([], $this->get(TaskChecklistRepository::class)->findItemsForStage($workspaceUid, $stageUid));
    }

    private function createWorkspace(): int
    {
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => 1,
            'pid' => 0,
            'title' => 'Editorial',
            'adminusers' => 'be_users_1',
        ]);

        return 1;
    }

    private function createStage(int $workspaceUid, string $title): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_workspace_stage');
        // No `parenttable` - core dropped that column; `parentid` alone is what
        // ties a stage to its workspace in v14.
        $connection->insert('sys_workspace_stage', [
            'pid' => 0,
            'parentid' => $workspaceUid,
            'title' => $title,
        ]);

        return (int)$connection->lastInsertId();
    }
}
