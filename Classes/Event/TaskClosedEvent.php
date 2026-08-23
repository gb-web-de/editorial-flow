<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Event;

use GbWeb\EditorialFlow\Domain\Model\TaskSnapshot;

/**
 * A task is finished.
 *
 * `reason` is what the activity log records: 'manual' when an editor closed it,
 * 'published' when CloseTaskAfterPublishListener closed it because nothing was
 * left pending. An external system usually wants to treat those differently -
 * one is a decision, the other is a consequence.
 */
final readonly class TaskClosedEvent
{
    /**
     * @param array{uid: int, username: string}|null $actor
     */
    public function __construct(
        public TaskSnapshot $task,
        public string $reason,
        public ?array $actor,
    ) {
    }
}
