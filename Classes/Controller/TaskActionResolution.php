<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Controller;

/**
 * The way out of a refusal.
 *
 * Several of this controller's rejections are correct and still leave the
 * editor with nowhere to go. A task with nothing pending cannot change stage,
 * cannot return to a planning column and cannot be published - each answer is
 * right on its own, and together they are a dead end. The refusal is not the
 * problem; being told only what is impossible is.
 *
 * So a rejection may carry one concrete next step. This is deliberately not a
 * softening of the rule that was applied: the stage gate still refuses, the
 * planning columns still refuse. The offer sits beside the refusal and names
 * the action that would actually help.
 *
 * `action` is a stable kebab-case identifier, the same contract `code` has on
 * TaskActionError - clients switch on it and must never parse `label`, which is
 * editor-facing prose and free to be rephrased or translated.
 */
final readonly class TaskActionResolution
{
    /**
     * @param array<string, mixed> $payload what the client needs to carry out the
     *        offer - a task uid, a workspace to switch to. Unlike
     *        TaskActionError::$context this IS sent to the browser, so nothing
     *        belongs here that the editor may not see.
     */
    public function __construct(
        public string $action,
        public string $label,
        public array $payload = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['action' => $this->action, 'label' => $this->label] + $this->payload;
    }
}
