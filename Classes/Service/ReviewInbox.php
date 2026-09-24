<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Workspaces\Service\StagesService;

/**
 * What is waiting for THIS user, across every workspace they belong to.
 *
 * The question a coach actually has when they open the backend is not "what
 * is on the board of the workspace I happen to be in" but "is there anything
 * I have to look at and approve?". The top-bar Approvals item
 * (ReviewInboxToolbarItem) answers it everywhere in the backend, and acts on
 * the answer without a workspace switch.
 *
 * A task is waiting for the user when it has something pending AND either
 *  - the user may publish it right now (TaskPublishGate - the same answer the
 *    board's Publish button is rendered from), or
 *  - it sits in a review stage the user is responsible for.
 * Both asked in the task's own workspace, like everywhere else. Nothing here
 * grants anything: a task the user could not act on from the board is not
 * listed, and the endpoints behind the buttons ask the gates again.
 */
final readonly class ReviewInbox
{
    public function __construct(
        private TaskRepository $taskRepository,
        private TaskMemberSynchronizer $memberSynchronizer,
        private TaskPublishGate $publishGate,
        private TaskWorkspaceScope $workspaceScope,
        private StagesService $stagesService,
    ) {
    }

    /**
     * @param list<int> $workspaceUids the workspaces the user may enter
     * @return list<array{uid: int, title: string, workspaceTitle: string, stageLabel: string, readyToPublish: bool, subjectTable: string, subjectUid: int, pageUid: int, canPublish: bool}>
     */
    public function forUser(BackendUserAuthentication $user, array $workspaceUids, int $limit = 20): array
    {
        $workspaceUids = array_values(array_filter(
            $workspaceUids,
            fn (int $uid): bool => $this->workspaceScope->canEnter($user, $uid),
        ));

        $entries = [];
        foreach ($this->taskRepository->findOpenInWorkspaces($workspaceUids) as $task) {
            $entry = $this->entryFor($user, $task);
            if ($entry !== null) {
                $entries[] = $entry;
            }
            if (count($entries) >= $limit) {
                break;
            }
        }

        // Ready to publish first: that is where a click finishes something.
        usort($entries, static fn (array $a, array $b): int => $b['readyToPublish'] <=> $a['readyToPublish']);

        return $entries;
    }

    /**
     * @param array<string, mixed> $task
     * @return array{uid: int, title: string, workspaceTitle: string, stageLabel: string, readyToPublish: bool, subjectTable: string, subjectUid: int, pageUid: int, canPublish: bool}|null
     */
    private function entryFor(BackendUserAuthentication $user, array $task): ?array
    {
        $workspaceUid = (int)$task['workspace_uid'];
        $stageUid = (int)$task['stage_uid'];
        $pageUid = (int)$task['subject_pid'];

        if (!$this->mayReadPage($user, $pageUid)) {
            return null;
        }

        $canPublish = $this->publishGate->isGranted($user, $workspaceUid, $stageUid);
        $isReviewer = $stageUid !== StagesService::STAGE_EDIT_ID
            && $this->workspaceScope->run(
                $user,
                $workspaceUid,
                static fn (): bool => $user->workspaceCheckStageForCurrent($stageUid),
            );
        if (!$canPublish && !$isReviewer) {
            return null;
        }
        // Last, because it is the most expensive question: one lookup per member.
        if (!$this->memberSynchronizer->hasPendingVersions((int)$task['uid'], $workspaceUid)) {
            return null;
        }

        return [
            'uid' => (int)$task['uid'],
            'title' => (string)$task['title'],
            'workspaceTitle' => (string)(BackendUtility::getRecord('sys_workspace', $workspaceUid, 'title')['title'] ?? ''),
            'stageLabel' => $this->stagesService->getStageTitle($stageUid),
            'readyToPublish' => $stageUid === StagesService::STAGE_PUBLISH_ID,
            'subjectTable' => (string)$task['subject_table'],
            'subjectUid' => (int)$task['subject_uid'],
            'pageUid' => $pageUid,
            'canPublish' => $canPublish,
        ];
    }

    private function mayReadPage(BackendUserAuthentication $user, int $pageUid): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $pageUid > 0
            && BackendUtility::readPageAccess($pageUid, $user->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
    }
}
