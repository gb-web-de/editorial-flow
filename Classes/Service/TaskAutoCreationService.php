<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use GbWeb\EditorialFlow\Domain\Model\TaskState;
use GbWeb\EditorialFlow\Domain\Repository\CommentRepository;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Workspaces\Service\StagesService;

/**
 * Creates or advances a task whenever an editor edits something inside a workspace -
 * "wenn kein Task da ist und die Seite bearbeitet wird, wird ein Task erzeugt".
 *
 * The routing is what makes the board readable:
 *
 *   editing a page          -> that page's task
 *   editing a news record   -> that news record's own task (it is page-like)
 *   editing a content elem. -> the task of the page it sits on, NOT its own card
 *   ...unless an editor detached that element, in which case it keeps its own task
 *
 * Plain injectable service, deliberately free of any hook or event signature, so
 * the trigger can be swapped without touching the logic. See the adapter in
 * Hooks/ for why the trigger is still a DataHandler hook.
 *
 * The capture itself stays invisible and non-blocking - the save already succeeded
 * before any follow-up wizard appears. Editors type first; Editorial Flow asks for
 * details or routing afterwards only when the task needs a human decision.
 */
final class TaskAutoCreationService
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly TaskSubjectRegistry $subjectRegistry,
        private readonly TaskMemberSynchronizer $memberSynchronizer,
        private readonly ReferenceInspector $referenceInspector,
        private readonly ActivityLogger $activityLogger,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly StageTransitionService $stageTransitionService,
        private readonly CommentRepository $commentRepository,
        private readonly ActiveTaskSession $activeTaskSession,
        private readonly TaskEventPublisher $taskEventPublisher,
    ) {
    }

    /**
     * Called for every record DataHandler wrote. Decides whether it belongs to a
     * task, and creates or advances one.
     */
    public function captureEdit(
        string $status,
        string $table,
        int|string $id,
        DataHandler $dataHandler,
    ): void {
        if ($status !== 'update') {
            return;
        }
        $workspaceUid = (int)($dataHandler->BE_USER->workspace ?? 0);
        if ($workspaceUid < 1) {
            // Live edits do not open tasks: the workflow starts when work becomes
            // reviewable, and on Live there is nothing to review against.
            return;
        }
        if (!$this->subjectRegistry->isTrackable($table)) {
            return;
        }

        $resolved = $this->resolveLiveAndVersion($table, (int)$id, $workspaceUid, $dataHandler);
        if ($resolved === null) {
            return;
        }
        [$liveUid, $versionUid] = $resolved;

        $stageUid = (int)(BackendUtility::getRecord($table, $versionUid, 't3ver_stage')['t3ver_stage'] ?? 0);
        $beUserId = (int)($dataHandler->BE_USER->user['uid'] ?? 0);
        $pageUid = $this->derivePid($table, $liveUid);

        // 0. An explicit active task outranks automatic routing. Page-scoped
        // choices cover the whole page; record-scoped choices only match the
        // exact live record being saved.
        $activeTaskUid = $this->activeTaskSession->resolveForEdit(
            $dataHandler->BE_USER,
            $table,
            $liveUid,
            $pageUid,
        );
        if ($activeTaskUid !== null) {
            $homePid = $this->derivePid($table, $liveUid);
            $shared = $this->referenceInspector->isSharedAcrossPages($table, $liveUid, $homePid);
            // Re-point like attachAction() does for a manual "select to task"
            // - an explicit choice may reasonably steal a record away from
            // whatever task auto-claimed it by default.
            if ($this->taskRepository->findOpenTaskByMember($table, $liveUid) !== null) {
                $this->taskRepository->moveMemberToTask($table, $liveUid, $activeTaskUid);
            } else {
                $this->taskRepository->addMemberIfUnclaimed(
                    $activeTaskUid,
                    $table,
                    $liveUid,
                    TaskRepository::ORIGIN_AUTO,
                    $homePid,
                    $shared,
                );
            }

            // The declaration moved the task when it was made
            // (setActiveTaskForPageAction()), but a declaration outlives the
            // moment: the task can be sent to review by anyone while the editor
            // still holds it, and then keeps collecting edits from that same
            // session. Applying the ordinary rules on every captured edit is
            // what keeps "an edit means the task is being edited" true for the
            // whole life of the choice, not only its first second.
            $activeTask = $this->taskRepository->findByUid($activeTaskUid);
            if ($activeTask !== null) {
                if ((int)($activeTask['workspace_uid'] ?? 0) === 0) {
                    $this->taskRepository->attachWorkspace($activeTaskUid, $workspaceUid, $stageUid);
                } else {
                    // No-ops for anything that is not Review/Ready, so this is
                    // a call rather than a second copy of that condition.
                    $this->maybeRegressPastEditing($activeTask, $table, $liveUid, $workspaceUid, $beUserId, $dataHandler);
                }
            }

            return;
        }

        // 1. Check if record is ALREADY a member of an open task (2nd save bypass)
        $existing = $this->taskRepository->findOpenTaskByMember($table, $liveUid);
        if ($existing !== null) {
            if ((int)($existing['workspace_uid'] ?? 0) === 0) {
                $this->taskRepository->attachWorkspace((int)$existing['uid'], $workspaceUid, $stageUid);
            } else {
                $this->maybeRegressPastEditing($existing, $table, $liveUid, $workspaceUid, $beUserId, $dataHandler);
            }
            return;
        }

        // 2. Check if an open task exists for parent page
        $pageTask = $this->taskRepository->findOpenBySubject('pages', $pageUid);

        if ($pageTask !== null && !$this->subjectRegistry->isSubject($table)) {
            $pageTaskUid = (int)$pageTask['uid'];
            $homePid = $this->derivePid($table, $liveUid);
            $regressed = false;

            if ((int)($pageTask['workspace_uid'] ?? 0) === 0) {
                $this->taskRepository->attachWorkspace($pageTaskUid, $workspaceUid, $stageUid);
                $this->activityLogger->log(
                    $pageTaskUid,
                    ActivityLogger::EVENT_WORK_STARTED,
                    $beUserId,
                    ['table' => $table, 'recordUid' => $liveUid, 'stageUid' => $stageUid],
                );
            } else {
                $regressed = $this->maybeRegressPastEditing($pageTask, $table, $liveUid, $workspaceUid, $beUserId, $dataHandler);
            }

            // Claim it onto the page task NOW, not only once the editor answers
            // the routing prompt. Without this, moveMemberToTask() and
            // detachIntoOwnTask() in TaskAjaxController::wizardSubmitAction() have
            // no membership row to act on - both are UPDATE-only, so either wizard
            // choice would silently do nothing and the edit would never be linked
            // to any task at all. Claiming here first means "attach to page task"
            // is already true and merely confirmed, and "split into its own task"
            // has a real row to re-point.
            //
            // This also matches the standing invariant: unplanned work is captured
            // immediately, and ignoring the routing prompt is a valid answer
            // because the sensible default already happened.
            $this->taskRepository->addMemberIfUnclaimed(
                $pageTaskUid,
                $table,
                $liveUid,
                TaskRepository::ORIGIN_AUTO,
                $homePid,
                $this->referenceInspector->isSharedAcrossPages($table, $liveUid, $homePid),
            );

            // A regression already claimed this save's one follow-up wizard slot
            // (see maybeRegressPastEditing()) - the routing question below is a
            // refinement of where the edit lands, secondary to the fact that the
            // task's stage itself just silently moved.
            if ($regressed) {
                return;
            }

            // Offer the editor a choice: keep it on the page task, or split it off.
            $this->storePendingWizard($dataHandler, [
                'mode' => 'route_member',
                'table' => $table,
                'uid' => $liveUid,
                'recordTitle' => $this->deriveTitle($table, $liveUid),
                'pageTaskUid' => $pageTaskUid,
                'pageTaskTitle' => (string)$pageTask['title'],
                'defaultTitle' => $this->deriveTitle($table, $liveUid),
            ]);
            return;
        }

        $resolvedTask = $this->resolveTask($table, $liveUid, $workspaceUid, $stageUid, $beUserId);
        if ($resolvedTask === null) {
            return;
        }

        $task = $resolvedTask['task'];
        $taskUid = (int)$task['uid'];
        $workStartedNow = false;
        $regressed = false;
        if ((int)($task['workspace_uid'] ?? 0) === 0) {
            // Backlog/Planned -> In Progress, now that real work exists.
            $this->taskRepository->attachWorkspace($taskUid, $workspaceUid, $stageUid);
            $this->activityLogger->log(
                $taskUid,
                ActivityLogger::EVENT_WORK_STARTED,
                $beUserId,
                ['table' => $table, 'recordUid' => $liveUid, 'stageUid' => $stageUid],
            );
            $workStartedNow = true;
        } else {
            $regressed = $this->maybeRegressPastEditing($task, $table, $liveUid, $workspaceUid, $beUserId, $dataHandler);
        }

        if ($resolvedTask['createdNow'] && !$workStartedNow) {
            $this->activityLogger->log(
                $taskUid,
                ActivityLogger::EVENT_WORK_STARTED,
                $beUserId,
                ['table' => $table, 'recordUid' => $liveUid, 'stageUid' => $stageUid],
            );
        }

        // Same one-wizard-per-save rule as the page-task branch above.
        if ($regressed) {
            return;
        }

        if ($resolvedTask['createdNow']) {
            $this->storePendingWizard($dataHandler, [
                'mode' => 'configure_auto_task',
                'taskUid' => $taskUid,
                'table' => $table,
                'uid' => $liveUid,
                'editedTitle' => $this->deriveTitle($table, $liveUid),
                'subjectTable' => (string)$task['subject_table'],
                'subjectUid' => (int)$task['subject_uid'],
                'subjectTitle' => $this->deriveTitle((string)$task['subject_table'], (int)$task['subject_uid']),
                'defaultTitle' => (string)$task['title'],
            ]);
        }
    }

    /**
     * Same routing captureEdit() applies to a live editor's save, driven instead by
     * a workspace version that already existed before Editorial Flow was installed.
     * The upgrade wizard `MigrateExistingWorkspaceChangesToTasksUpdate` is this
     * method's only caller.
     *
     * Deliberately narrower than captureEdit(): there is no DataHandler to hang a
     * follow-up wizard on, no active task session (nobody is mid-save), and nothing
     * to regress past - a version already sitting in Review/Ready just means the
     * task is created straight into that state, not walked back to Editing.
     */
    public function captureExistingVersion(string $table, int $liveUid, int $versionUid, int $workspaceUid): void
    {
        if (!$this->subjectRegistry->isTrackable($table)) {
            return;
        }

        $stageUid = (int)(BackendUtility::getRecord($table, $versionUid, 't3ver_stage')['t3ver_stage'] ?? 0);
        $pageUid = $this->derivePid($table, $liveUid);

        $existing = $this->taskRepository->findOpenTaskByMember($table, $liveUid);
        if ($existing !== null) {
            $this->attachExistingVersionWorkspace($existing, $workspaceUid, $stageUid);
            return;
        }

        $pageTask = $this->taskRepository->findOpenBySubject('pages', $pageUid);
        if ($pageTask !== null && !$this->subjectRegistry->isSubject($table)) {
            $this->attachExistingVersionWorkspace($pageTask, $workspaceUid, $stageUid);
            $homePid = $this->derivePid($table, $liveUid);
            $this->taskRepository->addMemberIfUnclaimed(
                (int)$pageTask['uid'],
                $table,
                $liveUid,
                TaskRepository::ORIGIN_AUTO,
                $homePid,
                $this->referenceInspector->isSharedAcrossPages($table, $liveUid, $homePid),
            );
            return;
        }

        $resolvedTask = $this->resolveTask($table, $liveUid, $workspaceUid, $stageUid, 0);
        if ($resolvedTask === null) {
            return;
        }
        $this->attachExistingVersionWorkspace($resolvedTask['task'], $workspaceUid, $stageUid);
    }

    /**
     * @param array<string, mixed> $task
     */
    private function attachExistingVersionWorkspace(array $task, int $workspaceUid, int $stageUid): void
    {
        if ((int)($task['workspace_uid'] ?? 0) !== 0) {
            // Another pending version on the same subject already attached the
            // workspace - core's t3ver_stage is the per-record source of truth,
            // this cached column only needs one of them to have set it.
            return;
        }
        $this->taskRepository->attachWorkspace((int)$task['uid'], $workspaceUid, $stageUid);
    }

    /**
     * Work out which live record this edit was really about, and which version now
     * holds it.
     *
     * The subtlety that makes this necessary: DataHandler **rewrites `$id` to the
     * version uid** before calling this hook. In processDatamap_afterDatabaseOperations
     * `$id` is therefore usually the version, not the live record - see
     * `$id = $this->autoVersionIdMap[$table][$id];` in DataHandler::process_datamap().
     * Calling getAutoVersionId() on that id returns null, because a version has no
     * version of its own, and an implementation that trusts it alone silently does
     * nothing at all.
     *
     * Both directions are handled: `$id` already being a version (the normal case),
     * and `$id` still being live with a version created alongside it.
     *
     * @return array{0: int, 1: int}|null [liveUid, versionUid]
     */
    private function resolveLiveAndVersion(
        string $table,
        int $id,
        int $workspaceUid,
        DataHandler $dataHandler,
    ): ?array {
        if ($id < 1) {
            return null;
        }

        $record = BackendUtility::getRecord($table, $id, 'uid,t3ver_oid,t3ver_wsid');
        if ($record === null) {
            return null;
        }

        $versionedFrom = (int)($record['t3ver_oid'] ?? 0);
        if ($versionedFrom > 0) {
            // $id is the version. Only ours if it lives in the active workspace.
            if ((int)($record['t3ver_wsid'] ?? 0) !== $workspaceUid) {
                return null;
            }
            return [$versionedFrom, $id];
        }

        // $id is still the live record - a version may have been created next to it.
        $autoVersionUid = $dataHandler->getAutoVersionId($table, $id);
        if ($autoVersionUid !== null) {
            return [$id, $autoVersionUid];
        }

        // No autoVersionIdMap entry. That map is filled by the datamap's
        // auto-versioning only; a cmdmap command (delete, move) versions the
        // record through versionizeRecord() without ever touching it. The
        // placeholder is in the database all the same, so ask for it directly
        // rather than concluding there is no version.
        $versionUid = $this->memberSynchronizer->findVersionUid($table, $id, $workspaceUid);

        return $versionUid > 0 ? [$id, $versionUid] : null;
    }

    /**
     * Find the task this edit belongs to, creating it if the work was unplanned.
     *
     * @return array{task: array<string, mixed>, createdNow: bool}|null
     */
    private function resolveTask(string $table, int $liveUid, int $workspaceUid, int $stageUid, int $beUserId): ?array
    {
        // An existing membership wins over everything else. This is what keeps a
        // detached element with its own task instead of being pulled back into the
        // page's task the next time someone edits it.
        $existing = $this->taskRepository->findOpenTaskByMember($table, $liveUid);
        if ($existing !== null) {
            return ['task' => $existing, 'createdNow' => false];
        }

        $subject = $this->subjectRegistry->resolveSubjectFor($table, $liveUid);
        if ($subject === null) {
            return null;
        }

        $isNew = $this->taskRepository->findOpenBySubject($subject['table'], $subject['uid']) === null;
        $subjectPid = $this->derivePid($subject['table'], $subject['uid']);
        $task = $this->taskRepository->findOrCreateOpenForSubject($subject['table'], $subject['uid'], [
            'title' => $this->deriveTitle($subject['table'], $subject['uid']),
            'subject_pid' => $subjectPid,
            'state' => TaskState::fromStageId($stageUid)->value,
            'workspace_uid' => $workspaceUid,
            'stage_uid' => $stageUid,
            'assignee' => $beUserId,
            // Nobody planned this - the editor simply started working. The board
            // marks it, and the post-save wizard lets the editor refine it.
            'auto_created' => 1,
        ]);
        $taskUid = (int)$task['uid'];

        if ($isNew) {
            // Members first, log second. The order is load-bearing: a throw out of
            // log() used to land between the two and leave the task holding nothing
            // but its subject - a page task with no content, which then reported
            // "nothing pending to publish" for a page full of changes. Building the
            // task fully before recording that it exists means an audit failure can
            // cost the trail, never the task itself.
            //
            // A page's task covers the page and everything on it.
            if ($subject['table'] === 'pages') {
                $this->memberSynchronizer->syncPageMembers($taskUid, $subject['uid']);
            }
            $this->activityLogger->log($taskUid, ActivityLogger::EVENT_TASK_CREATED, $beUserId, [
                'subjectTable' => $subject['table'],
                'subjectUid' => $subject['uid'],
                'unplanned' => true,
            ]);
            // Announced with autoCreated: true, which is the difference an
            // external board needs - a card somebody planned is worth a ticket
            // over there, a task that opened itself because an editor started
            // typing usually is not.
            $this->taskEventPublisher->taskCreated(
                $this->taskRepository->findByUid($taskUid) ?? $task,
                $beUserId,
            );
        }

        // The edited record may still be unclaimed - a record created after the last
        // sync, or one on a subject that is not a page. Claim it now; if someone else
        // already owns it, leave it with them.
        $homePid = $this->derivePid($table, $liveUid);
        $this->taskRepository->addMemberIfUnclaimed(
            $taskUid,
            $table,
            $liveUid,
            TaskRepository::ORIGIN_AUTO,
            $homePid,
            $this->referenceInspector->isSharedAcrossPages($table, $liveUid, $homePid),
        );

        return ['task' => $task, 'createdNow' => $isNew];
    }

    /**
     * A task needs a human-readable title from the first moment, so an editor who
     * never opens the board still leaves behind something readable.
     *
     * Reads the label field via the TCA schema rather than calling
     * BackendUtility::getRecordTitle(). That helper resolves LLL references and
     * therefore requires $GLOBALS['LANG'], which is not guaranteed here: this hook
     * also runs when DataHandler is driven from the CLI (imports, scheduler tasks,
     * tests), and there it fataled on a null LanguageService.
     */
    private function deriveTitle(string $table, int $uid): string
    {
        $fallback = sprintf('%s:%d', $table, $uid);
        if (!$this->tcaSchemaFactory->has($table)) {
            return $fallback;
        }

        $labelCapability = $this->tcaSchemaFactory->get($table)->getCapability(TcaSchemaCapability::Label);
        $labelField = $labelCapability->getPrimaryFieldName();
        if ($labelField === null) {
            return $fallback;
        }

        $record = BackendUtility::getRecord($table, $uid, $labelField);
        $title = trim((string)($record[$labelField] ?? ''));

        return $title !== '' ? $title : $fallback;
    }

    private function derivePid(string $table, int $uid): int
    {
        // A page's board scope is the page itself; a page-like record (news) is
        // scoped to the folder or page it is stored on.
        if ($table === 'pages') {
            return $uid;
        }
        return (int)(BackendUtility::getRecord($table, $uid, 'pid')['pid'] ?? 0);
    }

    /**
     * B5: an edit landing on a task that had already moved past Editing means
     * work has resumed on it - regress the whole task back to Editing (core
     * stage 0) rather than leaving the board showing "in review" for
     * something an editor is visibly still touching. Every one of the task's
     * pending records moves together, not just the one just edited - the
     * board shows one stage per task, and letting that diverge from what
     * core actually holds per-record is a state it cannot represent.
     *
     * Applied immediately with an auto-generated comment: this runs from a
     * DataHandler hook, after the save already completed, so it cannot block
     * on a human-authored comment the way a synchronous validation could
     * (see the class docblock). The pending-wizard mechanism - the same one
     * `route_member`/`configure_auto_task` use - instead offers the editor a
     * follow-up step to edit or expand that default text.
     *
     * @param array<string, mixed> $task
     * @return bool whether the task actually regressed and queued a wizard
     */
    private function maybeRegressPastEditing(
        array $task,
        string $table,
        int $liveUid,
        int $workspaceUid,
        int $beUserId,
        DataHandler $dataHandler,
    ): bool {
        $state = TaskState::tryFrom((string)$task['state']);
        if ($state !== TaskState::REVIEW && $state !== TaskState::READY) {
            return false;
        }

        $taskUid = (int)$task['uid'];
        $versionsByTable = $this->memberSynchronizer->findPendingVersionsByTable($taskUid, $workspaceUid);
        if ($versionsByTable === []) {
            return false;
        }

        $comment = sprintf($this->reopenCommentFormat(), $table, $liveUid);

        $refusal = $this->stageTransitionService->transition(
            $task,
            $versionsByTable,
            StagesService::STAGE_EDIT_ID,
            $beUserId,
            $comment,
        );
        if ($refusal !== null) {
            return false;
        }

        // transition() does not hand back the comment uid it just wrote - it
        // is built for the controller's fire-and-forget use, which never
        // needed one. Reading it straight back out is simpler than widening
        // that return type for this one caller.
        $comments = $this->commentRepository->findByTask($taskUid);
        $lastComment = end($comments);
        $commentUid = $lastComment !== false ? (int)$lastComment['uid'] : 0;

        $this->storePendingWizard($dataHandler, [
            'mode' => 'regression_comment',
            'taskUid' => $taskUid,
            'commentUid' => $commentUid,
            'table' => $table,
            'uid' => $liveUid,
            'defaultComment' => $comment,
        ]);

        return true;
    }

    /**
     * The sprintf format for the comment a regression writes into the task's
     * history, through the same `editorial_flow.messages` domain the wizard and
     * the Visual Editor actions use (`ve.comment.reopened` is its hand-picked
     * counterpart). `%1$s` is the table, `%2$d` the record uid.
     *
     * Falls back to the English source rather than letting sprintf() run on an
     * empty string: this text is persisted into a comment an editor will read
     * later, and a blank one is worse than an untranslated one. A DataHandler
     * hook can also run from a context that never set up $GLOBALS['LANG'] - the
     * scheduler, a CLI import - which is the other way sL() has nothing to say.
     */
    private function reopenCommentFormat(): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $format = $languageService instanceof LanguageService
            ? $languageService->sL('editorial_flow.messages:autoCreation.comment.reopened')
            : '';

        return $format !== ''
            ? $format
            : 'Automatically reopened for editing - %1$s:%2$d was modified.';
    }

    /**
     * One save results in at most one follow-up wizard. The task itself has
     * already been captured server-side, so this payload is purely "what should
     * the browser ask next?" data.
     *
     * @param array<string, mixed> $payload
     */
    private function storePendingWizard(DataHandler $dataHandler, array $payload): void
    {
        $dataHandler->BE_USER->setAndSaveSessionData('editorial_flow_pending_wizard', $payload);
    }
}
