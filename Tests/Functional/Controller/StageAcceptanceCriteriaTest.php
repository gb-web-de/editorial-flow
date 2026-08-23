<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Controller;

use GbWeb\EditorialFlow\Controller\TaskAjaxController;
use GbWeb\EditorialFlow\Domain\Model\TaskState;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Service\ActivityLogger;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A stage's acceptance criteria are asked before the task leaves it, and the
 * answer is written down.
 *
 * The question is asked server-side, not only in the dialog: the dialog is a
 * client, and a client that skips the question must not be able to skip the
 * rule with it. It is a question and not a gate, though - the acknowledgement
 * always gets the task through, which is why every test here that sends one
 * asserts the task actually moved.
 */
final class StageAcceptanceCriteriaTest extends FunctionalTestCase
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
    public function aStageWithoutCriteriaAsksNothing(): void
    {
        $stageUid = $this->createReviewStage();
        $this->editPageInWorkspace();
        $taskUid = $this->findOpenTaskUid();

        $payload = $this->decode($this->subject()->executeStageAction($this->jsonRequest([
            'task' => $taskUid,
            'stageUid' => $stageUid,
        ])));

        self::assertTrue($payload['success']);
        self::assertSame(TaskState::REVIEW->value, $this->taskRow($taskUid)['state']);
        self::assertSame([], $this->commentsFor($taskUid), 'nobody was asked, so nothing is recorded');
    }

    #[Test]
    public function anUnconfirmedCriterionStopsTheFirstAttemptAndNamesItself(): void
    {
        $stageUid = $this->createReviewStage();
        $this->addCriterion(0, 'All links checked');
        $this->addCriterion(0, 'Images have alt text');
        $this->editPageInWorkspace();
        $taskUid = $this->findOpenTaskUid();

        $response = $this->subject()->executeStageAction($this->jsonRequest([
            'task' => $taskUid,
            'stageUid' => $stageUid,
        ]));
        $payload = $this->decode($response);

        // 200, not 400: nothing failed and nothing is refused - the server is
        // asking a question, and an error status would have the board's own
        // refusal handling report it as one.
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('checklist-incomplete', $payload['code']);
        self::assertTrue($payload['needsAcknowledgement']);
        self::assertSame(['All links checked', 'Images have alt text'], $payload['unconfirmed']);

        // The transition really did not happen - a question that moves the task
        // anyway is not a question.
        self::assertSame(TaskState::IN_PROGRESS->value, $this->taskRow($taskUid)['state']);
    }

    #[Test]
    public function acknowledgingSendsTheTaskOnAndSaysSoInTheRecord(): void
    {
        $stageUid = $this->createReviewStage();
        $first = $this->addCriterion(0, 'All links checked');
        $this->addCriterion(0, 'Images have alt text');
        $this->editPageInWorkspace();
        $taskUid = $this->findOpenTaskUid();

        $this->subject()->checklistToggleAction($this->jsonRequest([
            'task' => $taskUid,
            'itemUid' => $first,
            'completed' => true,
        ]));

        $payload = $this->decode($this->subject()->executeStageAction($this->jsonRequest([
            'task' => $taskUid,
            'stageUid' => $stageUid,
            'acknowledgeIncomplete' => true,
        ])));

        self::assertTrue($payload['success']);
        self::assertSame(TaskState::REVIEW->value, $this->taskRow($taskUid)['state']);

        $record = $this->acceptanceRecordFor($taskUid);
        self::assertStringContainsString('[x] All links checked', $record);
        self::assertStringContainsString('[ ] Images have alt text', $record);
        self::assertStringContainsString('Sent on with 1 of 2 criteria left unconfirmed.', $record);
    }

    #[Test]
    public function everythingConfirmedNeedsNoAcknowledgementAndSaysSo(): void
    {
        $stageUid = $this->createReviewStage();
        $itemUid = $this->addCriterion(0, 'All links checked');
        $this->editPageInWorkspace();
        $taskUid = $this->findOpenTaskUid();

        $this->subject()->checklistToggleAction($this->jsonRequest([
            'task' => $taskUid,
            'itemUid' => $itemUid,
            'completed' => true,
        ]));

        // No acknowledgeIncomplete: there is nothing left to acknowledge.
        $payload = $this->decode($this->subject()->executeStageAction($this->jsonRequest([
            'task' => $taskUid,
            'stageUid' => $stageUid,
        ])));

        self::assertTrue($payload['success']);
        self::assertStringContainsString('All criteria confirmed.', $this->acceptanceRecordFor($taskUid));
    }

    #[Test]
    public function theEditorsOwnCommentAndTheRecordBothSurvive(): void
    {
        $stageUid = $this->createReviewStage();
        $this->addCriterion(0, 'All links checked');
        $this->editPageInWorkspace();
        $taskUid = $this->findOpenTaskUid();

        $this->subject()->executeStageAction($this->jsonRequest([
            'task' => $taskUid,
            'stageUid' => $stageUid,
            'comment' => 'Sending this on before the weekend.',
            'acknowledgeIncomplete' => true,
        ]));

        $comments = $this->commentsFor($taskUid);
        self::assertCount(2, $comments, 'the editor wrote one, the record is the other');
        self::assertSame('Sending this on before the weekend.', $comments[0]['content']);
        self::assertStringContainsString('[ ] All links checked', (string)$comments[1]['content']);

        // Both anchored to the transition they belong to, so the ticket nests
        // them under it instead of leaving them floating in the timeline.
        self::assertGreaterThan(0, (int)$comments[0]['activity']);
        self::assertSame((int)$comments[0]['activity'], (int)$comments[1]['activity']);
    }

    #[Test]
    public function everyTickIsRecordedAsItHappens(): void
    {
        $this->createReviewStage();
        $itemUid = $this->addCriterion(0, 'All links checked');
        $this->editPageInWorkspace();
        $taskUid = $this->findOpenTaskUid();

        $this->subject()->checklistToggleAction($this->jsonRequest([
            'task' => $taskUid,
            'itemUid' => $itemUid,
            'completed' => true,
        ]));
        $this->subject()->checklistToggleAction($this->jsonRequest([
            'task' => $taskUid,
            'itemUid' => $itemUid,
            'completed' => false,
        ]));

        $entries = array_values(array_filter(
            $this->get(ActivityLogger::class)->findByTask($taskUid),
            static fn (array $entry): bool => $entry['event'] === ActivityLogger::EVENT_CHECKLIST_CHECKED,
        ));

        self::assertCount(2, $entries, 'withdrawing a confirmation is a decision too');

        // The title travels in the payload rather than as a pointer, so the
        // entry still reads correctly once the criterion itself is gone.
        $payloads = array_map(
            static fn (array $entry): array => json_decode((string)$entry['payload'], true, 512, JSON_THROW_ON_ERROR),
            $entries,
        );
        self::assertSame('All links checked', $payloads[0]['title']);
        self::assertTrue($payloads[0]['checked']);
        self::assertFalse($payloads[1]['checked']);
    }

    /**
     * A form-encoded request delivers a boolean as text, and (bool)"false" is
     * true - so withdrawing a confirmation was recorded as giving one, and the
     * box came back ticked the next time the ticket was opened. Found by the
     * browser test, because both sides of it looked right in isolation: the
     * client sent `false` and the server read a boolean.
     */
    #[Test]
    public function aWithdrawnConfirmationSentAsTextIsStillAWithdrawal(): void
    {
        $this->createReviewStage();
        $itemUid = $this->addCriterion(0, 'All links checked');
        $this->editPageInWorkspace();
        $taskUid = $this->findOpenTaskUid();

        $this->subject()->checklistToggleAction($this->jsonRequest([
            'task' => $taskUid,
            'itemUid' => $itemUid,
            'completed' => true,
        ]));
        $this->subject()->checklistToggleAction($this->jsonRequest([
            'task' => $taskUid,
            'itemUid' => $itemUid,
            // The string, exactly as a form-encoded body delivers it.
            'completed' => 'false',
        ]));

        $payload = $this->decode($this->subject()->checkStageTransitionEligibilityAction($this->jsonRequest([
            'task' => $taskUid,
        ])));

        self::assertFalse($payload['criteria'][0]['completed']);
    }

    #[Test]
    public function aCriterionThatDoesNotExistIsRefused(): void
    {
        $this->createReviewStage();
        $this->editPageInWorkspace();
        $taskUid = $this->findOpenTaskUid();

        $response = $this->subject()->checklistToggleAction($this->jsonRequest([
            'task' => $taskUid,
            'itemUid' => 987654,
            'completed' => true,
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('missing-checklist-item', $this->decode($response)['code']);
        self::assertSame(
            [],
            array_filter(
                $this->get(ActivityLogger::class)->findByTask($taskUid),
                static fn (array $entry): bool => $entry['event'] === ActivityLogger::EVENT_CHECKLIST_CHECKED,
            ),
            'a refused toggle must not leave a confirmation behind',
        );
    }

    #[Test]
    public function theBoardIsToldWhatToAskBeforeTheDialogOpens(): void
    {
        $this->createReviewStage();
        $itemUid = $this->addCriterion(0, 'All links checked');
        $this->editPageInWorkspace();
        $taskUid = $this->findOpenTaskUid();

        $this->subject()->checklistToggleAction($this->jsonRequest([
            'task' => $taskUid,
            'itemUid' => $itemUid,
            'completed' => true,
        ]));

        $payload = $this->decode($this->subject()->checkStageTransitionEligibilityAction($this->jsonRequest([
            'task' => $taskUid,
        ])));

        self::assertTrue($payload['hasPending']);
        self::assertSame(
            [['uid' => $itemUid, 'title' => 'All links checked', 'completed' => true]],
            $payload['criteria'],
        );
    }

    private function subject(): TaskAjaxController
    {
        return $this->buildTaskAjaxController();
    }

    /**
     * The criteria of the stage a freshly edited task sits in, which is always
     * the default Editing stage (uid 0) - the one it is about to leave.
     */
    private function addCriterion(int $stageUid, string $title): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_stage_checklist_item');
        $connection->insert('tx_editorialflow_stage_checklist_item', [
            'pid' => 0,
            'workspace_uid' => 1,
            'stage_uid' => $stageUid,
            'title' => $title,
            'sorting' => $this->criterionCount(),
        ]);

        return (int)$connection->lastInsertId();
    }

    private function criterionCount(): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_stage_checklist_item');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('tx_editorialflow_stage_checklist_item')
            ->executeQuery()
            ->fetchOne();
    }

    private function acceptanceRecordFor(int $taskUid): string
    {
        foreach ($this->commentsFor($taskUid) as $comment) {
            if (str_contains((string)$comment['content'], 'Acceptance criteria for')) {
                return (string)$comment['content'];
            }
        }

        self::fail('No acceptance record was written for task ' . $taskUid . '.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commentsFor(int $taskUid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_comment');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('tx_editorialflow_comment')
            ->where($queryBuilder->expr()->eq('task', $queryBuilder->createNamedParameter($taskUid)))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(int $taskUid): array
    {
        $row = $this->get(TaskRepository::class)->findByUid($taskUid);
        self::assertIsArray($row);

        return $row;
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
}
