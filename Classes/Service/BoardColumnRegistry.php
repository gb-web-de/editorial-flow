<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use GbWeb\EditorialFlow\Domain\Model\TaskState;
use GbWeb\EditorialFlow\Domain\Repository\TaskChecklistRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceRepository;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceStageRepository;
use TYPO3\CMS\Workspaces\Service\StagesService;

/**
 * Builds the board's columns.
 *
 * A Editorial Flow board is:
 *
 *   [ Backlog ] [ Planned ]  |  [ In Progress ] [ ...merged stages... ] [ Ready ]  |  [ Done ]
 *   \___ Editorial Flow ____/     \___________ TYPO3 core workspace stages __________/     \_ CF _/
 *
 * The middle section is read straight from `sys_workspace_stage`, so an integrator
 * defines review steps exactly where TYPO3 already expects them (Workspace record ->
 * Stages) and Editorial Flow picks them up without its own configuration. The outer
 * columns are Editorial Flow's own states, which is what makes a backlog possible at
 * all: core has no notion of "planned but not yet touched".
 *
 * This also answers web-vision/kanban-workspaces#31 (custom stages before/after the
 * default ones) for free - "before editing" is Backlog/Planned, "after ready" is Done,
 * and everything in between is already freely definable in core.
 *
 * "Merged stages" means the middle section is never just the active workspace's own
 * stage chain: every stage the current backend user's *other* accessible workspaces
 * define is folded in too, one column per distinct stage title, so a step named
 * "Editing" in three different workspaces is one column, not three. A task from a
 * workspace other than the active one used to be exiled to a dedicated
 * "other workspaces" sentinel column (removed - see git history); now it sits in the
 * merged column its own stage belongs to, right where an editor scanning the board by
 * step would look for it. See buildMergedStageColumns() for how a column's color is
 * decided from this.
 */
final class BoardColumnRegistry
{
    public function __construct(
        private readonly WorkspaceStageRepository $workspaceStageRepository,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly StagesService $stagesService,
        private readonly TaskChecklistRepository $checklistRepository,
        private readonly WorkspaceColorResolver $workspaceColorResolver,
    ) {
    }

    /**
     * @param list<int> $otherWorkspaceUids Workspaces the current backend user may
     *        access besides $workspaceUid - see EditorialFlowController::indexAction().
     *        Their stages are merged into the board's middle section; live (0) is
     *        never among them.
     * @param list<int> $otherWorkspaceUids
     * @return list<array<string, mixed>>
     */
    public function getColumns(BackendUserAuthentication $backendUser, int $workspaceUid, array $otherWorkspaceUids = []): array
    {
        $columns = [
            $this->column('backlog', TaskState::BACKLOG, true),
            $this->column('planned', TaskState::PLANNED, true),
        ];

        $canManageChecklist = $this->canManageChecklist($backendUser, $workspaceUid);
        foreach ($this->buildMergedStageColumns($backendUser, $workspaceUid, $otherWorkspaceUids, $canManageChecklist) as $stageColumn) {
            $columns[] = $stageColumn;
        }

        // Publishing is an explicit, irreversible action - it is deliberately not a
        // drop target. Editors publish from the card, with a confirmation.
        $columns[] = $this->column('done', TaskState::DONE, false);

        return $columns;
    }

    /**
     * One merged column per distinct stage title across $workspaceUid and
     * $otherWorkspaceUids, in board order.
     *
     * Grouping is by resolved title text, not by stage uid: `sys_workspace_stage`
     * rows are per-workspace, so the same conceptual step ("Editing", a custom
     * "Legal review", ...) has a different uid in every workspace that defines it.
     * Title text is the only thing an editor actually compares across workspaces,
     * and it is what the board is asked to merge on.
     *
     * A column's color is a fact about which workspaces contribute to it, not a
     * per-card detail (belongsInColumn() below still knows which
     * task belongs where):
     *   - only $workspaceUid contributes -> uncoloured, exactly like before this
     *     merge existed at all - nothing to compare, nothing to say.
     *   - 2+ distinct workspaces contribute -> 'shared', the board's one fixed
     *     "several workspaces share this step" accent (already used for
     *     foreign-workspace cards elsewhere on the board).
     *   - exactly one workspace contributes and it is not $workspaceUid -> 'own',
     *     that single workspace's own color (WorkspaceColorResolver).
     *
     * @param list<int> $otherWorkspaceUids
     * @return list<array<string, mixed>>
     */
    private function buildMergedStageColumns(
        BackendUserAuthentication $backendUser,
        int $workspaceUid,
        array $otherWorkspaceUids,
        bool $canManageChecklist,
    ): array {
        $contributingWorkspaceUids = array_values(array_unique(array_filter(
            $workspaceUid > 0 ? array_merge([$workspaceUid], $otherWorkspaceUids) : $otherWorkspaceUids,
            static fn (int $uid): bool => $uid > 0,
        )));

        $stageUidsByWorkspace = [];
        foreach ($contributingWorkspaceUids as $contributingWorkspaceUid) {
            $stageUidsByWorkspace[$contributingWorkspaceUid] = $this->getOrderedStageUids($backendUser, $contributingWorkspaceUid);
        }

        /** @var array<string, array{label: string, position: int, workspaceUids: list<int>, stageUidByWorkspace: array<int, int>}> $groups */
        $groups = [];
        foreach ($stageUidsByWorkspace as $contributingWorkspaceUid => $stageUids) {
            foreach ($stageUids as $position => $stageUid) {
                $label = $this->stagesService->getStageTitle($stageUid);
                $groups[$label] ??= [
                    'label' => $label,
                    'position' => $position,
                    'workspaceUids' => [],
                    'stageUidByWorkspace' => [],
                ];
                $groups[$label]['position'] = min($groups[$label]['position'], $position);
                $groups[$label]['workspaceUids'][] = $contributingWorkspaceUid;
                $groups[$label]['stageUidByWorkspace'][$contributingWorkspaceUid] = $stageUid;
            }
        }

        /*
         * Four sort keys, and the first one is the one this got wrong.
         *
         * "Editing" and "Ready to publish" are core's fixed bookends, not
         * editorial steps that can move: every workspace starts at one and ends
         * at the other, whatever it defines in between. Their POSITION however
         * depends on how many custom stages the workspace has - in a workspace
         * with none, "Ready to publish" sits at position 1 - and since a merged
         * column takes the lowest position any workspace gives it, one workspace
         * without review stages was enough to pull "Ready to publish" in front
         * of every review column on the board. Ranking the bookends explicitly
         * is what makes the board independent of the shortest chain on it.
         *
         * Then position, and only then whether the step belongs to the workspace
         * the editor is actually in. Two chains of the same length put their
         * differing steps on the same position - Editorial's "Approval" and
         * Marketing's "Legal" are both the second review step - and that tie
         * used to fall to the label, so whether an editor's own next step came
         * before or after a step from a workspace they are not in was decided by
         * the letter it starts with.
         *
         * The label stays as the last tie-break so the order is stable: two
         * foreign steps on the same position have nothing else to be sorted by,
         * and an order that changes between two page loads is worse than an
         * arbitrary one.
         */
        usort(
            $groups,
            fn (array $a, array $b): int => $this->stageRank($a) <=> $this->stageRank($b)
                ?: $a['position'] <=> $b['position']
                ?: (isset($a['stageUidByWorkspace'][$workspaceUid]) ? 0 : 1)
                    <=> (isset($b['stageUidByWorkspace'][$workspaceUid]) ? 0 : 1)
                ?: strcasecmp($a['label'], $b['label']),
        );

        return array_map(
            fn (array $group): array => $this->buildStageColumn($group, $workspaceUid, $canManageChecklist),
            $groups,
        );
    }

    /**
     * Where a merged step belongs among the three bands the board has: the fixed
     * edit stage, everything a workspace defines itself, and the fixed publish
     * stage. Core emits its two in exactly those places for every workspace, and
     * nothing an integrator configures can move them.
     *
     * @param array{label: string, position: int, workspaceUids: list<int>, stageUidByWorkspace: array<int, int>} $group
     */
    private function stageRank(array $group): int
    {
        foreach ($group['stageUidByWorkspace'] as $stageUid) {
            if ($stageUid === StagesService::STAGE_EDIT_ID) {
                return 0;
            }
            if ($stageUid === StagesService::STAGE_PUBLISH_ID) {
                return 2;
            }
        }

        return 1;
    }

    /**
     * @param array{label: string, position: int, workspaceUids: list<int>, stageUidByWorkspace: array<int, int>} $group
     * @return array<string, mixed>
     */
    private function buildStageColumn(array $group, int $workspaceUid, bool $canManageChecklist): array
    {
        $distinctWorkspaceUids = array_values(array_unique($group['workspaceUids']));
        $shared = count($distinctWorkspaceUids) >= 2;
        $foreignWorkspaceUids = array_values(array_diff($distinctWorkspaceUids, [$workspaceUid]));

        $colorMode = 'none';
        $style = '';
        $contributingWorkspaceTitles = [];
        if ($shared) {
            $colorMode = 'shared';
            $contributingWorkspaceTitles = array_map(
                fn (int $uid): string => $this->resolveWorkspaceTitle($uid),
                $distinctWorkspaceUids,
            );
        } elseif ($foreignWorkspaceUids !== []) {
            // Not shared, and the sole contributor is not the active workspace:
            // this step exists only in one other workspace. `$color` is always
            // one of WorkspaceColorResolver::CORE_COLORS, never user input, so
            // it is safe to interpolate straight into a custom property name.
            $colorMode = 'own';
            $color = $this->workspaceColorResolver->resolve($foreignWorkspaceUids[0]);
            $style = sprintf(
                '--editorialflow-stage-color: var(--typo3-state-%1$s-bg); --editorialflow-stage-color-text: var(--typo3-state-%1$s-color); --editorialflow-stage-color-border: var(--typo3-state-%1$s-border-color);',
                $color,
            );
            $contributingWorkspaceTitles = [$this->resolveWorkspaceTitle($foreignWorkspaceUids[0])];
        }

        $ownStageUid = $group['stageUidByWorkspace'][$workspaceUid] ?? null;
        $checklistItems = $ownStageUid !== null
            ? array_map(
                static fn (array $item): array => ['uid' => (int)$item['uid'], 'title' => (string)$item['title']],
                $this->checklistRepository->findItemsForStage($workspaceUid, $ownStageUid),
            )
            : [];

        return [
            'key' => $ownStageUid !== null ? 'stage-' . $ownStageUid : 'stage-foreign-' . md5($group['label']),
            'label' => $group['label'],
            // Why this column is not a target, in a sentence, for the column's
            // own title attribute. A dimmed column says "not for you" and
            // nothing about why; the names underneath say which workspaces are
            // involved but not what that means for the card being dragged.
            'foreignHint' => $ownStageUid !== null ? '' : sprintf(
                $this->translate('column.foreign.hint') ?: 'This step belongs to %1$s. Switch into that workspace to move a task through it.',
                $contributingWorkspaceTitles === []
                    ? ($this->translate('column.foreign.otherWorkspace') ?: 'another workspace')
                    : implode(', ', $contributingWorkspaceTitles),
            ),
            'state' => $ownStageUid !== null ? TaskState::fromStageId($ownStageUid)->value : 'foreign_stage',
            'stageUid' => $ownStageUid,
            // Every contributing workspace's own stage uid for this merged step -
            // belongsInColumn() matches a task against its
            // own workspace's entry here, not against the scalar stageUid above
            // (which only ever names the active workspace's stage).
            'stageUidByWorkspace' => $group['stageUidByWorkspace'],
            // Dropping here triggers a core stage transition for the active
            // workspace's own tasks, never a direct write - and never at all when
            // the active workspace has no stage of its own in this merged column.
            'acceptsDrop' => $ownStageUid !== null,
            // Configuring a stage's checklist is workspace policy, not
            // editorial work - restricted to whoever owns the workspace
            // (or admin), same as publishing. Never available on a column that
            // is not the active workspace's own stage.
            'canManageChecklist' => $ownStageUid !== null && $canManageChecklist,
            // Pre-encoded: checklist.js's manage modal reads this straight
            // out of the column's dataset, and Fluid has no JSON format
            // ViewHelper in this version to do it in the template instead.
            'checklistItemsJson' => json_encode($checklistItems, JSON_THROW_ON_ERROR),
            'colorMode' => $colorMode,
            // Plain booleans, not a string compare, for the Fluid template -
            // matches every other card/column flag in this view (card.isActive,
            // card.foreignWorkspace, ...). colorMode above is the one place that
            // spells out which of the two (if either) applies, for PHP callers
            // and tests.
            'colorShared' => $colorMode === 'shared',
            'colorOwn' => $colorMode === 'own',
            'style' => $style,
            'contributingWorkspaceTitles' => implode(', ', $contributingWorkspaceTitles),
            // A board this column belongs to can merge in every accessible
            // *other* workspace's stage chain (see this class's own docblock),
            // which for an installation with many workspaces means a column
            // can list a couple dozen names. Index.html only renders them
            // inline up to a threshold and folds the rest behind a <details>
            // disclosure past it - this count is what decides which.
            'contributingWorkspaceCount' => count($contributingWorkspaceTitles),
        ];
    }

    /**
     * An editor-facing text through the same `editorial_flow.messages` domain
     * the wizard and the ajax controller use. Empty when the key is missing, so
     * every caller states its own English fallback - this text is rendered into
     * a title attribute and a blank tooltip is worse than an untranslated one.
     */
    private function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;

        return $languageService instanceof LanguageService
            ? $languageService->sL('editorial_flow.messages:' . $key)
            : '';
    }

    /**
     * Whether the current user may add or remove checklist items for stages in
     * this workspace - workspace policy, not editorial work, so restricted to
     * whoever owns the workspace (or admin), same as publishing.
     */
    private function canManageChecklist(BackendUserAuthentication $backendUser, int $workspaceUid): bool
    {
        if ($backendUser->isAdmin()) {
            return true;
        }
        if ($workspaceUid < 1) {
            return false;
        }
        $access = $backendUser->checkWorkspace($workspaceUid);
        return is_array($access) && ($access['_ACCESS'] ?? '') === 'owner';
    }

    /**
     * Stage uids for the given workspace, in board order, excluding the internal
     * "publish execute" stage (-20) which is a core implementation detail and never
     * a column an editor should see. Public: also used to fold every accessible
     * *other* workspace's own stage chain into the merged middle section - see
     * buildMergedStageColumns().
     *
     * @return list<int>
     */
    public function getOrderedStageUids(BackendUserAuthentication $backendUser, int $workspaceUid): array
    {
        if ($workspaceUid < 1) {
            return [];
        }
        try {
            // Throws (does not return null) when the workspace record is gone,
            // e.g. a workspace deleted while a be_user still had it selected.
            $workspaceRecord = $this->workspaceRepository->findByUid($workspaceUid);
        } catch (\RuntimeException) {
            return [];
        }

        $stageUids = [];
        foreach ($this->workspaceStageRepository->findAllStagesByWorkspace($backendUser, $workspaceRecord) as $stage) {
            $stageUid = (int)$stage->uid;
            if ($stageUid === StagesService::STAGE_PUBLISH_EXECUTE_ID) {
                continue;
            }
            $stageUids[] = $stageUid;
        }
        return $stageUids;
    }

    /**
     * A Editorial Flow-owned column. These never map to a core stage - that is what
     * `stageUid => null` means, and it is how the board tells the two apart.
     *
     * @return array{key: string, label: string, state: string, stageUid: null, acceptsDrop: bool}
     */
    private function column(string $key, TaskState $state, bool $acceptsDrop): array
    {
        return [
            'key' => $key,
            'label' => $this->getLanguageService()->sL(
                'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:column.' . $key
            ) ?: ucfirst($key),
            'state' => $state->value,
            'stageUid' => null,
            'stageUidByWorkspace' => null,
            'acceptsDrop' => $acceptsDrop,
            'colorMode' => 'none',
            'colorShared' => false,
            'colorOwn' => false,
            'style' => '',
            'contributingWorkspaceTitles' => '',
            'contributingWorkspaceCount' => 0,
        ];
    }

    /**
     * Which column does this task belong in?
     *
     * Lives here rather than in the controller because the answer is read
     * entirely out of the column shapes this class builds - `stageUidByWorkspace`
     * is its invention, and a rule that reads it belongs next to the code that
     * writes it. It was private in EditorialFlowController, which also made it
     * untestable: the only coverage was rendering a board with hand-built
     * columns, which never ran this at all.
     *
     * A versioned task (workspace_uid > 0, whether the active workspace or one of
     * the other ones merged into the board - see BoardColumnRegistry) belongs to
     * the merged column whose stageUidByWorkspace entry for its own workspace
     * matches its own stage_uid. An unversioned task belongs to the column of its
     * Editorial Flow state instead, and only ever to one from the active workspace
     * (or none) - a foreign workspace never owns a Backlog/Planned/Done task,
     * since those states only exist before/after a workspace version does.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $column
     */
    public function belongsInColumn(array $task, array $column): bool
    {
        // A closed task is in Done because it is closed. Checked first and on
        // its own: close() keeps the workspace the task was finished in (that
        // uid is the only key into its sys_history trail), so without this a
        // closed task with stage_uid 0 would also match the Editing stage
        // column and appear twice on the board.
        if ((int)($task['closed'] ?? 0) === 1) {
            return ($column['stageUidByWorkspace'] ?? null) === null
                && $column['state'] === TaskState::DONE->value;
        }

        $stageUidByWorkspace = $column['stageUidByWorkspace'] ?? null;
        if ($stageUidByWorkspace !== null) {
            $taskWorkspaceUid = (int)($task['workspace_uid'] ?? 0);
            if ($taskWorkspaceUid < 1) {
                return false;
            }
            return ($stageUidByWorkspace[$taskWorkspaceUid] ?? null) === (int)($task['stage_uid'] ?? 0);
        }

        return (int)($task['workspace_uid'] ?? 0) === 0
            && (string)($task['state'] ?? '') === $column['state'];
    }

    private function resolveWorkspaceTitle(int $workspaceUid): string
    {
        $record = BackendUtility::getRecord('sys_workspace', $workspaceUid, 'title');
        return ($record['title'] ?? '') !== '' ? (string)$record['title'] : ('#' . $workspaceUid);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
