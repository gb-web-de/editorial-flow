<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Unit\Service;

use GbWeb\EditorialFlow\Service\TaskPublishGate;
use GbWeb\EditorialFlow\Service\TaskWorkspaceScope;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
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

        return new TaskPublishGate($workspaceGate, new TaskWorkspaceScope(new Context()));
    }

    /**
     * @param list<int> $actableStages stages core lets this user act on - an
     *        owner acts on every stage, a plain member on Editing only
     */
    private function userWith(int $publishAccess, array $actableStages = [StagesService::STAGE_EDIT_ID]): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        // Already "in" workspace 1, so TaskWorkspaceScope runs the stage
        // questions directly instead of switching a mock around.
        $user->workspace = 1;
        $user->method('checkWorkspace')->willReturn([
            'uid' => 1,
            'publish_access' => $publishAccess,
            '_ACCESS' => 'member',
        ]);
        $user->method('workspaceCheckStageForCurrent')->willReturnCallback(
            static fn (int $stage): bool => in_array($stage, $actableStages, true),
        );

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
    public function withTheStageRestrictionAnEarlyStageIsRefusedToAMember(): void
    {
        $gate = $this->gateFor(true);
        $user = $this->userWith(WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE);

        // This is the bug the gate exists for: a member with live access, task
        // still in the edit stage, previously published straight to live.
        self::assertFalse($gate->isGranted($user, 1, StagesService::STAGE_EDIT_ID));
        // A custom review stage in between is refused for the same reason.
        self::assertFalse($gate->isGranted($user, 1, 3));
    }

    /**
     * Someone core already lets move the card to "Ready to publish" - the
     * workspace owner, a coach reviewing their team's posts - gets the click
     * directly, and the controller records the walk as a stage change. Nothing
     * is granted that one drag would not have granted anyway.
     */
    #[Test]
    public function withTheStageRestrictionSomeoneWhoMayWalkTheTaskThereIsGranted(): void
    {
        $gate = $this->gateFor(true);
        $owner = $this->userWith(
            WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE,
            [StagesService::STAGE_EDIT_ID, 3, StagesService::STAGE_PUBLISH_ID],
        );

        self::assertTrue($gate->isGranted($owner, 1, StagesService::STAGE_EDIT_ID));
        self::assertTrue($gate->needsWalkToPublishStage($owner, 1, StagesService::STAGE_EDIT_ID));
    }

    /**
     * Responsible for the review stage is not enough: "Ready to publish" is
     * the owner's stage, and walking there means acting on both.
     */
    #[Test]
    public function aReviewerWhoMayNotActOnThePublishStageIsStillRefused(): void
    {
        $gate = $this->gateFor(true);
        $reviewer = $this->userWith(WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE, [StagesService::STAGE_EDIT_ID, 3]);

        self::assertFalse($gate->isGranted($reviewer, 1, 3));
    }

    #[Test]
    public function noWalkIsNeededFromThePublishStageOrWithoutTheRestriction(): void
    {
        $gate = $this->gateFor(true);

        self::assertFalse($gate->needsWalkToPublishStage(
            $this->userWith(WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE),
            1,
            StagesService::STAGE_PUBLISH_ID,
        ));
        self::assertFalse($gate->needsWalkToPublishStage($this->userWith(0), 1, StagesService::STAGE_EDIT_ID));
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
