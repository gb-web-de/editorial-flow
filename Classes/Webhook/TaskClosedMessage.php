<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Webhook;

use GbWeb\EditorialFlow\Domain\Model\TaskSnapshot;
use GbWeb\EditorialFlow\Event\TaskClosedEvent;
use TYPO3\CMS\Core\Attribute\WebhookMessage;
use TYPO3\CMS\Core\Messaging\WebhookMessageInterface;

/**
 * "A task is finished" as it leaves TYPO3.
 *
 * `reason` separates the two ways that happens: 'manual' is a decision somebody
 * made, 'published' is a consequence of the last pending version going live. An
 * external board usually moves the card either way but says something different
 * about it.
 *
 * See TaskCreatedMessage for how the registration works and why this costs
 * nothing without EXT:webhooks installed.
 */
#[WebhookMessage(
    identifier: 'editorial-flow/task-closed',
    description: 'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:webhook.taskClosed',
)]
final readonly class TaskClosedMessage implements WebhookMessageInterface
{
    /**
     * @param array{uid: int, username: string}|null $actor
     */
    public function __construct(
        private TaskSnapshot $task,
        private string $reason,
        private ?array $actor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->task->jsonSerialize() + [
            'event' => 'task-closed',
            'reason' => $this->reason,
            'actor' => $this->actor,
        ];
    }

    public static function createFromEvent(TaskClosedEvent $event): self
    {
        return new self($event->task, $event->reason, $event->actor);
    }
}
