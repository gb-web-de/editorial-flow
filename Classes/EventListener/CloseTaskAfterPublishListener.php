<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\EventListener;

use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Service\ActivityLogger;
use GbWeb\EditorialFlow\Service\TaskMemberSynchronizer;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent;

/**
 * "Es geht live, der Task wird geschlossen."
 *
 * With one qualification that the aggregation model forces: a task covers a page
 * AND everything on it, and this event fires once per published record. Closing on
 * the first one would archive a task while half its content elements are still in
 * review. So the task closes only when nothing it covers is pending any more.
 *
 * `getRecordId()` is the *live* uid, in both core publish paths (the swap path in
 * EXT:workspaces DataHandlerHook::publishVersion, and publishNewRecord). That
 * matches how membership is stored, so no version->live resolution is needed.
 *
 * Nothing is snapshotted here. Core migrates the version's sys_history rows onto
 * the live uid a few lines after dispatching this event
 * (RecordHistoryStore::publishRecord -> migrateWorkspaceHistory), so the trail
 * survives publishing on its own. What Editorial Flow keeps durably is written when
 * each decision is made - see ActivityLogger.
 */
final class CloseTaskAfterPublishListener
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly TaskMemberSynchronizer $memberSynchronizer,
        private readonly ActivityLogger $activityLogger,
    ) {
    }

    #[AsEventListener(identifier: 'editorial-flow/close-task-after-publish')]
    public function __invoke(AfterRecordPublishedEvent $event): void
    {
        $task = $this->taskRepository->findOpenTaskByMember($event->getTable(), $event->getRecordId());
        if ($task === null) {
            // Published something that was never tracked - nothing to close.
            return;
        }

        $taskUid = (int)$task['uid'];
        $taskWorkspaceUid = (int)$task['workspace_uid'];
        $beUserId = (int)($this->getBackendUser()?->user['uid'] ?? 0);

        // Whose publish was this?
        //
        // findOpenTaskByMember() is not workspace-filtered, and core lets the
        // same live record be versioned in several workspaces at once - that is
        // WorkspaceConflictDetector's entire reason for existing. So when
        // workspace B publishes a record whose open task belongs to workspace A,
        // this listener still finds task A. Asking "is anything pending in B?"
        // then gets `false` for a reason that has nothing to do with A, and task
        // A was closed while its own version was still sitting in review.
        //
        // The task's own workspace is the only one that can answer whether the
        // task is finished. A task that never entered Editing has none, and
        // there the event's workspace is the only information available.
        if ($taskWorkspaceUid > 0 && $taskWorkspaceUid !== $event->getWorkspaceId()) {
            $this->activityLogger->log($taskUid, ActivityLogger::EVENT_PUBLISHED, $beUserId, [
                'table' => $event->getTable(),
                'liveUid' => $event->getRecordId(),
                'workspaceId' => $event->getWorkspaceId(),
                'taskWorkspaceId' => $taskWorkspaceUid,
                'taskComplete' => false,
            ]);
            return;
        }

        $workspaceUid = $taskWorkspaceUid > 0 ? $taskWorkspaceUid : $event->getWorkspaceId();

        if ($this->memberSynchronizer->hasPendingVersions($taskUid, $workspaceUid)) {
            // Part of the task went live, the rest has not. Record it and wait.
            $this->activityLogger->log($taskUid, ActivityLogger::EVENT_PUBLISHED, $beUserId, [
                'table' => $event->getTable(),
                'liveUid' => $event->getRecordId(),
                'workspaceId' => $workspaceUid,
                'taskComplete' => false,
            ]);
            return;
        }

        $this->taskRepository->close($taskUid, $beUserId);
        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_CLOSED, $beUserId, [
            'workspaceId' => $workspaceUid,
            // Kept so the archived task can still find its trail: after publishing,
            // core has re-pointed the version's sys_history rows at these live uids.
            'table' => $event->getTable(),
            'liveUid' => $event->getRecordId(),
        ]);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
