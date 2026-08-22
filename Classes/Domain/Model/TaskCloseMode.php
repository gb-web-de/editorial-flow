<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Domain\Model;

/**
 * What happens to a task's still-pending workspace versions when it is closed.
 *
 * Closing a task and throwing away unpublished work are two different
 * decisions, and an editor has to make the second one on purpose. KEEP leaves
 * every version exactly where it is - still in the workspace, still reachable
 * from TYPO3's own Workspaces module, just no longer claimed by an open task.
 * DISCARD hands them to core's DataHandler to be thrown away, and cannot be
 * undone.
 *
 * KEEP is the default everywhere, including when the client sends nothing: a
 * missing mode must never be the destructive one.
 *
 * Handing the records to a *different* task is deliberately not a third case
 * here. That is `editorialflow_task_attach`, which already re-derives per-record
 * permissions and writes its own activity entries on both tasks; folding it in
 * would be a second implementation of the same loop. It also has to happen
 * before the close rather than as part of it, because close() marks the member
 * rows closed and moveMemberToTask() only ever moves open ones.
 *
 * The rejected alternative was two routes, `close` and `close_discarding`,
 * self-documenting in AjaxRoutes.php. They would have shared their entire
 * precondition chain, the close() call, the activity entry and the response
 * shape, and would have had to be kept in lockstep forever.
 */
enum TaskCloseMode: string
{
    case KEEP = 'keep';
    case DISCARD = 'discard';

    /**
     * Unlike TaskPriority::fromRequest(), an unknown value is NOT clamped to a
     * default. A priority the client got wrong is a cosmetic mistake; a close
     * mode the client got wrong decides whether content survives, so the
     * request is refused instead of guessed at.
     *
     * Absent is not the same as unknown: no mode at all means KEEP, because
     * that is the non-destructive reading of "close this task".
     */
    public static function fromRequest(mixed $value): ?self
    {
        if ($value === null || $value === '') {
            return self::KEEP;
        }

        return is_string($value) ? self::tryFrom($value) : null;
    }
}
