<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Setup;

use GbWeb\EditorialFlow\Service\DemoEnvironmentSeeder;

/**
 * What `editorialflow:check` looks at: the handful of settings that, when
 * missing, make the board look broken without any error anywhere.
 *
 * Every one of these was a real "the board is empty" or "the card will not
 * move" during this extension's development - see WORKSPACE-STAGES.md. The
 * check is read-only; the fix methods each repair one named thing, on request.
 */
final class InstallationCheck
{
    public function __construct(
        private readonly InstallationRepository $installation,
        private readonly DemoEnvironmentSeeder $workspaces,
    ) {
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $findings = [];

        $pages = $this->installation->countPages();
        $findings[] = $pages === 0
            ? new Finding(Finding::ERROR, 'no-pages', 'There are no pages yet.', 'editorialflow:setup fresh creates a root page and a site.')
            : new Finding(Finding::OK, 'pages', sprintf('%d page(s).', $pages));

        $workspaces = $this->installation->findAllWorkspaces();
        if ($workspaces === []) {
            $findings[] = new Finding(
                Finding::ERROR,
                'no-workspace',
                'There is no workspace. Without one nobody gets a version, and no task ever opens on its own.',
                'editorialflow:setup single-group --group="Your editors" creates one.',
            );
        }
        foreach ($workspaces as $workspace) {
            $findings = [...$findings, ...$this->checkWorkspace($workspace)];
        }

        $findings = [...$findings, ...$this->checkGroups($workspaces)];

        $liveEditors = array_map(static fn (array $user): string => (string)$user['username'], $this->boardUsersWhoMayEditLive());
        if ($liveEditors !== []) {
            $findings[] = new Finding(
                Finding::WARNING,
                'users-edit-live',
                sprintf('%d editor(s) may edit Live, so what they change there never becomes a task: %s.', count($liveEditors), implode(', ', array_slice($liveEditors, 0, 10)) . (count($liveEditors) > 10 ? ', ...' : '')),
                'The backend user form ticks "Edit Live" by default. editorialflow:check --fix-live-editing switches it off for everyone who can open the board.',
            );
        }

        return $findings;
    }

    /**
     * Repairs `custom_stages`, the one setting with exactly one right value.
     *
     * @return int how many workspaces were repaired
     */
    public function fixStageCounters(): int
    {
        $repaired = 0;
        foreach ($this->installation->findAllWorkspaces() as $workspace) {
            $stageUids = array_values($this->workspaces->findStages((int)$workspace['uid']));
            if ($stageUids !== [] && (int)$workspace['custom_stages'] !== count($stageUids)) {
                $this->workspaces->syncCustomStagesCounter((int)$workspace['uid'], $stageUids);
                $repaired++;
            }
        }

        return $repaired;
    }

    /**
     * Takes "may edit Live" away from the editors who can open the board.
     *
     * Opt-in and separate from fixStageCounters(): whether an editor may edit
     * Live is an editorial decision, not a broken setting - but for a team
     * that wants every change on the board it is the one switch that makes
     * the difference, and the backend user form has it ticked by default.
     *
     * @return list<string> the usernames changed
     */
    public function disallowLiveEditingForBoardUsers(): array
    {
        $changed = [];
        foreach ($this->boardUsersWhoMayEditLive() as $user) {
            $this->installation->disallowLiveEditing((int)$user['uid'], (int)$user['workspace_perms']);
            $changed[] = (string)$user['username'];
        }

        return $changed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function boardUsersWhoMayEditLive(): array
    {
        $groups = $this->groupsByUid();

        return array_values(array_filter(
            $this->installation->findUsersWhoMayEditLive(),
            function (array $user) use ($groups): bool {
                foreach (CommaList::parse((string)$user['usergroup']) as $groupUid) {
                    if (isset($groups[(int)$groupUid])
                        && in_array(SetupPlanner::BOARD_MODULE, $this->effectiveModules($groups[(int)$groupUid], $groups), true)
                    ) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupsByUid(): array
    {
        $groups = [];
        foreach ($this->installation->findAllGroups() as $group) {
            $groups[(int)$group['uid']] = $group;
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $workspace
     * @return list<Finding>
     */
    private function checkWorkspace(array $workspace): array
    {
        $title = sprintf('Workspace "%s" (uid %d)', $workspace['title'], $workspace['uid']);
        $stages = $this->workspaces->findStages((int)$workspace['uid']);
        $findings = [];

        if ($stages !== [] && (int)$workspace['custom_stages'] !== count($stages)) {
            $findings[] = new Finding(
                Finding::ERROR,
                'stage-counter',
                sprintf('%s has %d stage(s) but its stage counter says %d, so nobody but its owners can move a card past "Editing".', $title, count($stages), $workspace['custom_stages']),
                'editorialflow:check --fix repairs this.',
            );
        }
        if (trim((string)$workspace['members']) === '' && trim((string)$workspace['adminusers']) === '') {
            $findings[] = new Finding(
                Finding::WARNING,
                'no-members',
                sprintf('%s has no members, so only administrators can work in it.', $title),
                'Add a group under Members, or run editorialflow:setup with --workspace.',
            );
        }
        if (trim((string)$workspace['adminusers']) === '') {
            $findings[] = new Finding(
                Finding::INFO,
                'no-owner',
                sprintf('%s has no owner, so only administrators can publish from it.', $title),
                'Re-run editorialflow:setup with --owner=<username> or --owner-group=<group>.',
            );
        }
        if ($findings === []) {
            $findings[] = new Finding(Finding::OK, 'workspace', sprintf(
                '%s: %s.',
                $title,
                $stages === [] ? 'no review stages' : 'stages ' . implode(' > ', array_keys($stages)),
            ));
        }

        return $findings;
    }

    /**
     * @param list<array<string, mixed>> $workspaces
     * @return list<Finding>
     */
    private function checkGroups(array $workspaces): array
    {
        $groups = $this->groupsByUid();

        $withBoard = array_filter(
            $groups,
            fn (array $group): bool => in_array(SetupPlanner::BOARD_MODULE, $this->effectiveModules($group, $groups), true),
        );
        if ($withBoard === []) {
            return [new Finding(
                Finding::WARNING,
                'no-board-group',
                'No backend group has the board module, so only administrators can open it.',
                'editorialflow:setup single-group or existing-groups grants it.',
            )];
        }

        $workspaceMembers = '';
        foreach ($workspaces as $workspace) {
            $workspaceMembers .= ',' . $workspace['members'] . ',' . $workspace['adminusers'];
        }

        $findings = [new Finding(Finding::OK, 'board-groups', sprintf(
            '%d group(s) can open the board: %s.',
            count($withBoard),
            implode(', ', array_map(static fn (array $group): string => (string)$group['title'], $withBoard)),
        ))];
        foreach ($withBoard as $uid => $group) {
            if (((int)$group['workspace_perms'] & 1) === 1 && $this->isEditorGroup($group)) {
                $findings[] = new Finding(
                    Finding::INFO,
                    'edits-live',
                    sprintf('Group "%s" may edit Live. Changes its editors make in Live never become tasks.', $group['title']),
                    'Untick "Edit Live" under Access Rights > Workspace permissions if all editing should go through the board.',
                );
            }
            if ($this->isEditorGroup($group) && !$this->isInAnyWorkspace($uid, $groups, $workspaceMembers)) {
                $findings[] = new Finding(
                    Finding::WARNING,
                    'group-without-workspace',
                    sprintf('Group "%s" can open the board but is not a member of any workspace.', $group['title']),
                    sprintf('editorialflow:setup single-group --group="%s" --workspace=<name> adds it.', $group['title']),
                );
            }
        }

        return $findings;
    }

    /**
     * A group that edits pages itself, as opposed to an access-only group like
     * the one existing-groups creates - whose members come from its parents.
     *
     * @param array<string, mixed> $group
     */
    private function isEditorGroup(array $group): bool
    {
        return trim((string)$group['tables_modify']) !== '' || trim((string)$group['db_mountpoints']) !== '';
    }

    /**
     * Membership counts through the group itself or any group it inherits
     * from, mirroring how core resolves a user's groups.
     *
     * @param array<int, array<string, mixed>> $groups
     */
    private function isInAnyWorkspace(int $uid, array $groups, string $workspaceMembers): bool
    {
        $references = CommaList::parse($workspaceMembers);
        foreach ($this->selfAndSubgroups($uid, $groups) as $candidate) {
            if (in_array('be_groups_' . $candidate, $references, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The group's own modules and those of every subgroup it includes.
     *
     * @param array<string, mixed> $group
     * @param array<int, array<string, mixed>> $groups
     * @return list<string>
     */
    private function effectiveModules(array $group, array $groups, int $depth = 0): array
    {
        $modules = CommaList::parse((string)$group['groupMods']);
        if ($depth > 20) {
            return $modules;
        }
        foreach (CommaList::parse((string)$group['subgroup']) as $subgroupUid) {
            if (isset($groups[(int)$subgroupUid])) {
                $modules = [...$modules, ...$this->effectiveModules($groups[(int)$subgroupUid], $groups, $depth + 1)];
            }
        }

        return array_values(array_unique($modules));
    }

    /**
     * The group and every subgroup it includes, transitively. Core resolves a
     * user's groups the same way, so a workspace that lists a subgroup as
     * member admits everyone in a group that includes it.
     *
     * @param array<int, array<string, mixed>> $groups
     * @return list<int>
     */
    private function selfAndSubgroups(int $uid, array $groups): array
    {
        $seen = [];
        $queue = [$uid];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current]) || !isset($groups[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach (CommaList::parse((string)$groups[$current]['subgroup']) as $subgroup) {
                $queue[] = (int)$subgroup;
            }
        }

        return array_keys($seen);
    }
}
