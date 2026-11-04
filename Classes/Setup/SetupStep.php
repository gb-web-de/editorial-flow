<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Setup;

/**
 * One line of the plan `editorialflow:setup` prints before it writes anything.
 *
 * The description is decided when the plan is made, so `--dry-run` shows
 * exactly what a real run would do. The action runs later and may read uids
 * an earlier step created - which is why it gets the shared context rather
 * than values captured at planning time.
 */
final class SetupStep
{
    /**
     * @param \Closure(SetupContext): void $action
     */
    public function __construct(
        public readonly string $description,
        private readonly \Closure $action,
        public readonly bool $changesSomething = true,
    ) {
    }

    public static function unchanged(string $description): self
    {
        return new self($description, static function (): void {
            // Shown in the plan so nobody wonders whether it was looked at.
        }, false);
    }

    public function run(SetupContext $context): void
    {
        ($this->action)($context);
    }
}
