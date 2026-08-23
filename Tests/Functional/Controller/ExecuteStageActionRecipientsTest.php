<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Controller;

use GbWeb\EditorialFlow\Controller\TaskAjaxController;
use GbWeb\EditorialFlow\Domain\Model\TaskState;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Service\ActiveTaskSession;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ExecuteStageActionRecipientsTest extends FunctionalTestCase
{
    use BuildsTaskAjaxController;

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
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');
    }

    #[Test]
    public function additionalRecipientEmailsAreForwardedInTheFormatCoreWritesToHistory(): void
    {
        $stageUid = $this->createReviewStage();
        $this->editPageInWorkspace();

        $taskUid = $this->findOpenTaskUid();
        $versionUid = $this->versionUidOf('pages', 2);
        self::assertGreaterThan(0, $taskUid, 'editing should have opened a task');
        self::assertGreaterThan(0, $versionUid, 'editing should have created a workspace version');

        $response = $this->subject()->executeStageAction($this->jsonRequest([
            'task' => $taskUid,
            'stageUid' => $stageUid,
            'comment' => 'Ready for review.',
            'additional' => "review@example.org\nnot-an-email",
        ]));
        $payload = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame($stageUid, $payload['stageUid']);

        $entries = $this->stageHistoryFor('pages', $versionUid);
        self::assertCount(1, $entries, 'core should record exactly one stage change');

        $historyPayload = json_decode((string)$entries[0]['history_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Ready for review.', $historyPayload['comment']);
        self::assertSame([
            ['email' => 'review@example.org'],
        ], $historyPayload['recipients']);
    }

    #[Test]
    public function aSuccessfulStageChangeCanStopTheActiveTaskWithoutClosingIt(): void
    {
        $stageUid = $this->createReviewStage();
        $this->editPageInWorkspace();
        $taskUid = $this->findOpenTaskUid();
        $this->get(ActiveTaskSession::class)->remember($GLOBALS['BE_USER'], 2, $taskUid);

        $payload = $this->decode($this->subject()->executeStageAction($this->jsonRequest([
            'task' => $taskUid,
            'stageUid' => $stageUid,
            'deactivateActiveTask' => true,
        ])));

        self::assertTrue($payload['success']);
        self::assertTrue($payload['activeTaskDeactivated']);
        self::assertNull($this->get(ActiveTaskSession::class)->current($GLOBALS['BE_USER']));
        self::assertSame(TaskState::REVIEW->value, $this->get(TaskRepository::class)->findByUid($taskUid)['state']);

        // Deactivation releases only the editor's choice. The record still
        // belongs to this open task, so a real edit reopens the same card.
        $this->editPageInWorkspace('About us (edited after review)');
        $reopened = $this->get(TaskRepository::class)->findByUid($taskUid);
        self::assertSame(TaskState::IN_PROGRESS->value, $reopened['state']);
        self::assertSame(0, (int)$reopened['stage_uid']);
        self::assertSame($taskUid, $this->findOpenTaskUid());
    }

    #[Test]
    public function anActiveTaskCanRemainSelectedAfterTheStageChange(): void
    {
        $stageUid = $this->createReviewStage();
        $this->editPageInWorkspace();
        $taskUid = $this->findOpenTaskUid();
        $this->get(ActiveTaskSession::class)->remember($GLOBALS['BE_USER'], 2, $taskUid);

        $payload = $this->decode($this->subject()->executeStageAction($this->jsonRequest([
            'task' => $taskUid,
            'stageUid' => $stageUid,
            'deactivateActiveTask' => false,
        ])));

        self::assertTrue($payload['success']);
        self::assertFalse($payload['activeTaskDeactivated']);
        self::assertSame($taskUid, $this->get(ActiveTaskSession::class)->resolve($GLOBALS['BE_USER'], 2));
    }

    private function subject(): TaskAjaxController
    {
        return $this->buildTaskAjaxController();
    }

    private function jsonRequest(array $body): ServerRequestInterface
    {
        return (new ServerRequest())->withParsedBody($body)->withMethod('POST');
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

    private function createReviewStage(): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_workspace_stage');
        $connection->insert('sys_workspace_stage', [
            'pid' => 0,
            'parentid' => 1,
            'title' => 'Review',
        ]);

        return (int)$connection->lastInsertId('sys_workspace_stage');
    }

    private function editPageInWorkspace(string $title = 'About us (revised)'): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [2 => ['title' => $title]]], []);
        $dataHandler->process_datamap();
    }

    private function findOpenTaskUid(): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_task');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->select('uid')
            ->from('tx_editorialflow_task')
            ->where(
                $queryBuilder->expr()->eq('subject_table', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('subject_uid', $queryBuilder->createNamedParameter(2)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0)),
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    private function versionUidOf(string $table, int $liveUid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(1)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stageHistoryFor(string $table, int $recordUid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_history');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('sys_history')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($recordUid)),
                $queryBuilder->expr()->eq('actiontype', $queryBuilder->createNamedParameter(RecordHistoryStore::ACTION_STAGECHANGE)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
