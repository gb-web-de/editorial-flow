<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use GbWeb\EditorialFlow\Domain\Model\TaskState;
use GbWeb\EditorialFlow\Domain\Repository\CommentRepository;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * "Ask core to move a version to a stage, then mirror it into the task row" -
 * the canonical stage-transition path, extracted so it has exactly one
 * implementation instead of two that could drift apart.
 *
 * Originally lived only in TaskAjaxController::executeStageAction(), for an
 * editor's own drag-and-drop. TaskAutoCreationService now also needs it, for
 * B5's automatic "regress to Editing" transition when an edit lands on a task
 * that had already moved past Editing - a Service reaching into a Controller
 * for this would have been backwards, hence the move.
 *
 * EXT:workspaces' version_setStage() is what checks
 * workspaceCannotEditOfflineVersion(), hasPermissionToUpdate() and
 * workspaceCheckStageForCurrent(), writes t3ver_stage, records the transition
 * in sys_history and queues the stage notification mails. Writing our own
 * table directly would skip every one of those, which is why this always
 * goes through a DataHandler cmdmap rather than an UPDATE.
 */
final class StageTransitionService
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly CommentRepository $commentRepository,
        private readonly ActivityLogger $activityLogger,
    ) {
    }

    /**
     * @param array<string, mixed> $task the task row being transitioned
     * @param array<string, list<int>> $versionsByTable table => version uids to move
     * @param list<mixed> $recipients
     * @param string|null $acceptanceRecord what the stage being left asked for and
     *        what was answered, composed by the caller that asked the question
     *        (TaskAjaxController::buildAcceptanceRecord()). Null where nobody was
     *        asked - the stage has no criteria, or the transition is automatic,
     *        as in TaskAutoCreationService's regression back to Editing. Recording
     *        a confirmation nobody gave would be worse than recording nothing.
     * @return string|null the refusal reason, or null when core accepted
     */
    public function transition(
        array $task,
        array $versionsByTable,
        int $targetStageUid,
        int $beUserId,
        string $comment = '',
        array $recipients = [],
        ?string $acceptanceRecord = null,
    ): ?string {
        $refusal = $this->askCoreToSetStage($versionsByTable, $targetStageUid, $comment, $recipients);
        if ($refusal !== null) {
            return $refusal;
        }

        $this->recordStageChange(
            $task,
            $targetStageUid,
            $comment,
            $recipients,
            $versionsByTable,
            $beUserId,
            $acceptanceRecord,
        );

        return null;
    }

    /**
     * @param array<string, list<int>> $versionsByTable
     * @param list<mixed> $recipients
     */
    private function askCoreToSetStage(array $versionsByTable, int $stageUid, string $comment, array $recipients): ?string
    {
        $cmd = [];
        foreach ($versionsByTable as $table => $versionUids) {
            foreach ($versionUids as $versionUid) {
                $cmd[$table][$versionUid]['version'] = [
                    'action' => 'setStage',
                    'stageId' => $stageUid,
                    'comment' => $comment,
                    'notificationAlternativeRecipients' => $recipients,
                ];
            }
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();

        return $dataHandler->errorLog === []
            ? null
            : implode(' ', array_map('strval', $dataHandler->errorLog));
    }

    /**
     * Mirror what core just decided.
     *
     * The task row is a read cache for the board; the activity entry is the durable
     * record, because sys_history - where core wrote the same transition - is
     * garbage-collected after 30 days.
     *
     * @param array<string, mixed> $task
     * @param list<mixed> $recipients
     * @param array<string, list<int>> $versionsByTable
     */
    private function recordStageChange(
        array $task,
        int $targetStageUid,
        string $comment,
        array $recipients,
        array $versionsByTable,
        int $beUserId,
        ?string $acceptanceRecord = null,
    ): void {
        $taskUid = (int)$task['uid'];
        $targetState = TaskState::fromStageId($targetStageUid);

        $this->taskRepository->moveToColumn($taskUid, $targetState->value, $targetStageUid);

        $activityUid = $this->activityLogger->log($taskUid, ActivityLogger::EVENT_STAGE_CHANGED, $beUserId, [
            'from_state' => $task['state'],
            'from_stage' => (int)$task['stage_uid'],
            'to_state' => $targetState->value,
            'to_stage' => $targetStageUid,
            'recipients' => $recipients,
        ], $this->findLatestStageHistoryUid($versionsByTable));

        if ($comment !== '') {
            $this->commentRepository->add($taskUid, $comment, $beUserId, $activityUid);
        }

        // Its own comment rather than appended to the editor's: the timeline
        // nests every comment anchored to the same activity under it, so both
        // arrive in the right place, and what the editor wrote stays theirs to
        // edit without an auto-generated block glued to the end of it.
        if ($acceptanceRecord !== null) {
            $this->commentRepository->add($taskUid, $acceptanceRecord, $beUserId, $activityUid);
        }
    }

    /**
     * The sys_history row core just wrote for this transition, so the activity
     * entry can point at core's full detail for as long as it exists.
     *
     * @param array<string, list<int>> $versionsByTable
     */
    private function findLatestStageHistoryUid(array $versionsByTable): int
    {
        foreach ($versionsByTable as $table => $versionUids) {
            foreach ($versionUids as $versionUid) {
                $changes = $this->activityLogger->findStageChanges($table, $versionUid);
                if ($changes !== []) {
                    return (int)$changes[0]['uid'];
                }
            }
        }

        return 0;
    }
}
