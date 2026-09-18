<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Controller;

use GbWeb\EditorialFlow\Controller\TaskAjaxController;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
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
    public function publishingFromTheEditStageIsRefusedWhenTheWorkspaceRequiresThePublishStage(): void
    {
        $this->givenWorkspace(WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE);
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
    public function publishingFromACustomReviewStageIsRefusedTheSameWay(): void
    {
        $this->givenWorkspace(WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE);
        $taskUid = $this->createOpenTask(3);

        self::assertSame('publish-not-permitted', $this->decode($this->publish($taskUid))['code']);
    }

    /**
     * The admin bypass in WorkspacePublishGate::isGranted() stops at the role
     * question. checkWorkspace() hands admins the full workspace record, so the
     * publish_access bit applies to them like to anyone else - which is why the
     * board could not promise "button visible means it works" without this.
     */
    #[Test]
    public function beingAdminDoesNotBypassTheStageRestriction(): void
    {
        $this->givenWorkspace(WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE);
        self::assertTrue($GLOBALS['BE_USER']->isAdmin());
        $taskUid = $this->createOpenTask(StagesService::STAGE_EDIT_ID);

        self::assertSame('publish-not-permitted', $this->decode($this->publish($taskUid))['code']);
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
