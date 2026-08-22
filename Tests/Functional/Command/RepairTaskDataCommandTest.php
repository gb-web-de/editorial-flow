<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Command;

use GbWeb\EditorialFlow\Command\RepairTaskDataCommand;
use GbWeb\EditorialFlow\Domain\Model\TaskState;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The failure this covers is invisible from the outside, which is why it went
 * unnoticed until an editor asked why content with an obvious task showed no
 * marker anywhere.
 *
 * A member row left open under a task that is already closed is skipped by
 * every read path - the board and the markers both ask findAllOpenForPage(),
 * which passes over closed tasks - while `one_open_task_per_record` still
 * counts it, so no open task can claim that record either. The element belongs
 * to nobody an editor can see, and to somebody the database will not release.
 */
final class RepairTaskDataCommandTest extends FunctionalTestCase
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

    private function runRepair(bool $fix): string
    {
        $tester = new CommandTester($this->get(RepairTaskDataCommand::class));
        $tester->execute($fix ? ['--fix' => true] : []);

        return $tester->getDisplay();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createTask(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task');
        $connection->insert('tx_editorialflow_task', array_merge([
            'title' => 'FAQs',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => TaskState::IN_PROGRESS->value,
            'workspace_uid' => 1,
            'closed' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function addMember(int $taskUid, int $recordUid, array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task_item');
        $connection->insert('tx_editorialflow_task_item', array_merge([
            'task' => $taskUid,
            'record_table' => 'tt_content',
            'record_uid' => $recordUid,
            'pid' => 2,
            'home_pid' => 2,
            'closed' => 0,
            'deleted' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|false
     */
    private function findItem(int $itemUid): array|false
    {
        return $this->getConnectionPool()
            ->getConnectionForTable('tx_editorialflow_task_item')
            ->select(['closed', 'deleted'], 'tx_editorialflow_task_item', ['uid' => $itemUid])
            ->fetchAssociative();
    }

    #[Test]
    public function aClaimHeldOpenByAClosedTaskIsReported(): void
    {
        $closedTask = $this->createTask(['closed' => 1]);
        $this->addMember($closedTask, 10);

        $output = $this->runRepair(false);

        self::assertStringContainsString('still claimed by a finished task', $output);
        self::assertStringContainsString('tt_content:10', $output);
        // Dry run by default - the row is untouched.
        self::assertSame(['closed' => 0, 'deleted' => 0], $this->findItem(1));
    }

    #[Test]
    public function fixingClosesTheClaimAndLetsThePageTaskTakeTheRecord(): void
    {
        $closedTask = $this->createTask(['closed' => 1]);
        $itemUid = $this->addMember($closedTask, 10);
        $openTask = $this->createTask(['title' => 'FAQs, again']);

        $this->runRepair(true);

        self::assertSame(1, (int)$this->findItem($itemUid)['closed'], 'the stale claim is retired');

        // Freeing the slot is only half of it: until an open task holds the
        // record, it still shows no marker anywhere.
        $reclaimed = $this->getConnectionPool()
            ->getConnectionForTable('tx_editorialflow_task_item')
            ->count('uid', 'tx_editorialflow_task_item', [
                'task' => $openTask,
                'record_table' => 'tt_content',
                'record_uid' => 10,
                'closed' => 0,
            ]);
        self::assertSame(1, $reclaimed, 'the open page task now holds it');
    }

    /**
     * The first real run of this command died here: closing collided with a row
     * closed earlier, and the fallback - soft-deleting while still open -
     * collided with one deleted earlier, because the unique key covers both
     * flags.
     */
    #[Test]
    public function aRowThatCanNeitherBeClosedNorSoftDeletedIsRemoved(): void
    {
        $history = $this->createTask(['title' => 'long finished', 'closed' => 1]);
        // Every escape route for tt_content:10 is already occupied: closed,
        // soft-deleted while open, and both at once.
        $this->addMember($history, 10, ['closed' => 1]);
        $this->addMember($history, 10, ['closed' => 0, 'deleted' => 1]);
        $this->addMember($history, 10, ['closed' => 1, 'deleted' => 1]);

        $closedTask = $this->createTask(['closed' => 1]);
        $stranded = $this->addMember($closedTask, 10);

        $this->runRepair(true);

        self::assertFalse($this->findItem($stranded), 'the row that could go nowhere else is gone');
    }

    /**
     * "Bubbles and badges only on content belonging to a task that is not
     * done." Done is reachable without `closed` ever being set, and a claim
     * under such a task is just as invisible as one under a closed task.
     */
    #[Test]
    public function aClaimUnderADoneTaskCountsAsFinishedToo(): void
    {
        $doneTask = $this->createTask(['state' => TaskState::DONE->value, 'closed' => 0]);
        $itemUid = $this->addMember($doneTask, 10);

        $this->runRepair(true);

        self::assertSame(1, (int)$this->findItem($itemUid)['closed']);
    }

    #[Test]
    public function anOpenTaskKeepsItsOwnClaims(): void
    {
        $openTask = $this->createTask();
        $itemUid = $this->addMember($openTask, 10);

        $this->runRepair(true);

        self::assertSame(['closed' => 0, 'deleted' => 0], $this->findItem($itemUid));
    }

    /**
     * The stuck state reported from the data side: an open task holding a
     * workspace whose member has no pending version refuses every action an
     * editor has, and the report is what points at closing as the way out.
     *
     * Report-only, like reportEmptyTasks() - whether such a task should be
     * closed or picked back up is an editorial decision.
     */
    #[Test]
    public function reportsAnOpenTaskLeftWithoutAPendingVersion(): void
    {
        $taskUid = $this->createTask(['title' => 'Nordheidehalle', 'workspace_uid' => 1]);
        $this->addMember($taskUid, 10);

        $output = $this->runRepair(false);

        self::assertStringContainsString('with nothing pending', $output);
        self::assertStringContainsString('Nordheidehalle', $output);
        self::assertStringContainsString('tt_content:10', $output);
    }

    #[Test]
    public function saysNothingAboutTasksThatNeverEnteredAWorkspace(): void
    {
        $taskUid = $this->createTask(['workspace_uid' => 0]);
        $this->addMember($taskUid, 10);

        self::assertStringContainsString('No open tasks with a workspace to check.', $this->runRepair(false));
    }
}
