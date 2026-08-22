<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Service;

use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Service\WorkspaceIntegrationService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What the ticket's "Changes" section is allowed to show.
 *
 * The bug these tests pin down: getAggregatedMemberDiffs() asked core's
 * HistoryService for the LIVE uid's history, while core itself only ever asks
 * for a version's (Workspaces\Service\GridDataService::getRowDetails()).
 * Workspace edits write their sys_history rows against the version record, so
 * the live uid never selects them - and RecordHistory::findEventsForRecord()
 * lets `workspace = 0` rows through unconditionally, even for a backend user
 * sitting inside a workspace. The ticket therefore presented edits made
 * directly in Live, by anyone, at any time, as this task's pending work.
 *
 * Two things went wrong at once, so both are covered below: the Changes list
 * itself, and `hasDiffs` - which feeds the "Covered records" filter in
 * getTaskDetails(), so a member the task had never touched still showed up
 * there with a Diff button.
 *
 * Only the first test reproduces the bug (it reported three Live revisions as
 * the task's own work before the fix). The second passes either way and is
 * kept as a guard: RecordHistory::resolveElement() happens to map live->version
 * on its own while the backend user sits in the task's workspace, which is
 * exactly why the defect stayed invisible for the case anyone would test by
 * hand - and why the member without a version is the one that has to be
 * asserted.
 */
final class WorkspaceIntegrationDiffTest extends FunctionalTestCase
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
        // getTaskDetails() renders record titles and TCA field labels, both of
        // which reach for $GLOBALS['LANG'] - a request the CLI test run does
        // not set up on its own.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    private function subject(): WorkspaceIntegrationService
    {
        return $this->get(WorkspaceIntegrationService::class);
    }

    /**
     * Save straight into Live, the way an editor outside any workspace does.
     * This is what leaves `workspace = 0` rows in sys_history.
     */
    private function editInLive(string $table, int $uid, array $fields): void
    {
        $GLOBALS['BE_USER']->setWorkspace(0);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $fields]], []);
        $dataHandler->process_datamap();
    }

    /**
     * Same helper as WorkspaceConflictDetectorTest - the DataHandler path the
     * backend form takes, which is what creates the version record.
     */
    private function editInWorkspace(string $table, int $uid, array $fields, int $workspaceUid): void
    {
        $GLOBALS['BE_USER']->setWorkspace($workspaceUid);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $fields]], []);
        $dataHandler->process_datamap();
    }

    /**
     * A task with a member that was never edited inside its workspace - the
     * `origin = manual` case an editor produces by attaching a record by hand.
     * Written directly rather than through TaskAutoCreationService, which only
     * ever opens a task off a workspace edit and so cannot reach this state.
     */
    private function createTaskWithManualMember(int $workspaceUid, string $table, int $uid): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task');
        $connection->insert('tx_editorialflow_task', [
            'pid' => 0,
            'title' => 'About us',
            'subject_table' => $table,
            'subject_uid' => $uid,
            'subject_pid' => 1,
            'state' => 'in_progress',
            'workspace_uid' => $workspaceUid,
            'closed' => 0,
        ]);
        $taskUid = (int)$connection->lastInsertId();

        $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task_item')->insert(
            'tx_editorialflow_task_item',
            [
                'pid' => 0,
                'task' => $taskUid,
                'record_table' => $table,
                'record_uid' => $uid,
                'origin' => 'manual',
                'home_pid' => 1,
                'closed' => 0,
            ],
        );

        return $taskUid;
    }

    #[Test]
    public function liveOnlyHistoryIsNotReportedAsTheTasksOwnChanges(): void
    {
        // Three revisions in Live, none of them this task's doing.
        $this->editInLive('pages', 2, ['title' => 'About us (typo fix)']);
        $this->editInLive('pages', 2, ['title' => 'About us (rewritten)']);
        $this->editInLive('pages', 2, ['subtitle' => 'Who we are']);

        $taskUid = $this->createTaskWithManualMember(1, 'pages', 2);
        $GLOBALS['BE_USER']->setWorkspace(1);

        $details = $this->subject()->getTaskDetails($taskUid);

        self::assertSame(
            [],
            $details['diffs'],
            'the record has no version in this workspace, so the task has no changes of its own to show',
        );
        self::assertSame(
            [],
            $details['members'],
            'and an untouched member must not reach "Covered records" on the strength of somebody else\'s Live edits',
        );
    }

    #[Test]
    public function theWorkspacesOwnEditIsReportedAndLiveHistoryStaysOut(): void
    {
        $this->editInLive('pages', 2, ['title' => 'About us (Live)']);

        $taskUid = $this->createTaskWithManualMember(1, 'pages', 2);
        $this->editInWorkspace('pages', 2, ['subtitle' => 'Draft subtitle'], 1);
        $GLOBALS['BE_USER']->setWorkspace(1);

        $details = $this->subject()->getTaskDetails($taskUid);

        self::assertNotSame([], $details['diffs'], 'the workspace edit is what the ticket exists to show');
        self::assertCount(1, $details['members'], 'the member is covered now - it has a pending version');
        self::assertTrue($details['members'][0]['hasDiffs']);

        // The anchor stays the live uid, so Ticket.html's Diff jump button still
        // matches its member row - only the history source changed.
        self::assertSame(2, $details['diffs'][0]['uid']);
        self::assertSame('pages', $details['diffs'][0]['table']);

        // Only the subtitle was touched in the workspace; the title change was
        // made in Live and must not appear alongside it. "Page title" is the
        // rendered TCA label core's DiffUtility produces for pages.title.
        self::assertNotContains(
            'Page title',
            array_column($details['diffs'], 'label'),
            'the Live-only title change belongs to Live, not to this task',
        );
    }

    /**
     * The stuck state, stated instead of left as a blank ticket.
     *
     * A task holding a workspace with no pending version anywhere refuses every
     * action, because each one asks for a version first - and the ticket
     * otherwise just looks empty, with no hint why.
     *
     * Not derived from sys_history, which is the obvious thing to try: a
     * discarded version's rows stay filed under a uid nothing resolves (this
     * test's own discard leaves them under the version uid, not the live one),
     * so a history-based check would report nothing at all here.
     */
    #[Test]
    public function aTaskWhoseDraftIsGoneSaysSoRatherThanRenderingBlank(): void
    {
        $taskUid = $this->createTaskWithManualMember(1, 'pages', 2);
        $this->editInWorkspace('pages', 2, ['subtitle' => 'Draft subtitle'], 1);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['pages' => [2 => ['discard' => true]]]);
        $dataHandler->process_cmdmap();

        $details = $this->subject()->getTaskDetails($taskUid);

        self::assertTrue($details['nothingPending']);
    }

    #[Test]
    public function aTaskWithWorkStillPendingIsNotReportedAsStuck(): void
    {
        $taskUid = $this->createTaskWithManualMember(1, 'pages', 2);
        $this->editInWorkspace('pages', 2, ['subtitle' => 'Draft subtitle'], 1);
        $GLOBALS['BE_USER']->setWorkspace(1);

        $details = $this->subject()->getTaskDetails($taskUid);

        self::assertFalse($details['nothingPending']);
    }

    /**
     * A task that never entered a workspace is not stuck, it is unstarted - and
     * the planning columns still accept it, so there is nothing to explain.
     */
    #[Test]
    public function aTaskWithoutAWorkspaceIsNotReportedAsStuck(): void
    {
        $taskUid = $this->createTaskWithManualMember(0, 'pages', 2);

        $details = $this->subject()->getTaskDetails($taskUid);

        self::assertFalse($details['nothingPending']);
    }

    /**
     * A finished task must still say what it did.
     *
     * Two things used to empty this out at once: close() marks every member row
     * closed, and findMembers() filters closed = 0, so the ticket had no members
     * before it looked at a single diff; and the version uid the diff reader
     * keys on does not survive publishing.
     */
    #[Test]
    public function aClosedTasksTicketStillShowsWhatItChanged(): void
    {
        $taskUid = $this->createTaskWithManualMember(1, 'pages', 2);
        $this->editInWorkspace('pages', 2, ['subtitle' => 'Draft subtitle'], 1);
        $GLOBALS['BE_USER']->setWorkspace(1);

        $this->get(TaskRepository::class)->close($taskUid, 1);

        $details = $this->subject()->getTaskDetails($taskUid);

        self::assertCount(1, $details['members'], 'the archive reader sees the closed member rows');
        self::assertNotSame([], $details['diffs'], 'and the trail is still addressable');
        self::assertContains('Subtitle', array_column($details['diffs'], 'label'));
    }

    /**
     * The trap in the archive reader.
     *
     * Publishing re-points the version's sys_history rows at the live uid, so
     * reading the live uid through core's HistoryService looks correct. But that
     * migration rewrites `recuid` only and never the `workspace` column, while
     * RecordHistory::findEventsForRecord() always lets workspace = 0 through -
     * so from Live the live uid returns everybody's Live edits, attributed to
     * this task. Scoping the query to the task's workspace is what prevents it,
     * and it has to hold whatever workspace the reader is sitting in.
     */
    #[Test]
    public function aClosedTasksArchiveNeverAdoptsLiveEditsEvenWhenReadFromLive(): void
    {
        $this->editInLive('pages', 2, ['title' => 'About us (Live)']);

        $taskUid = $this->createTaskWithManualMember(1, 'pages', 2);
        $this->editInWorkspace('pages', 2, ['subtitle' => 'Draft subtitle'], 1);
        $this->get(TaskRepository::class)->close($taskUid, 1);

        $GLOBALS['BE_USER']->setWorkspace(0);
        $details = $this->subject()->getTaskDetails($taskUid);

        self::assertNotContains(
            'Page title',
            array_column($details['diffs'], 'label'),
            'the Live title change was never this task\'s work',
        );
    }
}
