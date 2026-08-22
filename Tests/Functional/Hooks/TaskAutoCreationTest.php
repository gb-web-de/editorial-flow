<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Hooks;

use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Service\ActiveTaskSession;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The central promise of the extension: an editor who just opens a page and types
 * ends up with a task, without ever being asked to create one.
 *
 * These tests drive DataHandler exactly the way the backend does, so they exercise
 * the real auto-versioning path rather than a simulation of it.
 */
final class TaskAutoCreationTest extends FunctionalTestCase
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

    /**
     * Edit a page inside a workspace, the way the backend form does.
     */
    private function editInWorkspace(string $table, int $uid, array $fields, int $workspaceUid = 1): void
    {
        // setWorkspace(), not ->workspace = : the setter validates the workspace and
        // populates workspaceRec, which DataHandler needs before it will version.
        $GLOBALS['BE_USER']->setWorkspace($workspaceUid);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $fields]], []);
        $dataHandler->process_datamap();
    }

    /**
     * A content element created directly on Live, bypassing DataHandler's
     * versioning - it must not itself trigger task creation, only exist as
     * something a later workspace edit can pick up.
     */
    private function createLiveContentElement(int $pid, string $header): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $connection->insert('tt_content', [
            'pid' => $pid,
            'header' => $header,
            'CType' => 'text',
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createOpenTask(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task');
        $connection->insert('tx_editorialflow_task', array_merge([
            'title' => 'About us',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => 'planned',
            'workspace_uid' => 0,
            'assignee' => 1,
            'closed' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    private function addOpenMember(int $taskUid, string $table, int $recordUid): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task_item')->insert(
            'tx_editorialflow_task_item',
            [
                'task' => $taskUid,
                'record_table' => $table,
                'record_uid' => $recordUid,
                'origin' => 'manual',
                'pid' => 2,
                'home_pid' => 2,
                'closed' => 0,
                'deleted' => 0,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectAll(string $table): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder->select('*')->from($table)->executeQuery()->fetchAllAssociative();
    }

    /**
     * Delete a record inside a workspace, the way the backend's delete button
     * does: a cmdmap command, not a datamap write. Core answers it by versioning
     * the record into a delete placeholder - the change is pending until the
     * workspace is published, exactly like an edit.
     */
    private function deleteInWorkspace(string $table, int $uid, int $workspaceUid = 1): void
    {
        $GLOBALS['BE_USER']->setWorkspace($workspaceUid);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();
    }

    #[Test]
    public function editingAPageInAWorkspaceCreatesATaskForIt(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (revised)']);

        $tasks = $this->selectAll('tx_editorialflow_task');
        self::assertCount(1, $tasks, 'exactly one task should have been created');
        self::assertSame('pages', $tasks[0]['subject_table']);
        self::assertSame(2, (int)$tasks[0]['subject_uid']);
        // Nobody planned this - it opened itself.
        self::assertSame(1, (int)$tasks[0]['auto_created']);
    }

    #[Test]
    public function editingAPageInAWorkspaceQueuesTheDetailsWizardForTheAutoCreatedTask(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (revised)']);

        $pending = $GLOBALS['BE_USER']->getSessionData('editorial_flow_pending_wizard');
        self::assertIsArray($pending);
        self::assertSame('configure_auto_task', $pending['mode']);
        self::assertSame('pages', $pending['subjectTable']);
        self::assertSame(2, $pending['subjectUid']);
        self::assertSame('About us', $pending['defaultTitle']);
    }

    #[Test]
    public function editingOnLiveDoesNotCreateATask(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'Live edit'], workspaceUid: 0);

        self::assertSame([], $this->selectAll('tx_editorialflow_task'));
    }

    #[Test]
    public function aPagesTaskAlsoCoversTheContentOnThatPage(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (revised)']);

        $members = $this->selectAll('tx_editorialflow_task_item');
        $claimed = array_map(
            static fn (array $row): string => $row['record_table'] . ':' . $row['record_uid'],
            $members,
        );

        // The page itself, plus both content elements sitting on it - one card
        // covering "this page and everything on it".
        self::assertContains('pages:2', $claimed);
        self::assertContains('tt_content:10', $claimed);
        self::assertContains('tt_content:11', $claimed);
    }

    #[Test]
    public function editingAContentElementJoinsItsPagesTaskInsteadOfOpeningItsOwn(): void
    {
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro text (revised)']);

        $tasks = $this->selectAll('tx_editorialflow_task');
        self::assertCount(1, $tasks, 'a content element must not get a card of its own');
        self::assertSame('pages', $tasks[0]['subject_table']);
        self::assertSame(2, (int)$tasks[0]['subject_uid'], 'it should belong to the page it sits on');
    }

    #[Test]
    public function editingTwoElementsOnTheSamePageKeepsOneTask(): void
    {
        $this->editInWorkspace('tt_content', 10, ['header' => 'first change']);
        $this->editInWorkspace('tt_content', 11, ['header' => 'second change']);

        self::assertCount(
            1,
            $this->selectAll('tx_editorialflow_task'),
            'the board must not flood with a card per content element',
        );
    }

    #[Test]
    public function editingAContentElementOnAPlannedPageTaskMovesThatTaskIntoTheWorkspaceFlow(): void
    {
        $taskUid = $this->createOpenTask();

        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro text (revised)']);

        $task = $this->selectAll('tx_editorialflow_task')[0];
        self::assertSame($taskUid, (int)$task['uid']);
        self::assertSame('in_progress', $task['state']);
        self::assertSame(1, (int)$task['workspace_uid']);
    }

    #[Test]
    public function aRecordBelongsToAtMostOneOpenTask(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (revised)']);

        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task_item');
        $duplicates = $connection->executeQuery(
            'SELECT record_table, record_uid, COUNT(*) AS amount'
            . ' FROM tx_editorialflow_task_item WHERE closed = 0 AND deleted = 0'
            . ' GROUP BY record_table, record_uid HAVING amount > 1',
        )->fetchAllAssociative();

        self::assertSame([], $duplicates, 'the unique key must prevent double membership');
    }

    #[Test]
    public function theTaskMovesToInProgressOnceAVersionExists(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (revised)']);

        $tasks = $this->selectAll('tx_editorialflow_task');
        self::assertSame('in_progress', $tasks[0]['state']);
        self::assertSame(1, (int)$tasks[0]['workspace_uid']);
        self::assertSame(0, (int)$tasks[0]['stage_uid'], 'stage 0 is the workspace edit stage, not Live');
    }

    #[Test]
    public function startingWorkIsRecordedInTheActivityTrail(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (revised)']);

        $events = array_column($this->selectAll('tx_editorialflow_activity'), 'event');

        self::assertContains('task_created', $events);
        self::assertContains('work_started', $events);
    }

    /**
     * Reproduces a bug found while auditing the "post-save routing wizard": when
     * a page already has an open task, editing a content element on that page
     * stored a pending-wizard choice in the session but never claimed the
     * element as a member of anything. moveMemberToTask() and
     * detachIntoOwnTask() are both UPDATE-only, so whichever choice the editor
     * made in the wizard would silently do nothing - the edit was captured
     * nowhere.
     *
     * The new element is inserted directly via DataHandler AFTER the page task
     * exists, deliberately outside syncPageMembers()'s reach - exactly the
     * situation a real editor creates by adding a new element to an
     * already-in-progress page. Editing an element that existed at task-creation
     * time would not reproduce the bug, since the aggregation sync already
     * claims it and the routing branch is never entered.
     */
    #[Test]
    public function editingANewContentElementWhosePageAlreadyHasATaskClaimsItImmediately(): void
    {
        // Opens the page task; syncPageMembers() claims uid 10 and 11 right away.
        $this->editInWorkspace('pages', 2, ['title' => 'About us (revised)']);

        $newContentUid = $this->createLiveContentElement(2, 'A brand new element');

        // First edit ever on this element - genuinely unclaimed until now.
        $this->editInWorkspace('tt_content', $newContentUid, ['header' => 'edited right after creation']);

        $members = $this->selectAll('tx_editorialflow_task_item');
        $claimed = array_map(
            static fn (array $row): string => $row['record_table'] . ':' . $row['record_uid'],
            $members,
        );

        self::assertContains(
            'tt_content:' . $newContentUid,
            $claimed,
            'the element must be claimed onto the page task even before the routing wizard is answered',
        );
        self::assertCount(1, $this->selectAll('tx_editorialflow_task'));

        $pending = $GLOBALS['BE_USER']->getSessionData('editorial_flow_pending_wizard');
        self::assertIsArray($pending, 'the routing choice must still be offered');
        self::assertSame('route_member', $pending['mode']);
        self::assertSame($newContentUid, $pending['uid']);
    }

    #[Test]
    public function theFirstEditOnAnUntouchedPageQueuesTheTaskDetailsWizard(): void
    {
        $this->editInWorkspace('tt_content', 10, ['header' => 'first edit on an untouched page']);

        $pending = $GLOBALS['BE_USER']->getSessionData('editorial_flow_pending_wizard');
        self::assertIsArray($pending);
        self::assertSame('configure_auto_task', $pending['mode']);
        self::assertSame('tt_content', $pending['table']);
        self::assertSame(10, $pending['uid']);
        self::assertSame('pages', $pending['subjectTable']);
        self::assertSame(2, $pending['subjectUid']);
    }

    /**
     * A deletion is a change like any other: it is pending in the workspace and
     * it has to go live. If it does not open a task, the change is invisible on
     * the board and there is nothing to publish it from.
     */
    #[Test]
    public function deletingContentInAWorkspaceCreatesATaskForItsPage(): void
    {
        $this->deleteInWorkspace('tt_content', 10);

        $tasks = $this->selectAll('tx_editorialflow_task');
        self::assertCount(1, $tasks, 'a workspace deletion should open a task for the page');
        self::assertSame('pages', $tasks[0]['subject_table']);
        self::assertSame(2, (int)$tasks[0]['subject_uid']);
    }

    /**
     * A page-scoped declaration covers the page, not just the task's subject.
     *
     * This is what "switch task, but the content does not change" turned out to
     * be. The page module banner used to pass the TASK's own subject as the
     * declaration context, so a task about one content element got a
     * declaration scoped to that element - which captured nothing new, since
     * the element was already its member. The same button on a page-subject
     * task got a page-wide declaration and swallowed everything. One control,
     * two unrelated behaviours.
     *
     * The banner now always declares the page, which is what a page surface
     * means. ActiveTaskSession still supports the record scope; the Visual
     * Editor sets that one deliberately.
     */
    #[Test]
    public function aPageScopedDeclarationClaimsEveryElementEditedOnThatPage(): void
    {
        // A task about ONE element, the shape that used to be a no-op.
        $elementTask = $this->createOpenTask([
            'title' => 'Optional Items',
            'subject_table' => 'tt_content',
            'subject_uid' => 11,
            'subject_pid' => 2,
            'workspace_uid' => 1,
        ]);
        $this->addOpenMember($elementTask, 'tt_content', 11);

        $this->get(ActiveTaskSession::class)->remember($GLOBALS['BE_USER'], 2, $elementTask);

        // A DIFFERENT element on the same page.
        $this->editInWorkspace('tt_content', 10, ['header' => 'Clothing (draft)']);

        self::assertSame(
            $elementTask,
            (int)($this->taskRepository()->findOpenTaskByMember('tt_content', 10)['uid'] ?? 0),
            'the declaration was made for the page, so the page\'s edits belong to it',
        );
    }

    #[Test]
    public function aRecordScopedDeclarationLeavesOtherElementsAlone(): void
    {
        $elementTask = $this->createOpenTask([
            'title' => 'Optional Items',
            'subject_table' => 'tt_content',
            'subject_uid' => 11,
            'subject_pid' => 2,
            'workspace_uid' => 1,
        ]);
        $this->addOpenMember($elementTask, 'tt_content', 11);

        // What the Visual Editor declares: this record, nothing else.
        $this->get(ActiveTaskSession::class)
            ->rememberForContext($GLOBALS['BE_USER'], 'tt_content', 11, $elementTask);

        $this->editInWorkspace('tt_content', 10, ['header' => 'Clothing (draft)']);

        self::assertNotSame(
            $elementTask,
            (int)($this->taskRepository()->findOpenTaskByMember('tt_content', 10)['uid'] ?? 0),
            'a record-scoped choice must not reach across the page',
        );
    }

    private function taskRepository(): TaskRepository
    {
        return $this->get(TaskRepository::class);
    }
}
