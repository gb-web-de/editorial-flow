<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Domain\Model;

/**
 * One task as an external system sees it: everything resolved, nothing to look up.
 *
 * This exists because of what a webhook message is allowed to be. Core's rule
 * (TYPO3\CMS\Core\Messaging\WebhookMessageInterface) is that a message is a plain
 * object - no services, no requests, no models - because it may be serialized
 * onto a queue and handled later, in a process where none of those still mean
 * anything. So everything a receiver needs has to be resolved at the moment the
 * thing happened, not at the moment the message is sent: stage and workspace
 * titles, the subject's title, who the assignee is.
 *
 * That is also why this carries titles rather than only uids. A Jira automation
 * cannot look up sys_workspace_stage.
 *
 * The shape below is a published contract - it is what integrators map onto
 * their side. Adding a key is safe; renaming or removing one breaks whatever was
 * built on it, and there is no way for us to find out that it did.
 */
final readonly class TaskSnapshot implements \JsonSerializable
{
    /**
     * @param array{uid: int, username: string, email: string}|null $assignee
     * @param array{system: string, id: string, url: string}|null $externalReference
     */
    public function __construct(
        public int $uid,
        public string $title,
        public string $description,
        public string $state,
        public int $priority,
        public bool $closed,
        public int $stageUid,
        public string $stageTitle,
        public int $workspaceUid,
        public string $workspaceTitle,
        public string $subjectTable,
        public int $subjectUid,
        public string $subjectTitle,
        public int $subjectPid,
        public ?array $assignee,
        public ?array $externalReference,
        public string $boardUrl,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'task' => [
                'uid' => $this->uid,
                'title' => $this->title,
                'description' => $this->description,
                'state' => $this->state,
                'priority' => $this->priority,
                'closed' => $this->closed,
            ],
            'stage' => [
                'uid' => $this->stageUid,
                'title' => $this->stageTitle,
            ],
            'workspace' => [
                'uid' => $this->workspaceUid,
                'title' => $this->workspaceTitle,
            ],
            'subject' => [
                'table' => $this->subjectTable,
                'uid' => $this->subjectUid,
                'title' => $this->subjectTitle,
                'pid' => $this->subjectPid,
            ],
            'assignee' => $this->assignee,
            'externalReference' => $this->externalReference,
            'boardUrl' => $this->boardUrl,
        ];
    }
}
