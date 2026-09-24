<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Controller;

use GbWeb\EditorialFlow\Controller\TaskAjaxController;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The board used to offer a Publish button on every open card, and
 * publishTaskAction() accepted every one of them: the only check was
 * WorkspacePublishGate, which asks about the user's ROLE and knows nothing
 * about stages. A task could go live straight out of the edit stage, skipping
 * every review the workspace defines, and then close itself as Done.
 *
 * The button being gone is a rendering decision. This covers the part that
 * actually holds: the endpoint itself, which is reachable without the button.
 */
final class TaskPublishStageGateTest extends FunctionalTestCase
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

    private int $workspaceUid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
    }

    #[Test]
    public function aMemberPublishingFromTheEditStageIsRefusedWhenTheWorkspaceRequiresThePublishStage(): void
    {
        $this->givenWorkspaceWithMember(WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE);
        $taskUid = $this->createOpenTask(StagesService::STAGE_EDIT_ID);

        $payload = $this->decode($this->publish($taskUid));

        self::assertSame('publish-not-permitted', $payload['code']);
        self::assertSame(
            'You are not allowed to publish this task from the stage it is in.',
            $payload['message'],
        );
    }

    /**
     * A custom review stage is no closer to live than the edit stage is - only
     * StagesService::STAGE_PUBLISH_ID counts, exactly as core's own
     * DataHandlerHook decides it.
     */
    #[Test]
    public function aMemberPublishingFromACustomReviewStageIsRefusedTheSameWay(): void
    {
        $this->givenWorkspaceWithMember(WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE);
        $taskUid = $this->createOpenTask(3);

        self::assertSame('publish-not-permitted', $this->decode($this->publish($taskUid))['code']);
    }

    /**
     * An admin (like a workspace owner) may act on every stage, "Ready to
     * publish" included, so core would let them drag the card there and then
     * publish. The Publish button does both in one go - and records the walk,
     * so the stage restriction is honoured rather than skipped: the version is
     * in the publish stage when core publishes it, and the trail says so.
     *
     * This replaces beingAdminDoesNotBypassTheStageRestriction, which asserted
     * a refusal. That refusal was the over-correction TaskPublishGate's
     * docblock describes: it made every owner drag before every publish.
     */
    #[Test]
    public function anAdminPublishingFromTheEditStageIsWalkedThroughThePublishStageFirst(): void
    {
        $this->givenWorkspace(WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE);
        self::assertTrue($GLOBALS['BE_USER']->isAdmin());
        $taskUid = $this->editPageInWorkspace('About us (approved)');
        // Back on Live, as a coach who never switched would be.
        $GLOBALS['BE_USER']->setWorkspace(0);

        $payload = $this->decode($this->publish($taskUid));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        self::assertSame('About us (approved)', $this->liveTitleOfPage(2));
        $stageChanges = array_values(array_filter(
            $this->activityOf($taskUid),
            static fn (array $row): bool => $row['event'] === 'stage_changed',
        ));
        self::assertCount(1, $stageChanges);
        self::assertSame(StagesService::STAGE_PUBLISH_ID, json_decode($stageChanges[0]['payload'], true)['to_stage']);
    }

    /**
     * The other half of the promise, and the reason this is not simply "publish
     * only from the last stage": with the bit unset, whoever may publish still
     * publishes straight from the card, at any stage. Refusal here would be an
     * over-correction of the bug, not a fix for it.
     */
    #[Test]
    public function withoutTheWorkspaceRestrictionPublishingFromTheEditStageStillGoesThrough(): void
    {
        $this->givenWorkspace(0);
        $taskUid = $this->createOpenTask(StagesService::STAGE_EDIT_ID);

        $payload = $this->decode($this->publish($taskUid));

        // It gets past the gate and fails further in, on there being nothing
        // pending to take live - which is what this fixture has. The gate is
        // what this asserts, so `no-pending-versions` is the pass.
        self::assertNotSame('publish-not-permitted', $payload['code'] ?? null);
        self::assertSame('no-pending-versions', $payload['code']);
    }

    /**
     * The uid is whatever the database hands out - EXT:workspaces already
     * occupies uid 1 in a functional fixture, and pinning it here made every
     * case in this class fail on a duplicate key rather than on its assertion.
     */
    private function givenWorkspace(int $publishAccess): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_workspace');
        $connection->insert('sys_workspace', [
            'pid' => 0,
            'title' => 'Editorial',
            'publish_access' => $publishAccess,
            'deleted' => 0,
        ]);
        $this->workspaceUid = (int)$connection->lastInsertId();

        $backendUser = $this->setUpBackendUser(1);
        $backendUser->workspace = $this->workspaceUid;
    }

    /**
     * A plain member with live access: WorkspacePublishGate's second branch
     * lets them publish at all, core's stage rules let them act on Editing
     * only - exactly the user the stage restriction exists for.
     */
    private function givenWorkspaceWithMember(int $publishAccess): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_workspace');
        $connection->insert('sys_workspace', [
            'pid' => 0,
            'title' => 'Editorial',
            'publish_access' => $publishAccess,
            'members' => 'be_users_2',
            'deleted' => 0,
        ]);
        $this->workspaceUid = (int)$connection->lastInsertId();
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->update('be_users', ['workspace_perms' => 1], ['uid' => 2]);

        $backendUser = $this->setUpBackendUser(2);
        $backendUser->setWorkspace($this->workspaceUid);
    }

    /**
     * A real pending version and the task auto-creation opens for it.
     */
    private function editPageInWorkspace(string $title): int
    {
        $GLOBALS['BE_USER']->setWorkspace($this->workspaceUid);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [2 => ['title' => $title]]], []);
        $dataHandler->process_datamap();

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_task');
        return (int)$queryBuilder->select('uid')->from('tx_editorialflow_task')->executeQuery()->fetchOne();
    }

    private function liveTitleOfPage(int $uid): string
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (string)$queryBuilder->select('title')->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->executeQuery()->fetchOne();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activityOf(int $taskUid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_activity');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder->select('*')->from('tx_editorialflow_activity')
            ->where($queryBuilder->expr()->eq('task', $taskUid))
            ->executeQuery()->fetchAllAssociative();
    }

    private function createOpenTask(int $stageUid): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task');
        $connection->insert('tx_editorialflow_task', [
            'title' => 'About us',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => 'in_progress',
            'workspace_uid' => $this->workspaceUid,
            'stage_uid' => $stageUid,
            'closed' => 0,
        ]);

        return (int)$connection->lastInsertId();
    }

    private function publish(int $taskUid): ResponseInterface
    {
        return $this->subject()->publishTaskAction($this->jsonRequest(['task' => $taskUid]));
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
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
