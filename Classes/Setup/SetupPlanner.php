<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Setup;

use GbWeb\EditorialFlow\Service\DemoEnvironmentSeeder;
use TYPO3\CMS\Core\Configuration\SiteWriter;

/**
 * Turns SetupOptions into the list of steps `editorialflow:setup` shows and
 * then runs.
 *
 * Everything is looked up while planning, so the plan - and `--dry-run` - says
 * "create" only for what is really missing and "keep" for what is there.
 * Running the same command twice is therefore safe: the second plan consists
 * of nothing but "keep" lines.
 */
final class SetupPlanner
{
    /** The board itself. Everything else in a group is the installation's business. */
    public const BOARD_MODULE = 'web_editorialflow';

    /**
     * The page module, which every editor group has - the marker
     * --attach-to-editors uses to find "the editors" in a tree it knows
     * nothing else about.
     */
    public const PAGE_MODULE = 'web_layout';

    /** What a group created from scratch gets: the board and what editing on it needs. */
    public const EDITOR_MODULES = [self::BOARD_MODULE, self::PAGE_MODULE, 'records', 'media_management'];

    /**
     * group-per-workspace: carries the pages and editing rights all workspace
     * groups share, see plan().
     */
    public const SHARED_GROUP = 'Editorial Flow Editors (shared)';

    public const ROOT_PAGE_TITLE = 'Home';
    public const SITE_IDENTIFIER = 'main';

    public function __construct(
        private readonly InstallationRepository $installation,
        private readonly DemoEnvironmentSeeder $workspaces,
        private readonly SiteWriter $siteWriter,
    ) {
    }

    /**
     * @return list<SetupStep>
     * @throws InvalidSetupOptions when something the options name does not exist
     */
    public function plan(SetupOptions $options): array
    {
        $owners = $this->resolveOwners($options->owners);

        if ($options->scenario === SetupScenario::ExistingGroups) {
            $attachTo = $this->resolveAttachTo($options);

            return [
                ...$this->planAccessGroup($options->groups[0], $attachTo),
                ...($options->workspace === null
                    ? [SetupStep::unchanged('No --workspace given: existing workspaces are left as they are.')]
                    : $this->planWorkspace($options->workspace, $options->stages, $this->references('be_groups', $attachTo), $owners, $options)),
            ];
        }

        $steps = $options->scenario === SetupScenario::Fresh ? $this->planPageTree($options->siteBase) : [];
        // With one workspace per group, the groups must not include one
        // another - membership travels with inclusion, and Sport would end up
        // in News's workspace. Pages, on the other hand, can belong to only
        // one group. A shared group without the board and without any
        // workspace carries the pages and editing rights; every workspace
        // group includes it and adds nothing but the board.
        $inheritFrom = $options->scenario === SetupScenario::GroupPerWorkspace ? self::SHARED_GROUP : $options->groups[0];
        if ($options->scenario === SetupScenario::GroupPerWorkspace) {
            $steps = [...$steps, ...$this->planEditorGroup(self::SHARED_GROUP, null, array_values(array_diff(self::EDITOR_MODULES, [self::BOARD_MODULE])))];
        }
        foreach ($options->groups as $group) {
            $steps = [...$steps, ...$this->planEditorGroup($group, $options->scenario === SetupScenario::GroupPerWorkspace ? self::SHARED_GROUP : null)];
        }
        foreach ($options->ownerGroups as $ownerGroup) {
            $steps = [...$steps, ...$this->planOwnerGroup($ownerGroup, $inheritFrom)];
        }
        foreach ($options->workspacesWithMemberGroups() as $workspace => $memberGroups) {
            $steps = [...$steps, ...$this->planWorkspaceForGroups($workspace, $memberGroups, $owners, $options)];
        }
        foreach ($options->editors as $username => $group) {
            $steps[] = $this->planEditor($username, $group, $options);
        }

        return $steps;
    }

    /**
     * @return list<SetupStep>
     */
    private function planPageTree(string $siteBase): array
    {
        $pageCount = $this->installation->countPages();
        if ($pageCount > 0) {
            return [SetupStep::unchanged(sprintf('Keep the page tree (%d pages).', $pageCount))];
        }

        return [new SetupStep(
            sprintf('Create root page "%s" and site "%s" with base "%s".', self::ROOT_PAGE_TITLE, self::SITE_IDENTIFIER, $siteBase),
            function (SetupContext $context) use ($siteBase): void {
                $rootUid = $this->installation->createRootPage(self::ROOT_PAGE_TITLE);
                if ($rootUid < 1) {
                    throw new \RuntimeException('Could not create the root page.', 1762272001);
                }
                $this->siteWriter->createNewBasicSite(self::SITE_IDENTIFIER, $rootUid, $siteBase);
                $context->report(sprintf('Root page uid %d, site "%s".', $rootUid, self::SITE_IDENTIFIER));
            },
        )];
    }

    /**
     * @param string|null $inheritFrom a group that carries pages and rights; the new group then has the board and nothing else
     * @param list<string> $modules
     * @return list<SetupStep>
     */
    private function planEditorGroup(string $title, ?string $inheritFrom = null, array $modules = self::EDITOR_MODULES): array
    {
        $existing = $this->installation->findGroup($title);
        if ($existing !== null) {
            return [$this->ensureModuleOnExistingGroup($existing, $modules)];
        }

        if ($inheritFrom !== null) {
            return [new SetupStep(
                sprintf('Create group "%s" with the board, which inherits pages and editing rights from "%s".', $title, $inheritFrom),
                function (SetupContext $context) use ($title, $inheritFrom): void {
                    $uid = $this->installation->createAccessGroup($title, self::BOARD_MODULE);
                    if ($uid < 1 || $context->group($inheritFrom) < 1) {
                        throw new \RuntimeException(sprintf('Could not create the group "%s".', $title), 1762272007);
                    }
                    $this->installation->addSubgroup($uid, '', $context->group($inheritFrom));
                    $context->rememberGroup($title, $uid);
                    $context->report(sprintf('Group "%s" is uid %d.', $title, $uid));
                },
            )];
        }

        $moduleLabel = in_array(self::BOARD_MODULE, $modules, true) ? 'board, Page, List and Media modules' : 'Page, List and Media modules';

        return [new SetupStep(
            sprintf('Create editor group "%s": %s, mounted on the root pages, edits only in workspaces.', $title, $moduleLabel),
            function (SetupContext $context) use ($title, $modules): void {
                $uid = $this->installation->createEditorGroup(
                    $title,
                    implode(',', $modules),
                    $this->installation->findRootPageUids(),
                );
                if ($uid < 1) {
                    throw new \RuntimeException(sprintf('Could not create the group "%s".', $title), 1762272002);
                }
                $context->rememberGroup($title, $uid);

                $granted = $this->installation->grantUnownedPages($uid);
                $ownedByOthers = $this->installation->countPagesOwnedByOtherGroups($uid);
                $context->report(sprintf('Group "%s" is uid %d and owns %d page(s).', $title, $uid, $granted));
                if ($ownedByOthers > 0) {
                    $context->report(sprintf(
                        '%d page(s) already belong to another group and were left alone. Give "%s" access to them in Access > Page permissions if its editors need it.',
                        $ownedByOthers,
                        $title,
                    ));
                }
            },
        )];
    }

    /**
     * An owner group needs everything the editors have - pages, tables,
     * mounts - plus ownership of the workspace. Inheriting the editor group as
     * a subgroup gives it the first part without a second copy of every page
     * permission: a page belongs to exactly one group, and a user's groups
     * include their subgroups.
     *
     * @return list<SetupStep>
     */
    private function planOwnerGroup(string $title, string $editorGroup): array
    {
        $existing = $this->installation->findGroup($title);
        if ($existing !== null) {
            return [SetupStep::unchanged(sprintf('Keep owner group "%s" (uid %d) as it is.', $title, (int)$existing['uid']))];
        }

        return [new SetupStep(
            sprintf('Create owner group "%s", which inherits everything from "%s" and may publish.', $title, $editorGroup),
            function (SetupContext $context) use ($title, $editorGroup): void {
                $uid = $this->installation->createAccessGroup($title, self::BOARD_MODULE);
                $editorUid = $context->group($editorGroup);
                if ($uid < 1 || $editorUid < 1) {
                    throw new \RuntimeException(sprintf('Could not create the owner group "%s".', $title), 1762272003);
                }
                $this->installation->addSubgroup($uid, '', $editorUid);
                $context->rememberGroup($title, $uid);
                $context->report(sprintf('Owner group "%s" is uid %d.', $title, $uid));
            },
        )];
    }

    /**
     * An existing group gets the board and nothing else - except the shared
     * group, which by design has no board at all and is only remembered.
     *
     * @param array<string, mixed> $group
     * @param list<string> $intendedModules what a new group of this kind would get
     */
    private function ensureModuleOnExistingGroup(array $group, array $intendedModules = self::EDITOR_MODULES): SetupStep
    {
        $uid = (int)$group['uid'];
        $title = (string)$group['title'];
        $modules = (string)$group['groupMods'];

        $remember = static function (SetupContext $context) use ($title, $uid): void {
            $context->rememberGroup($title, $uid);
        };

        if (!in_array(self::BOARD_MODULE, $intendedModules, true)) {
            return new SetupStep(sprintf('Keep group "%s" (uid %d) as it is.', $title, $uid), $remember, false);
        }
        if (CommaList::containsAll($modules, [self::BOARD_MODULE])) {
            return new SetupStep(sprintf('Keep group "%s" (uid %d), it already has the board.', $title, $uid), $remember, false);
        }

        return new SetupStep(
            sprintf('Add the board module to existing group "%s" (uid %d). Its other permissions stay as they are.', $title, $uid),
            function (SetupContext $context) use ($uid, $modules, $remember): void {
                $this->installation->addModulesToGroup($uid, $modules, [self::BOARD_MODULE]);
                $remember($context);
            },
        );
    }

    /**
     * @param array<int, array<string, mixed>> $attachTo uid => group row
     * @return list<SetupStep>
     */
    private function planAccessGroup(string $title, array $attachTo): array
    {
        $existing = $this->installation->findGroup($title);
        $steps = [];
        if ($existing === null) {
            $steps[] = new SetupStep(
                sprintf('Create access group "%s" with nothing but the board module.', $title),
                function (SetupContext $context) use ($title): void {
                    $uid = $this->installation->createAccessGroup($title, self::BOARD_MODULE);
                    if ($uid < 1) {
                        throw new \RuntimeException(sprintf('Could not create the group "%s".', $title), 1762272004);
                    }
                    $context->rememberGroup($title, $uid);
                },
            );
        } else {
            $steps[] = $this->ensureModuleOnExistingGroup($existing);
        }

        $accessUid = (int)($existing['uid'] ?? 0);
        foreach ($attachTo as $parentUid => $parent) {
            $subgroups = (string)$parent['subgroup'];
            if ($accessUid > 0 && CommaList::containsAll($subgroups, [(string)$accessUid])) {
                $steps[] = SetupStep::unchanged(sprintf('Keep "%s" (uid %d), it already includes "%s".', $parent['title'], $parentUid, $title));
                continue;
            }
            $steps[] = new SetupStep(
                sprintf('Add "%s" as subgroup of "%s" (uid %d).', $title, $parent['title'], $parentUid),
                function (SetupContext $context) use ($parentUid, $subgroups, $title): void {
                    $this->installation->addSubgroup($parentUid, $subgroups, $context->group($title));
                },
            );
        }

        return $steps;
    }

    /**
     * @param list<string> $memberGroups titles; their uids are known only once the groups exist
     * @param list<string> $owners references like be_users_3
     * @return list<SetupStep>
     */
    private function planWorkspaceForGroups(string $workspace, array $memberGroups, array $owners, SetupOptions $options): array
    {
        $ownerGroups = $options->ownerGroups;

        return $this->planWorkspace(
            $workspace,
            $options->stages,
            static fn (SetupContext $context): array => array_map(
                static fn (string $title): string => 'be_groups_' . $context->group($title),
                $memberGroups,
            ),
            static fn (SetupContext $context): array => [
                ...$owners,
                ...array_map(static fn (string $title): string => 'be_groups_' . $context->group($title), $ownerGroups),
            ],
            $options,
            implode('", "', $memberGroups),
            $this->knownGroupReferences($memberGroups),
            ($knownOwnerGroups = $this->knownGroupReferences($ownerGroups)) === null ? null : [...$owners, ...$knownOwnerGroups],
        );
    }

    /**
     * The references for groups that exist already, or null as soon as one of
     * them is still to be created - the plan cannot know its uid yet, so it
     * cannot say "nothing to do" for it either.
     *
     * @param list<string> $titles
     * @return list<string>|null
     */
    private function knownGroupReferences(array $titles): ?array
    {
        $references = [];
        foreach ($titles as $title) {
            $group = $this->installation->findGroup($title);
            if ($group === null) {
                return null;
            }
            $references[] = 'be_groups_' . (int)$group['uid'];
        }

        return $references;
    }

    /**
     * @param list<string> $stages
     * @param list<string>|\Closure(SetupContext): list<string> $members
     * @param list<string>|\Closure(SetupContext): list<string> $owners
     * @param list<string>|null $knownMembers what $members resolves to, if that is known while planning
     * @param list<string>|null $knownOwners what $owners resolves to, if that is known while planning
     * @return list<SetupStep>
     */
    private function planWorkspace(
        string $title,
        array $stages,
        array|\Closure $members,
        array|\Closure $owners,
        SetupOptions $options,
        ?string $memberLabel = null,
        ?array $knownMembers = null,
        ?array $knownOwners = null,
    ): array {
        $existingUid = $this->workspaces->findWorkspace($title);
        $existingStages = $existingUid > 0 ? $this->workspaces->findStages($existingUid) : [];
        $missingStages = array_values(array_diff($stages, array_keys($existingStages)));
        $memberLabel ??= is_array($members) ? implode(', ', $members) : '';
        $knownMembers ??= is_array($members) ? $members : null;
        $knownOwners ??= is_array($owners) ? $owners : null;

        $description = $existingUid > 0
            ? sprintf('Keep workspace "%s" (uid %d)', $title, $existingUid)
            : sprintf('Create workspace "%s"', $title);
        $description .= $missingStages === []
            ? ($stages === [] ? ', without review stages' : ', stages present: ' . implode(' > ', $stages))
            : ', add stages: ' . implode(' > ', $missingStages);
        $description .= $memberLabel === '' ? '.' : sprintf('; members: "%s".', $memberLabel);
        if ($options->owners !== [] || $options->ownerGroups !== []) {
            $description .= ' Owners (may publish): ' . implode(', ', [...$options->owners, ...$options->ownerGroups]) . '.';
        }

        return [new SetupStep(
            $description,
            function (SetupContext $context) use ($title, $stages, $members, $owners, $existingUid): void {
                $workspaceUid = $existingUid > 0
                    ? $existingUid
                    : $this->workspaces->createWorkspace($title, 'Created by editorialflow:setup.');
                if ($workspaceUid < 1) {
                    throw new \RuntimeException(sprintf('Could not create the workspace "%s".', $title), 1762272005);
                }
                $context->rememberWorkspace($title, $workspaceUid);

                $memberReferences = $members instanceof \Closure ? $members($context) : $members;
                $ownerReferences = $owners instanceof \Closure ? $owners($context) : $owners;

                $stageUids = $this->workspaces->ensureStages($workspaceUid, $stages);
                // Members are responsible for every stage, so a task can travel
                // the whole chain out of the box. Only owners may publish - that
                // is core's rule, and the one line of separation that is there
                // without anyone configuring it. Narrow it per stage in the
                // workspace record once real roles exist.
                foreach ($stageUids as $stageUid) {
                    $this->installation->addStageResponsible($stageUid, $memberReferences);
                }
                $this->installation->addWorkspaceAccess($workspaceUid, $memberReferences, $ownerReferences);
                $context->report(sprintf('Workspace "%s" is uid %d.', $title, $workspaceUid));
            },
            $existingUid === 0
                || $missingStages !== []
                || !$this->workspaceAlreadyGrants($existingUid, array_intersect_key($existingStages, array_flip($stages)), $knownMembers, $knownOwners),
        )];
    }

    /**
     * @param array<string, int> $stageUids the stages this run asks for
     * @param list<string>|null $members
     * @param list<string>|null $owners
     */
    private function workspaceAlreadyGrants(int $workspaceUid, array $stageUids, ?array $members, ?array $owners): bool
    {
        $row = $this->installation->findWorkspaceRow($workspaceUid);
        if ($row === null || $members === null || $owners === null) {
            return false;
        }
        if (!CommaList::containsAll((string)$row['members'], $members) || !CommaList::containsAll((string)$row['adminusers'], $owners)) {
            return false;
        }
        foreach ($stageUids as $stageUid) {
            if (!CommaList::containsAll($this->installation->findStageResponsible($stageUid), $members)) {
                return false;
            }
        }

        return true;
    }

    private function planEditor(string $username, string $group, SetupOptions $options): SetupStep
    {
        $workspace = (string)$options->workspaceOfGroup($group);
        $existing = $this->installation->findUser($username);

        if ($existing !== null) {
            $groupUid = (int)($this->installation->findGroup($group)['uid'] ?? 0);
            if ($groupUid > 0 && CommaList::containsAll((string)$existing['usergroup'], [(string)$groupUid])) {
                return SetupStep::unchanged(sprintf('Keep user "%s", already in group "%s".', $username, $group));
            }

            return new SetupStep(
                sprintf('Add existing user "%s" to group "%s". Password and other settings stay as they are.', $username, $group),
                function (SetupContext $context) use ($existing, $group): void {
                    $this->installation->addUserToGroup((int)$existing['uid'], (string)$existing['usergroup'], $context->group($group));
                },
            );
        }

        $password = $options->editorPassword;
        if ($password === null) {
            throw new InvalidSetupOptions(sprintf(
                'Creating the editor "%s" needs a password: --editor-password=... or the environment variable EDITORIALFLOW_EDITOR_PASSWORD.',
                $username,
            ));
        }

        return new SetupStep(
            sprintf('Create editor "%s" in group "%s", working in workspace "%s" (not in Live).', $username, $group, $workspace),
            function (SetupContext $context) use ($username, $password, $group, $workspace): void {
                $uid = $this->installation->createEditor($username, $password, $context->group($group), $context->workspace($workspace));
                if ($uid < 1) {
                    throw new \RuntimeException(sprintf(
                        'Could not create the editor "%s". Most often the password does not meet the installation\'s password policy.',
                        $username,
                    ), 1762272006);
                }
                $context->report(sprintf('Editor "%s" is uid %d.', $username, $uid));
            },
        );
    }

    /**
     * @param list<string> $usernames
     * @return list<string> references like be_users_3
     */
    private function resolveOwners(array $usernames): array
    {
        $references = [];
        foreach ($usernames as $username) {
            $uid = $this->installation->findUserUid($username);
            if ($uid < 1) {
                throw new InvalidSetupOptions(sprintf(
                    'There is no backend user "%s". Create it first, for example: typo3 backend:user:create --username=%s',
                    $username,
                    $username,
                ));
            }
            $references[] = 'be_users_' . $uid;
        }

        return $references;
    }

    /**
     * @return array<int, array<string, mixed>> uid => group row
     */
    private function resolveAttachTo(SetupOptions $options): array
    {
        $accessGroup = $this->installation->findGroup($options->groups[0]);
        $accessUid = (int)($accessGroup['uid'] ?? 0);

        $rows = [];
        if ($options->attachToEditors) {
            foreach ($this->installation->findGroupsWithModule(self::PAGE_MODULE) as $row) {
                $rows[(int)$row['uid']] = $row;
            }
        }
        foreach ($options->attachTo as $reference) {
            $row = $this->installation->findGroup($reference);
            if ($row === null) {
                throw new InvalidSetupOptions(sprintf(
                    'There is no backend group "%s". --attach-to takes group titles or uids; "editorialflow:check" lists the groups.',
                    $reference,
                ));
            }
            $rows[(int)$row['uid']] = $row;
        }
        unset($rows[$accessUid]);

        if ($rows === []) {
            throw new InvalidSetupOptions(sprintf(
                'No group has the Page module (%s), so --attach-to-editors found nothing. Name the groups with --attach-to.',
                self::PAGE_MODULE,
            ));
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<string>
     */
    private function references(string $table, array $rows): array
    {
        return array_map(static fn (int $uid): string => $table . '_' . $uid, array_keys($rows));
    }
}
