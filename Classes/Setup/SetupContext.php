<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Setup;

/**
 * The uids a setup run has resolved or created so far, by title.
 *
 * Planning knows names; only running knows the uid a new group got. Steps
 * hand each other uids through this instead of the planner guessing them.
 */
final class SetupContext
{
    /** @var array<string, int> */
    private array $groups = [];

    /** @var array<string, int> */
    private array $workspaces = [];

    /** @var list<string> */
    private array $messages = [];

    public function rememberGroup(string $title, int $uid): void
    {
        $this->groups[$title] = $uid;
    }

    public function group(string $title): int
    {
        return $this->groups[$title] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    public function rememberWorkspace(string $title, int $uid): void
    {
        $this->workspaces[$title] = $uid;
    }

    public function workspace(string $title): int
    {
        return $this->workspaces[$title] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function workspaces(): array
    {
        return $this->workspaces;
    }

    public function report(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return $this->messages;
    }
}
