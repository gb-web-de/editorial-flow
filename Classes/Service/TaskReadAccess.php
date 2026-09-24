<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Who may read a task - its ticket, its comments, its activity.
 *
 * The board's own rule, needed wherever a task is shown outside the board:
 * a task is shown to whoever may see the page it is filed on
 * (BoardScopeResolver::filterByAccess()) - either with the mounts the user
 * sits in, or, for a member of the task's workspace, with that workspace's
 * mounts, which is how the board reaches a workspace's tasks from anywhere
 * (TaskWorkspaceScope).
 *
 * Needed because nothing upstream asks: a backend AJAX route admits any
 * logged-in backend user, and a dashboard widget anyone it was granted to.
 * Neither knows which page a task belongs to.
 *
 * Whether the unpublished changes behind a task may be shown as well is a
 * question of its own - see TaskAjaxController::readTaskDetails().
 */
final class TaskReadAccess
{
    /**
     * How far a feed looks for rows the user may read before it settles for
     * fewer - so a widget stays full for someone who sees most of the tree,
     * without scanning the whole table for someone who sees almost none of it.
     */
    private const FEED_BATCHES = 5;

    public function __construct(
        private readonly TaskWorkspaceScope $workspaceScope,
        private readonly TaskRepository $taskRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $task
     */
    public function mayRead(BackendUserAuthentication $user, array $task): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $pageUid = (int)($task['subject_pid'] ?? 0);
        if ($this->maySeePage($user, $pageUid)) {
            return true;
        }

        $workspaceUid = (int)($task['workspace_uid'] ?? 0);

        return $this->workspaceScope->canEnter($user, $workspaceUid)
            && $this->workspaceScope->run($user, $workspaceUid, fn (): bool => $this->maySeePage($user, $pageUid));
    }

    /**
     * The first $limit rows of a newest-first feed - comments, activity -
     * whose task the user may read.
     *
     * @param callable(int $offset, int $count): list<array<string, mixed>> $fetch
     *        one page of the feed; every row carries its task uid in `task`
     * @return list<array<string, mixed>>
     */
    public function firstReadable(BackendUserAuthentication $user, callable $fetch, int $limit): array
    {
        $limit = max(1, $limit);
        if ($user->isAdmin()) {
            return $fetch(0, $limit);
        }

        $batchSize = $limit * 4;
        $readableByTask = [];
        $readable = [];
        for ($batch = 0; $batch < self::FEED_BATCHES; $batch++) {
            $rows = $fetch($batch * $batchSize, $batchSize);
            foreach ($rows as $row) {
                $taskUid = (int)($row['task'] ?? 0);
                $readableByTask[$taskUid] ??= $this->mayReadTaskUid($user, $taskUid);
                if (!$readableByTask[$taskUid]) {
                    continue;
                }
                $readable[] = $row;
                if (count($readable) === $limit) {
                    return $readable;
                }
            }
            if (count($rows) < $batchSize) {
                break;
            }
        }

        return $readable;
    }

    private function mayReadTaskUid(BackendUserAuthentication $user, int $taskUid): bool
    {
        $task = $taskUid > 0 ? $this->taskRepository->findByUid($taskUid) : null;

        return $task !== null && $this->mayRead($user, $task);
    }

    /**
     * Asked twice by mayRead() with the same arguments on purpose: the answer
     * depends on the user's page mounts, which TaskWorkspaceScope swaps for
     * the task workspace's in between.
     *
     * @phpstan-impure
     */
    private function maySeePage(BackendUserAuthentication $user, int $pageUid): bool
    {
        if ($pageUid < 1) {
            return false;
        }
        $page = BackendUtility::getRecord('pages', $pageUid);

        return $page !== null && $user->doesUserHaveAccess($page, Permission::PAGE_SHOW);
    }
}
