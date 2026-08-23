<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Event;

use GbWeb\EditorialFlow\Domain\Model\TaskSnapshot;

/**
 * A task moved from one stage to another, and core allowed it.
 *
 * Dispatched after the transition, never before: until EXT:workspaces'
 * version_setStage() has accepted it, nothing has happened, and an external
 * system told about a move that was then refused has no way to find out.
 *
 * The `from` side is carried explicitly because the snapshot already holds the
 * new state - a receiver that wants "left Review" cannot reconstruct it.
 */
final readonly class TaskStageChangedEvent
{
    /**
     * @param array{uid: int, username: string}|null $actor
     */
    public function __construct(
        public TaskSnapshot $task,
        public string $fromState,
        public int $fromStageUid,
        public string $fromStageTitle,
        public ?array $actor,
    ) {
    }
}
