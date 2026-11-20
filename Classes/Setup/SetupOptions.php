<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Setup;

/**
 * Everything `editorialflow:setup` was told, checked and normalised once.
 *
 * Built from plain values rather than from a Symfony InputInterface so the
 * rules - which scenario needs which option, what the defaults are - can be
 * tested without a console, and so the interactive wizard and the
 * non-interactive call end up in exactly the same place.
 */
final class SetupOptions
{
    public const DEFAULT_GROUP = 'Editors';
    public const DEFAULT_ACCESS_GROUP = 'Editorial Flow Access';
    public const DEFAULT_STAGES = ['Review', 'Approval'];

    /** be_groups.title is varchar(50). */
    private const MAX_GROUP_TITLE_LENGTH = 50;

    /**
     * Core's fixed stages. A custom stage with one of these titles would show
     * up as a second column of the same name next to core's own.
     */
    private const RESERVED_STAGE_TITLES = ['editing', 'ready to publish', 'publish'];

    /**
     * @param list<string> $groups editor groups to create or reuse, or - for
     *        existing-groups - the single access group to hang into the tree
     * @param list<string> $stages custom review stages, in order; empty = none
     * @param list<string> $owners usernames that own (= may publish) the workspace(s)
     * @param list<string> $ownerGroups group titles that own the workspace(s)
     * @param list<string> $attachTo existing groups (title or uid) that get the access group
     * @param array<string, string> $editors username => title of the group they join
     */
    private function __construct(
        public readonly SetupScenario $scenario,
        public readonly array $groups,
        public readonly ?string $workspace,
        public readonly array $stages,
        public readonly array $owners,
        public readonly array $ownerGroups,
        public readonly array $attachTo,
        public readonly bool $attachToEditors,
        public readonly string $siteBase,
        public readonly bool $dryRun,
        public readonly array $editors = [],
        public readonly ?string $editorPassword = null,
    ) {
    }

    /**
     * @param array{
     *     group?: string|list<string>|null,
     *     workspace?: string|null,
     *     stages?: string|list<string>|null,
     *     owner?: string|list<string>|null,
     *     owner-group?: string|list<string>|null,
     *     attach-to?: string|list<string>|null,
     *     attach-to-editors?: bool|null,
     *     site-base?: string|null,
     *     dry-run?: bool|null,
     *     editor?: string|list<string>|null,
     *     editor-password?: string|null,
     * } $values
     */
    public static function fromArray(SetupScenario $scenario, array $values): self
    {
        $groups = CommaList::parse($values['group'] ?? []);
        if ($groups === []) {
            $groups = match ($scenario) {
                SetupScenario::ExistingGroups => [self::DEFAULT_ACCESS_GROUP],
                SetupScenario::GroupPerWorkspace => [],
                default => [self::DEFAULT_GROUP],
            };
        }

        $workspace = trim((string)($values['workspace'] ?? ''));
        $workspace = $workspace === '' ? null : $workspace;

        $options = new self(
            $scenario,
            $groups,
            $workspace ?? ($scenario === SetupScenario::ExistingGroups || $scenario === SetupScenario::GroupPerWorkspace ? null : $groups[0]),
            self::parseStages($values['stages'] ?? null),
            CommaList::parse($values['owner'] ?? []),
            CommaList::parse($values['owner-group'] ?? []),
            CommaList::parse($values['attach-to'] ?? []),
            (bool)($values['attach-to-editors'] ?? false),
            self::normaliseSiteBase((string)($values['site-base'] ?? '/')),
            (bool)($values['dry-run'] ?? false),
            self::parseEditors(CommaList::parse($values['editor'] ?? []), $groups),
            ($values['editor-password'] ?? '') === '' ? null : (string)$values['editor-password'],
        );
        $options->validate($workspace);

        return $options;
    }

    /**
     * The workspace each editor group works in - one shared workspace, or one
     * per group.
     *
     * @return array<string, list<string>> workspace title => member group titles
     */
    public function workspacesWithMemberGroups(): array
    {
        return match ($this->scenario) {
            SetupScenario::GroupPerWorkspace => array_combine(
                $this->groups,
                array_map(static fn (string $group): array => [$group], $this->groups),
            ),
            SetupScenario::ExistingGroups => $this->workspace === null ? [] : [$this->workspace => $this->attachTo],
            default => [(string)$this->workspace => $this->groups],
        };
    }

    /**
     * The workspace an editor group works in.
     */
    public function workspaceOfGroup(string $group): ?string
    {
        foreach ($this->workspacesWithMemberGroups() as $workspace => $memberGroups) {
            if (in_array($group, $memberGroups, true)) {
                return $workspace;
            }
        }

        return null;
    }

    /**
     * "jana" joins the first group, "jana:Sport" the group named Sport - the
     * one way to say which of several groups an editor belongs to without a
     * second option.
     *
     * @param list<string> $entries
     * @param list<string> $groups
     * @return array<string, string>
     */
    private static function parseEditors(array $entries, array $groups): array
    {
        $editors = [];
        foreach ($entries as $entry) {
            [$username, $group] = array_map(trim(...), explode(':', $entry, 2)) + [1 => ''];
            $editors[$username] = $group !== '' ? $group : (string)($groups[0] ?? '');
        }

        return $editors;
    }

    /**
     * @param string|list<string>|null $value
     * @return list<string>
     */
    private static function parseStages(string|array|null $value): array
    {
        if ($value === null) {
            return self::DEFAULT_STAGES;
        }
        $stages = CommaList::parse($value);
        if ($stages === ['none'] || $stages === ['-']) {
            return [];
        }

        return $stages;
    }

    private static function normaliseSiteBase(string $base): string
    {
        $base = trim($base);

        return $base === '' ? '/' : $base;
    }

    private function validate(?string $explicitWorkspace): void
    {
        foreach ([...$this->groups, ...$this->ownerGroups] as $group) {
            if (mb_strlen($group) > self::MAX_GROUP_TITLE_LENGTH) {
                throw new InvalidSetupOptions(sprintf(
                    'The group name "%s" is longer than %d characters, which is all a TYPO3 group title can hold.',
                    $group,
                    self::MAX_GROUP_TITLE_LENGTH,
                ));
            }
        }

        foreach ($this->stages as $stage) {
            if (in_array(mb_strtolower($stage), self::RESERVED_STAGE_TITLES, true)) {
                throw new InvalidSetupOptions(sprintf(
                    '"%s" is one of TYPO3\'s own fixed stages and is always there. Leave it out of --stages.',
                    $stage,
                ));
            }
        }

        foreach ($this->editors as $username => $group) {
            if ($this->scenario === SetupScenario::ExistingGroups) {
                throw new InvalidSetupOptions('In an existing group tree editors already have their groups; --editor does not apply. Add them to a group in the backend.');
            }
            if ($username === '' || !in_array($group, $this->groups, true)) {
                throw new InvalidSetupOptions(sprintf(
                    '--editor="%s:%s" names a group this run does not set up. Use one of: %s.',
                    $username,
                    $group,
                    implode(', ', $this->groups),
                ));
            }
        }

        match ($this->scenario) {
            SetupScenario::Fresh, SetupScenario::SingleGroup => $this->validateSingleGroup(),
            SetupScenario::GroupPerWorkspace => $this->validateGroupPerWorkspace($explicitWorkspace),
            SetupScenario::ExistingGroups => $this->validateExistingGroups(),
        };
    }

    private function validateSingleGroup(): void
    {
        if (count($this->groups) !== 1) {
            throw new InvalidSetupOptions(sprintf(
                'The "%s" scenario sets up exactly one group, but %d were given. For one workspace per group use "group-per-workspace".',
                $this->scenario->value,
                count($this->groups),
            ));
        }
        if ($this->attachTo !== [] || $this->attachToEditors) {
            throw new InvalidSetupOptions('--attach-to only applies to the "existing-groups" scenario.');
        }
    }

    private function validateGroupPerWorkspace(?string $explicitWorkspace): void
    {
        if (in_array(SetupPlanner::SHARED_GROUP, $this->groups, true)) {
            throw new InvalidSetupOptions(sprintf('"%s" is the group setup creates to share pages between the groups. Choose another name.', SetupPlanner::SHARED_GROUP));
        }
        if ($this->groups === []) {
            throw new InvalidSetupOptions('Name the groups, for example: --group="News,Sport,Culture".');
        }
        if ($explicitWorkspace !== null) {
            throw new InvalidSetupOptions('Every group gets a workspace of its own name here, so --workspace does not apply.');
        }
        if ($this->attachTo !== [] || $this->attachToEditors) {
            throw new InvalidSetupOptions('--attach-to only applies to the "existing-groups" scenario.');
        }
    }

    private function validateExistingGroups(): void
    {
        if (count($this->groups) !== 1) {
            throw new InvalidSetupOptions('The "existing-groups" scenario adds exactly one access group; --group names it.');
        }
        if ($this->attachTo === [] && !$this->attachToEditors) {
            throw new InvalidSetupOptions(
                'Say which existing groups get board access: --attach-to="Editors,Online desk" '
                . '(titles or uids), or --attach-to-editors for every group that has the Page module.',
            );
        }
    }
}
