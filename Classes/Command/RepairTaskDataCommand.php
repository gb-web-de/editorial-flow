<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Command;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use GbWeb\EditorialFlow\Domain\Model\TaskState;
use GbWeb\EditorialFlow\Service\TaskMemberSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Finds and, with --fix, heals the ways tx_editorialflow_task_item can drift away
 * from tx_editorialflow_task.
 *
 * The tables have no TCA and therefore no DataHandler management at all - a task
 * can currently only disappear via the race-loser cleanup in
 * TaskRepository::findOrCreateOpenForSubject() (which runs before any member
 * exists, so it cannot orphan one) or via manual intervention directly on the
 * database. Either way, nothing in the extension ever released the member rows
 * of a task that stopped existing, and the unique key
 * `one_open_task_per_record (record_table, record_uid, closed, deleted)` then
 * blocks that record from ever being claimed again - by any task, forever.
 *
 * Dry-run by default: this only ever reports what it found unless --fix is
 * given, the same one-step-back-from-destructive stance
 * CreateDemoContentCommand takes with --force.
 */
#[AsCommand(
    name: 'editorialflow:repair',
    description: 'Report (and, with --fix, heal) task_item rows that outlived their task.',
)]
final class RepairTaskDataCommand extends Command
{
    private const TABLE_TASK = 'tx_editorialflow_task';
    private const TABLE_ITEM = 'tx_editorialflow_task_item';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TaskMemberSynchronizer $memberSynchronizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'fix',
            null,
            InputOption::VALUE_NONE,
            'Actually write the repairs. Without this, only report what would change.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Bootstrap::initializeBackendAuthentication();
        $fix = (bool)$input->getOption('fix');

        $orphanCount = $this->repairOrphanedItems($io, $fix);
        $strandedCount = $this->releaseClaimsOfClosedTasks($io, $fix);
        $backfillCount = $this->backfillMissingPid($io, $fix);
        // After the two releases above, and only then: a slot that was just
        // freed is of no use to anyone until an open task takes it.
        $reclaimed = $fix ? $this->reclaimPagesForOpenTasks($io) : 0;
        $this->reportEmptyTasks($io);
        $this->reportMissingVersions($io);

        if ($orphanCount === 0 && $strandedCount === 0 && $backfillCount === 0 && $reclaimed === 0) {
            $io->success('Nothing to repair.');
            return Command::SUCCESS;
        }

        if (!$fix) {
            $io->note('Dry run - nothing was written. Re-run with --fix to apply the repairs above.');
        }

        return Command::SUCCESS;
    }

    /**
     * A member row whose task no longer exists holds its record's slot in
     * one_open_task_per_record forever. Soft-deleting it releases that slot -
     * exactly what the task's own close() would have done had it closed
     * normally instead of vanishing.
     */
    private function repairOrphanedItems(SymfonyStyle $io, bool $fix): int
    {
        $taskQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_TASK);
        $taskQueryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $existingTaskUids = array_map(
            'intval',
            $taskQueryBuilder
                ->select('uid')
                ->from(self::TABLE_TASK)
                ->where($taskQueryBuilder->expr()->eq('deleted', $taskQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchFirstColumn(),
        );

        // DeletedRestriction is a no-op for both tables here (neither has TCA), so
        // `deleted` must be filtered explicitly - otherwise a row this same
        // command already fixed on a previous run keeps being reported, since
        // its task is still just as gone as it was before.
        $itemQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $itemQueryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $orphans = $itemQueryBuilder
            ->select('uid', 'task', 'record_table', 'record_uid')
            ->from(self::TABLE_ITEM)
            ->where(
                $itemQueryBuilder->expr()->notIn(
                    'task',
                    $itemQueryBuilder->createNamedParameter($existingTaskUids === [] ? [0] : $existingTaskUids, Connection::PARAM_INT_ARRAY),
                ),
                $itemQueryBuilder->expr()->eq('deleted', $itemQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($orphans === []) {
            $io->writeln('No orphaned task_item rows found.');
            return 0;
        }

        $io->section(sprintf('%d orphaned task_item row(s) (task no longer exists):', count($orphans)));
        $itemConnection = $this->connectionPool->getConnectionForTable(self::TABLE_ITEM);
        foreach ($orphans as $orphan) {
            $io->writeln(sprintf(
                '  item %d: task %d (gone) held %s:%d',
                $orphan['uid'],
                $orphan['task'],
                $orphan['record_table'],
                $orphan['record_uid'],
            ));
            if ($fix) {
                $itemConnection->update(
                    self::TABLE_ITEM,
                    ['deleted' => 1, 'tstamp' => $GLOBALS['EXEC_TIME']],
                    ['uid' => (int)$orphan['uid']],
                );
            }
        }

        return count($orphans);
    }

    /**
     * Take one member row out of circulation, whatever it takes.
     *
     * `one_open_task_per_record` spans (record_table, record_uid, closed,
     * deleted), so every escape route can itself be occupied by an older row
     * for the same record: closing collides with a row closed earlier, and
     * soft-deleting while still open collides with one deleted earlier - which
     * is exactly how this command failed the first time it was run for real.
     * Each step therefore falls through to a more final one, and the last of
     * them cannot collide because the row is gone.
     */
    private function retireItem(Connection $connection, int $itemUid): void
    {
        try {
            $connection->update(self::TABLE_ITEM, ['closed' => 1, 'tstamp' => $GLOBALS['EXEC_TIME']], ['uid' => $itemUid]);
            return;
        } catch (UniqueConstraintViolationException) {
        }

        try {
            $connection->update(
                self::TABLE_ITEM,
                ['closed' => 1, 'deleted' => 1, 'tstamp' => $GLOBALS['EXEC_TIME']],
                ['uid' => $itemUid],
            );
            return;
        } catch (UniqueConstraintViolationException) {
        }

        // Nothing about this row is worth keeping: the record's history already
        // lives on the rows it collided with, and a claim nobody can see must
        // not keep blocking the record.
        $connection->delete(self::TABLE_ITEM, ['uid' => $itemUid]);
    }

    /**
     * An open page task should hold that page's content - "one card = this page
     * and everything on it" is the whole reason a card is not one per element.
     * A slot freed above belongs to nobody until somebody takes it, and until
     * then the element shows no marker anywhere while still being part of the
     * work.
     *
     * Only runs with --fix, and only ever adds: syncPageMembers() goes through
     * addMemberIfUnclaimed(), so an element another open task holds stays where
     * it is. Repairing must not redistribute work between tasks.
     */
    private function reclaimPagesForOpenTasks(SymfonyStyle $io): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_TASK);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $tasks = $queryBuilder
            ->select('uid', 'title', 'subject_uid')
            ->from(self::TABLE_TASK)
            ->where(
                $queryBuilder->expr()->eq('subject_table', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->gt('subject_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('state', $queryBuilder->createNamedParameter(TaskState::DONE->value)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $claimed = 0;
        foreach ($tasks as $task) {
            $count = $this->memberSynchronizer->syncPageMembers((int)$task['uid'], (int)$task['subject_uid']);
            if ($count > 0) {
                $io->writeln(sprintf(
                    '  task %d ("%s") reclaimed %d unheld record(s) on page %d',
                    $task['uid'],
                    (string)$task['title'],
                    $count,
                    $task['subject_uid'],
                ));
                $claimed += $count;
            }
        }

        if ($claimed === 0) {
            $io->writeln('No unheld records to reclaim for open page tasks.');
        } else {
            $io->section(sprintf('%d record(s) reclaimed by their page task.', $claimed));
        }

        return $claimed;
    }

    /**
     * A member row still open under a task that is already closed.
     *
     * Worse than the orphan above, because nothing shows it: the board and the
     * markers both ask findAllOpenForPage(), which skips closed tasks, so the
     * claim is invisible - while one_open_task_per_record still counts it, so
     * the record cannot be claimed by any open task either. The element ends up
     * belonging to nobody an editor can see and to somebody the database will
     * not let go of, which is exactly what "no badge on content that plainly
     * has a task" looks like from the outside.
     *
     * Closing the row is what TaskRepository::close() would have done, with the
     * same collision fallback: a record that already holds a closed slot from
     * an earlier task keeps that history, and this row is soft-deleted instead.
     */
    private function releaseClaimsOfClosedTasks(SymfonyStyle $io, bool $fix): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $stranded = $queryBuilder
            ->select('i.uid', 'i.task', 'i.record_table', 'i.record_uid', 't.title')
            ->from(self::TABLE_ITEM, 'i')
            ->join('i', self::TABLE_TASK, 't', $queryBuilder->expr()->eq('t.uid', $queryBuilder->quoteIdentifier('i.task')))
            ->where(
                $queryBuilder->expr()->eq('i.closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('i.deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                // Both halves of "finished", because both make the claim
                // invisible: findAllOpenForPage() skips a task that is closed
                // AND one whose state is Done, so a member row under either is
                // held by something no surface will ever draw.
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('t.closed', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('t.state', $queryBuilder->createNamedParameter(TaskState::DONE->value)),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($stranded === []) {
            $io->writeln('No task_item rows are held open by a finished task.');
            return 0;
        }

        $io->section(sprintf('%d task_item row(s) still claimed by a finished task:', count($stranded)));
        $itemConnection = $this->connectionPool->getConnectionForTable(self::TABLE_ITEM);
        foreach ($stranded as $row) {
            $io->writeln(sprintf(
                '  item %d: finished task %d ("%s") still holds %s:%d',
                $row['uid'],
                $row['task'],
                (string)$row['title'],
                $row['record_table'],
                $row['record_uid'],
            ));
            if (!$fix) {
                continue;
            }
            $this->retireItem($itemConnection, (int)$row['uid']);
        }

        return count($stranded);
    }

    /**
     * `pid` started mirroring `home_pid` only once TaskRepository::addMember()
     * began writing it - rows inserted before that keep the column's default of
     * 0. Backfilling from the sibling column that already holds the right value
     * needs no lookup against the actual record.
     *
     * Scoped to `deleted = 0`: a soft-deleted row is done being queried by
     * anything, so giving it a correct pid buys nothing and would otherwise
     * keep this command reporting "needs a repair" forever on historical rows.
     */
    private function backfillMissingPid(SymfonyStyle $io, bool $fix): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $stale = $queryBuilder
            ->select('uid', 'home_pid')
            ->from(self::TABLE_ITEM)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('home_pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($stale === []) {
            $io->writeln('No task_item rows need a pid backfill.');
            return 0;
        }

        $io->section(sprintf('%d task_item row(s) with pid=0 but a known home_pid:', count($stale)));
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_ITEM);
        foreach ($stale as $row) {
            $io->writeln(sprintf('  item %d: pid 0 -> %d', $row['uid'], $row['home_pid']));
            if ($fix) {
                $connection->update(
                    self::TABLE_ITEM,
                    ['pid' => (int)$row['home_pid'], 'tstamp' => $GLOBALS['EXEC_TIME']],
                    ['uid' => (int)$row['uid']],
                );
            }
        }

        return count($stale);
    }

    /**
     * Reported only, never fixed: whether an empty task should be closed,
     * deleted, or left as a reminder that something needs attention is an
     * editorial call, not a data-integrity one.
     */
    private function reportEmptyTasks(SymfonyStyle $io): void
    {
        $taskQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_TASK);
        $taskQueryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $openTasks = $taskQueryBuilder
            ->select('uid', 'title')
            ->from(self::TABLE_TASK)
            ->where(
                $taskQueryBuilder->expr()->eq('closed', $taskQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $taskQueryBuilder->expr()->eq('deleted', $taskQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $itemQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $itemQueryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $tasksWithMembers = array_map(
            'intval',
            $itemQueryBuilder
                ->select('task')
                ->from(self::TABLE_ITEM)
                ->where(
                    $itemQueryBuilder->expr()->eq('closed', $itemQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $itemQueryBuilder->expr()->eq('deleted', $itemQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->groupBy('task')
                ->executeQuery()
                ->fetchFirstColumn(),
        );

        $empty = array_filter($openTasks, static fn (array $task): bool => !in_array((int)$task['uid'], $tasksWithMembers, true));
        if ($empty === []) {
            $io->writeln('No open tasks without members.');
            return;
        }

        $io->section(sprintf('%d open task(s) with zero members (not fixed automatically):', count($empty)));
        foreach ($empty as $task) {
            $io->writeln(sprintf('  task %d: "%s"', $task['uid'], $task['title']));
        }
    }

    /**
     * Members of an open, workspace-holding task that have no pending version.
     *
     * This is the stuck state read from the data side: every exit an editor has
     * - stage move, planning column, publish - asks for a pending version
     * first, so a task in this state refuses all of them, and its ticket looks
     * blank while doing it.
     *
     * Deliberately NOT cross-checked against sys_history, which is the obvious
     * thing to try and does not work. Discarding leaves the version's rows filed
     * under a uid that no longer resolves, and publishing from another workspace
     * leaves them under that workspace - so neither case is findable from the
     * live uid, and requiring a history hit would report nothing at all. The
     * absence of a version is both observable and the thing every refusal is
     * actually based on.
     *
     * The cost is that a member merely attached and never edited is reported
     * too. That is not a false positive worth filtering out: it is the same dead
     * end for the editor, reached a different way.
     *
     * Reported, never fixed, for the reason reportEmptyTasks() gives: whether
     * such a task should be closed or picked back up is an editorial call.
     * Closing it from the board is now possible, which is what this points at.
     *
     * Batched: one query for the open tasks, one for their members, then core's
     * own version lookup per member - never a query per task.
     */
    private function reportMissingVersions(SymfonyStyle $io): void
    {
        $taskQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_TASK);
        $taskQueryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $tasks = $taskQueryBuilder
            ->select('uid', 'title', 'workspace_uid')
            ->from(self::TABLE_TASK)
            ->where(
                $taskQueryBuilder->expr()->eq('closed', $taskQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $taskQueryBuilder->expr()->eq('deleted', $taskQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $taskQueryBuilder->expr()->gt('workspace_uid', $taskQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($tasks === []) {
            $io->writeln('No open tasks with a workspace to check.');
            return;
        }

        $workspaceByTask = [];
        $titleByTask = [];
        foreach ($tasks as $task) {
            $workspaceByTask[(int)$task['uid']] = (int)$task['workspace_uid'];
            $titleByTask[(int)$task['uid']] = (string)$task['title'];
        }

        $itemQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $itemQueryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $members = $itemQueryBuilder
            ->select('task', 'record_table', 'record_uid')
            ->from(self::TABLE_ITEM)
            ->where(
                $itemQueryBuilder->expr()->in(
                    'task',
                    $itemQueryBuilder->createNamedParameter(array_keys($workspaceByTask), Connection::PARAM_INT_ARRAY),
                ),
                $itemQueryBuilder->expr()->eq('closed', $itemQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $itemQueryBuilder->expr()->eq('deleted', $itemQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $stranded = [];
        foreach ($members as $member) {
            $table = (string)$member['record_table'];
            $recordUid = (int)$member['record_uid'];
            $taskUid = (int)$member['task'];
            $workspaceUid = $workspaceByTask[$taskUid] ?? 0;

            if ($this->memberSynchronizer->findVersionUid($table, $recordUid, $workspaceUid) > 0) {
                continue;
            }

            $stranded[] = [
                'task' => $taskUid,
                'title' => $titleByTask[$taskUid] ?? '',
                'record' => sprintf('%s:%d', $table, $recordUid),
                'workspace' => $workspaceUid,
            ];
        }

        if ($stranded === []) {
            $io->writeln('No open tasks stuck without a pending version.');
            return;
        }

        $io->section(sprintf(
            '%d member(s) of open tasks with nothing pending (not fixed automatically):',
            count($stranded),
        ));
        foreach ($stranded as $entry) {
            $io->writeln(sprintf(
                '  task %d "%s": %s has no version in workspace %d - never edited there, discarded, or published from elsewhere. Close the task from the board to clear it.',
                $entry['task'],
                $entry['title'],
                $entry['record'],
                $entry['workspace'],
            ));
        }
    }

}
