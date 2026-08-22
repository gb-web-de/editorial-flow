<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\EventListener;

use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Service\ActiveTaskSession;
use GbWeb\EditorialFlow\Service\TaskColor;
use GbWeb\EditorialFlow\Service\WorkspaceConflictDetector;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\Event\AfterPageContentPreviewRenderedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Marks a content element in the Page module when a task already claims it.
 *
 * The Visual Editor tells an editor this with a coloured bubble; the Page
 * module said nothing at all, so the same element looked free there. Same
 * question, same answer, same colour (TaskColor::hueFor()) - a task is "the
 * green one" on both surfaces.
 *
 * AfterPageContentPreviewRenderedEvent rather than a custom preview renderer:
 * this has no opinion about how a content element previews itself, it only adds
 * a line to whatever the element (or another extension) already rendered. A
 * preview renderer would have to reimplement every element type to say one
 * thing about it.
 */
final class ContentElementTaskBadgeListener
{
    /**
     * Claims per page, built once per request rather than per element: a page
     * module render walks every element on the page, and asking the database
     * for each one would turn one query into dozens.
     *
     * @var array<int, array<string, array{title: string, hue: float, isActive: bool, isSubject: bool, hasConflict: bool, conflictLabel: string, isEdited: bool}>>
     */
    private array $claimsByPage = [];

    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly ActiveTaskSession $activeTaskSession,
        private readonly WorkspaceConflictDetector $conflictDetector,
        private readonly IconFactory $iconFactory,
    ) {
    }

    #[AsEventListener(identifier: 'editorial-flow/content-element-task-badge')]
    public function __invoke(AfterPageContentPreviewRenderedEvent $event): void
    {
        $record = $event->getRecord();
        $pageUid = $record->getPid();
        if ($pageUid < 1) {
            return;
        }

        $claims = $this->claimsFor($pageUid);
        // The membership row holds the live uid, and so does a record read for
        // the page module - unlike the frontend, which the Visual Editor renders
        // workspace-overlaid (see TaskAjaxController::memberIdentifiers()).
        $table = $event->getTable();
        $recordUid = $record->getUid();
        $claim = $claims[$table . ':' . $recordUid] ?? null;
        if ($claim === null) {
            return;
        }

        $event->setPreviewContent(
            $this->renderBadge($claim, $table, $recordUid, $this->titleOf($table, $record))
                . $event->getPreviewContent(),
        );
    }

    /**
     * @param array{title: string, hue: float, isActive: bool, isSubject: bool, hasConflict: bool, conflictLabel: string, isEdited: bool} $claim
     */
    private function renderBadge(array $claim, string $table, int $recordUid, string $recordTitle): string
    {
        // Never colour alone: the badge always carries the task's name, and the
        // active one says so in words rather than only through its ring.
        $label = htmlspecialchars($claim['title'], ENT_QUOTES | ENT_HTML5);
        // An untouched element says so in words too, not just by being paler:
        // nine identical pills on a page where two elements were actually
        // edited is what made the badge misleading in the first place.
        if (!$claim['isEdited']) {
            $label .= ' <span class="editorialflow-element-badge-note">'
                . htmlspecialchars($this->getLanguageService()->sL(
                    'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:badge.onPageOnly',
                ) ?: 'on this page, not edited yet', ENT_QUOTES | ENT_HTML5)
                . '</span>';
        }
        $title = match (true) {
            $claim['isActive'] => 'You picked this task in the Visual Editor - edits here go to it',
            $claim['isEdited'] => 'This element has unpublished changes in this task',
            default => 'This element belongs to this task because it sits on the page the task covers - nobody has edited it here yet',
        };

        return sprintf(
            '<div class="editorialflow-element-badge%s%s" style="--editorialflow-task-hue: %s" title="%s">'
                . '<span class="editorialflow-task-dot"></span>%s%s</div>%s',
            $claim['isActive'] ? ' editorialflow-element-badge--active' : '',
            $claim['isEdited'] ? '' : ' editorialflow-element-badge--untouched',
            (string)$claim['hue'],
            htmlspecialchars($title, ENT_QUOTES | ENT_HTML5),
            $label,
            $this->renderActions($claim, $table, $recordUid, $recordTitle),
            $claim['hasConflict'] ? $this->renderConflictBadge($claim, $table, $recordUid) : '',
        );
    }

    /**
     * A second, independent badge - not folded into the task-colour dot -
     * because the record whose live uid is versioned in a second workspace
     * may belong to a task that knows nothing about that second workspace at
     * all (see WorkspaceConflictDetector's docblock). It must survive
     * regardless of which task, if any, the element above claims it for.
     *
     * @param array{conflictLabel: string} $claim
     */
    private function renderConflictBadge(array $claim, string $table, int $recordUid): string
    {
        return sprintf(
            '<span class="editorialflow-element-conflict" title="%s">%s %s</span>'
                . '<button type="button" class="editorialflow-element-action" data-editorialflow-open-conflict-diff="%s:%d">%s</button>',
            htmlspecialchars(
                sprintf('Also edited in %s - compare before publishing either side.', $claim['conflictLabel']),
                ENT_QUOTES | ENT_HTML5,
            ),
            $this->iconFactory->getIcon('actions-exclamation-triangle', IconSize::SMALL)->render(),
            htmlspecialchars($this->label('conflict.badge', 'conflict'), ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($table, ENT_QUOTES | ENT_HTML5),
            $recordUid,
            htmlspecialchars($this->label('conflict.view', 'Compare versions'), ENT_QUOTES | ENT_HTML5),
        );
    }

    /**
     * Split and move, right where the editor already sees which task owns this
     * element. The buttons carry nothing but data attributes: the behaviour is
     * task/membership.js', delegated from the document this markup lands in -
     * board.js is already loaded in the Page module (PageModuleEventListener),
     * so this surface needs no entry point of its own.
     *
     * The task's own subject is left out for the same reason the ticket leaves
     * it out: TaskAjaxController::detachAction() refuses to split a task from
     * itself, and offering the button anyway would only produce that error.
     *
     * @param array{title: string, hue: float, isActive: bool, isSubject: bool} $claim
     */
    private function renderActions(array $claim, string $table, int $recordUid, string $recordTitle): string
    {
        $data = sprintf(
            'data-table="%s" data-uid="%d" data-title="%s"',
            htmlspecialchars($table, ENT_QUOTES | ENT_HTML5),
            $recordUid,
            htmlspecialchars($recordTitle, ENT_QUOTES | ENT_HTML5),
        );

        $buttons = '';
        if (!$claim['isSubject']) {
            $buttons .= sprintf(
                '<button type="button" class="editorialflow-element-action" data-editorialflow-split="1" %s>%s</button>',
                $data,
                htmlspecialchars($this->label('membership.split.button', 'Split off'), ENT_QUOTES | ENT_HTML5),
            );
        }
        $buttons .= sprintf(
            '<button type="button" class="editorialflow-element-action" data-editorialflow-move="1" %s>%s</button>',
            $data,
            htmlspecialchars($this->label('membership.move.button', 'Move to task'), ENT_QUOTES | ENT_HTML5),
        );

        return '<span class="editorialflow-element-actions">' . $buttons . '</span>';
    }

    private function label(string $key, string $fallback): string
    {
        $label = $GLOBALS['LANG']?->sL(
            'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:' . $key
        ) ?? '';

        return $label !== '' ? $label : $fallback;
    }

    /**
     * The element's own name, for the dialogs' "%s gets a task of its own".
     */
    private function titleOf(string $table, RecordInterface $record): string
    {
        $title = BackendUtility::getRecordTitle($table, $record->toArray());

        return $title !== '' ? $title : sprintf('%s:%d', $table, $record->getUid());
    }

    /**
     * @return array<string, array{title: string, hue: float, isActive: bool, isSubject: bool, hasConflict: bool, conflictLabel: string, isEdited: bool}>
     */
    private function claimsFor(int $pageUid): array
    {
        if (isset($this->claimsByPage[$pageUid])) {
            return $this->claimsByPage[$pageUid];
        }

        $activeTaskUid = ($this->activeTaskSession->current($this->getBackendUser()) ?? [])['taskUid'] ?? 0;

        $tasks = $this->taskRepository->findAllOpenForPage($pageUid);
        $membersByTask = $this->taskRepository->findMembersForTasks(
            array_map(static fn (array $task): int => (int)$task['uid'], $tasks),
        );

        // One conflict check for the whole page, not one per member - the
        // same batching PageModuleEventListener::findConflictsByTask() uses.
        $liveUidsByTable = [];
        foreach ($membersByTask as $members) {
            foreach ($members as $member) {
                $liveUidsByTable[(string)$member['record_table']][] = (int)$member['record_uid'];
            }
        }
        // One pass, two answers: which records are contested, and which have a
        // pending version at all. The second is what tells an element somebody
        // actually worked on from one merely swept onto the task because it sits
        // on the page the task covers.
        $pendingWorkspaces = $this->conflictDetector->findPendingWorkspacesForRecords($liveUidsByTable);
        $conflicts = [];
        foreach ($pendingWorkspaces as $table => $byLiveUid) {
            foreach ($byLiveUid as $liveUid => $workspaceUids) {
                if (count($workspaceUids) >= 2) {
                    $conflicts[$table][$liveUid] = $workspaceUids;
                }
            }
        }

        $claims = [];
        foreach ($tasks as $task) {
            $taskUid = (int)$task['uid'];
            $taskWorkspaceUid = (int)($task['workspace_uid'] ?? 0);
            $entry = [
                'title' => (string)$task['title'],
                'hue' => TaskColor::hueFor($taskUid),
                'isActive' => $taskUid === $activeTaskUid,
                'isSubject' => false,
            ];
            foreach ($membersByTask[$taskUid] ?? [] as $member) {
                $memberTable = (string)$member['record_table'];
                $memberUid = (int)$member['record_uid'];
                $workspaceUids = $conflicts[$memberTable][$memberUid] ?? null;
                $conflictLabel = '';
                if ($workspaceUids !== null) {
                    $otherWorkspaceUids = array_values(array_diff($workspaceUids, [$taskWorkspaceUid]));
                    $titles = $this->conflictDetector->resolveWorkspaceTitles($otherWorkspaceUids ?: $workspaceUids);
                    $conflictLabel = implode(', ', $titles);
                }
                $claims[$memberTable . ':' . $memberUid] = [
                    'isSubject' => $memberTable === (string)$task['subject_table']
                        && $memberUid === (int)$task['subject_uid'],
                    'hasConflict' => $workspaceUids !== null,
                    'conflictLabel' => $conflictLabel,
                    // Membership alone says almost nothing: syncPageMembers()
                    // claims every trackable record on the covered page, so most
                    // badges on a page mean "sits here", not "was worked on". A
                    // pending version in the task's own workspace is the
                    // difference, and it is the one an editor is reading the
                    // badge for.
                    'isEdited' => $taskWorkspaceUid > 0
                        && in_array($taskWorkspaceUid, $pendingWorkspaces[$memberTable][$memberUid] ?? [], true),
                ] + $entry;
            }
        }

        return $this->claimsByPage[$pageUid] = $claims;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
