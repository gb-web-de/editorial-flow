<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Webhook;

use GbWeb\EditorialFlow\Domain\Model\TaskSnapshot;
use GbWeb\EditorialFlow\Event\TaskStageChangedEvent;
use TYPO3\CMS\Core\Attribute\WebhookMessage;
use TYPO3\CMS\Core\Messaging\WebhookMessageInterface;

/**
 * "A task moved" as it leaves TYPO3 - the one an external board actually tracks.
 *
 * Carries both ends of the move: a receiver mapping TYPO3 stages onto Jira
 * statuses or Trello lists needs to know which column to take the card out of,
 * and the snapshot only holds where it landed.
 *
 * See TaskCreatedMessage for how the registration works and why this costs
 * nothing without EXT:webhooks installed.
 */
#[WebhookMessage(
    identifier: 'editorial-flow/task-stage-changed',
    description: 'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:webhook.taskStageChanged',
)]
final readonly class TaskStageChangedMessage implements WebhookMessageInterface
{
    /**
     * @param array{uid: int, username: string}|null $actor
     */
    public function __construct(
        private TaskSnapshot $task,
        private string $fromState,
        private int $fromStageUid,
        private string $fromStageTitle,
        private ?array $actor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->task->jsonSerialize() + [
            'event' => 'task-stage-changed',
            'from' => [
                'state' => $this->fromState,
                'stageUid' => $this->fromStageUid,
                'stageTitle' => $this->fromStageTitle,
            ],
            'actor' => $this->actor,
        ];
    }

    public static function createFromEvent(TaskStageChangedEvent $event): self
    {
        return new self(
            $event->task,
            $event->fromState,
            $event->fromStageUid,
            $event->fromStageTitle,
            $event->actor,
        );
    }
}
