<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Controller;

use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Service\ActiveTaskSession;
use GbWeb\EditorialFlow\Service\AssignableUserProvider;
use GbWeb\EditorialFlow\Service\BoardColumnRegistry;
use GbWeb\EditorialFlow\Service\BoardScopeResolver;
use GbWeb\EditorialFlow\Service\TaskPublishGate;
use GbWeb\EditorialFlow\Service\TaskSubjectRegistry;
use GbWeb\EditorialFlow\Service\WorkspaceConflictDetector;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * The Editorial Flow board.
 *
 * Renders one column set (see BoardColumnRegistry) and distributes the page's open
 * tasks into it. Cards are grouped in PHP from a single query - the number of review
 * stages an integrator configures never changes the number of queries.
 */
#[AsController]
final class EditorialFlowController extends ActionController
{
    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly PageRenderer $pageRenderer,
        protected readonly BoardColumnRegistry $boardColumnRegistry,
        protected readonly TaskRepository $taskRepository,
        protected readonly TaskSubjectRegistry $subjectRegistry,
        protected readonly UriBuilder $backendUriBuilder,
        protected readonly TaskPublishGate $taskPublishGate,
        protected readonly BoardScopeResolver $boardScopeResolver,
        protected readonly WorkspaceService $workspaceService,
        protected readonly AssignableUserProvider $assignableUserProvider,
        protected readonly ActiveTaskSession $activeTaskSession,
        protected readonly WorkspaceConflictDetector $conflictDetector,
    ) {
    }

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $backendUser = $this->getBackendUser();
        $queryParams = $this->request->getQueryParams();
        $pageUid = (int)($queryParams['id'] ?? 0);
        $workspaceUid = (int)$backendUser->workspace;
        $depth = (int)($queryParams['depth'] ?? 999);
        $fromWorkspaceRoot = (bool)($queryParams['wsroot'] ?? true);

        // Own workspaces the user has access to, live workspace excluded (it is
        // never "other" for the purposes of the cross-workspace badge/filter) and
        // the currently active one excluded too - only populated, and only then
        // rendered by the view at all, when there is actually something to filter.
        $otherWorkspaces = array_values(array_filter(
            $this->workspaceService->getAvailableWorkspaces(true),
            static fn (array $workspace): bool => (int)$workspace['uid'] > 0 && (int)$workspace['uid'] !== $workspaceUid,
        ));

        $pageRecord = $pageUid > 0 ? (BackendUtility::getRecord('pages', $pageUid) ?? []) : [];
        $pageTitle = $pageRecord !== [] ? BackendUtility::getRecordTitle('pages', $pageRecord) : '';

        $moduleTitle = $this->getLanguageService()->sL(
            'LLL:EXT:editorial_flow/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'
        );
        $moduleTemplate->setTitle($moduleTitle, $pageTitle);
        $docHeader = $moduleTemplate->getDocHeaderComponent();
        if ($pageRecord !== []) {
            $docHeader->setPageBreadcrumb($pageRecord);
        }
        $docHeader->setShortcutContext(
            'web_editorialflow',
            sprintf('%s: %s [%d]', $moduleTitle, $pageTitle !== '' ? $pageTitle : '/', $pageUid),
            ['id' => $pageUid],
        );

        $otherWorkspaceUids = array_map(static fn (array $workspace): int => (int)$workspace['uid'], $otherWorkspaces);
        $columns = $this->buildBoard($backendUser, $workspaceUid, $pageUid, $depth, $fromWorkspaceRoot, $otherWorkspaceUids);

        $moduleTemplate->assignMultiple([
            'pageUid' => $pageUid,
            'pageSelected' => $pageUid > 0,
            'workspaceUid' => $workspaceUid,
            'columns' => $columns,
            'otherWorkspaces' => $otherWorkspaces,
        ]);

        $this->pageRenderer->addCssFile('EXT:editorial_flow/Resources/Public/Css/Styles.css');
        $this->pageRenderer->addCssInlineBlock('editorial-flow-due-date-colors', $this->dueDateColorCss(), csp: true);
        $this->pageRenderer->loadJavaScriptModule('@gb-web/editorial-flow/board.js');
        // Core's element browser gives the "+" button a page tree with live search
        // and depth navigation - no bespoke picker needed.
        //
        // Plain route URL only: the parameters are appended client-side the way
        // core's FormEngine.openPopupWindow() builds them (mode/allowedTypes/
        // useEvents as query params). The old pipe-delimited `bparams` string is
        // deprecated in v14 and silently produced a browser that selected nothing.
        $this->pageRenderer->addInlineSetting(
            'EditorialFlow',
            'elementBrowserUrl',
            (string)$this->backendUriBuilder->buildUriFromRoute('wizard_element_browser'),
        );
        // "Create a new content element": core's own New Content Element wizard
        // (the one behind the page module's "+Content" button), opened as-is in
        // an ajax modal - no bespoke type picker needed here either. Base route
        // URL only, same reasoning as elementBrowserUrl above: id/uid_pid/
        // returnUrl depend on which page is selected on the board, so
        // create-wizard.js appends them client-side.
        $this->pageRenderer->addInlineSetting(
            'EditorialFlow',
            'newContentElementWizardUrl',
            (string)$this->backendUriBuilder->buildUriFromRoute('new_content_element_wizard'),
        );
        $this->pageRenderer->addInlineSetting(
            'EditorialFlow',
            'currentUserId',
            (int)($backendUser->user['uid'] ?? 0),
        );
        // filters.js needs this to always show the active workspace's own cards
        // regardless of the workspace checkbox filter - that filter only ever
        // narrows which *other* workspaces' merged-in cards are visible.
        $this->pageRenderer->addInlineSetting(
            'EditorialFlow',
            'currentWorkspaceId',
            $workspaceUid,
        );
        $this->pageRenderer->addInlineSetting(
            'EditorialFlow',
            'createTargetTables',
            $this->getCreateTargetTables(),
        );
        // Also added by LoadWizardModuleEventListener, for the outer chrome
        // document - this is a SEPARATE PageRenderer/TYPO3.settings context (the
        // board's own content iframe), which does not inherit inline settings
        // added there. Needed here for the assignee picker in the "+ New task"
        // wizard steps, which run inside this iframe, not the outer chrome.
        $this->pageRenderer->addInlineSetting(
            'EditorialFlow',
            'assignableUsers',
            $this->assignableUserProvider->getAssignableUsers(),
        );
        $this->pageRenderer->addInlineSetting(
            'EditorialFlow',
            'currentPageId',
            $pageUid,
        );
        return $moduleTemplate->renderResponse('EditorialFlow/Index');
    }

    /**
     * Records the explicit "+" flow may promote into their own task.
     *
     * Page-like tables are obvious candidates. Page-bound content and custom
     * records are included too: they auto-join their page's task by default, but
     * an editor can still choose them deliberately and give them a dedicated card.
     *
     * @return list<string>
     */
    private function getCreateTargetTables(): array
    {
        return array_values(array_unique(array_merge(
            $this->subjectRegistry->getSubjectTables(),
            $this->subjectRegistry->getAggregatableTables(),
        )));
    }

    /**
     * @param list<int> $otherWorkspaceUids
     * @return list<array<string, mixed>>
     */
    private function buildBoard(
        BackendUserAuthentication $backendUser,
        int $workspaceUid,
        int $pageUid,
        int $depth,
        bool $fromWorkspaceRoot,
        array $otherWorkspaceUids,
    ): array {
        $columns = $this->boardColumnRegistry->getColumns($backendUser, $workspaceUid, $otherWorkspaceUids);
        if ($pageUid < 1) {
            return $columns;
        }

        if ($fromWorkspaceRoot && $workspaceUid > 0) {
            $pageUids = $this->boardScopeResolver->resolveWorkspaceRootPageUids($workspaceUid, $backendUser);
            if ($pageUids === []) {
                // BoardScopeResolver already falls back from an empty db_mountpoints
                // to the installation's pid=0/is_siteroot pages, so this only fires
                // when even that yields nothing accessible - fall back to "just the
                // selected page" rather than showing nothing.
                $pageUids = $this->boardScopeResolver->resolvePageUids($pageUid, 0, $backendUser);
            }
        } else {
            $pageUids = $this->boardScopeResolver->resolvePageUids($pageUid, $depth, $backendUser);
        }

        $tasks = $this->taskRepository->findForBoard($pageUids);
        $activeTaskUid = $this->activeTaskSession->current($backendUser)['taskUid'] ?? 0;
        $conflictsByTask = $this->findConflictsByTask($tasks);
        $enrichedTasks = array_map(function (array $task) use ($backendUser, $workspaceUid, $activeTaskUid, $conflictsByTask): array {
            $table = (string)($task['subject_table'] ?? 'pages');
            $uid = (int)($task['subject_uid'] ?? 0);
            $task['iconIdentifier'] = $table === 'pages' ? 'apps-pagetree-page-default' : 'mimetypes-x-content-text';
            $task['isActive'] = (int)$task['uid'] === $activeTaskUid;
            $task += $conflictsByTask[(int)$task['uid']] ?? ['hasConflict' => false, 'conflictLabel' => '', 'conflictTable' => '', 'conflictUid' => 0];

            $assigneeUid = (int)($task['assignee'] ?? 0);
            if ($assigneeUid > 0) {
                $userRecord = BackendUtility::getRecord('be_users', $assigneeUid, 'username,realName');
                if ($userRecord) {
                    $task['assigneeName'] = !empty($userRecord['realName']) ? $userRecord['realName'] : $userRecord['username'];
                }
            }

            $task['warnedMembers'] = $this->taskRepository->findWarnedMembers((int)$task['uid'], (int)$task['subject_pid']);
            $task['warnedCount'] = count($task['warnedMembers']);

            // Which page this task is about, in words. An auto-created task
            // takes its title from the page it was born on
            // (TaskAutoCreationService::deriveTitle()), so two tasks on the
            // same page read as the same thing - and once the page is renamed,
            // the card still carries the old name with nothing to relate it to.
            // The card used to answer that with "pages:5", which is a fact
            // about the database rather than about the work.
            $task['subjectPageTitle'] = $this->resolvePageTitle(
                $table === 'pages' ? $uid : (int)($task['subject_pid'] ?? 0),
            );

            // Never color-only (ARCHITECTURE.md's "always icon and label, never
            // silence" rule, already followed everywhere else on the board) -
            // dueDateLabel carries the same information in words, dueDateUrgency
            // only picks which accent color the card gets.
            $dueDate = (int)($task['due_date'] ?? 0);
            $task['dueDateUrgency'] = $this->computeDueDateUrgency($dueDate);
            $task['dueDateLabel'] = $this->computeDueDateLabel($dueDate);
            $task['dueDateCardClass'] = match ($task['dueDateUrgency']) {
                'overdue' => 'editorialflow-card--overdue',
                'due-soon' => 'editorialflow-card--due-soon',
                default => '',
            };

            // A task whose own workspace_uid points elsewhere than the currently
            // active workspace: meaningless to act on from here (stage/publish
            // permissions below are all scoped to the active workspace), so it is
            // shown read-only in the "Other workspaces" column instead - see
            // BoardColumnRegistry::getColumns() and its belongsInColumn().
            //
            // A CLOSED task is never foreign, whatever workspace it was finished
            // in. close() keeps that uid now, so without this every archived
            // task from another workspace would turn into a read-only foreign
            // card - and the workspace filter could hide the Done column's
            // contents from the very editor who closed them.
            $taskWorkspaceUid = (int)($task['workspace_uid'] ?? 0);
            $task['foreignWorkspace'] = (int)($task['closed'] ?? 0) === 0
                && $taskWorkspaceUid > 0
                && $taskWorkspaceUid !== $workspaceUid;
            if ($task['foreignWorkspace']) {
                $foreignWorkspaceRecord = BackendUtility::getRecord('sys_workspace', $taskWorkspaceUid, 'title');
                $task['foreignWorkspaceTitle'] = $foreignWorkspaceRecord['title'] ?? ('#' . $taskWorkspaceUid);
                $task['canAct'] = false;
                $task['canPublish'] = false;
                return $task;
            }

            // Gates dragging the card at all (board.js checks the DRAGGED card's
            // own canAct, not the drop target's). Mirrors the real rule behind
            // every stage transition, BackendUserAuthentication::
            // workspaceCheckStageForCurrent(): responsibility is for what
            // currently SITS in a stage, never for what may move into one - see
            // WORKSPACE-STAGES.md. Correct unmodified for stage_uid 0 (always
            // allowed) and for tasks with no workspace version yet (also 0).
            //
            // Who counts as "responsible" is entirely core's own, existing
            // configuration - sys_workspace_stage.responsible_persons, edited on
            // the stage record in the Workspaces module like any other stage
            // setting. It already covers every case this could be asked to
            // support: `be_users_<uid>` for one person, `be_groups_<uid>` for a
            // team, several of either combined, or a group with every editor in
            // it for "anyone may act here". Editorial Flow deliberately adds no
            // parallel permission model of its own on top of it.
            $task['canAct'] = $backendUser->workspaceCheckStageForCurrent((int)($task['stage_uid'] ?? 0));

            // Per card, not per board: with the workspace's
            // PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE bit set, the answer differs
            // between two cards of the same board, because it depends on the
            // stage each of them sits in. The view renders the Publish button
            // from this flag alone, and publishTaskAction() refuses on the same
            // gate - so a button that is there is a button that works.
            $task['canPublish'] = (int)($task['closed'] ?? 0) === 0
                && $this->taskPublishGate->isGranted(
                    $backendUser,
                    $taskWorkspaceUid,
                    (int)($task['stage_uid'] ?? 0),
                );
            return $task;
        }, $tasks);

        $board = [];
        foreach ($columns as $column) {
            $column['cards'] = [];
            foreach ($enrichedTasks as $task) {
                if ($this->boardColumnRegistry->belongsInColumn($task, $column)) {
                    $column['cards'][] = $task;
                }
            }
            $board[] = $column;
        }

        return $board;
    }

    /**
     * One batch conflict check for the whole board: every card's members in
     * one query (TaskRepository::findMembersForTasks()), then one
     * WorkspaceConflictDetector query per distinct record table - never one
     * query per card, matching this controller's existing "one query per
     * board" doctrine (see the class docblock).
     *
     * @param list<array<string, mixed>> $tasks
     * @return array<int, array{hasConflict: bool, conflictLabel: string, conflictTable: string, conflictUid: int}>
     */
    private function findConflictsByTask(array $tasks): array
    {
        $taskUids = array_map(static fn (array $task): int => (int)$task['uid'], $tasks);
        $membersByTask = $this->taskRepository->findMembersForTasks($taskUids);

        $taskWorkspaceUids = [];
        foreach ($tasks as $task) {
            $taskWorkspaceUids[(int)$task['uid']] = (int)($task['workspace_uid'] ?? 0);
        }

        return $this->conflictDetector->findConflictsForTasks($membersByTask, $taskWorkspaceUids);
    }


    /**
     * The title of a page as the current workspace sees it.
     *
     * Overlaid on purpose: a task in a workspace is usually about a page whose
     * title was changed in that same workspace, and showing the live title
     * there would name something the editor is in the middle of replacing.
     */
    private function resolvePageTitle(int $pageUid): string
    {
        if ($pageUid < 1) {
            return '';
        }

        $page = BackendUtility::getRecord('pages', $pageUid, 'uid,pid,title,t3ver_oid,t3ver_wsid,t3ver_state');
        if ($page === null) {
            return '';
        }
        BackendUtility::workspaceOL('pages', $page);

        return (string)($page['title'] ?? '');
    }

    /**
     * How urgently a card's due date should be flagged, or null for "not due
     * soon" (no accent). The threshold is EXTCONF-configurable - see
     * ext_localconf.php - so an integrator can widen or narrow the warning
     * window without touching this extension's code.
     */
    private function computeDueDateUrgency(int $dueDate): ?string
    {
        if ($dueDate < 1) {
            return null;
        }

        $daysRemaining = $this->daysUntil($dueDate);
        if ($daysRemaining < 0) {
            return 'overdue';
        }

        $warningDays = (int)($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['editorial_flow']['dueDateThresholds']['warningDays'] ?? 3);

        return $daysRemaining <= $warningDays ? 'due-soon' : null;
    }

    /**
     * The words behind computeDueDateUrgency()'s color - a card must never
     * communicate its due date by color alone (ARCHITECTURE.md's "always icon
     * and label, never silence" rule, already followed by every other status
     * indicator on the board).
     */
    private function computeDueDateLabel(int $dueDate): ?string
    {
        if ($dueDate < 1) {
            return null;
        }

        $daysRemaining = $this->daysUntil($dueDate);
        if ($daysRemaining < 0) {
            $overdueBy = abs($daysRemaining);
            return sprintf('Overdue by %d day%s', $overdueBy, $overdueBy === 1 ? '' : 's');
        }
        if ($daysRemaining === 0) {
            return 'Due today';
        }

        return sprintf('Due in %d day%s', $daysRemaining, $daysRemaining === 1 ? '' : 's');
    }

    private function daysUntil(int $timestamp): int
    {
        $today = (new \DateTimeImmutable('today'))->getTimestamp();
        $dueDay = (new \DateTimeImmutable('@' . $timestamp))->setTime(0, 0)->getTimestamp();

        return (int)floor(($dueDay - $today) / 86400);
    }

    /**
     * Injects the configured warning/overdue colors as CSS custom properties
     * so Styles.css can consume them via var(--editorialflow-due-soon, ...) the
     * same way it already consumes every --typo3-* design token, with a safe
     * fallback if EXTCONF holds something that isn't a color at all.
     */
    private function dueDateColorCss(): string
    {
        $thresholds = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['editorial_flow']['dueDateThresholds'] ?? [];
        $warningColor = $this->sanitizeCssColor((string)($thresholds['warningColor'] ?? ''), '#e0a810');
        $overdueColor = $this->sanitizeCssColor((string)($thresholds['overdueColor'] ?? ''), '#d9534f');

        return sprintf(':root { --editorialflow-due-soon: %s; --editorialflow-overdue: %s; }', $warningColor, $overdueColor);
    }

    private function sanitizeCssColor(string $color, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) === 1 ? $color : $fallback;
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
