<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Workspaces\Authorization\WorkspacePublishGate;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * May this user publish THIS task, right now?
 *
 * Core answers that question in two halves, and both have to hold. The board
 * used to ask only the first one, which is why a Publish button appeared on
 * every open card - including cards still sitting in the edit stage - and why
 * pressing it went live without ever passing review.
 *
 * The two halves, mirroring DataHandlerHook::version_swap_processFields()'s own
 * guards (EXT:workspaces, see the checks around $wsAccess['publish_access']):
 *
 *  1. WorkspacePublishGate: a ROLE question only. Admin, workspace owner, or a
 *     member with live access - it knows nothing about stages, and it returns
 *     true for admins before looking at anything else.
 *  2. The workspace's own publish_access bitmask. With
 *     PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE set, a record may only go live from
 *     the publish stage. Core enforces this in the DataHandler for EVERY user,
 *     admins included - checkWorkspace() hands admins the full workspace record,
 *     so the bit applies to them like to anyone else.
 *
 * Asking both in one place is what lets the board promise that a visible Publish
 * button actually works: the button is rendered from exactly this answer, and
 * the AJAX endpoint refuses on exactly this answer. The two cannot drift.
 *
 * It also gives an integrator both workflows without a setting of Editorial
 * Flow's own, off one switch core already has on the workspace record:
 *
 *  - Bit unset: whoever may publish, publishes - straight from the card, at any
 *    stage. The fast lane for a small team where review is a convention rather
 *    than a gate.
 *  - Bit set: the task has to reach the publish stage first. Someone core
 *    itself lets walk it there - who may act on the stage it sits in AND on
 *    the publish stage, which core reserves for workspace owners and admins
 *    (BackendUserAuthentication::workspaceCheckStageForCurrent()) - gets the
 *    Publish button anyway, and the walk is made for them as a recorded stage
 *    change (TaskAjaxController::walkToPublishStageAndPublish()). A plain
 *    member still sees no button until the task has been sent there.
 *
 * That last part corrects the first version of this gate, which refused
 * everyone below the publish stage. It closed the real hole - a member with
 * live access publishing straight out of Editing - but also made a coach who
 * owns the workspace, and so could move the card to "Ready to publish" in one
 * drag anyway, do exactly that drag before every single publish. Granting the
 * click to whoever may already make the drag grants nothing new.
 *
 * Both stage questions read the CURRENT workspace's stage owners, so they are
 * asked inside the task's own workspace (TaskWorkspaceScope) - the answer must
 * not depend on which workspace the user's selector happens to point at.
 */
final readonly class TaskPublishGate
{
    public function __construct(
        private WorkspacePublishGate $workspacePublishGate,
        private TaskWorkspaceScope $workspaceScope,
    ) {
    }

    /**
     * @param int $workspaceUid The task's OWN workspace, not necessarily the
     *                          user's current one - a task carries the workspace
     *                          its pending version lives in.
     * @param int $stageUid     The task's stage, mirroring t3ver_stage of its
     *                          records. 0 (edit) for a task with no workspace
     *                          version yet, which is also what core assumes.
     */
    public function isGranted(BackendUserAuthentication $user, int $workspaceUid, int $stageUid): bool
    {
        // Nothing pending anywhere means nothing to publish. Guarded here rather
        // than left to WorkspacePublishGate, which answers an unconditional true
        // for the live workspace (uid 0) - correct for its own question, wrong
        // as an answer to "can this task go live".
        if ($workspaceUid < 1) {
            return false;
        }

        if (!$this->workspacePublishGate->isGranted($user, $workspaceUid)) {
            return false;
        }

        $workspaceAccess = $user->checkWorkspace($workspaceUid);
        if (!is_array($workspaceAccess)) {
            return false;
        }

        if (!$this->requiresPublishStage($workspaceAccess) || $stageUid === StagesService::STAGE_PUBLISH_ID) {
            return true;
        }

        return $this->mayWalkToPublishStage($user, $workspaceUid, $stageUid);
    }

    /**
     * Whether publishing this task has to move it to the publish stage first -
     * true only where isGranted() let it through on mayWalkToPublishStage().
     */
    public function needsWalkToPublishStage(BackendUserAuthentication $user, int $workspaceUid, int $stageUid): bool
    {
        if ($stageUid === StagesService::STAGE_PUBLISH_ID) {
            return false;
        }
        $workspaceAccess = $user->checkWorkspace($workspaceUid);

        return is_array($workspaceAccess) && $this->requiresPublishStage($workspaceAccess);
    }

    /**
     * @param array<string, mixed> $workspaceAccess
     */
    private function requiresPublishStage(array $workspaceAccess): bool
    {
        return ((int)($workspaceAccess['publish_access'] ?? 0) & WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE) !== 0;
    }

    private function mayWalkToPublishStage(BackendUserAuthentication $user, int $workspaceUid, int $stageUid): bool
    {
        try {
            return $this->workspaceScope->run(
                $user,
                $workspaceUid,
                static fn (): bool => $user->workspaceCheckStageForCurrent($stageUid)
                    && $user->workspaceCheckStageForCurrent(StagesService::STAGE_PUBLISH_ID),
            );
        } catch (WorkspaceAccessDenied) {
            return false;
        }
    }
}
