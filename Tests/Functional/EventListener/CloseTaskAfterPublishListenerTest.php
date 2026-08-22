<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\EventListener;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Whose publish closes whose task.
 *
 * The listener finds the open task a published record belongs to, then asks
 * whether that task has anything left pending. Both halves are right; the join
 * between them was not. findOpenTaskByMember() is not workspace-filtered, and
 * core lets the same live record hold a version in several workspaces at once -
 * which is what WorkspaceConflictDetector exists to detect. So a publish out of
 * workspace B found a task belonging to workspace A, asked "is anything pending
 * in B?", got no for a reason that had nothing to do with A, and closed A while
 * A's own version was still in review. The task's members were marked closed and
 * its still-pending version became unreachable from the board.
 *
 * Driven through a real DataHandler publish rather than by dispatching the event
 * by hand, so the test also proves the listener is wired to the path core
 * actually takes.
 */
final class CloseTaskAfterPublishListenerTest extends FunctionalTestCase
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
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $this->createSecondWorkspace();
    }

    /**
     * pages.csv ships workspace 1 ("Editorial"); the conflict this class is
     * about needs a second, independent one.
     */
    private function createSecondWorkspace(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => 2,
            'pid' => 0,
            'title' => 'Legal',
            'deleted' => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function editInWorkspace(string $table, int $uid, array $fields, int $workspaceUid): void
    {
        $GLOBALS['BE_USER']->setWorkspace($workspaceUid);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $fields]], []);
        $dataHandler->process_datamap();
    }

    private function versionUidOf(string $table, int $liveUid, int $workspaceUid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $uid = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceUid)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $uid ? (int)$uid : 0;
    }

    /**
     * Publish the way core's own Workspaces module does: the live uid keyed,
     * the version as swapWith.
     */
    private function publish(string $table, int $liveUid, int $workspaceUid): void
    {
        $GLOBALS['BE_USER']->setWorkspace($workspaceUid);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [
            $table => [
                $liveUid => [
                    'version' => [
                        'action' => 'publish',
                        'swapWith' => $this->versionUidOf($table, $liveUid, $workspaceUid),
                    ],
                ],
            ],
        ]);
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog, 'precondition: core accepted the publish');
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(int $taskUid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_task');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from('tx_editorialflow_task')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($taskUid)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function openTaskUidFor(string $table, int $recordUid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_task_item');
        $queryBuilder->getRestrictions()->removeAll();

        $taskUid = $queryBuilder
            ->select('task')
            ->from('tx_editorialflow_task_item')
            ->where(
                $queryBuilder->expr()->eq('record_table', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('record_uid', $queryBuilder->createNamedParameter($recordUid)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $taskUid ? (int)$taskUid : 0;
    }

    /**
     * The control: the ordinary case has to keep working.
     */
    #[Test]
    public function publishingFromTheTasksOwnWorkspaceClosesIt(): void
    {
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (editorial)'], 1);
        $taskUid = $this->openTaskUidFor('tt_content', 10);
        self::assertGreaterThan(0, $taskUid, 'precondition: editing auto-created a task');
        self::assertSame(1, (int)$this->taskRow($taskUid)['workspace_uid']);

        $this->publish('tt_content', 10, 1);

        self::assertSame(1, (int)$this->taskRow($taskUid)['closed']);
    }

    /**
     * The regression. Task A belongs to workspace 1; workspace 2 versions the
     * same live record independently and publishes it. Nothing about that says
     * task A is finished - its own version is untouched and still pending.
     */
    #[Test]
    public function publishingFromAnotherWorkspaceDoesNotCloseThisWorkspacesTask(): void
    {
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (editorial)'], 1);
        $taskUid = $this->openTaskUidFor('tt_content', 10);
        self::assertSame(1, (int)$this->taskRow($taskUid)['workspace_uid'], 'the task belongs to workspace 1');

        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (legal)'], 2);
        self::assertGreaterThan(0, $this->versionUidOf('tt_content', 10, 2), 'precondition: both workspaces hold a version');

        $this->publish('tt_content', 10, 2);

        self::assertSame(
            0,
            (int)$this->taskRow($taskUid)['closed'],
            'workspace 2 publishing says nothing about whether workspace 1 is done',
        );
        self::assertGreaterThan(
            0,
            $this->versionUidOf('tt_content', 10, 1),
            'and workspace 1\'s own version is still pending, so the task has work left',
        );
    }

    /**
     * The narrower half of the fix, which the test above cannot see.
     *
     * Scoping hasPendingVersions() to the task's own workspace already stops the
     * common case. It does not stop this one: task A has nothing pending in
     * workspace 1 any more (its version was discarded), so asking about
     * workspace 1 answers "nothing pending" and the task would close - triggered
     * by a publish in workspace 2 that has nothing to do with it, and recorded
     * in the trail as though workspace 1 had gone live.
     *
     * A task ends because its own work is done, not because someone else's was.
     * With the manual close available there is no reason to guess on an
     * unrelated event.
     */
    #[Test]
    public function anUnrelatedWorkspacesPublishNeverClosesThisTaskEvenWithNothingPending(): void
    {
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (editorial)'], 1);
        $taskUid = $this->openTaskUidFor('tt_content', 10);
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (legal)'], 2);

        // Workspace 1 gives up on its draft, leaving the task with nothing of
        // its own pending - the stuck state the manual close now resolves.
        $GLOBALS['BE_USER']->setWorkspace(1);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['tt_content' => [10 => ['discard' => true]]]);
        $dataHandler->process_cmdmap();
        self::assertSame(0, $this->versionUidOf('tt_content', 10, 1), 'precondition: workspace 1 has nothing left');

        $this->publish('tt_content', 10, 2);

        self::assertSame(
            0,
            (int)$this->taskRow($taskUid)['closed'],
            'the task may well be over, but workspace 2 publishing is not what decides that',
        );
    }

    /**
     * A task that never entered Editing has no workspace of its own, so the
     * event's is the only information there is - the previous behaviour, kept.
     */
    #[Test]
    public function aTaskWithoutAWorkspaceOfItsOwnStillFollowsTheEvent(): void
    {
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (editorial)'], 1);
        $taskUid = $this->openTaskUidFor('tt_content', 10);

        $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task')->update(
            'tx_editorialflow_task',
            ['workspace_uid' => 0],
            ['uid' => $taskUid],
        );

        $this->publish('tt_content', 10, 1);

        self::assertSame(1, (int)$this->taskRow($taskUid)['closed']);
    }
}
