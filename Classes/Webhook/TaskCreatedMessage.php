<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Webhook;

use GbWeb\EditorialFlow\Domain\Model\TaskSnapshot;
use GbWeb\EditorialFlow\Event\TaskCreatedEvent;
use TYPO3\CMS\Core\Attribute\WebhookMessage;
use TYPO3\CMS\Core\Messaging\WebhookMessageInterface;

/**
 * "A task was opened" as it leaves TYPO3.
 *
 * How this is wired, verified against the installed packages rather than
 * assumed: the #[WebhookMessage] attribute is picked up by EXT:webhooks'
 * WebhookCompilerPass, which reads the single parameter of createFromEvent() by
 * reflection and registers core's MessageListener for exactly that event class.
 * There is no listener of ours anywhere, and no configuration naming the event
 * twice.
 *
 * Both the attribute and WebhookMessageInterface live in typo3/cms-core, not in
 * typo3/cms-webhooks - so this class costs nothing on an installation without
 * EXT:webhooks. Without it the attribute is simply never registered for
 * autoconfiguration and this is an inert object nobody constructs.
 *
 * A plain object with no services, per WebhookMessageInterface's own note: a
 * message may be queued and handled in a later process, where a service or a
 * request means nothing any more. Everything a receiver needs is therefore
 * already resolved in the snapshot.
 */
#[WebhookMessage(
    identifier: 'editorial-flow/task-created',
    description: 'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:webhook.taskCreated',
)]
final readonly class TaskCreatedMessage implements WebhookMessageInterface
{
    /**
     * @param array{uid: int, username: string}|null $actor
     */
    public function __construct(
        private TaskSnapshot $task,
        private bool $autoCreated,
        private ?array $actor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->task->jsonSerialize() + [
            'event' => 'task-created',
            // The difference an external system cares about: a task somebody
            // planned, versus one that opened itself because an editor started
            // typing. Only the first is usually worth a ticket on the other side.
            'autoCreated' => $this->autoCreated,
            'actor' => $this->actor,
        ];
    }

    public static function createFromEvent(TaskCreatedEvent $event): self
    {
        return new self($event->task, $event->autoCreated, $event->actor);
    }
}
