<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Controller;

use GbWeb\EditorialFlow\Controller\TaskAjaxController;
use GbWeb\EditorialFlow\Service\ActivityLogger;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A task must always be closable.
 *
 * Before this action existed, the only thing that ever closed a task was
 * CloseTaskAfterPublishListener reacting to a publish. A task whose versions
 * had been discarded had nothing left to publish, and every other exit was
 * refused on purpose: stage moves answer `no-pending-versions`, the planning
 * columns answer `cannot-return-versioned-task-to-planning`, and the Done
 * column takes no drops because going live is an explicit act. The task stayed
 * open forever with no way out. The first test here is that exact situation.
 *
 * The most valuable assertion in this class is the one about discarding from
 * Live. DataHandler::discard() resolves the version through the acting user's
 * workspace and simply returns when that workspace is Live - without writing
 * anything to errorLog. An implementation that trusts an empty errorLog reports
 * a successful discard, destroys nothing, and then closes the task on top of
 * the versions it claimed to have removed.
 */
final class TaskCloseActionTest extends FunctionalTestCase
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
        // setWorkspace(), never ->workspace = 1: only the setter validates the
        // workspace and populates workspaceRec, which DataHandler needs before
        // it will version anything.
        $GLOBALS['BE_USER']->setWorkspace(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    private function subject(): TaskAjaxController
    {
        return $this->get(TaskAjaxController::class);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postRequest(array $body): ServerRequestInterface
    {
        return (new ServerRequest())->withParsedBody($body)->withMethod('POST');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createTask(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task');
        $connection->insert('tx_editorialflow_task', array_merge([
            'pid' => 0,
            'title' => 'About us',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 1,
            'state' => 'in_progress',
            'workspace_uid' => 1,
            'closed' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    private function addMember(int $taskUid, string $table, int $recordUid): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task_item')->insert(
            'tx_editorialflow_task_item',
            [
                'pid' => 0,
                'task' => $taskUid,
                'record_table' => $table,
                'record_uid' => $recordUid,
                'origin' => 'manual',
                'home_pid' => 2,
                'closed' => 0,
            ],
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function editInWorkspace(string $table, int $uid, array $fields, int $workspaceUid = 1): void
    {
        $GLOBALS['BE_USER']->setWorkspace($workspaceUid);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $fields]], []);
        $dataHandler->process_datamap();
    }

    private function versionUidOf(string $table, int $liveUid, int $workspaceUid = 1): int
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

    /**
     * @return list<array<string, mixed>>
     */
    private function activityFor(int $taskUid, string $event): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_activity');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('tx_editorialflow_activity')
            ->where(
                $queryBuilder->expr()->eq('task', $queryBuilder->createNamedParameter($taskUid)),
                $queryBuilder->expr()->eq('event', $queryBuilder->createNamedParameter($event)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * The situation this whole action exists for: a task that was worked on,
     * whose version was then discarded, leaving nothing to publish and no exit.
     */
    #[Test]
    public function aTaskWhoseVersionWasDiscardedElsewhereCanStillBeClosed(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 'tt_content', 10);
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (draft)']);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['tt_content' => [10 => ['discard' => true]]]);
        $dataHandler->process_cmdmap();
        self::assertSame(0, $this->versionUidOf('tt_content', 10), 'precondition: nothing pending is left');

        $payload = $this->decode($this->subject()->closeTaskAction($this->postRequest(['task' => $taskUid])));

        self::assertTrue($payload['success']);
        self::assertSame(1, (int)$this->taskRow($taskUid)['closed']);
    }

    #[Test]
    public function closingWithoutAModeLeavesPendingVersionsAlone(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 'tt_content', 10);
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (draft)']);
        $versionUid = $this->versionUidOf('tt_content', 10);
        self::assertGreaterThan(0, $versionUid);

        $payload = $this->decode($this->subject()->closeTaskAction($this->postRequest(['task' => $taskUid])));

        self::assertTrue($payload['success']);
        self::assertSame('keep', $payload['mode'], 'a missing mode must never be the destructive one');
        self::assertSame(1, $payload['keptPending']);
        self::assertSame(
            $versionUid,
            $this->versionUidOf('tt_content', 10),
            'the version survives - it is still in the workspace, just unclaimed',
        );
        self::assertSame(1, (int)$this->taskRow($taskUid)['closed']);
    }

    #[Test]
    public function theActivityTrailNamesWhatWasLeftBehind(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 'tt_content', 10);
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (draft)']);

        $this->subject()->closeTaskAction($this->postRequest(['task' => $taskUid, 'mode' => 'keep']));

        $entries = $this->activityFor($taskUid, ActivityLogger::EVENT_CLOSED);
        self::assertCount(1, $entries);
        $payload = json_decode((string)$entries[0]['payload'], true);
        self::assertSame('manual', $payload['reason']);
        self::assertSame('keep', $payload['mode']);
        self::assertSame('tt_content', $payload['keptPending'][0]['table']);
        self::assertSame(10, $payload['keptPending'][0]['uid']);
    }

    #[Test]
    public function discardModeThrowsThePendingVersionAway(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 'tt_content', 10);
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (draft)']);
        self::assertGreaterThan(0, $this->versionUidOf('tt_content', 10));

        $payload = $this->decode(
            $this->subject()->closeTaskAction($this->postRequest(['task' => $taskUid, 'mode' => 'discard'])),
        );

        self::assertTrue($payload['success']);
        self::assertSame(1, $payload['discarded']);
        self::assertSame(0, $payload['keptPending']);
        self::assertSame(0, $this->versionUidOf('tt_content', 10));
        self::assertSame(1, (int)$this->taskRow($taskUid)['closed']);
        self::assertCount(1, $this->activityFor($taskUid, ActivityLogger::EVENT_DISCARDED));
    }

    /**
     * The trap. DataHandler::discard() returns without a word - and crucially
     * without an errorLog entry - when the acting user sits in Live, because it
     * resolves the version through that user's workspace. Trusting an empty
     * errorLog here means reporting success, discarding nothing, and closing the
     * task anyway.
     */
    #[Test]
    public function discardingFromLiveIsRefusedAndDestroysNothing(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 'tt_content', 10);
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (draft)']);
        $versionUid = $this->versionUidOf('tt_content', 10);
        self::assertGreaterThan(0, $versionUid);

        $GLOBALS['BE_USER']->setWorkspace(0);
        $response = $this->subject()->closeTaskAction($this->postRequest(['task' => $taskUid, 'mode' => 'discard']));
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('close-requires-task-workspace', $payload['code']);
        self::assertStringContainsString('Editorial', $payload['message'], 'the message names the workspace to switch to');
        self::assertSame($versionUid, $this->versionUidOf('tt_content', 10), 'nothing was discarded');
        self::assertSame(0, (int)$this->taskRow($taskUid)['closed'], 'and the task stays open');
    }

    #[Test]
    public function anUnknownModeIsRefusedRatherThanGuessedAt(): void
    {
        $taskUid = $this->createTask();

        $response = $this->subject()->closeTaskAction($this->postRequest(['task' => $taskUid, 'mode' => 'nuke']));
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('unknown-close-mode', $payload['code']);
        self::assertSame(0, (int)$this->taskRow($taskUid)['closed']);
    }

    #[Test]
    public function anAlreadyClosedTaskSaysSo(): void
    {
        $taskUid = $this->createTask(['closed' => 1, 'state' => 'done']);

        $response = $this->subject()->closeTaskAction($this->postRequest(['task' => $taskUid]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('task-closed', $this->decode($response)['code']);
    }

    #[Test]
    public function aMissingTaskSaysSo(): void
    {
        $response = $this->subject()->closeTaskAction($this->postRequest(['task' => 4711]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('task-not-found', $this->decode($response)['code']);
    }

    /**
     * A ticket planned for a page that was never created has subject_uid = 0.
     * assertMayEdit() would answer `record-not-found` for it, which is why the
     * close path deliberately does not use that gate.
     */
    #[Test]
    public function aPendingTicketWithNoSubjectYetCanBeClosed(): void
    {
        $taskUid = $this->createTask([
            'subject_uid' => 0,
            'state' => 'planned',
            'workspace_uid' => 0,
        ]);

        $payload = $this->decode($this->subject()->closeTaskAction($this->postRequest(['task' => $taskUid])));

        self::assertTrue($payload['success']);
        self::assertSame(1, (int)$this->taskRow($taskUid)['closed']);
    }

    /**
     * A task whose subject page is gone must not be stranded either - closing it
     * is the only thing left to do with it.
     */
    #[Test]
    public function aTaskWhoseSubjectPageIsGoneCanStillBeClosed(): void
    {
        $taskUid = $this->createTask(['subject_uid' => 999, 'subject_pid' => 999]);

        $payload = $this->decode($this->subject()->closeTaskAction($this->postRequest(['task' => $taskUid])));

        self::assertTrue($payload['success']);
        self::assertSame(1, (int)$this->taskRow($taskUid)['closed']);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function getRequest(array $query): ServerRequestInterface
    {
        return (new ServerRequest())->withQueryParams($query);
    }

    /**
     * The dialog has to name what is at stake, not just that something is. An
     * editor choosing between keeping and discarding needs the record and the
     * fields, otherwise the choice is blind.
     */
    #[Test]
    public function thePreviewNamesEachPendingRecordAndWhichFieldsChanged(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 'tt_content', 10);
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (draft)']);

        $payload = $this->decode($this->subject()->closePreviewAction($this->getRequest(['task' => $taskUid])));

        self::assertTrue($payload['success']);
        self::assertSame('Editorial', $payload['task']['workspaceTitle']);
        self::assertCount(1, $payload['pending']);
        self::assertSame('tt_content', $payload['pending'][0]['table']);
        self::assertSame(10, $payload['pending'][0]['uid']);
        self::assertSame('Intro text', $payload['pending'][0]['title'], 'the LIVE title - the version is about to go');
        self::assertContains('Header', $payload['pending'][0]['changes']);
        self::assertSame(1, $payload['pending'][0]['changeCount']);
        self::assertTrue($payload['canDiscard']);
        self::assertSame('', $payload['discardBlockedReason']);
    }

    #[Test]
    public function aTaskWithNothingPendingPreviewsAsEmpty(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 'tt_content', 10);

        $payload = $this->decode($this->subject()->closePreviewAction($this->getRequest(['task' => $taskUid])));

        self::assertTrue($payload['success']);
        self::assertSame([], $payload['pending']);
        self::assertFalse($payload['canDiscard'], 'nothing to discard is not the same as discarding nothing');
    }

    /**
     * The dialog greys the discard option out rather than letting an editor pick
     * something the POST is certain to refuse.
     */
    #[Test]
    public function thePreviewSaysWhyDiscardingIsUnavailableFromLive(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 'tt_content', 10);
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (draft)']);

        $GLOBALS['BE_USER']->setWorkspace(0);
        $payload = $this->decode($this->subject()->closePreviewAction($this->getRequest(['task' => $taskUid])));

        self::assertFalse($payload['canDiscard']);
        self::assertStringContainsString('Editorial', $payload['discardBlockedReason']);
    }

    /**
     * Handover targets and the move picker have to agree, so both go through
     * openTaskCandidatesAround() and both apply attachAction()'s workspace rule.
     */
    #[Test]
    public function handoverTargetsExcludeTheTaskItselfAndForeignWorkspaces(): void
    {
        $taskUid = $this->createTask();
        $sibling = $this->createTask(['title' => 'Campaign']);
        $foreign = $this->createTask(['title' => 'Legal review', 'workspace_uid' => 2]);

        $payload = $this->decode($this->subject()->closePreviewAction($this->getRequest(['task' => $taskUid])));

        $offered = array_column($payload['handoverTargets'], 'uid');
        self::assertContains($sibling, $offered);
        self::assertNotContains($taskUid, $offered, 'a task cannot hand its records to itself');
        self::assertNotContains($foreign, $offered, 'attach would refuse a task in another workspace');
    }

    #[Test]
    public function previewingAClosedTaskSaysSo(): void
    {
        $taskUid = $this->createTask(['closed' => 1, 'state' => 'done']);

        $response = $this->subject()->closePreviewAction($this->getRequest(['task' => $taskUid]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('task-closed', $this->decode($response)['code']);
    }
}
