<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Controller;

use GbWeb\EditorialFlow\Controller\TaskAjaxController;
use GbWeb\EditorialFlow\Domain\Model\TaskState;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Service\PendingSubjectHandoff;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PendingRecordTicketMovesTest extends FunctionalTestCase
{
    private const RECORD_TABLE = 'sys_category';

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
        $GLOBALS['BE_USER']->setWorkspace(1);
    }

    private function subject(): TaskAjaxController
    {
        return $this->get(TaskAjaxController::class);
    }

    private function request(array $body = [], array $query = []): ServerRequestInterface
    {
        return (new ServerRequest())
            ->withParsedBody($body)
            ->withQueryParams($query)
            ->withMethod('POST');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function createPendingRecordTask(): int
    {
        $task = $this->get(TaskRepository::class)->createPendingSubjectTask(1, self::RECORD_TABLE, [
            'title' => 'New category',
            'state' => TaskState::PLANNED->value,
            'workspace_uid' => 0,
            'closed' => 0,
        ]);

        return (int)$task['uid'];
    }

    /**
     * @return array<string, mixed>
     */
    private function moveIntoEditing(int $taskUid): array
    {
        return $this->decode($this->subject()->moveStageAction($this->request([
            'task' => $taskUid,
            'state' => TaskState::IN_PROGRESS->value,
            'stageUid' => StagesService::STAGE_EDIT_ID,
        ])));
    }

    #[Test]
    public function movingIntoEditingRequestsAPageAndListsAllEligibleTargets(): void
    {
        $taskUid = $this->createPendingRecordTask();

        $move = $this->moveIntoEditing($taskUid);
        self::assertTrue($move['success']);
        self::assertTrue($move['requiresRecordTarget']);
        self::assertSame(self::RECORD_TABLE, $move['recordTable']);

        $targets = $this->decode($this->subject()->recordCreationTargetsAction($this->request([
            'task' => $taskUid,
        ])));
        self::assertTrue($targets['success']);
        self::assertSame([1, 2], array_column($targets['pages'], 'uid'));
    }

    #[Test]
    public function manipulatedTargetPageIsRejectedServerSide(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => 'mod.web_list.deniedNewTables = ' . self::RECORD_TABLE],
            ['uid' => 2],
        );
        $taskUid = $this->createPendingRecordTask();

        $response = $this->subject()->startRecordCreationAction($this->request([
            'task' => $taskUid,
            'page' => 2,
        ]));
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('record-target-not-allowed', $payload['code']);
        self::assertNull($this->get(PendingSubjectHandoff::class)->resolve($GLOBALS['BE_USER']));
    }

    #[Test]
    public function formEngineCreationIsClaimedByTheWaitingTask(): void
    {
        $taskUid = $this->createPendingRecordTask();
        $this->moveIntoEditing($taskUid);

        $start = $this->decode($this->subject()->startRecordCreationAction($this->request([
            'task' => $taskUid,
            'page' => 2,
        ])));
        self::assertTrue($start['success']);
        self::assertNotSame('', $start['redirectUrl']);

        $recordUid = $this->createCategoryThroughDataHandler(2, 'Hero category');
        $task = $this->get(TaskRepository::class)->findByUid($taskUid);

        self::assertSame(self::RECORD_TABLE, $task['subject_table']);
        self::assertSame($recordUid, (int)$task['subject_uid']);
        self::assertSame(2, (int)$task['subject_pid']);
        self::assertSame(1, (int)$task['workspace_uid']);
        self::assertSame(StagesService::STAGE_EDIT_ID, (int)$task['stage_uid']);
        self::assertSame(TaskState::IN_PROGRESS->value, $task['state']);
        self::assertSame(
            $taskUid,
            (int)$this->get(TaskRepository::class)->findOpenTaskByMember(self::RECORD_TABLE, $recordUid)['uid'],
        );
        self::assertNull($this->get(PendingSubjectHandoff::class)->resolve($GLOBALS['BE_USER']));
    }

    #[Test]
    public function cancelledFormEngineCreationDoesNotClaimTheNextRecord(): void
    {
        $taskUid = $this->createPendingRecordTask();
        $this->moveIntoEditing($taskUid);
        $this->subject()->startRecordCreationAction($this->request(['task' => $taskUid, 'page' => 2]));

        $response = $this->subject()->recordCreationReturnAction($this->request(query: [
            'task' => $taskUid,
            'id' => 2,
        ]));
        self::assertSame(303, $response->getStatusCode());

        $recordUid = $this->createCategoryThroughDataHandler(2, 'Unrelated');
        $task = $this->get(TaskRepository::class)->findByUid($taskUid);
        self::assertSame(0, (int)$task['subject_uid']);
        // Not claimed by the cancelled ticket. It is not taskless either: a
        // record created inside a workspace opens its own task like any other
        // pending change (see NewRecordInWorkspaceTest).
        $owner = $this->get(TaskRepository::class)->findOpenTaskByMember(self::RECORD_TABLE, $recordUid);
        self::assertNotSame($taskUid, (int)($owner['uid'] ?? 0));
    }

    #[Test]
    public function aNewRecordOfAnotherTableIsNotClaimed(): void
    {
        $taskUid = $this->createPendingRecordTask();
        $this->moveIntoEditing($taskUid);
        $this->subject()->startRecordCreationAction($this->request(['task' => $taskUid, 'page' => 2]));

        $placeholder = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [$placeholder => [
            'pid' => 1,
            'title' => 'Unrelated page',
            'doktype' => 1,
        ]]], []);
        $dataHandler->process_datamap();

        $task = $this->get(TaskRepository::class)->findByUid($taskUid);
        self::assertSame(0, (int)$task['subject_uid']);
        self::assertNotNull($this->get(PendingSubjectHandoff::class)->resolve($GLOBALS['BE_USER']));
    }

    #[Test]
    public function closingAnUnrelatedPageWizardDoesNotCancelARecordHandoff(): void
    {
        $taskUid = $this->createPendingRecordTask();
        $this->moveIntoEditing($taskUid);
        $this->subject()->startRecordCreationAction($this->request(['task' => $taskUid, 'page' => 2]));

        $this->subject()->cancelPageWizardAction($this->request());

        $handoff = $this->get(PendingSubjectHandoff::class)->resolve($GLOBALS['BE_USER']);
        self::assertIsArray($handoff);
        self::assertSame($taskUid, $handoff['taskUid']);
        self::assertSame(self::RECORD_TABLE, $handoff['subjectTable']);
    }

    private function createCategoryThroughDataHandler(int $pageUid, string $title): int
    {
        $placeholder = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::RECORD_TABLE => [$placeholder => [
            'pid' => $pageUid,
            'title' => $title,
        ]]], []);
        $dataHandler->process_datamap();

        $uid = (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
        self::assertGreaterThan(0, $uid, implode(' ', array_map('strval', $dataHandler->errorLog)));

        return $uid;
    }
}
