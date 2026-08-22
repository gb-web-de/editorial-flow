<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Controller;

/**
 * One rejected action, with two audiences.
 *
 * `code` is for whoever debugs this later - stable, kebab-case, greppable in a
 * log file, and never rephrased once shipped (an editor's bug report says
 * "task-closed", not a sentence that changed between versions). `message` is for
 * the editor in the moment: specific enough to explain what to do next, not a
 * generic "an error occurred".
 *
 * Both are logged server-side (see TaskAjaxController::error()) and returned to
 * the client, so the same identifier appears in the browser console and in the
 * TYPO3 system log, wired together.
 */
final readonly class TaskActionError
{
    /**
     * @param array<string, mixed> $context extra fields for the log entry only -
     *        never shown to the editor, so this is where record IDs, table names
     *        etc. belong instead of being folded into the message text.
     * @param TaskActionResolution|null $resolution one concrete way out, where
     *        there is one. Optional because most refusals are self-explanatory:
     *        an offer on every rejection would be noise, and the ones that need
     *        it are the ones that would otherwise be dead ends.
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $context = [],
        public ?TaskActionResolution $resolution = null,
    ) {
    }
}
