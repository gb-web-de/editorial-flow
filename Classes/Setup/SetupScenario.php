<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Setup;

/**
 * The starting points `editorialflow:setup` knows.
 *
 * Each one is a different answer to "what is already in this installation?",
 * because that - not the size of the editorial team - decides what is safe to
 * write. A fresh installation has nothing to break; a large, hand-grown group
 * tree has permissions that took someone years and must not be rewritten by a
 * setup command.
 */
enum SetupScenario: string
{
    /** Empty database: page tree, group and workspace are all created. */
    case Fresh = 'fresh';

    /** One editorial group whose name is yours to choose, one workspace for it. */
    case SingleGroup = 'single-group';

    /** Several groups, and every one of them gets a workspace of its own. */
    case GroupPerWorkspace = 'group-per-workspace';

    /**
     * An existing, possibly huge group tree that stays exactly as it is. Only a
     * small access group is hung into it as a subgroup.
     */
    case ExistingGroups = 'existing-groups';

    public function label(): string
    {
        return match ($this) {
            self::Fresh => 'Fresh installation / empty database',
            self::SingleGroup => 'One editorial group (name of your choice) with one workspace',
            self::GroupPerWorkspace => 'Several groups, each with its own workspace',
            self::ExistingGroups => 'Existing group tree - keep it, only add board access',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $scenario): string => $scenario->value, self::cases());
    }
}
