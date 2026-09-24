<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Runs one piece of work as if the user had switched into a task's workspace -
 * without switching them.
 *
 * Core binds every workspace decision to the user's CURRENT workspace:
 * DataHandler::workspaceCannotEditRecord() refuses a setStage on a version
 * from any other workspace, and workspaceCheckStageForCurrent() reads the
 * stage owners of the current workspace record. So a trainer who owns three
 * team workspaces had to switch into each one before the board would let
 * them move or publish anything from it, and every card from the other two
 * was shown read-only.
 *
 * setTemporaryWorkspace() is core's own answer to exactly this: it sets the
 * workspace for the running request only, never persists it, and - the part
 * that keeps this safe - refuses (returns false) for a workspace the user has
 * no access to, via the same checkWorkspace() the workspace selector uses. A
 * scope therefore never grants anything; it only stops the current selection
 * from being the only one that counts.
 *
 * The Context workspace aspect is switched alongside it because
 * BackendUtility's overlays and core's preview links read the workspace from
 * there, not from the user object.
 *
 * So are the user's page mounts. Core narrows them to the workspace's own
 * mounts once, at login (initializeDbMountpointsInWorkspace()), and every
 * page permission check - calcPerms(), DataHandler's edit and publish checks
 * - goes through them. Switching only the workspace left a coach sitting in
 * one workspace with that workspace's mounts, and their own post in another
 * one was "not in your page tree": refused, although they own it. The mounts
 * for the target workspace are computed the way login computes them, on a
 * fresh user object that never writes anything back.
 *
 * Everything is restored in `finally`, so an exception half way through
 * cannot leave the rest of the request working in the wrong workspace.
 */
final class TaskWorkspaceScope
{
    /**
     * @var array<string, array<string, mixed>> "userUid:workspaceUid" => groupData
     */
    private array $groupDataByWorkspace = [];

    public function __construct(
        private readonly Context $context,
    ) {
    }

    /**
     * Whether the user may work in this workspace at all - access, not a
     * stage or publish permission. Live (0) is never a task workspace.
     */
    public function canEnter(BackendUserAuthentication $user, int $workspaceUid): bool
    {
        return $workspaceUid > 0 && $user->checkWorkspace($workspaceUid) !== false;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     * @throws WorkspaceAccessDenied when the user has no access to $workspaceUid
     */
    public function run(BackendUserAuthentication $user, int $workspaceUid, callable $work): mixed
    {
        if ((int)$user->workspace === $workspaceUid) {
            return $work();
        }
        if (!$this->canEnter($user, $workspaceUid)) {
            throw new WorkspaceAccessDenied($workspaceUid);
        }

        $previousWorkspace = $user->workspace;
        $previousWorkspaceRecord = $user->workspaceRec;
        $previousGroupData = $user->groupData;
        $previousAspect = $this->context->getAspect('workspace');

        $user->setTemporaryWorkspace($workspaceUid);
        $user->groupData = $this->groupDataIn($user, $workspaceUid);
        $this->context->setAspect('workspace', new WorkspaceAspect($workspaceUid));
        try {
            return $work();
        } finally {
            $user->workspace = $previousWorkspace;
            $user->workspaceRec = $previousWorkspaceRecord;
            $user->groupData = $previousGroupData;
            $this->context->setAspect('workspace', $previousAspect);
        }
    }

    /**
     * The user's group data - page mounts above all - as login would compute
     * it had they logged in with $workspaceUid selected.
     *
     * workspace_id is set on the copy's user row first, so workspaceInit()
     * finds it already selected and setWorkspace() has nothing to persist.
     *
     * @return array<string, mixed>
     */
    private function groupDataIn(BackendUserAuthentication $user, int $workspaceUid): array
    {
        $key = (int)($user->user['uid'] ?? 0) . ':' . $workspaceUid;
        if (!isset($this->groupDataByWorkspace[$key])) {
            $shadow = GeneralUtility::makeInstance(BackendUserAuthentication::class);
            $shadow->user = array_merge((array)$user->user, ['workspace_id' => $workspaceUid]);
            $shadow->fetchGroupData();
            $this->groupDataByWorkspace[$key] = (int)$shadow->workspace === $workspaceUid
                ? $shadow->groupData
                : $user->groupData;
        }

        return $this->groupDataByWorkspace[$key];
    }
}
