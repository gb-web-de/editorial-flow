<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Event;

use GbWeb\EditorialFlow\Domain\Model\TaskSnapshot;

/**
 * A task was planned, or opened itself because someone started editing.
 *
 * PSR-14, and dispatched for its own sake rather than for the webhook that
 * happens to be the first listener: "a task was created" is the seam a
 * notification, an @mention feed or a project's own integration all need, and
 * none of them should have to hook a DataHandler to find out.
 *
 * @param array{uid: int, username: string}|null $actor who did it, resolved -
 *        null for something the system did on its own.
 */
final readonly class TaskCreatedEvent
{
    /**
     * @param array{uid: int, username: string}|null $actor
     */
    public function __construct(
        public TaskSnapshot $task,
        public bool $autoCreated,
        public ?array $actor,
    ) {
    }
}
