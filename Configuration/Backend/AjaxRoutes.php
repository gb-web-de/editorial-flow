<?php

declare(strict_types=1);

use GbWeb\EditorialFlow\Controller\TaskAjaxController;

/**
 * Backend ajax routes for the board's write operations.
 *
 * Registered as backend routes rather than hand-rolled endpoints so the routing
 * layer enforces the backend user session and the request token (CSRF) for us.
 */
return [
    // "+" button: create a task for a page picked in the wizard.
    'editorialflow_task_create' => [
        'path' => '/editorialflow/task/create',
        'target' => TaskAjaxController::class . '::createAction',
    ],
    // "+" button, "Neue Seite erstellen": plan a page that does not exist yet.
    'editorialflow_task_create_pending_page' => [
        'path' => '/editorialflow/task/create-pending-page',
        'target' => TaskAjaxController::class . '::createPendingPageAction',
    ],
    // Page wizard closed without creating a page: drop the ticket's claim on it.
    'editorialflow_task_cancel_page_wizard' => [
        'path' => '/editorialflow/task/cancel-page-wizard',
        'target' => TaskAjaxController::class . '::cancelPageWizardAction',
    ],
    // Pending record task: choose a valid page and open core FormEngine there.
    'editorialflow_task_record_creation_targets' => [
        'path' => '/editorialflow/task/record-creation-targets',
        'target' => TaskAjaxController::class . '::recordCreationTargetsAction',
    ],
    // "+" button, "Create a new record": grouped/iconed table choices for the picker.
    'editorialflow_task_record_type_categories' => [
        'path' => '/editorialflow/task/record-type-categories',
        'target' => TaskAjaxController::class . '::recordTypeCategoriesAction',
    ],
    'editorialflow_task_start_record_creation' => [
        'path' => '/editorialflow/task/start-record-creation',
        'target' => TaskAjaxController::class . '::startRecordCreationAction',
    ],
    // "Select to task": move selected records onto a task.
    'editorialflow_task_attach' => [
        'path' => '/editorialflow/task/attach',
        'target' => TaskAjaxController::class . '::attachAction',
    ],
    // "Split from task": pull a record into a task of its own.
    'editorialflow_task_detach' => [
        'path' => '/editorialflow/task/detach',
        'target' => TaskAjaxController::class . '::detachAction',
    ],
    // "Move to another task": the open tasks one record could be moved onto.
    'editorialflow_task_move_targets' => [
        'path' => '/editorialflow/task/move-targets',
        'target' => TaskAjaxController::class . '::moveTargetsAction',
    ],
    // Shareable preview link for one member's pending version.
    'editorialflow_task_preview_member' => [
        'path' => '/editorialflow/task/preview-member',
        'target' => TaskAjaxController::class . '::previewMemberAction',
    ],
    // Throw away one member's pending version, keeping its task membership.
    'editorialflow_task_discard_member' => [
        'path' => '/editorialflow/task/discard-member',
        'target' => TaskAjaxController::class . '::discardMemberAction',
    ],
    // Column drop / move stage: update task state or stage.
    'editorialflow_task_move_stage' => [
        'path' => '/editorialflow/task/move-stage',
        'target' => TaskAjaxController::class . '::moveStageAction',
    ],
    // Self assign: assign task to current backend user.
    'editorialflow_task_assign_me' => [
        'path' => '/editorialflow/task/assign-me',
        'target' => TaskAjaxController::class . '::assignMeAction',
    ],
    // Fetch full task inspector details (diffs, comments, activities).
    'editorialflow_task_details' => [
        'path' => '/editorialflow/task/details',
        'target' => TaskAjaxController::class . '::detailsAction',
    ],
    // Visual Editor task select: every open task touching a page.
    'editorialflow_task_list_open_for_page' => [
        'path' => '/editorialflow/task/list-open-for-page',
        'target' => TaskAjaxController::class . '::listOpenTasksForPageAction',
    ],
    // Visual Editor task select: declare the active task for a page before editing.
    'editorialflow_task_set_active_for_page' => [
        'path' => '/editorialflow/task/set-active-for-page',
        'target' => TaskAjaxController::class . '::setActiveTaskForPageAction',
    ],
    // Shared active-task control for Board, Layout and record edit forms.
    'editorialflow_task_active_context' => [
        'path' => '/editorialflow/task/active-context',
        'target' => TaskAjaxController::class . '::activeTaskContextAction',
    ],
    'editorialflow_task_set_active_context' => [
        'path' => '/editorialflow/task/set-active-context',
        'target' => TaskAjaxController::class . '::setActiveTaskForContextAction',
    ],
    // Visual Editor hover markers: which task already claims which record on a page.
    'editorialflow_task_list_member_markers' => [
        'path' => '/editorialflow/task/list-member-markers',
        'target' => TaskAjaxController::class . '::listMemberTaskMarkersForPageAction',
    ],
    // Post a standalone comment on a task.
    'editorialflow_task_comment' => [
        'path' => '/editorialflow/task/comment',
        'target' => TaskAjaxController::class . '::commentAction',
    ],
    // Ticket view: the full task rendered as HTML for a modal.
    'editorialflow_task_ticket' => [
        'path' => '/editorialflow/task/ticket',
        'target' => TaskAjaxController::class . '::ticketAction',
    ],
    // "Compare versions": workspace-vs-workspace diff for a record with a
    // pending version in more than one workspace at once, rendered as HTML
    // for a modal - see WorkspaceConflictDetector.
    'editorialflow_task_conflict_diff' => [
        'path' => '/editorialflow/task/conflict-diff',
        'target' => TaskAjaxController::class . '::conflictDiffAction',
    ],
    // Whether a task has anything pending to send to a review stage - checked
    // before the "Send to stage" dialog opens, so a task with nothing pending
    // is refused inline instead of opening a dialog that can only ever fail.
    'editorialflow_task_check_stage_transition' => [
        'path' => '/editorialflow/task/check-stage-transition',
        'target' => TaskAjaxController::class . '::checkStageTransitionEligibilityAction',
    ],
    // Workspace stage transition execution with comments and recipients.
    'editorialflow_task_execute_stage' => [
        'path' => '/editorialflow/task/execute-stage',
        'target' => TaskAjaxController::class . '::executeStageAction',
    ],
    // Publish everything a task still has pending, straight to live.
    'editorialflow_task_publish' => [
        'path' => '/editorialflow/task/publish',
        'target' => TaskAjaxController::class . '::publishTaskAction',
    ],
    // Finish a task. Separate from publishing on purpose: a task can be over
    // without anything of it going live from here - discarded, published from
    // another workspace, or simply abandoned - and until this route existed
    // those tasks could never be closed at all.
    'editorialflow_task_close' => [
        'path' => '/editorialflow/task/close',
        'target' => TaskAjaxController::class . '::closeTaskAction',
    ],
    // What the close dialog shows before an editor commits: which records still
    // have unpublished changes, which fields those are, and where they could be
    // handed to instead of being discarded.
    'editorialflow_task_close_preview' => [
        'path' => '/editorialflow/task/close-preview',
        'target' => TaskAjaxController::class . '::closePreviewAction',
    ],
    // Post-Save Task Routing Wizard session check - submission itself goes
    // through TYPO3 core's generic wizard_submit route (mode=editorialflow_task_wizard),
    // see Classes/Wizard/TaskWizardProvider.php.
    'editorialflow_task_wizard_pending' => [
        'path' => '/editorialflow/task/wizard-pending',
        'target' => TaskAjaxController::class . '::getPendingWizardAction',
    ],
    // Review checklist: check/uncheck one item for one task.
    'editorialflow_checklist_toggle' => [
        'path' => '/editorialflow/checklist/toggle',
        'target' => TaskAjaxController::class . '::checklistToggleAction',
    ],
    // Review checklist: add an item to a stage's policy (workspace owner only).
    'editorialflow_checklist_add' => [
        'path' => '/editorialflow/checklist/add',
        'target' => TaskAjaxController::class . '::checklistAddAction',
    ],
    // Review checklist: remove an item from a stage's policy (workspace owner only).
    'editorialflow_checklist_remove' => [
        'path' => '/editorialflow/checklist/remove',
        'target' => TaskAjaxController::class . '::checklistRemoveAction',
    ],
];
