<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Controller;

use GbWeb\EditorialFlow\Domain\Model\TaskCloseMode;
use GbWeb\EditorialFlow\Domain\Model\TaskPriority;
use GbWeb\EditorialFlow\Domain\Model\TaskState;
use GbWeb\EditorialFlow\Domain\Repository\CommentRepository;
use GbWeb\EditorialFlow\Domain\Repository\TaskChecklistRepository;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Notification\AssignmentNotificationService;
use GbWeb\EditorialFlow\Service\ActiveTaskSession;
use GbWeb\EditorialFlow\Service\ActivityLogger;
use GbWeb\EditorialFlow\Service\PendingPageHandoff;
use GbWeb\EditorialFlow\Service\PendingSubjectHandoff;
use GbWeb\EditorialFlow\Service\RecordCreationTargetProvider;
use GbWeb\EditorialFlow\Service\ReferenceInspector;
use GbWeb\EditorialFlow\Service\StageTransitionService;
use GbWeb\EditorialFlow\Service\TaskColor;
use GbWeb\EditorialFlow\Service\TaskEventPublisher;
use GbWeb\EditorialFlow\Service\TaskMemberSynchronizer;
use GbWeb\EditorialFlow\Service\TaskPublishGate;
use GbWeb\EditorialFlow\Service\TaskSubjectRegistry;
use GbWeb\EditorialFlow\Service\WorkspaceConflictDetector;
use GbWeb\EditorialFlow\Service\WorkspaceIntegrationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;
use TYPO3\CMS\Workspaces\Service\StagesService;

/**
 * Write and detail endpoints for the board and workspace popups.
 */
final class TaskAjaxController
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly CommentRepository $commentRepository,
        private readonly TaskChecklistRepository $checklistRepository,
        private readonly TaskSubjectRegistry $subjectRegistry,
        private readonly TaskMemberSynchronizer $memberSynchronizer,
        private readonly ReferenceInspector $referenceInspector,
        private readonly ActivityLogger $activityLogger,
        private readonly ActiveTaskSession $activeTaskSession,
        private readonly PendingPageHandoff $pendingPageHandoff,
        private readonly PendingSubjectHandoff $pendingSubjectHandoff,
        private readonly RecordCreationTargetProvider $recordCreationTargetProvider,
        private readonly AssignmentNotificationService $assignmentNotificationService,
        private readonly WorkspaceIntegrationService $workspaceService,
        private readonly TaskPublishGate $taskPublishGate,
        private readonly StageTransitionService $stageTransitionService,
        private readonly TaskEventPublisher $taskEventPublisher,
        private readonly StagesService $stagesService,
        private readonly UriBuilder $uriBuilder,
        private readonly ViewFactoryInterface $viewFactory,
        private readonly LoggerInterface $logger,
        private readonly WorkspaceConflictDetector $conflictDetector,
    ) {
    }

    /**
     * Create a task for a record explicitly picked in the wizard.
     *
     * The "+" flow is intentional user input: if an editor selects a content
     * element or another trackable record here, it should get its own task even
     * though the same record would usually auto-join its page's task.
     */
    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $table = (string)($body['table'] ?? 'pages');
        $uid = (int)($body['uid'] ?? 0);

        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->error($error);
        }

        // Values the wizard collected. Ignoring them would make the wizard a
        // decorative form whose inputs do nothing.
        $title = trim((string)($body['title'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $priority = TaskPriority::fromRequest($body['priority'] ?? null);
        // 'open' means deliberately unassigned so someone can take it - a real
        // planning state, not a missing value.
        $assignee = $this->resolveRequestedAssignee($body['assignee'] ?? 'me');
        $startDate = $this->parseDate($body['startDate'] ?? null);
        $dueDate = $this->parseDate($body['dueDate'] ?? null);

        // Asked before, the same way TaskAutoCreationService does: the repository
        // answers with an existing open task without saying so, and announcing
        // "a task was created" for one that has been on the board for a week
        // would put a duplicate ticket on whatever is listening.
        $isNew = $this->taskRepository->findOpenBySubject($table, $uid) === null;

        $task = $this->taskRepository->findOrCreateOpenForSubject($table, $uid, [
            'title' => $title !== '' ? $title : $this->deriveTitle($table, $uid),
            'description' => $description,
            'subject_pid' => $table === 'pages' ? $uid : (int)(BackendUtility::getRecord($table, $uid, 'pid')['pid'] ?? 0),
            // A start date is the editorial commitment "this is being worked
            // on" - the board should already show it as Planned rather than
            // waiting for someone to notice a bare Backlog card and drag it.
            'state' => $startDate > 0 ? TaskState::PLANNED->value : TaskState::BACKLOG->value,
            'priority' => $priority->value,
            'assignee' => $assignee,
            'start_date' => $startDate,
            'due_date' => $dueDate,
            // Planned by a human, so no auto_created flag and no wizard nagging.
            'auto_created' => 0,
        ]);
        $taskUid = (int)$task['uid'];

        $claimed = 0;
        if ($table === 'pages') {
            $claimed = $this->memberSynchronizer->syncPageMembers($taskUid, $uid);
        }

        // Guards the idempotent-existing-task path: findOrCreateOpenForSubject()
        // ignores $values entirely when the task already existed, so the
        // persisted assignee only matches what was requested here when it was
        // genuinely just applied.
        if ((int)$task['assignee'] === $assignee) {
            $this->notifyAssignment($taskUid, (string)$task['title'], $table, $uid, $assignee);
        }

        if ($isNew) {
            $this->taskEventPublisher->taskCreated(
                $this->taskRepository->findByUid($taskUid) ?? $task,
                (int)($this->getBackendUser()->user['uid'] ?? 0),
            );
        }

        return new JsonResponse([
            'success' => true,
            'task' => $taskUid,
            'claimed' => $claimed,
        ]);
    }

    /**
     * "Neue Seite erstellen" on the "+ New task" wizard: plans a page that does
     * not exist yet, rather than opening core's page-creation wizard immediately.
     * The page itself is only created once an editor drags this ticket into
     * Editing, and it is TYPO3's own page wizard that creates it - see
     * moveStageAction()'s requestPageWizard() and PendingPageHandoff. Until
     * then it is a title-only ticket moving freely between Backlog and Planned
     * like any other, just without a real subject record behind it yet.
     */
    public function createPendingPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $parentPid = (int)($body['parentPid'] ?? 0);
        if ($parentPid < 1) {
            return $this->reject(
                'missing-parent-page',
                'No parent page was specified to create the new page under.',
                [],
            );
        }
        if (!$this->mayCreatePageUnder($parentPid)) {
            return $this->reject(
                'no-permission',
                'You are not allowed to create a new page here.',
                ['parentPid' => $parentPid],
            );
        }

        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            return $this->reject('task-title-required', 'A title is required.', []);
        }
        $description = trim((string)($body['description'] ?? ''));
        $priority = TaskPriority::fromRequest($body['priority'] ?? null);
        $assignee = $this->resolveRequestedAssignee($body['assignee'] ?? 'me');
        $startDate = $this->parseDate($body['startDate'] ?? null);
        $dueDate = $this->parseDate($body['dueDate'] ?? null);

        $task = $this->taskRepository->createPendingPageTask($parentPid, [
            'title' => $title,
            'description' => $description,
            'state' => $startDate > 0 ? TaskState::PLANNED->value : TaskState::BACKLOG->value,
            'priority' => $priority->value,
            'assignee' => $assignee,
            'start_date' => $startDate,
            'due_date' => $dueDate,
            'auto_created' => 0,
        ]);
        $taskUid = (int)$task['uid'];

        $this->notifyAssignment($taskUid, $title, 'pages', 0, $assignee);

        return new JsonResponse(['success' => true, 'task' => $taskUid]);
    }

    /**
     * "Select to task": move the selected records onto a task.
     *
     * Records already belonging to another open task are moved, not duplicated -
     * a record lives in exactly one open task, and that invariant is what the whole
     * detach mechanism rests on.
     */
    public function attachAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $records = is_array($body['records'] ?? null) ? $body['records'] : [];

        $task = $this->findOpenTaskOrError($taskUid, 'attach records to it');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        // Checked once rather than per record: whether a task can receive work
        // at all is a property of that task, not of any record handed to it. A
        // pending version lives in the editor's workspace, so moving it onto a
        // task bound to a different one would leave its changes unreachable -
        // the ticket could only offer "switch to that workspace to act on
        // this". Same rule openTasksForContext() applies when it offers tasks.
        $workspaceUid = (int)$this->getBackendUser()->workspace;
        $taskWorkspaceUid = (int)($task['workspace_uid'] ?? 0);
        if ($taskWorkspaceUid !== 0 && $taskWorkspaceUid !== $workspaceUid) {
            return $this->reject(
                'task-in-other-workspace',
                $this->label('membership.error.otherWorkspace'),
                ['taskUid' => $taskUid, 'taskWorkspaceUid' => $taskWorkspaceUid, 'workspaceUid' => $workspaceUid],
            );
        }

        $beUserId = (int)($this->getBackendUser()->user['uid'] ?? 0);
        $moved = [];
        $refused = [];
        foreach ($records as $record) {
            $table = (string)($record['table'] ?? '');
            $uid = (int)($record['uid'] ?? 0);

            $error = $this->assertMayEdit($table, $uid);
            if ($error !== null) {
                $refused[] = ['table' => $table, 'uid' => $uid] + $this->logAndExposeError($error);
                continue;
            }

            $homePid = $this->derivePid($table, $uid);
            $currentTask = $this->taskRepository->findOpenTaskByMember($table, $uid);
            if ($currentTask !== null) {
                if ((int)$currentTask['uid'] === $taskUid) {
                    // Already where it was asked to go. Not an error, and not
                    // worth an activity entry saying nothing happened.
                    $moved[] = ['table' => $table, 'uid' => $uid];
                    continue;
                }
                $this->taskRepository->moveMemberToTask($table, $uid, $taskUid);
            } else {
                $this->taskRepository->addMemberIfUnclaimed(
                    $taskUid,
                    $table,
                    $uid,
                    TaskRepository::ORIGIN_MANUAL,
                    $homePid,
                    $this->referenceInspector->isSharedAcrossPages($table, $uid, $homePid),
                );
            }

            $this->logMembershipChange(
                ActivityLogger::EVENT_MEMBER_MOVED,
                $table,
                $uid,
                $currentTask !== null ? (int)$currentTask['uid'] : 0,
                $taskUid,
                $beUserId,
            );
            $moved[] = ['table' => $table, 'uid' => $uid];
        }

        return new JsonResponse([
            'success' => $refused === [],
            'moved' => $moved,
            'refused' => $refused,
        ]);
    }

    /**
     * "Split from task": pull a record out into a task of its own.
     *
     * The escape hatch from page aggregation - one banner really being its own
     * piece of work. The board can route any trackable record back to its edit
     * form, so an editor may promote even page-bound content into its own task.
     *
     * Nothing the record carries is at stake here: the workspace version hangs
     * on the record, and detachIntoOwnTask() only re-points the membership row.
     * The new task inherits the old one's state, stage and workspace, so the
     * split-off card appears in the same column showing the same diffs.
     *
     * `title`, `description` and `assignee` are optional: the split dialog
     * collects them, while callers that just want the record out (and the
     * existing wizard route) keep the derived title and the acting editor.
     */
    public function detachAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $table = (string)($body['table'] ?? '');
        $uid = (int)($body['uid'] ?? 0);

        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->error($error);
        }

        $current = $this->taskRepository->findOpenTaskByMember($table, $uid);
        if ($current === null) {
            return $this->reject(
                'record-not-in-open-task',
                'This record does not belong to an open task, so there is nothing to split.',
                ['table' => $table, 'uid' => $uid],
            );
        }
        if ((string)$current['subject_table'] === $table && (int)$current['subject_uid'] === $uid) {
            return $this->reject(
                'cannot-split-task-from-itself',
                'This is already the task\'s own subject - it cannot be split from itself.',
                ['table' => $table, 'uid' => $uid, 'taskUid' => (int)$current['uid']],
            );
        }

        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            $title = $this->deriveTitle($table, $uid);
        }
        $description = trim((string)($body['description'] ?? ''));
        $assignee = $this->resolveRequestedAssignee($body['assignee'] ?? 'me');
        $beUserId = (int)($this->getBackendUser()->user['uid'] ?? 0);

        $task = $this->taskRepository->detachIntoOwnTask($table, $uid, [
            'title' => $title,
            'description' => $description,
            'subject_pid' => $this->derivePid($table, $uid),
            'state' => (string)$current['state'],
            'stage_uid' => (int)$current['stage_uid'],
            'workspace_uid' => (int)$current['workspace_uid'],
            'assignee' => $assignee,
            'auto_created' => 0,
        ]);
        $taskUid = (int)$task['uid'];

        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_TASK_CREATED, $beUserId, [
            'subjectTable' => $table,
            'subjectUid' => $uid,
            'split' => true,
        ]);
        $this->logMembershipChange(
            ActivityLogger::EVENT_MEMBER_SPLIT,
            $table,
            $uid,
            (int)$current['uid'],
            $taskUid,
            $beUserId,
        );
        $this->notifyAssignment($taskUid, $title, $table, $uid, $assignee);

        return new JsonResponse([
            'success' => true,
            'task' => $taskUid,
            'from' => (int)$current['uid'],
        ]);
    }

    /**
     * Which other open tasks this record could be moved onto.
     *
     * Not openTasksForContext(): that one narrows to the record's own subject
     * and member tasks, which for a content element is precisely the task it
     * already sits in - it would offer the editor a list of one wrong answer.
     * The useful candidates are the open tasks around the record instead: those
     * on the page it lives on, plus those on the page its current task is about
     * (the two differ exactly in the cross-page case a move is there to
     * resolve).
     */
    public function moveTargetsAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $table = trim((string)($query['table'] ?? ''));
        $uid = (int)($query['uid'] ?? 0);

        // The table/uid pair is the client's claim, so the record's own edit
        // permission gates the answer - without it this lists the open tasks,
        // titles and stages around any record in the installation.
        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->error($error);
        }

        $current = $this->taskRepository->findOpenTaskByMember($table, $uid);
        if ($current === null) {
            return $this->reject(
                'record-not-in-open-task',
                'This record does not belong to an open task, so there is nothing to move.',
                ['table' => $table, 'uid' => $uid],
            );
        }

        $currentTaskUid = (int)$current['uid'];
        $tasks = $this->openTaskCandidatesAround(
            $this->derivePid($table, $uid),
            (int)$current['subject_pid'],
            $currentTaskUid,
        );

        return new JsonResponse([
            'success' => true,
            'currentTask' => $currentTaskUid,
            'currentTaskTitle' => (string)$current['title'],
            'tasks' => array_values($tasks),
        ]);
    }

    /**
     * The open tasks a record could reasonably be handed to.
     *
     * Both the record's own page and the current task's subject page, because
     * the two differ exactly when an editor changed content belonging elsewhere
     * - which is the case where "give this to another task" is most needed.
     *
     * Shared by moveTargetsAction() and closePreviewAction() so the picker and
     * the close dialog can never offer different targets. The workspace filter
     * is the same rule attachAction() enforces on the way in: nothing is offered
     * that the write endpoint would refuse.
     *
     * @return array<int, array{uid: int, title: string, state: string, stageLabel: string}>
     */
    private function openTaskCandidatesAround(int $recordPid, int $subjectPid, int $excludeTaskUid): array
    {
        $workspaceUid = (int)$this->getBackendUser()->workspace;

        $candidates = array_merge(
            $this->taskRepository->findAllOpenForPage($recordPid),
            $this->taskRepository->findAllOpenForPage($subjectPid),
        );

        $tasks = [];
        foreach ($candidates as $candidate) {
            $candidateUid = (int)$candidate['uid'];
            if ($candidateUid === $excludeTaskUid || isset($tasks[$candidateUid])) {
                continue;
            }
            $candidateWorkspaceUid = (int)$candidate['workspace_uid'];
            if ($candidateWorkspaceUid !== 0 && $candidateWorkspaceUid !== $workspaceUid) {
                continue;
            }

            $tasks[$candidateUid] = [
                'uid' => $candidateUid,
                'title' => (string)$candidate['title'],
                'state' => (string)$candidate['state'],
                'stageLabel' => $this->stageLabelFor($candidate),
            ];
        }

        return $tasks;
    }

    /**
     * Everything the close dialog has to show before an editor decides.
     *
     * Closing is irreversible for the task and, in discard mode, for the
     * content. "This task still has unpublished changes" is not enough to
     * decide on - the dialog names each record and which of its fields changed,
     * so the choice between keeping, handing over and discarding is made
     * against what is actually at stake.
     *
     * The change labels come from WorkspaceIntegrationService::getRecordDiffs(),
     * the same renderer the ticket's Changes section uses, so the two surfaces
     * cannot describe the same version differently.
     */
    public function closePreviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $taskUid = (int)($request->getQueryParams()['task'] ?? 0);

        $task = $this->findOpenTaskOrError($taskUid, 'close it');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $workspaceUid = (int)$task['workspace_uid'];
        $currentWorkspace = (int)$this->getBackendUser()->workspace;
        $workspaceTitles = $this->conflictDetector->resolveWorkspaceTitles([$workspaceUid]);
        $workspaceTitle = $workspaceUid > 0 ? ($workspaceTitles[$workspaceUid] ?? ('#' . $workspaceUid)) : '';

        $pending = [];
        foreach ($this->memberSynchronizer->findPendingVersionPairsByTable($taskUid, $workspaceUid) as $table => $pairs) {
            foreach ($pairs as $pair) {
                $record = BackendUtility::getRecord($table, $pair['live']);
                // Capped, with the true count alongside: a version with forty
                // changed fields must not turn the dialog into a scroll region,
                // but "and 35 more" is information the editor needs.
                $labels = array_values(array_unique(array_filter(array_map(
                    static fn (array $diff): string => (string)$diff['label'],
                    $this->workspaceService->getRecordDiffs($table, $pair['version']),
                ))));

                $pending[] = [
                    'table' => $table,
                    'uid' => $pair['live'],
                    'versionUid' => $pair['version'],
                    'title' => $record !== null
                        ? BackendUtility::getRecordTitle($table, $record)
                        : sprintf('%s:%d', $table, $pair['live']),
                    'changes' => array_slice($labels, 0, 5),
                    'changeCount' => count($labels),
                ];
            }
        }

        // Answered here rather than left for the POST to refuse, so the dialog
        // can grey the option out and say why instead of letting an editor pick
        // something that is going to fail.
        $canDiscard = $pending !== [] && $workspaceUid > 0 && $currentWorkspace === $workspaceUid;
        $discardBlockedReason = '';
        if ($pending !== [] && !$canDiscard) {
            $discardBlockedReason = sprintf(
                'Switch to workspace "%s" to discard these changes.',
                $workspaceTitle !== '' ? $workspaceTitle : ('#' . $workspaceUid),
            );
        }

        return new JsonResponse([
            'success' => true,
            'task' => [
                'uid' => $taskUid,
                'title' => (string)$task['title'],
                'workspaceUid' => $workspaceUid,
                'workspaceTitle' => $workspaceTitle,
            ],
            'canDiscard' => $canDiscard,
            'discardBlockedReason' => $discardBlockedReason,
            'pending' => $pending,
            'handoverTargets' => array_values($this->openTaskCandidatesAround(
                (int)$task['subject_pid'],
                (int)$task['subject_pid'],
                $taskUid,
            )),
        ]);
    }

    /**
     * One membership change, written to both tasks involved.
     *
     * The source entry is the point: without it a task's trail simply loses an
     * element, with no record of where it went - and this trail has to outlive
     * sys_history's 30-day garbage collection, which is the only other place
     * the move would show up at all.
     */
    private function logMembershipChange(
        string $event,
        string $table,
        int $recordUid,
        int $fromTaskUid,
        int $toTaskUid,
        int $beUserId,
    ): void {
        $payload = [
            'table' => $table,
            'recordUid' => $recordUid,
            'recordTitle' => $this->deriveTitle($table, $recordUid),
            'fromTask' => $fromTaskUid,
            'toTask' => $toTaskUid,
        ];

        if ($fromTaskUid > 0) {
            $this->activityLogger->log($fromTaskUid, $event, $beUserId, $payload);
        }
        $this->activityLogger->log($toTaskUid, $event, $beUserId, $payload);
    }

    /**
     * A shareable link to preview one member's pending version - the workspace
     * module's own "view" action (WorkspacesAjaxController::viewSingleRecord()),
     * scoped to a single record instead of the whole page.
     */
    public function previewMemberAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $table = (string)($body['table'] ?? '');
        $uid = (int)($body['uid'] ?? 0);

        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->error($error);
        }

        $task = $this->taskRepository->findOpenTaskByMember($table, $uid);
        if ($task === null) {
            return $this->reject(
                'record-not-in-open-task',
                'This record does not belong to an open task.',
                ['table' => $table, 'uid' => $uid],
            );
        }

        $workspaceUid = (int)$task['workspace_uid'];
        // buildUriForElement() wants the version(!) uid - passing the live uid
        // straight through would preview the already-live content, defeating
        // the point of a workspace preview.
        $versionRecord = $workspaceUid > 0
            ? BackendUtility::getWorkspaceVersionOfRecord($workspaceUid, $table, $uid, 'uid')
            : false;
        $versionUid = $versionRecord !== false ? (int)$versionRecord['uid'] : $uid;

        $url = GeneralUtility::makeInstance(PreviewUriBuilder::class)->buildUriForElement($table, $versionUid);
        if ($url === '') {
            return $this->reject(
                'preview-unavailable',
                'Could not build a preview link for that record.',
                ['table' => $table, 'uid' => $uid],
            );
        }

        return new JsonResponse(['success' => true, 'url' => $url]);
    }

    /**
     * Throw away one member's pending version, without touching its task
     * membership - the live record stays claimed for whenever it is next
     * edited. `discard()` accepts either uid and resolves the live one to its
     * version itself (TYPO3\CMS\Core\DataHandling\DataHandler::discard()), so
     * no separate version lookup is needed here the way publish/setStage need one.
     */
    public function discardMemberAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $table = (string)($body['table'] ?? '');
        $uid = (int)($body['uid'] ?? 0);

        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->error($error);
        }

        $task = $this->taskRepository->findOpenTaskByMember($table, $uid);
        if ($task === null) {
            return $this->reject(
                'record-not-in-open-task',
                'This record does not belong to an open task.',
                ['table' => $table, 'uid' => $uid],
            );
        }

        $workspaceUid = (int)$task['workspace_uid'];
        if ($workspaceUid < 1) {
            return $this->reject(
                'no-pending-versions',
                'This record has no pending version to discard.',
                ['table' => $table, 'uid' => $uid],
            );
        }

        // DataHandler::discard() resolves the version through the ACTING user's
        // workspace, and returns without a word - and without an errorLog entry -
        // when that workspace is Live (DataHandler.php:6158-6165). Called from
        // Live this reported a successful discard having thrown away nothing.
        $currentWorkspace = (int)$this->getBackendUser()->workspace;
        if ($currentWorkspace !== $workspaceUid) {
            $titles = $this->conflictDetector->resolveWorkspaceTitles([$workspaceUid]);
            return $this->reject(
                'discard-requires-task-workspace',
                sprintf(
                    'Switch to workspace "%s" to discard this record\'s changes.',
                    $titles[$workspaceUid] ?? ('#' . $workspaceUid),
                ),
                [
                    'table' => $table,
                    'uid' => $uid,
                    'workspaceUid' => $workspaceUid,
                    'currentWorkspace' => $currentWorkspace,
                ],
            );
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['discard' => true]]]);
        $dataHandler->process_cmdmap();

        if ($dataHandler->errorLog !== []) {
            $this->logger->warning('core-refused-discard', [
                'table' => $table,
                'uid' => $uid,
                'taskUid' => (int)$task['uid'],
                'errors' => $dataHandler->errorLog,
            ]);
            return new JsonResponse([
                'success' => false,
                'code' => 'core-refused-discard',
                'message' => implode(' ', array_map('strval', $dataHandler->errorLog)),
            ], 400);
        }

        $this->activityLogger->log((int)$task['uid'], ActivityLogger::EVENT_DISCARDED, (int)($this->getBackendUser()->user['uid'] ?? 0), [
            'table' => $table,
            'liveUid' => $uid,
            'workspaceUid' => $workspaceUid,
        ]);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Move a task to a different stage or state (column drop).
     */
    public function moveStageAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $targetState = (string)($body['state'] ?? 'backlog');
        $targetStageUid = (int)($body['stageUid'] ?? 0);

        $task = $this->findOpenTaskOrError($taskUid, 'move it');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $state = TaskState::tryFrom($targetState);

        // A pending page ("Neue Seite erstellen" - see createPendingPageAction())
        // has no real subject yet, so assertMayEdit() below would reject it on
        // subject_uid 0 alone. The page is created the moment the ticket reaches
        // a stage that needs one - the first of those is Editing, where a
        // workspace version starts to exist.
        //
        // Backlog and Planned are *not* such a stage: moving between them is
        // planning, writes nothing but this extension's own column, and needs no
        // page. Rejecting that move used to tell an editor their entirely
        // correct drag was wrong ("move it to a review stage to create it"),
        // which is what this distinction is for.
        $isPendingSubject = (int)$task['subject_uid'] === 0;
        $isPendingPage = $isPendingSubject && (string)$task['subject_table'] === 'pages';

        if ($isPendingSubject) {
            if ($state === null) {
                return $this->reject(
                    'unknown-target-column',
                    'This ticket cannot be moved to that column.',
                    ['taskUid' => $taskUid, 'targetState' => $targetState],
                );
            }
            if ($state === TaskState::DONE) {
                return $this->reject(
                    $isPendingPage ? 'pending-page-cannot-be-done' : 'pending-subject-cannot-be-done',
                    $isPendingPage
                        ? 'This ticket never became a page, so there is nothing to finish. Start work on it first.'
                        : 'This ticket has no record yet, so there is nothing to finish. Start work on it first.',
                    ['taskUid' => $taskUid],
                );
            }
            if ($state->hasVersion()) {
                // The page is created by TYPO3's own page wizard, not silently
                // here: position, page type and the type's required fields are
                // an editor's decision, and core already asks for them properly.
                // The move itself is completed by the DataHandler hook once that
                // wizard creates the page - see PendingPageHandoff.
                return $isPendingPage
                    ? $this->requestPageWizard($task)
                    : $this->requestRecordTarget($task);
            }
        }

        // Done is produced by closing, never by a column move.
        //
        // TaskState::DONE->hasVersion() is false, which groups it with
        // Backlog/Planned as one of Editorial Flow's own states - so without
        // this guard the branch below writes state = done through
        // moveToColumn(), which does not set `closed`. The result is a task
        // sitting in the Done column while still fully open and writable. The
        // board cannot produce that (the Done column takes no drops), but any
        // POST to this route can, and did.
        if ($state === TaskState::DONE) {
            return $this->reject(
                'close-task-instead-of-done',
                'Finishing a task is an explicit action, not a column move - close it instead.',
                ['taskUid' => $taskUid],
                $this->offerToClose($taskUid),
            );
        }

        // Skipped for a ticket that still has no page: there is no record to
        // hold a permission on, and the move about to happen writes none.
        if (!$isPendingSubject) {
            $error = $this->assertMayEdit((string)$task['subject_table'], (int)$task['subject_uid']);
            if ($error !== null) {
                return $this->error($error);
            }
        }

        // A drop onto a core stage column is a workspace stage transition and must
        // go through core - permissions, sys_history and stage notifications all
        // live there. Only Editorial Flow's own columns (Backlog / Planned), which
        // exist precisely because core has no state for "not versioned yet", are
        // written directly.
        if ($state !== null && $state->hasVersion()) {
            if ((int)$task['workspace_uid'] === 0 && $targetStageUid === StagesService::STAGE_EDIT_ID) {
                return $this->startEditing($task);
            }
            return $this->executeStageAction($request);
        }

        if ((int)$task['workspace_uid'] > 0) {
            return $this->reject(
                'cannot-return-versioned-task-to-planning',
                'This task already has a workspace version, so it cannot be moved back to a planning column.',
                ['taskUid' => $taskUid, 'workspaceUid' => (int)$task['workspace_uid']],
                $this->offerToClose($taskUid),
            );
        }

        $this->taskRepository->moveToColumn($taskUid, $targetState, $targetStageUid);

        $beUserId = (int)($this->getBackendUser()->user['uid'] ?? 0);
        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_STAGE_CHANGED, $beUserId, [
            'from_state' => $task['state'],
            'from_stage' => (int)$task['stage_uid'],
            'to_state' => $targetState,
            'to_stage' => $targetStageUid,
        ]);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Start a planned task before a workspace version exists. The first actual
     * save creates that version; the active context makes sure it lands here.
     *
     * @param array<string, mixed> $task
     */
    private function startEditing(array $task): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        $workspaceUid = (int)$backendUser->workspace;
        $taskUid = (int)$task['uid'];
        if ($workspaceUid < 1) {
            return $this->reject(
                'no-workspace-selected',
                $this->veLabel('ve.error.noWorkspaceSelected'),
                ['taskUid' => $taskUid],
            );
        }

        $table = (string)$task['subject_table'];
        $uid = (int)$task['subject_uid'];
        $this->taskRepository->attachWorkspace($taskUid, $workspaceUid, StagesService::STAGE_EDIT_ID);
        $this->activeTaskSession->rememberForContext($backendUser, $table, $uid, $taskUid);
        $this->activityLogger->log(
            $taskUid,
            ActivityLogger::EVENT_WORK_STARTED,
            (int)($backendUser->user['uid'] ?? 0),
            [
                'contextTable' => $table,
                'contextUid' => $uid,
                'startedFromBoard' => true,
            ],
        );

        $redirectUrl = $table === 'pages'
            ? (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $uid])
            : (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [$table => [$uid => 'edit']],
                'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_editorialflow', [
                    'id' => $this->derivePid($table, $uid),
                ]),
            ]);

        return new JsonResponse([
            'success' => true,
            'startedEditing' => true,
            'redirectUrl' => $redirectUrl,
        ]);
    }

    /**
     * Assign the task to the current logged in backend user.
     */
    public function assignMeAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $beUserId = (int)($this->getBackendUser()->user['uid'] ?? 0);

        $task = $this->findOpenTaskOrError($taskUid, 'assign it');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $this->taskRepository->assignTo($taskUid, $beUserId);

        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_ASSIGNED, $beUserId, [
            'assignee' => $beUserId,
        ]);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Fetch complete task inspector details (diffs, comments, activities, editUrl, recipients).
     */
    public function detailsAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $taskUid = (int)($params['task'] ?? 0);
        if ($taskUid < 1) {
            $body = $this->getBody($request);
            $taskUid = (int)($body['task'] ?? 0);
        }

        $details = $this->workspaceService->getTaskDetails($taskUid);
        if ($details === null) {
            return $this->reject('task-not-found', 'This task no longer exists.', ['taskUid' => $taskUid]);
        }

        $subjectTable = (string)$details['subject']['table'];
        $subjectUid = (int)$details['subject']['uid'];

        $editUrl = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$subjectTable => [$subjectUid => 'edit']],
            'returnUrl' => '',
        ]);

        $recipients = $this->workspaceService->getStageRecipients();

        return new JsonResponse([
            'success' => true,
            'details' => $details,
            'editUrl' => $editUrl,
            'recipients' => $recipients,
        ]);
    }

    /**
     * Every open task touching a page, across every stage from Backlog
     * through the stage just before Done - feeds the Visual Editor's
     * persistent "Task" select (B4). Picking one there is a proactive,
     * before-the-fact declaration of where an edit should land, which is
     * what setActiveTaskForPageAction() records - this action only lists the
     * choices. See TaskRepository::findAllOpenForPage().
     */
    public function listOpenTasksForPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageUid = (int)($request->getQueryParams()['pageUid'] ?? 0);

        // The page uid arrives from the request, so it is the client's claim
        // rather than a fact. Without this an editor could enumerate the task
        // titles, states and stages of any page in the installation, including
        // ones outside their own DB mounts, just by counting up uids.
        $error = $this->assertMayReadPage($pageUid);
        if ($error !== null) {
            return $this->error($error);
        }

        $tasks = array_map(
            fn (array $task): array => [
                'uid' => (int)$task['uid'],
                'title' => (string)$task['title'],
                'state' => (string)$task['state'],
                'stageLabel' => $this->stageLabelFor($task),
            ],
            $this->taskRepository->findAllOpenForPage($pageUid),
        );

        // The choice an editor made earlier still routes every save on this page
        // (TaskAutoCreationService::captureEdit()), so the select has to be able
        // to show it after a reload. Without this the select came back on its
        // placeholder while the server kept routing to a task nobody could see -
        // and the markers below, which key off "which task is active", treated
        // the active task's own records as foreign.
        $activeTaskUid = $this->activeTaskSession->resolve($this->getBackendUser(), $pageUid);

        return new JsonResponse([
            'success' => true,
            'tasks' => $tasks,
            'activeTaskUid' => $activeTaskUid ?? 0,
        ]);
    }

    /**
     * Active-task control shared by Board, Layout and record edit forms.
     */
    public function activeTaskContextAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $table = trim((string)($query['table'] ?? ''));
        $uid = (int)($query['uid'] ?? 0);

        $tasks = [];
        if ($table !== '' && $uid > 0) {
            $error = $this->assertMayEdit($table, $uid);
            if ($error !== null) {
                return $this->error($error);
            }
            $tasks = array_map(
                fn (array $task): array => $this->activeTaskPayload($task),
                $this->openTasksForContext($table, $uid),
            );
        }

        return new JsonResponse([
            'success' => true,
            'activeTask' => $this->currentActiveTaskPayload(),
            'tasks' => $tasks,
        ]);
    }

    public function setActiveTaskForContextAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);

        return $this->setActiveTaskForContext(
            trim((string)($body['table'] ?? '')),
            (int)($body['uid'] ?? 0),
            (int)($body['taskUid'] ?? 0),
        );
    }

    /**
     * A human-readable name for wherever a task currently sits - a core stage
     * title for anything with a workspace version (StagesService::
     * getStageTitle() already handles Editing/Ready to publish/custom stages
     * uniformly, by stage uid alone), or this extension's own column label
     * for Backlog/Planned, which core has no notion of at all.
     *
     * @param array<string, mixed> $task
     */
    private function stageLabelFor(array $task): string
    {
        $state = TaskState::tryFrom((string)$task['state']);
        if ($state !== null && $state->hasVersion()) {
            return $this->stagesService->getStageTitle((int)$task['stage_uid']);
        }

        return match ($state) {
            TaskState::BACKLOG => $this->getLanguageService()->sL(
                'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:column.backlog'
            ) ?: 'Backlog',
            TaskState::PLANNED => $this->getLanguageService()->sL(
                'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:column.planned'
            ) ?: 'Planned',
            default => ucfirst((string)$task['state']),
        };
    }

    /** Visual Editor compatibility wrapper around the generic context action. */
    public function setActiveTaskForPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $pageUid = (int)($body['pageUid'] ?? 0);
        $taskUid = (int)($body['taskUid'] ?? 0);

        if ($pageUid < 1) {
            return $this->reject('missing-page-uid', $this->veLabel('ve.error.noPageGiven'), ['taskUid' => $taskUid]);
        }

        return $this->setActiveTaskForContext('pages', $pageUid, $taskUid);
    }

    private function setActiveTaskForContext(string $table, int $uid, int $taskUid): ResponseInterface
    {
        if ($taskUid === 0) {
            $this->activeTaskSession->forget($this->getBackendUser());

            return new JsonResponse([
                'success' => true,
                'taskUid' => 0,
                'state' => '',
                'stageLabel' => '',
                'transitioned' => false,
                'comment' => '',
                'commentUid' => 0,
                'activeTask' => null,
            ]);
        }

        if ($table === '' || $uid < 1) {
            return $this->reject('missing-record-context', 'No record context was specified.', [
                'table' => $table,
                'uid' => $uid,
                'taskUid' => $taskUid,
            ]);
        }

        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->error($error);
        }

        $task = $this->findOpenTaskOrError($taskUid, 'make it the active task');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $allowedTaskUids = array_map(
            static fn (array $candidate): int => (int)$candidate['uid'],
            $this->openTasksForContext($table, $uid),
        );
        if (!in_array($taskUid, $allowedTaskUids, true)) {
            return $this->reject('task-not-in-context', 'This task does not belong to the selected record context.', [
                'table' => $table,
                'uid' => $uid,
                'taskUid' => $taskUid,
            ]);
        }

        // Skipped for a ticket that still has no page, exactly as
        // moveStageAction() does: there is no record to hold a permission on
        // yet. Such a ticket cannot be picked here in practice anyway - the
        // select only lists tasks that touch a real page - but the check has to
        // survive a request that did not come from the select.
        $isPendingPage = (string)$task['subject_table'] === 'pages' && (int)$task['subject_uid'] === 0;
        if (!$isPendingPage) {
            $error = $this->assertMayEdit((string)$task['subject_table'], (int)$task['subject_uid']);
            if ($error !== null) {
                return $this->error($error);
            }
        }

        $beUser = $this->getBackendUser();
        $beUserId = (int)($beUser->user['uid'] ?? 0);
        $workspaceUid = (int)$beUser->workspace;

        $transitioned = false;
        $comment = '';
        $commentUid = 0;

        if ((int)$task['workspace_uid'] === 0) {
            if ($workspaceUid < 1) {
                return $this->reject(
                    'no-workspace-selected',
                    $this->veLabel('ve.error.noWorkspaceSelected'),
                    ['taskUid' => $taskUid],
                );
            }
            // Backlog/Planned -> Editing, mirroring what a first captured
            // edit already does today (TaskAutoCreationService::
            // captureEdit()) - just triggered by intent instead of by the
            // edit itself.
            $this->taskRepository->attachWorkspace($taskUid, $workspaceUid, StagesService::STAGE_EDIT_ID);
            $this->activityLogger->log($taskUid, ActivityLogger::EVENT_WORK_STARTED, $beUserId, [
                'contextTable' => $table,
                'contextUid' => $uid,
                'selectedAsActiveTask' => true,
            ]);
            $transitioned = true;
            $task = $this->taskRepository->findByUid($taskUid) ?? $task;
        } else {
            $state = TaskState::tryFrom((string)$task['state']);
            if ($state === TaskState::REVIEW || $state === TaskState::READY) {
                $versionsByTable = $this->memberSynchronizer->findPendingVersionsByTable($taskUid, (int)$task['workspace_uid']);
                if ($versionsByTable !== []) {
                    $comment = $this->veLabel('active.comment.reopened');
                    $refusal = $this->stageTransitionService->transition(
                        $task,
                        $versionsByTable,
                        StagesService::STAGE_EDIT_ID,
                        $beUserId,
                        $comment,
                    );
                    if ($refusal === null) {
                        $transitioned = true;
                        $task = $this->taskRepository->findByUid($taskUid) ?? $task;
                        $comments = $this->commentRepository->findByTask($taskUid);
                        $lastComment = end($comments);
                        $commentUid = $lastComment !== false ? (int)$lastComment['uid'] : 0;
                    } else {
                        $comment = '';
                    }
                }
            }
        }

        $this->activeTaskSession->rememberForContext($beUser, $table, $uid, $taskUid);

        return new JsonResponse([
            'success' => true,
            'taskUid' => $taskUid,
            'state' => (string)$task['state'],
            'stageLabel' => $this->stageLabelFor($task),
            'transitioned' => $transitioned,
            'comment' => $comment,
            'commentUid' => $commentUid,
            'activeTask' => $this->activeTaskPayload($task, $table, $uid),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openTasksForContext(string $table, int $uid): array
    {
        $pageUid = $this->derivePid($table, $uid);
        if ($pageUid < 1) {
            return [];
        }

        $tasks = $this->taskRepository->findAllOpenForPage($pageUid);
        if ($table !== 'pages') {
            $contextTaskUids = [];
            $subjectTask = $this->taskRepository->findOpenBySubject($table, $uid);
            if ($subjectTask !== null) {
                $contextTaskUids[] = (int)$subjectTask['uid'];
            }
            $memberTask = $this->taskRepository->findOpenTaskByMember($table, $uid);
            if ($memberTask !== null) {
                $contextTaskUids[] = (int)$memberTask['uid'];
            }
            $tasks = array_filter(
                $tasks,
                static fn (array $task): bool => in_array((int)$task['uid'], $contextTaskUids, true),
            );
        }

        $workspaceUid = (int)$this->getBackendUser()->workspace;

        return array_values(array_filter(
            $tasks,
            static fn (array $task): bool => (int)$task['workspace_uid'] === 0
                || (int)$task['workspace_uid'] === $workspaceUid,
        ));
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function activeTaskPayload(array $task, ?string $table = null, ?int $uid = null): array
    {
        return [
            'uid' => (int)$task['uid'],
            'title' => (string)$task['title'],
            'state' => (string)$task['state'],
            'stageLabel' => $this->stageLabelFor($task),
            'hue' => TaskColor::hueFor((int)$task['uid']),
            'contextTable' => $table,
            'contextUid' => $uid,
        ];
    }

    /** @return array<string, mixed>|null */
    private function currentActiveTaskPayload(): ?array
    {
        $active = $this->activeTaskSession->current($this->getBackendUser());
        if ($active === null) {
            return null;
        }
        $task = $this->taskRepository->findByUid($active['taskUid']);

        return $task !== null
            ? $this->activeTaskPayload($task, $active['table'], $active['uid'])
            : null;
    }

    /**
     * B4's markers: for every open task touching a page, which records it
     * already claims - so the Visual Editor can mark a content element that
     * belongs to a *different* task than the one currently active, before an
     * editor accidentally edits into it. Excludes closed/Done tasks for the
     * same reason findAllOpenForPage() does: nothing to warn about once work
     * is finished.
     *
     * Each member carries a list of `table:uid` identifiers rather than one
     * uid, because the two sides do not agree on which uid names a record.
     * Membership rows hold the LIVE uid; the Visual Editor renders the
     * frontend page workspace-overlaid, and EXT:visual_editor's
     * ContentElementWrapperService writes `uid = localizedUid ?: versionedUid
     * ?: uid` onto every `ve-content-element` - and `versionedUid` is
     * `_ORIG_uid`, which PageRepository::versionOL() sets to the VERSION while
     * leaving `uid` live. So exactly the interesting case, a task being worked
     * on, would never match on a single uid. Sending both, and letting the
     * client match either, is what makes the markers appear at all.
     */
    public function listMemberTaskMarkersForPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageUid = (int)($request->getQueryParams()['pageUid'] ?? 0);

        // Same reasoning as listOpenTasksForPageAction(): the page uid is the
        // client's claim. This response additionally names the table and uid of
        // every record each task has claimed, so leaving it ungated hands out a
        // map of another editor's pending work for the asking.
        $error = $this->assertMayReadPage($pageUid);
        if ($error !== null) {
            return $this->error($error);
        }

        $workspaceUid = (int)$this->getBackendUser()->workspace;

        $tasks = $this->taskRepository->findAllOpenForPage($pageUid);

        $taskList = [];
        $members = [];
        foreach ($tasks as $task) {
            $taskUid = (int)$task['uid'];
            // The marker tooltip and the toolbar legend both name a task in
            // full - who has it and where it sits - so the client never has to
            // ask a second endpoint just to label a coloured dot.
            $assigneeUid = (int)($task['assignee'] ?? 0);
            $taskList[] = [
                'uid' => $taskUid,
                'title' => (string)$task['title'],
                'stageLabel' => $this->stageLabelFor($task),
                'assigneeName' => $assigneeUid > 0
                    ? $this->workspaceService->resolveUserName($assigneeUid)
                    : '',
            ];
            foreach ($this->taskRepository->findMembers($taskUid) as $member) {
                $table = (string)$member['record_table'];
                $liveUid = (int)$member['record_uid'];
                $members[] = [
                    'table' => $table,
                    'uid' => $liveUid,
                    'taskUid' => $taskUid,
                    // Named here rather than read off the rendered element: the
                    // frontend markup carries the record's identity, not its
                    // backend title, and the marker's own actions ("give THIS
                    // its own task") have to say which record they mean.
                    'title' => $this->deriveTitle($table, $liveUid),
                    'identifiers' => $this->memberIdentifiers($table, $liveUid, $workspaceUid),
                ];
            }
        }

        return new JsonResponse(['success' => true, 'tasks' => $taskList, 'members' => $members]);
    }

    /**
     * Every `table:uid` spelling one member can appear under in the rendered
     * frontend - the live record, plus its pending workspace version when one
     * exists. Resolution is TaskMemberSynchronizer's, not a second copy of it,
     * so a record created directly inside the workspace (no live counterpart)
     * is handled the same way here as everywhere else.
     *
     * @return list<string>
     */
    private function memberIdentifiers(string $table, int $liveUid, int $workspaceUid): array
    {
        $identifiers = [$table . ':' . $liveUid];

        $versionUid = $this->memberSynchronizer->findVersionUid($table, $liveUid, $workspaceUid);
        if ($versionUid > 0 && $versionUid !== $liveUid) {
            $identifiers[] = $table . ':' . $versionUid;
        }

        return $identifiers;
    }

    /**
     * Execute stage change with stage comments and recipients.
     */
    public function executeStageAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $targetStageUid = (int)($body['stageUid'] ?? 0);
        $comment = trim((string)($body['comment'] ?? ''));
        $additionalRecipients = trim((string)($body['additional'] ?? ''));
        $deactivateActiveTask = filter_var(
            $body['deactivateActiveTask'] ?? false,
            FILTER_VALIDATE_BOOL,
        );
        // The editor's answer to the question below, sent on the second attempt.
        $acknowledgeIncomplete = filter_var(
            $body['acknowledgeIncomplete'] ?? false,
            FILTER_VALIDATE_BOOL,
        );
        $selectedRecipientUids = [];
        if (is_array($body['recipients'] ?? null)) {
            foreach ($body['recipients'] as $recipientUid) {
                $recipientUid = (int)$recipientUid;
                if ($recipientUid > 0) {
                    $selectedRecipientUids[$recipientUid] = $recipientUid;
                }
            }
        }

        $task = $this->findOpenTaskOrError($taskUid, 'change its stage');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $workspaceUid = (int)$task['workspace_uid'];
        if ($workspaceUid < 1) {
            return $this->reject(
                'no-workspace-version',
                'This task has no workspace version yet, so it cannot change stage.',
                ['taskUid' => $taskUid],
            );
        }

        $versionsByTable = $this->memberSynchronizer->findPendingVersionsByTable($taskUid, $workspaceUid);
        if ($versionsByTable === []) {
            return $this->reject(
                'no-pending-versions',
                'There is nothing pending on this task to move to another stage.',
                ['taskUid' => $taskUid, 'workspaceUid' => $workspaceUid],
                $this->offerToClose($taskUid),
            );
        }

        $recipients = $this->workspaceService->buildNotificationRecipients(
            $workspaceUid,
            $targetStageUid,
            array_values($selectedRecipientUids),
            $additionalRecipients,
        );

        // Read before the transition moves the task on: these belong to the stage
        // being LEFT, not the one being entered.
        $stageTitle = $this->stageLabelFor($task);
        $criteria = $this->checklistRepository->findChecklistForTask($taskUid, $workspaceUid, (int)$task['stage_uid']);
        $unconfirmed = array_values(array_filter($criteria, static fn (array $item): bool => $item['completed'] === false));

        if ($unconfirmed !== [] && !$acknowledgeIncomplete) {
            // Not a rejection, and deliberately not routed through reject():
            // nothing went wrong and nothing is refused, the server is asking a
            // question the editor has to answer before this becomes a decision.
            // Hence 200 and no log entry - the answer is what gets recorded, in
            // the acceptance record below.
            //
            // Asked here rather than only in the dialog because the dialog is a
            // client: a caller that skips it must not be able to skip the
            // question with it.
            return new JsonResponse([
                'success' => false,
                'code' => 'checklist-incomplete',
                'needsAcknowledgement' => true,
                'stageTitle' => $stageTitle,
                'unconfirmed' => array_map(static fn (array $item): string => (string)$item['title'], $unconfirmed),
                'message' => sprintf(
                    $this->label('criteria.incomplete.message')
                        ?: '%1$d of %2$d acceptance criteria for "%3$s" are not confirmed.',
                    count($unconfirmed),
                    count($criteria),
                    $stageTitle,
                ),
            ]);
        }

        $refusal = $this->stageTransitionService->transition(
            $task,
            $versionsByTable,
            $targetStageUid,
            (int)($this->getBackendUser()->user['uid'] ?? 0),
            $comment,
            $recipients,
            $criteria === [] ? null : $this->buildAcceptanceRecord($criteria, $stageTitle),
        );
        if ($refusal !== null) {
            // Core refused. Our own state must not drift away from what core did,
            // so nothing is written on this path. Logged at warning, not notice -
            // a core-level refusal usually means a permission or stage-workflow
            // misconfiguration worth a developer's attention, not routine input.
            $this->logger->warning('core-refused-stage-change', [
                'taskUid' => $taskUid,
                'targetStageUid' => $targetStageUid,
                'reason' => $refusal,
                'beUser' => (int)($this->getBackendUser()->user['uid'] ?? 0),
            ]);
            return new JsonResponse([
                'success' => false,
                'code' => 'core-refused-stage-change',
                'message' => $refusal,
            ], 400);
        }

        $activeTaskDeactivated = $deactivateActiveTask
            && $this->activeTaskSession->forgetIfTask($this->getBackendUser(), $taskUid);

        return new JsonResponse([
            'success' => true,
            'stageUid' => $targetStageUid,
            'activeTaskDeactivated' => $activeTaskDeactivated,
        ]);
    }

    /**
     * The acceptance record: what the stage asked for, and what was answered,
     * as one block of prose anchored to the transition it belongs to.
     *
     * A comment rather than a payload field, because this is the part an editor
     * reads back later - the ticket's timeline renders it under the stage change
     * it explains, next to whatever the editor wrote themselves. The per-tick
     * activity entries (ActivityLogger::EVENT_CHECKLIST_CHECKED) stay the
     * machine-readable half; this is the human one.
     *
     * @param list<array{uid: int, title: string, completed: bool}> $criteria
     */
    private function buildAcceptanceRecord(array $criteria, string $stageTitle): string
    {
        $lines = [sprintf(
            $this->label('criteria.record.heading') ?: 'Acceptance criteria for "%1$s":',
            $stageTitle,
        )];

        foreach ($criteria as $item) {
            $lines[] = sprintf('[%s] %s', $item['completed'] ? 'x' : ' ', $item['title']);
        }

        $unconfirmed = count(array_filter($criteria, static fn (array $item): bool => $item['completed'] === false));
        $lines[] = $unconfirmed === 0
            ? ($this->label('criteria.record.allConfirmed') ?: 'All criteria confirmed.')
            : sprintf(
                $this->label('criteria.record.acknowledged')
                    ?: 'Sent on with %1$d of %2$d criteria left unconfirmed.',
                $unconfirmed,
                count($criteria),
            );

        return implode("\n", $lines);
    }

    /**
     * What the board needs to know before it opens the "Send to stage" dialog.
     *
     * Two answers, asked in one round trip because both are needed at the same
     * moment:
     *
     * - `hasPending` is the same question executeStageAction() answers with a
     *   hard `no-pending-versions` rejection. Asked up front, the board can
     *   refuse the drop with an inline message (matching
     *   getDropRejectionMessage()'s other rules in board.js) rather than opening
     *   a dialog for a transition that can only ever fail. A task whose subject
     *   has never been touched inside its workspace has nothing pending - see
     *   WorkspaceIntegrationService::decorateMembers()'s hasPendingVersion note.
     * - `criteria` are the acceptance criteria of the stage the task is LEAVING,
     *   with what this task has confirmed so far, so the dialog can ask about
     *   them instead of reporting afterwards that they went unanswered.
     */
    public function checkStageTransitionEligibilityAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);

        $task = $this->findOpenTaskOrError($taskUid, 'change its stage');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $workspaceUid = (int)$task['workspace_uid'];
        if ($workspaceUid < 1) {
            return new JsonResponse(['success' => true, 'hasPending' => false, 'criteria' => []]);
        }

        $versionsByTable = $this->memberSynchronizer->findPendingVersionsByTable($taskUid, $workspaceUid);

        return new JsonResponse([
            'success' => true,
            'hasPending' => $versionsByTable !== [],
            'criteria' => $this->checklistRepository->findChecklistForTask(
                $taskUid,
                $workspaceUid,
                (int)$task['stage_uid'],
            ),
        ]);
    }

    /**
     * Publish everything a task still has pending, straight to live.
     *
     * Deliberately not a drop target - going live is irreversible, so the board
     * makes it an explicit, confirmed action instead of something a slightly
     * off-target drop could trigger (ARCHITECTURE.md).
     *
     * Gated by TaskPublishGate, which asks both halves of core's own rule: the
     * role question WorkspacePublishGate answers (owner/admin only, independent
     * of stage `responsible_persons` - reaching the final stage never implies
     * permission to actually publish), AND the workspace's
     * PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE bit, which decides whether an
     * otherwise permitted user may go live from an early stage at all.
     *
     * Asking only the first half was how a task could be published straight out
     * of the edit stage, skipping every review the workspace defines.
     *
     * Closing the task once everything is live is not done here:
     * CloseTaskAfterPublishListener does it off core's own
     * AfterRecordPublishedEvent, one per published record, so a task that covers
     * several records only closes once none of them are pending any more.
     */
    public function publishTaskAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);

        $task = $this->findOpenTaskOrError($taskUid, 'publish it');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $workspaceUid = (int)$task['workspace_uid'];
        if ($workspaceUid < 1) {
            return $this->reject(
                'no-workspace-version',
                'This task has no workspace version yet, so there is nothing to publish.',
                ['taskUid' => $taskUid],
            );
        }

        // Same gate the board rendered the button from. Asked again here because
        // the button's absence is a UI decision and this endpoint is reachable
        // without it - and because the stage can have moved on since the board
        // was rendered.
        $stageUid = (int)($task['stage_uid'] ?? 0);
        if (!$this->taskPublishGate->isGranted($this->getBackendUser(), $workspaceUid, $stageUid)) {
            return $this->reject(
                'publish-not-permitted',
                'You are not allowed to publish this task from the stage it is in.',
                ['taskUid' => $taskUid, 'workspaceUid' => $workspaceUid, 'stageUid' => $stageUid],
            );
        }

        $pairsByTable = $this->memberSynchronizer->findPendingVersionPairsByTable($taskUid, $workspaceUid);
        if ($pairsByTable === []) {
            return $this->resolveEmptyPublish($taskUid, $workspaceUid);
        }

        $refusal = $this->askCoreToPublish($pairsByTable);
        if ($refusal !== null) {
            $this->logger->warning('core-refused-publish', [
                'taskUid' => $taskUid,
                'workspaceUid' => $workspaceUid,
                'reason' => $refusal,
                'beUser' => (int)($this->getBackendUser()->user['uid'] ?? 0),
            ]);
            return new JsonResponse([
                'success' => false,
                'code' => 'core-refused-publish',
                'message' => $refusal,
            ], 400);
        }

        // CloseTaskAfterPublishListener already closed the task, if everything it
        // covers is now live - re-read rather than assume, since a task with
        // members outside this workspace's pending set stays open.
        $reloaded = $this->taskRepository->findByUid($taskUid);

        return new JsonResponse([
            'success' => true,
            'closed' => $reloaded === null || (bool)$reloaded['closed'],
        ]);
    }

    /**
     * Nothing pending in this task's own workspace at publishTaskAction()'s
     * point. Most of the time that just means the task was created (or a
     * member was manually attached, see task_item.origin) before anyone
     * actually edited it here - "There is nothing pending on this task to
     * publish" is then the honest answer, not an error to hide: the editor
     * still has to make the change before there is anything to go live.
     *
     * The one distinction worth making explicit is whether the record is
     * quietly waiting to be edited, or is *actively* pending review in a
     * different workspace right now (see WorkspaceConflictDetector's docblock
     * on how core allows the same live record to be versioned in more than
     * one workspace at once, uncoordinated). The latter is not "nothing to
     * publish", it is "the change exists, just not here" - worth saying, so
     * an editor is not left wondering where their colleague's edit went.
     */
    private function resolveEmptyPublish(int $taskUid, int $workspaceUid): ResponseInterface
    {
        foreach ($this->taskRepository->findMembers($taskUid) as $member) {
            $table = (string)$member['record_table'];
            if (!$this->subjectRegistry->isTrackable($table)) {
                continue;
            }
            $otherWorkspaces = array_values(array_diff(
                $this->conflictDetector->findPendingWorkspaces($table, (int)$member['record_uid']),
                [$workspaceUid],
            ));
            if ($otherWorkspaces !== []) {
                $titles = $this->conflictDetector->resolveWorkspaceTitles($otherWorkspaces);
                return $this->reject(
                    'pending-in-other-workspace',
                    sprintf('This record is still pending review in %s, not here yet.', implode(', ', $titles)),
                    ['taskUid' => $taskUid, 'workspaceUid' => $workspaceUid, 'otherWorkspaces' => $otherWorkspaces],
                );
            }
        }

        return $this->reject(
            'no-pending-versions',
            'There is nothing pending on this task to publish.',
            ['taskUid' => $taskUid, 'workspaceUid' => $workspaceUid],
            $this->offerToClose($taskUid),
        );
    }

    /**
     * Hand the publish to TYPO3 and report back whether it was refused.
     *
     * Keyed by the LIVE uid with the version as `swapWith` - the opposite order
     * from setStage/discard, which are keyed by the version uid. Verified
     * directly against TYPO3\CMS\Workspaces\Hook\DataHandlerHook::version_swap():
     * $id (the cmdmap key) must be the live record, $swapWith must be the
     * version whose own t3ver_oid points back at $id. Getting this backwards
     * fails with "In offline record, either t3ver_oid was not set or the
     * t3ver_oid didn't match the id of the online version as it must" - see
     * WORKSPACE-STAGES.md.
     *
     * @param array<string, list<array{live: int, version: int}>> $pairsByTable
     * @return string|null the refusal reason, or null when core accepted
     */
    private function askCoreToPublish(array $pairsByTable): ?string
    {
        $cmd = [];
        foreach ($pairsByTable as $table => $pairs) {
            foreach ($pairs as $pair) {
                $cmd[$table][$pair['live']]['version'] = [
                    'action' => 'publish',
                    'swapWith' => $pair['version'],
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
     * Finish a task. Always possible - that is the whole point of this action.
     *
     * Until this existed, the only way a task ever closed was
     * CloseTaskAfterPublishListener reacting to a publish. A task whose versions
     * were discarded, or whose record was published from a different workspace,
     * had nothing left to publish and was therefore stuck open forever: every
     * stage move answered `no-pending-versions`, the planning columns answered
     * `cannot-return-versioned-task-to-planning`, Done refuses drops on purpose,
     * and there was no close and no delete. An editor could see the dead end but
     * not leave it.
     *
     * So the precondition chain here is deliberately almost empty. Anything that
     * can refuse a close recreates the trap it is meant to remove.
     *
     * `mode` decides only what happens to versions that are still pending, and
     * defaults to leaving them alone - see TaskCloseMode. Handing them to another
     * task first is the client calling `attach` before this, not a mode.
     */
    public function closeTaskAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);

        $task = $this->findOpenTaskOrError($taskUid, 'close it');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $mode = TaskCloseMode::fromRequest($body['mode'] ?? null);
        if ($mode === null) {
            return $this->reject(
                'unknown-close-mode',
                'That is not a way to close a task.',
                ['taskUid' => $taskUid, 'mode' => (string)($body['mode'] ?? '')],
            );
        }

        $readError = $this->assertMayReadPage((int)$task['subject_pid']);
        if ($readError !== null) {
            // Read access to the page is the bar, not assertMayEdit() on the
            // subject: a task whose subject record was deleted answers
            // `record-not-found` there and would be unclosable forever, which is
            // exactly the state this action exists to end. A page that no longer
            // resolves at all is the same argument one level up - close it and
            // leave a trace, rather than stranding the row.
            if ($readError->code !== 'page-not-found' && $readError->code !== 'missing-page-uid') {
                return $this->error($readError);
            }
            $this->logger->notice('close-orphan-task', [
                'taskUid' => $taskUid,
                'subjectPid' => (int)$task['subject_pid'],
                'reason' => $readError->code,
                'beUser' => (int)($this->getBackendUser()->user['uid'] ?? 0),
            ]);
        }

        $workspaceUid = (int)$task['workspace_uid'];
        $pending = $this->memberSynchronizer->findPendingVersionPairsByTable($taskUid, $workspaceUid);

        $discarded = [];
        if ($mode === TaskCloseMode::DISCARD && $pending !== []) {
            $refusal = $this->discardPendingVersions($taskUid, $workspaceUid, $pending);
            if ($refusal instanceof ResponseInterface) {
                return $refusal;
            }
            $discarded = $refusal;
            $pending = [];
        }

        $beUserId = (int)($this->getBackendUser()->user['uid'] ?? 0);
        $this->taskRepository->close($taskUid, $beUserId);

        // keptPending is the reason this payload exists. mode=keep leaves
        // versions in the workspace that no open task claims any more; without
        // recording them here, the only trace of them would be in the Workspaces
        // module, and the archive would imply the task took everything with it.
        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_CLOSED, $beUserId, [
            'reason' => 'manual',
            'mode' => $mode->value,
            'workspaceId' => $workspaceUid,
            'discarded' => $discarded,
            'keptPending' => $this->describePendingPairs($pending),
        ]);

        $this->taskEventPublisher->taskClosed($task, 'manual', $beUserId);

        return new JsonResponse([
            'success' => true,
            'taskUid' => $taskUid,
            'closed' => true,
            'mode' => $mode->value,
            'discarded' => count($discarded),
            'keptPending' => count($this->describePendingPairs($pending)),
        ]);
    }

    /**
     * Throw away every pending version of a task, or refuse without writing.
     *
     * Three guards, and each one earns its place:
     *
     * The workspace check is not a permission nicety. DataHandler::discard()
     * returns without doing anything at all when the acting user sits in Live
     * (DataHandler.php:6158-6165) and, separately, refuses when the user's
     * workspace differs from the version's. The first of those leaves NO entry
     * in errorLog, so "core accepted it" is indistinguishable from "core ignored
     * it" - a caller trusting an empty errorLog would report a successful
     * discard having destroyed nothing, and then close the task on top of it.
     *
     * Permissions are checked for every record before any of them is touched.
     * attachAction reports per-record results because a partly-attached task is
     * still coherent; a partly-discarded one is not, so this is all-or-nothing.
     *
     * The re-read afterwards is the answer to the silent-return problem above:
     * the only trustworthy proof that the versions are gone is that they are
     * gone.
     *
     * @param array<string, list<array{live: int, version: int}>> $pending
     * @return ResponseInterface|list<array{table: string, uid: int, title: string}> the refusal, or what was discarded
     */
    private function discardPendingVersions(int $taskUid, int $workspaceUid, array $pending): ResponseInterface|array
    {
        $currentWorkspace = (int)$this->getBackendUser()->workspace;
        if ($workspaceUid < 1 || $currentWorkspace !== $workspaceUid) {
            $titles = $this->conflictDetector->resolveWorkspaceTitles([$workspaceUid]);
            return $this->reject(
                'close-requires-task-workspace',
                sprintf(
                    'Switch to workspace "%s" to discard these changes, or close the task and leave them pending.',
                    $titles[$workspaceUid] ?? ('#' . $workspaceUid),
                ),
                ['taskUid' => $taskUid, 'workspaceUid' => $workspaceUid, 'currentWorkspace' => $currentWorkspace],
            );
        }

        $refused = [];
        foreach ($pending as $table => $pairs) {
            foreach ($pairs as $pair) {
                $error = $this->assertMayEdit($table, $pair['live']);
                if ($error !== null) {
                    $refused[] = ['table' => $table, 'uid' => $pair['live']] + $this->logAndExposeError($error);
                }
            }
        }
        if ($refused !== []) {
            return new JsonResponse([
                'success' => false,
                'code' => 'discard-refused',
                'message' => 'Some of these changes cannot be discarded, so nothing was discarded and the task stays open.',
                'refused' => $refused,
            ], 400);
        }

        $describing = $this->describePendingPairs($pending);

        $cmd = [];
        foreach ($pending as $table => $pairs) {
            foreach ($pairs as $pair) {
                // Keyed by the live uid, which is what membership rows hold;
                // discard() resolves it to the version itself. Same reasoning as
                // discardMemberAction().
                $cmd[$table][$pair['live']]['discard'] = true;
            }
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();

        if ($dataHandler->errorLog !== []) {
            $this->logger->warning('core-refused-discard', [
                'taskUid' => $taskUid,
                'workspaceUid' => $workspaceUid,
                'errors' => $dataHandler->errorLog,
                'beUser' => (int)($this->getBackendUser()->user['uid'] ?? 0),
            ]);
            return new JsonResponse([
                'success' => false,
                'code' => 'core-refused-discard',
                'message' => implode(' ', array_map('strval', $dataHandler->errorLog)),
            ], 400);
        }

        $survivors = $this->memberSynchronizer->findPendingVersionPairsByTable($taskUid, $workspaceUid);
        if ($survivors !== []) {
            $this->logger->warning('discard-incomplete', [
                'taskUid' => $taskUid,
                'workspaceUid' => $workspaceUid,
                'survivors' => $this->describePendingPairs($survivors),
                'beUser' => (int)($this->getBackendUser()->user['uid'] ?? 0),
            ]);
            return new JsonResponse([
                'success' => false,
                'code' => 'discard-incomplete',
                'message' => 'TYPO3 did not discard all of these changes, so the task stays open.',
                'refused' => $this->describePendingPairs($survivors),
            ], 400);
        }

        $beUserId = (int)($this->getBackendUser()->user['uid'] ?? 0);
        foreach ($describing as $record) {
            $this->activityLogger->log($taskUid, ActivityLogger::EVENT_DISCARDED, $beUserId, [
                'table' => $record['table'],
                'uid' => $record['uid'],
            ]);
        }

        return $describing;
    }

    /**
     * Name the records behind a pending-pair map, for the activity trail and for
     * the client. Titles are resolved from the LIVE record: the version may be
     * about to disappear, and "Intro text" is what the editor recognises either
     * way.
     *
     * @param array<string, list<array{live: int, version: int}>> $pending
     * @return list<array{table: string, uid: int, title: string}>
     */
    private function describePendingPairs(array $pending): array
    {
        $described = [];
        foreach ($pending as $table => $pairs) {
            foreach ($pairs as $pair) {
                $record = BackendUtility::getRecord($table, $pair['live']);
                $described[] = [
                    'table' => $table,
                    'uid' => $pair['live'],
                    'title' => $record !== null
                        ? BackendUtility::getRecordTitle($table, $record)
                        : sprintf('%s:%d', $table, $pair['live']),
                ];
            }
        }

        return $described;
    }

    /**
     * A pending page ("Neue Seite erstellen", see createPendingPageAction())
     * becomes real through TYPO3's own page wizard, which this answer asks the
     * browser to open - prefilled with the parent the ticket was planned under.
     *
     * Editorial Flow used to create the page itself here, with the ticket title
     * and nothing else. That skipped every decision core asks about: where the
     * page goes, which page type it is, and whatever that type declares
     * required. The wizard is where those belong, and it is the same dialog an
     * editor already knows from the page tree.
     *
     * The move is therefore not finished when this returns. It completes when
     * the wizard creates the page and the DataHandler hook claims it for this
     * ticket (PendingPageClaimService), which is also what lifts the ticket
     * into Editing.
     *
     * @param array<string, mixed> $task
     */
    private function requestPageWizard(array $task): ResponseInterface
    {
        $taskUid = (int)$task['uid'];
        $parentPid = (int)$task['subject_pid'];
        if ($parentPid < 1) {
            return $this->reject(
                'pending-page-no-parent',
                'This ticket has no parent page to create the new page under.',
                ['taskUid' => $taskUid],
            );
        }
        if (!$this->mayCreatePageUnder($parentPid)) {
            return $this->reject(
                'no-permission',
                'You are not allowed to create a new page here.',
                ['taskUid' => $taskUid, 'parentPid' => $parentPid],
            );
        }

        $workspaceUid = (int)$this->getBackendUser()->workspace;
        if ($workspaceUid < 1) {
            return $this->reject(
                'no-workspace-selected',
                'Switch into a workspace before starting work on this ticket.',
                ['taskUid' => $taskUid],
            );
        }

        $this->pendingPageHandoff->remember($this->getBackendUser(), $taskUid, $parentPid);

        return new JsonResponse([
            'success' => true,
            'requiresPageWizard' => true,
            'taskUid' => $taskUid,
            // The shape core's own callers pass to openPageWizardModal() - see
            // context-menu-actions.js, which opens the same dialog from the page
            // tree.
            'positionData' => [
                'pageUid' => $parentPid,
                'insertPosition' => 'inside',
            ],
            'title' => (string)$task['title'],
        ]);
    }

    /**
     * A pending record needs a real target page before FormEngine can create it.
     *
     * @param array<string, mixed> $task
     */
    private function requestRecordTarget(array $task): ResponseInterface
    {
        $table = (string)$task['subject_table'];
        if (!$this->recordCreationTargetProvider->isCreatableRecordTable($table, $this->getBackendUser())) {
            return $this->reject(
                'record-table-not-creatable',
                'You are not allowed to create this record type in the current workspace.',
                ['taskUid' => (int)$task['uid'], 'table' => $table],
            );
        }

        return new JsonResponse([
            'success' => true,
            'requiresRecordTarget' => true,
            'taskUid' => (int)$task['uid'],
            'recordTable' => $table,
            'recordTypeLabel' => $this->recordCreationTargetProvider->getRecordTypeLabel($table),
            'title' => (string)$task['title'],
        ]);
    }

    public function recordCreationTargetsAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $task = $this->findOpenTaskOrError($taskUid, 'create its record');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $table = (string)$task['subject_table'];
        if ((int)$task['subject_uid'] !== 0 || $table === 'pages') {
            return $this->reject(
                'task-not-waiting-for-record',
                'This task is not waiting for a new record.',
                ['taskUid' => $taskUid],
            );
        }

        return new JsonResponse([
            'success' => true,
            'taskUid' => $taskUid,
            'recordTable' => $table,
            'recordTypeLabel' => $this->recordCreationTargetProvider->getRecordTypeLabel($table),
            'pages' => $this->recordCreationTargetProvider->getEligiblePages($table, $this->getBackendUser()),
        ]);
    }

    /**
     * Grouped/iconed creatable record types for the "Create a new record" entry
     * point's picker (openRecordTypePicker() in create-wizard.js), which renders
     * them through TYPO3 core's own <typo3-backend-new-record-wizard> component -
     * the same one behind the page module's "+Content" wizard. An AJAX round
     * trip rather than an inline setting because the list depends on the current
     * backend user's live table/page permissions, not static configuration.
     */
    public function recordTypeCategoriesAction(): ResponseInterface
    {
        return new JsonResponse([
            'success' => true,
            'categories' => $this->recordCreationTargetProvider->getCreatableRecordTypeCategories($this->getBackendUser()),
        ]);
    }

    public function startRecordCreationAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $pageUid = (int)($body['page'] ?? 0);
        $task = $this->findOpenTaskOrError($taskUid, 'create its record');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $table = (string)$task['subject_table'];
        if ((int)$task['subject_uid'] !== 0 || $table === 'pages') {
            return $this->reject(
                'task-not-waiting-for-record',
                'This task is not waiting for a new record.',
                ['taskUid' => $taskUid],
            );
        }
        if ((int)$this->getBackendUser()->workspace < 1) {
            return $this->reject(
                'no-workspace-selected',
                'Switch into a workspace before starting work on this ticket.',
                ['taskUid' => $taskUid],
            );
        }
        if (!$this->recordCreationTargetProvider->isPageEligible($table, $pageUid, $this->getBackendUser())) {
            return $this->reject(
                'record-target-not-allowed',
                'You are not allowed to create this record type on that page.',
                ['taskUid' => $taskUid, 'table' => $table, 'pageUid' => $pageUid],
            );
        }

        $this->pendingSubjectHandoff->remember($this->getBackendUser(), $taskUid, $table, $pageUid);
        $returnUrl = (string)$this->uriBuilder->buildUriFromRoute('editorialflow_record_creation_return', [
            'task' => $taskUid,
            'id' => $pageUid,
        ]);
        $redirectUrl = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$table => [$pageUid => 'new']],
            'returnUrl' => $returnUrl,
        ]);

        return new JsonResponse([
            'success' => true,
            'taskUid' => $taskUid,
            'redirectUrl' => $redirectUrl,
        ]);
    }

    public function recordCreationReturnAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $taskUid = (int)($query['task'] ?? 0);
        $pageUid = (int)($query['id'] ?? 0);
        $this->pendingSubjectHandoff->forget($this->getBackendUser(), $taskUid > 0 ? $taskUid : null);

        return new RedirectResponse(
            $this->uriBuilder->buildUriFromRoute('web_editorialflow', ['id' => $pageUid]),
            303,
        );
    }

    /**
     * The editor closed the page wizard without creating anything. Drops the
     * claim, so the next page they create anywhere is not adopted by this
     * ticket - PendingPageHandoff's own expiry is the backstop for a browser
     * that never says so.
     */
    public function cancelPageWizardAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->pendingPageHandoff->forget($this->getBackendUser());

        return new JsonResponse(['success' => true]);
    }

    private function mayCreatePageUnder(int $parentPid): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser->isAdmin()) {
            return true;
        }
        $parentPage = BackendUtility::getRecord('pages', $parentPid);
        if ($parentPage === null) {
            return false;
        }
        return $backendUser->doesUserHaveAccess($parentPage, Permission::PAGE_NEW);
    }

    /**
     * The read counterpart to assertMayEdit(): may this user be told what is
     * going on for a page at all.
     *
     * Deliberately weaker than assertMayEdit(), which is the write gate. These
     * are listing endpoints feeding the Visual Editor's task select and its
     * markers, and assertMayEdit() additionally refuses the Live workspace -
     * applied here it would leave an editor who opened the Visual Editor
     * outside a workspace staring at a silently empty select rather than an
     * honest "no tasks on this page". Read access to the page is the right bar
     * for "which tasks touch this page".
     *
     * @return TaskActionError|null the failure, or null when allowed
     */
    private function assertMayReadPage(int $pageUid): ?TaskActionError
    {
        if ($pageUid < 1) {
            return new TaskActionError('missing-page-uid', $this->veLabel('ve.error.noPageGiven'), []);
        }

        $page = BackendUtility::getRecord('pages', $pageUid);
        if ($page === null) {
            return new TaskActionError(
                'page-not-found',
                $this->veLabel('ve.error.pageNotFound'),
                ['pageUid' => $pageUid],
            );
        }
        if (!$this->getBackendUser()->doesUserHaveAccess($page, Permission::PAGE_SHOW)) {
            return new TaskActionError(
                'no-page-show-permission',
                $this->veLabel('ve.error.noPageAccess'),
                ['pageUid' => $pageUid],
            );
        }

        return null;
    }

    /**
     * May the current user edit this record at all?
     *
     * Every branch names *which* check failed, not just that one did - "no
     * permission" alone is not actionable for an editor filing a bug report, and
     * "edit-not-allowed" covers several genuinely different TYPO3 conditions
     * (deleted parent, disabled record, language mismatch...) that a developer
     * reading the log needs told apart from a plain permission gap.
     *
     * @return TaskActionError|null the failure, or null when allowed
     */
    private function assertMayEdit(string $table, int $uid): ?TaskActionError
    {
        if ($uid < 1) {
            return new TaskActionError('missing-record-uid', 'No record was specified.', ['table' => $table]);
        }
        if (!$this->subjectRegistry->isTrackable($table)) {
            return new TaskActionError(
                'table-not-trackable',
                sprintf('"%s" records cannot be tracked here - they support no workspace versioning.', $table),
                ['table' => $table],
            );
        }

        $record = BackendUtility::getRecord($table, $uid);
        if ($record === null) {
            return new TaskActionError(
                'record-not-found',
                sprintf('%s:%d no longer exists.', $table, $uid),
                ['table' => $table, 'uid' => $uid],
            );
        }

        $backendUser = $this->getBackendUser();
        if ($table === 'pages') {
            if (!$backendUser->doesUserHaveAccess($record, Permission::PAGE_EDIT)) {
                return new TaskActionError(
                    'no-page-edit-permission',
                    'You do not have edit permission on this page.',
                    ['table' => $table, 'uid' => $uid],
                );
            }
        } else {
            $page = BackendUtility::getRecord('pages', (int)($record['pid'] ?? 0));
            if ($page === null || !$backendUser->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
                return new TaskActionError(
                    'no-content-edit-permission',
                    'You do not have edit permission on the page this record is on.',
                    ['table' => $table, 'uid' => $uid, 'pagePid' => $record['pid'] ?? null],
                );
            }
        }
        // checkRecordEditAccess() over the older recordEditAccessInternals(): the
        // latter is deprecated since v14 (removed in v15) and trips
        // failOnDeprecation in the test suite on every call. Both are marked
        // @internal ("should only be used from within TYPO3 Core") - there is
        // currently no public, non-deprecated API for this specific check. Given
        // this extension targets v14.3.x only (composer.json: ^14.3), the
        // non-deprecated internal method is the more honest trade: it will not
        // spam deprecation logs in production, and it is what core's own
        // controllers use for the same check today.
        $accessResult = $backendUser->checkRecordEditAccess($table, $record);
        if (!$accessResult->isAllowed) {
            return new TaskActionError(
                'record-edit-not-allowed',
                $accessResult->errorMessage !== ''
                    ? $accessResult->errorMessage
                    : sprintf('%s:%d cannot be edited right now.', $table, $uid),
                ['table' => $table, 'uid' => $uid],
            );
        }
        // The workspace is always the user's own - never taken from the request.
        if (!$backendUser->workspaceAllowsLiveEditingInTable($table) && $backendUser->workspace === 0) {
            return new TaskActionError(
                'live-editing-not-allowed',
                sprintf('"%s" records cannot be edited directly on the Live workspace.', $table),
                ['table' => $table, 'workspace' => $backendUser->workspace],
            );
        }

        return null;
    }

    /**
     * Parses an HTML `<input type="date">` value ("YYYY-MM-DD") into a unix
     * timestamp. 0 means "not set" - the wizard's fields are optional, and
     * `start_date`/`due_date` both default to 0 in ext_tables.sql for exactly
     * that reason, not to a sentinel that could collide with a real date.
     */
    private function parseDate(mixed $rawDate): int
    {
        $value = trim((string)($rawDate ?? ''));
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value . ' 00:00:00');

        return $timestamp !== false ? $timestamp : 0;
    }

    private function deriveTitle(string $table, int $uid): string
    {
        $record = BackendUtility::getRecord($table, $uid);
        if ($record === null) {
            return sprintf('%s:%d', $table, $uid);
        }
        $title = BackendUtility::getRecordTitle($table, $record);

        return $title !== '' ? $title : sprintf('%s:%d', $table, $uid);
    }

    private function derivePid(string $table, int $uid): int
    {
        if ($table === 'pages') {
            return $uid;
        }

        return (int)(BackendUtility::getRecord($table, $uid, 'pid')['pid'] ?? 0);
    }

    /**
     * 'open' means deliberately unassigned so someone can take the task later.
     * 'me' and anything else that is not a valid be_user uid collapse to the
     * current editor. A specific uid - offered by the assignee picker in
     * wizard/steps/task-details-step.js, backed by the same be_users list
     * LoadWizardModuleEventListener exposes - is honoured once verified to be
     * a real, non-deleted user: never trust a client-supplied uid without
     * looking it up.
     */
    private function resolveRequestedAssignee(mixed $rawAssignee): int
    {
        if ((string)$rawAssignee === 'open') {
            return 0;
        }
        $requestedUid = (int)$rawAssignee;
        if ($requestedUid > 0 && BackendUtility::getRecord('be_users', $requestedUid, 'uid') !== null) {
            return $requestedUid;
        }
        return (int)($this->getBackendUser()->user['uid'] ?? 0);
    }

    /**
     * Tell an assignee they were handed a task.
     *
     * Deliberately does not decide *whether* this is a real, new assignment -
     * that judgment differs by caller (a fresh task's assignee is always "new";
     * an existing task's is only new if it actually changed from before) and
     * belongs where that context already lives. AssignmentNotificationService
     * itself still skips silently when $assigneeBeUserId is the acting editor.
     */
    private function notifyAssignment(int $taskUid, string $taskTitle, string $subjectTable, int $subjectUid, int $assigneeBeUserId): void
    {
        if ($assigneeBeUserId < 1) {
            return;
        }

        $subjectRecord = BackendUtility::getRecord($subjectTable, $subjectUid);
        $subjectTitle = $subjectRecord !== null
            ? BackendUtility::getRecordTitle($subjectTable, $subjectRecord)
            : sprintf('%s:%d', $subjectTable, $subjectUid);
        $pageUid = $subjectTable === 'pages' ? $subjectUid : (int)($subjectRecord['pid'] ?? 0);

        $this->assignmentNotificationService->notifyAssignee(
            $assigneeBeUserId,
            (int)($this->getBackendUser()->user['uid'] ?? 0),
            $taskUid,
            $taskTitle,
            $subjectTitle,
            (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [$subjectTable => [$subjectUid => 'edit']],
                'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_editorialflow', ['id' => $pageUid]),
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        $raw = (string)$request->getBody();
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\Throwable) {
            }
        }

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Reject the request. Every rejection is logged server-side with its code and
     * context before the (deliberately terser) client response goes out - "for
     * developer logs" means the browser sees a clean message while `var/log`
     * keeps table/uid/be_user detail nobody wants surfaced to the editor.
     */
    private function error(TaskActionError $error): ResponseInterface
    {
        return new JsonResponse(['success' => false] + $this->logAndExposeError($error), 400);
    }

    /**
     * Log the full context for a developer, return only the safe subset (code +
     * message) for the client. Shared between the hard-rejection path (error())
     * and attachAction()'s per-record loop, where a failure does not abort the
     * whole request but must still be both logged and reported per record.
     *
     * A resolution, where one was offered, is added to both halves. The client
     * needs it to render the way out; the log needs it so that "the editor was
     * stuck here" and "the editor was shown a way out" are told apart when
     * someone reads back why a task sat untouched for a week.
     *
     * @return array{code: string, message: string, resolution?: array<string, mixed>}
     */
    private function logAndExposeError(TaskActionError $error): array
    {
        $resolution = $error->resolution?->toArray();

        $this->logger->notice($error->code, $error->context + [
            'message' => $error->message,
            'resolution' => $resolution === null ? null : $resolution['action'],
            'beUser' => (int)($this->getBackendUser()->user['uid'] ?? 0),
        ]);

        $exposed = ['code' => $error->code, 'message' => $error->message];

        // Absent rather than null when there is no offer: TaskAjaxControllerErrorsTest
        // pins the {success, code, message} shape, and a key that is always
        // present would make "has an offer" a value check instead of a key check
        // for every client.
        return $resolution === null ? $exposed : $exposed + ['resolution' => $resolution];
    }

    /**
     * Shorthand for the errors this controller raises itself, rather than ones
     * assertMayEdit() already built - same logging, same response shape, so
     * every failure path looks identical from the client's point of view.
     *
     * @param array<string, mixed> $context
     */
    private function reject(
        string $code,
        string $message,
        array $context = [],
        ?TaskActionResolution $resolution = null,
    ): ResponseInterface {
        return $this->error(new TaskActionError($code, $message, $context, $resolution));
    }

    /**
     * "Close this task instead" - the offer behind every refusal that would
     * otherwise be a dead end.
     *
     * A task with nothing pending is refused by the stage gate, by the planning
     * columns and by publish, all correctly. Closing is the one thing that does
     * work, and before it existed there was nothing to point at.
     */
    private function offerToClose(int $taskUid): TaskActionResolution
    {
        return new TaskActionResolution(
            'close-task',
            'Close this task instead',
            ['taskUid' => $taskUid],
        );
    }

    /**
     * Look up an open (not closed) task, or produce the rejection response.
     *
     * Splits what used to be a single "not found or closed" check into two
     * distinctly-coded, distinctly-worded failures: a missing task usually means
     * a stale link or typo'd id; a closed task exists but is an archive record
     * an editor is trying to act on. A developer reading the log - or an editor
     * reading the message - needs those told apart, not folded into one
     * "not found or closed" sentence that hides which one actually happened.
     *
     * @param string $action named in the closed-task message, e.g. "change its stage"
     * @return array<string, mixed>|ResponseInterface the task row, or the rejection
     */
    private function findOpenTaskOrError(int $taskUid, string $action = 'change it'): array|ResponseInterface
    {
        $task = $this->taskRepository->findByUid($taskUid);
        if ($task === null) {
            return $this->reject('task-not-found', 'This task no longer exists.', ['taskUid' => $taskUid]);
        }
        if ((int)$task['closed'] === 1) {
            return $this->reject(
                'task-closed',
                sprintf('This task is closed - you cannot %s.', $action),
                ['taskUid' => $taskUid],
            );
        }

        return $task;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * An editor-facing text, through the same `editorial_flow.messages` domain the
     * wizard uses (TaskWizardProvider::translate()). Only some actions are
     * covered so far - the rest of this controller still answers in English
     * literals, which is a separate job.
     */
    private function label(string $key): string
    {
        return $this->getLanguageService()->sL('editorial_flow.messages:' . $key);
    }

    /** The Visual Editor actions' own texts, from that same domain. */
    private function veLabel(string $key): string
    {
        return $this->label($key);
    }

    /**
     * The ticket view: everything about one task in one place.
     *
     * Rendered server-side as HTML rather than assembled in JavaScript from the
     * JSON endpoint. Diffs arrive as already-rendered markup from core's
     * DiffUtility, and escaping decisions belong in Fluid, not in string
     * concatenation in the browser.
     */
    public function ticketAction(ServerRequestInterface $request): ResponseInterface
    {
        $taskUid = (int)($request->getQueryParams()['task'] ?? 0);
        $details = $this->workspaceService->getTaskDetails($taskUid);
        if ($details === null) {
            return new HtmlResponse('<div class="callout callout-danger"><div class="callout-body">Task not found.</div></div>', 404);
        }

        $subjectTable = (string)$details['subject']['table'];
        $subjectUid = (int)$details['subject']['uid'];

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:editorial_flow/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:editorial_flow/Resources/Private/Partials/'],
            layoutRootPaths: ['EXT:editorial_flow/Resources/Private/Layouts/'],
            request: $request,
        ));
        $view->assignMultiple($details + [
            'editUrl' => (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [$subjectTable => [$subjectUid => 'edit']],
                'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_editorialflow', ['id' => (int)$details['task']['subject_pid']]),
            ]),
            // Lets Ticket.html tell "this task belongs to another workspace than
            // the one I'm currently in" apart from "not versioned yet" - Preview/
            // Discard/Comment only make sense once those two match.
            'activeWorkspaceUid' => (int)$this->getBackendUser()->workspace,
        ]);

        return new HtmlResponse($view->render('EditorialFlow/Ticket'));
    }

    /**
     * "Compare versions": the workspace-vs-workspace diff behind every
     * conflict badge (Page module banner, content-element badge, board card,
     * ticket). Keyed by the live record's table+uid, not by task - a
     * conflicted record may have no task of its own at all on the side that
     * hasn't been claimed (see WorkspaceConflictDetector's docblock).
     *
     * The workspace list is re-derived here, never trusted from the client -
     * same rule as every other endpoint in this controller.
     */
    public function conflictDiffAction(ServerRequestInterface $request): ResponseInterface
    {
        $table = (string)($request->getQueryParams()['table'] ?? '');
        $liveUid = (int)($request->getQueryParams()['uid'] ?? 0);

        if (!$this->subjectRegistry->isTrackable($table) || $liveUid < 1) {
            return new HtmlResponse(
                '<div class="callout callout-danger"><div class="callout-body">'
                    . htmlspecialchars($this->label('conflict.diff.invalid') ?: 'Invalid record.', ENT_QUOTES | ENT_HTML5)
                    . '</div></div>',
                400,
            );
        }

        $workspaceUids = $this->conflictDetector->findPendingWorkspaces($table, $liveUid);
        if (count($workspaceUids) < 2) {
            return new HtmlResponse(
                '<div class="callout callout-info"><div class="callout-body">'
                    . htmlspecialchars($this->label('conflict.diff.empty') ?: 'No conflict (any more) for this record.', ENT_QUOTES | ENT_HTML5)
                    . '</div></div>',
            );
        }

        // buildConflictDiff() iterates $workspaceUids in this exact order to
        // build each row's `cells` list - workspaceColumns is built from the
        // same array in the same order so the template can zip header and
        // body via two independent f:for loops, with no dynamic array-key
        // lookups in Fluid.
        $rows = $this->workspaceService->buildConflictDiff($table, $liveUid, $workspaceUids);
        $workspaceTitles = $this->conflictDetector->resolveWorkspaceTitles($workspaceUids);
        $workspaceColumns = array_map(
            static fn (int $workspaceUid): array => ['uid' => $workspaceUid, 'title' => $workspaceTitles[$workspaceUid] ?? ('#' . $workspaceUid)],
            $workspaceUids,
        );
        $recordTitle = $this->recordTitleFor($table, $liveUid);

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:editorial_flow/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:editorial_flow/Resources/Private/Partials/'],
            layoutRootPaths: ['EXT:editorial_flow/Resources/Private/Layouts/'],
            request: $request,
        ));
        $view->assignMultiple([
            'table' => $table,
            'uid' => $liveUid,
            'recordTitle' => $recordTitle,
            'workspaceColumns' => $workspaceColumns,
            'rows' => $rows,
        ]);

        return new HtmlResponse($view->render('EditorialFlow/ConflictDiff'));
    }

    private function recordTitleFor(string $table, int $uid): string
    {
        $record = BackendUtility::getRecord($table, $uid);
        if ($record === null) {
            return sprintf('%s:%d', $table, $uid);
        }
        $title = BackendUtility::getRecordTitle($table, $record);

        return $title !== '' ? $title : sprintf('%s:%d', $table, $uid);
    }

    /**
     * Check if there is a pending post-save wizard payload stored in user session.
     */
    public function getPendingWizardAction(): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        $pending = $backendUser->getSessionData('editorial_flow_pending_wizard');
        if (is_array($pending)) {
            $backendUser->setAndSaveSessionData('editorial_flow_pending_wizard', null);
            return new JsonResponse(['success' => true, 'pending' => $pending]);
        }
        return new JsonResponse(['success' => true, 'pending' => null]);
    }

    /**
     * Post a comment on a task.
     *
     * Standalone remarks only - a comment explaining a stage move is written by
     * executeStageAction(), anchored to that transition. Here `activity` stays 0,
     * and the ticket timeline renders it as its own entry.
     */
    public function commentAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $content = trim((string)($body['content'] ?? ''));

        if ($content === '') {
            return $this->reject('comment-empty', 'A comment cannot be empty.', ['taskUid' => $taskUid]);
        }

        $task = $this->findOpenTaskOrError($taskUid, 'comment on it');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        // Commenting is a write on the subject, so it needs the same permission
        // as any other change to it - never merely "is logged in".
        $error = $this->assertMayEdit((string)$task['subject_table'], (int)$task['subject_uid']);
        if ($error !== null) {
            return $this->error($error);
        }

        $this->commentRepository->add(
            $taskUid,
            $content,
            (int)($this->getBackendUser()->user['uid'] ?? 0),
        );

        return new JsonResponse(['success' => true]);
    }

    /**
     * Confirm or withdraw one of the stage's acceptance criteria for one task.
     *
     * Open to anyone who can act on the task, unlike add/remove: confirming a
     * criterion is editorial work done while passing through the stage, not
     * workspace policy - that distinction is what canManageChecklist() gates
     * instead.
     *
     * Every tick is written to the activity log as it happens. That is the
     * durable half of "each check is recorded": sys_history knows nothing about
     * these, and a task archived today would otherwise have no answer to "who
     * said the links were checked" at all.
     */
    public function checklistToggleAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $itemUid = (int)($body['itemUid'] ?? 0);
        // FILTER_VALIDATE_BOOL, not a cast. A form-encoded request delivers this
        // as the STRING "false", and (bool)"false" is true - so withdrawing a
        // confirmation was recorded as giving one, and the box came back ticked
        // the next time the ticket was opened. Found by the browser test; every
        // other boolean this controller reads already goes through the filter.
        $completed = filter_var($body['completed'] ?? false, FILTER_VALIDATE_BOOL);

        $task = $this->findOpenTaskOrError($taskUid, 'update its checklist');
        if ($task instanceof ResponseInterface) {
            return $task;
        }
        if ($itemUid < 1) {
            return $this->reject('missing-checklist-item', 'No checklist item was specified.', ['taskUid' => $taskUid]);
        }

        // Resolved rather than trusted, for the title the activity entry needs -
        // and because a criterion the client names does not necessarily exist.
        $item = $this->checklistRepository->findItem($itemUid);
        if ($item === null) {
            return $this->reject(
                'missing-checklist-item',
                'That acceptance criterion no longer exists.',
                ['taskUid' => $taskUid, 'itemUid' => $itemUid],
            );
        }

        $beUserId = (int)($this->getBackendUser()->user['uid'] ?? 0);
        $this->checklistRepository->setCompletion($taskUid, $itemUid, $completed, $beUserId);

        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_CHECKLIST_CHECKED, $beUserId, [
            'item' => $itemUid,
            'title' => (string)$item['title'],
            'checked' => $completed,
        ]);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Add a checklist item to a stage's review policy.
     */
    public function checklistAddAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $workspaceUid = (int)($body['workspaceUid'] ?? 0);
        $stageUid = (int)($body['stageUid'] ?? 0);
        $title = trim((string)($body['title'] ?? ''));

        if (!$this->canManageChecklist($workspaceUid)) {
            return $this->reject(
                'checklist-not-permitted',
                'You are not allowed to manage this workspace\'s checklists.',
                ['workspaceUid' => $workspaceUid],
            );
        }
        if ($title === '') {
            return $this->reject(
                'checklist-title-required',
                'A title is required to add a checklist item.',
                ['workspaceUid' => $workspaceUid, 'stageUid' => $stageUid],
            );
        }

        // Appended, not reordered - an integrator who wants a different order
        // removes and re-adds; there is no drag-to-reorder in the manage modal.
        $sorting = count($this->checklistRepository->findItemsForStage($workspaceUid, $stageUid));
        $itemUid = $this->checklistRepository->addItem($workspaceUid, $stageUid, $title, $sorting);

        return new JsonResponse(['success' => true, 'item' => ['uid' => $itemUid, 'title' => $title]]);
    }

    /**
     * Remove a checklist item from a stage's review policy.
     *
     * Soft-deletes the definition only - existing tx_editorialflow_task_checklist_state
     * rows for it are left alone, and simply become unreachable through
     * findItemsForStage()'s join. A task that already checked it off keeps no
     * visible trace, which is correct: the policy no longer asks for it.
     */
    public function checklistRemoveAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $workspaceUid = (int)($body['workspaceUid'] ?? 0);
        $itemUid = (int)($body['itemUid'] ?? 0);

        if (!$this->canManageChecklist($workspaceUid)) {
            return $this->reject(
                'checklist-not-permitted',
                'You are not allowed to manage this workspace\'s checklists.',
                ['workspaceUid' => $workspaceUid],
            );
        }
        if ($itemUid < 1) {
            return $this->reject('missing-checklist-item', 'No checklist item was specified.', ['workspaceUid' => $workspaceUid]);
        }

        $this->checklistRepository->removeItem($itemUid);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Same rule as GbWeb\EditorialFlow\Service\BoardColumnRegistry::canManageChecklist()
     * - configuring a stage's checklist is workspace policy, not editorial
     * work, so it is restricted the same way publishing is: workspace owner or
     * admin. Kept as its own three lines rather than shared with that class,
     * which builds board columns and has no reason to depend on the ajax
     * controller or vice versa.
     */
    private function canManageChecklist(int $workspaceUid): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser->isAdmin()) {
            return true;
        }
        if ($workspaceUid < 1) {
            return false;
        }
        $access = $backendUser->checkWorkspace($workspaceUid);
        return is_array($access) && ($access['_ACCESS'] ?? '') === 'owner';
    }
}
