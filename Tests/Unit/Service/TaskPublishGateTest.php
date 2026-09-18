<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Unit\Service;

use GbWeb\EditorialFlow\Service\TaskPublishGate;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Workspaces\Authorization\WorkspacePublishGate;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The rule that decides whether a Publish button is offered at all, and whether
 * publishTaskAction() goes through: role AND stage, never role alone.
 */
final class TaskPublishGateTest extends UnitTestCase
{
    private function gateFor(bool $roleGranted): TaskPublishGate
    {
        $workspaceGate = $this->createMock(WorkspacePublishGate::class);
        $workspaceGate->method('isGranted')->willReturn($roleGranted);

        return new TaskPublishGate($workspaceGate);
    }

    private function userWith(int $publishAccess): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('checkWorkspace')->willReturn([
            'uid' => 1,
            'publish_access' => $publishAccess,
            '_ACCESS' => 'owner',
        ]);

        return $user;
    }

    #[Test]
    public function refusesWhenTheUserMayNotPublishInTheWorkspaceAtAll(): void
    {
        $gate = $this->gateFor(false);

        self::assertFalse($gate->isGranted($this->userWith(0), 1, StagesService::STAGE_PUBLISH_ID));
    }

    #[Test]
    public function refusesATaskThatHasNoWorkspaceVersionYet(): void
    {
        $gate = $this->gateFor(true);

        // Live (uid 0) would make WorkspacePublishGate answer an unconditional
        // true - there is still nothing pending to take live.
        self::assertFalse($gate->isGranted($this->userWith(0), 0, StagesService::STAGE_EDIT_ID));
    }

    #[Test]
    public function withoutTheStageRestrictionPublishingFromTheEditStageIsAllowed(): void
    {
        $gate = $this->gateFor(true);

        // The fast lane: whoever may publish, publishes - no walk through the
        // stages required.
        self::assertTrue($gate->isGranted($this->userWith(0), 1, StagesService::STAGE_EDIT_ID));
    }

    #[Test]
    public function withTheStageRestrictionAnEarlyStageIsRefused(): void
    {
        $gate = $this->gateFor(true);
        $user = $this->userWith(WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE);

        // This is the bug the gate exists for: permitted user, task still in the
        // edit stage, previously published straight to live.
        self::assertFalse($gate->isGranted($user, 1, StagesService::STAGE_EDIT_ID));
        // A custom review stage in between is refused for the same reason.
        self::assertFalse($gate->isGranted($user, 1, 3));
    }

    #[Test]
    public function withTheStageRestrictionThePublishStageIsAllowed(): void
    {
        $gate = $this->gateFor(true);
        $user = $this->userWith(WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE);

        self::assertTrue($gate->isGranted($user, 1, StagesService::STAGE_PUBLISH_ID));
    }

    #[Test]
    public function anUnrelatedPublishAccessBitDoesNotRestrictTheStage(): void
    {
        $gate = $this->gateFor(true);
        $user = $this->userWith(WorkspaceService::PUBLISH_ACCESS_ONLY_WORKSPACE_OWNERS);

        // Only the ONLY_IN_PUBLISH_STAGE bit says anything about stages; the
        // owners bit is WorkspacePublishGate's business and already answered.
        self::assertTrue($gate->isGranted($user, 1, StagesService::STAGE_EDIT_ID));
    }
}
