<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;

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
 * there, not from the user object. Both are restored in `finally`, so an
 * exception half way through cannot leave the rest of the request working in
 * the wrong workspace.
 */
final readonly class TaskWorkspaceScope
{
    public function __construct(
        private Context $context,
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
        $previousAspect = $this->context->getAspect('workspace');

        $user->setTemporaryWorkspace($workspaceUid);
        $this->context->setAspect('workspace', new WorkspaceAspect($workspaceUid));
        try {
            return $work();
        } finally {
            $user->workspace = $previousWorkspace;
            $user->workspaceRec = $previousWorkspaceRecord;
            $this->context->setAspect('workspace', $previousAspect);
        }
    }
}
