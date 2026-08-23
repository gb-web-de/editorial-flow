<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use GbWeb\EditorialFlow\Domain\Model\TaskState;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Persistence for tasks and their members.
 *
 * Deliberately plain Doctrine/QueryBuilder rather than Extbase: tasks are written
 * from a DataHandler hook and a PSR-14 listener, i.e. from inside another write
 * transaction, where an Extbase persistence session would be the wrong tool.
 */
final class TaskRepository
{
    public const ORIGIN_SUBJECT = 'subject';
    public const ORIGIN_AUTO = 'auto';
    public const ORIGIN_MANUAL = 'manual';

    private const TABLE = 'tx_editorialflow_task';
    private const TABLE_ITEM = 'tx_editorialflow_task_item';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOpenBySubject(string $subjectTable, int $subjectUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        // DeletedRestriction is a no-op here: it only ever adds a constraint for
        // tables it finds in the TCA schema, and this table has none. `deleted`
        // must be filtered explicitly or a soft-deleted task keeps being found.
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('subject_table', $queryBuilder->createNamedParameter($subjectTable)),
                $queryBuilder->expr()->eq('subject_uid', $queryBuilder->createNamedParameter($subjectUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * The open task a record currently belongs to, if any.
     *
     * This is what makes detaching stick: a record already owned by another open
     * task is never reclaimed by its page's task.
     *
     * @return array<string, mixed>|null
     */
    public function findOpenTaskByMember(string $recordTable, int $recordUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        // DeletedRestriction is a no-op here: it only ever adds a constraint for
        // tables it finds in the TCA schema, and this table has none. Without the
        // explicit filter below, a soft-deleted row (e.g. one released by
        // RepairTaskDataCommand or TaskRepository::close()'s collision fallback)
        // still matches here, and with `one_open_task_per_record` now correctly
        // allowing a fresh open claim alongside it, this lookup would have two
        // candidate rows and no way to prefer the live one.
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $taskUid = $queryBuilder
            ->select('task')
            ->from(self::TABLE_ITEM)
            ->where(
                $queryBuilder->expr()->eq('record_table', $queryBuilder->createNamedParameter($recordTable)),
                $queryBuilder->expr()->eq('record_uid', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $taskUid ? $this->findByUid((int)$taskUid) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $taskUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        // DeletedRestriction is a no-op here: it only ever adds a constraint for
        // tables it finds in the TCA schema, and this table has none.
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($taskUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * The open task an external system already knows about.
     *
     * The pair is the identity a round trip is built on: a Jira issue or a
     * Trello card maps to at most one open task here, which is what lets the
     * incoming reaction be retried - and webhooks are retried - without leaving
     * a second card behind every time.
     *
     * Open only, deliberately. A closed task is an archive record; an external
     * system asking about the same issue again is asking about new work.
     *
     * @return array<string, mixed>|null
     */
    public function findOpenByExternalReference(string $system, string $reference): ?array
    {
        if ($system === '' || $reference === '') {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('external_system', $queryBuilder->createNamedParameter($system)),
                $queryBuilder->expr()->eq('external_ref', $queryBuilder->createNamedParameter($reference)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Get the open task for a subject, creating it if there is none.
     *
     * Uniqueness is enforced by the `one_open_task_per_record` unique key on the
     * item table, not by the preceding SELECT: two editors opening the same page
     * simultaneously would both see "no task" and both insert. The loser catches
     * the constraint violation and adopts the winner's task.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function findOrCreateOpenForSubject(string $subjectTable, int $subjectUid, array $values): array
    {
        $existing = $this->findOpenBySubject($subjectTable, $subjectUid);
        if ($existing !== null) {
            return $existing;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, array_merge($values, [
            'subject_table' => $subjectTable,
            'subject_uid' => $subjectUid,
            'crdate' => $GLOBALS['EXEC_TIME'],
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ]));
        $taskUid = (int)$connection->lastInsertId();

        try {
            // The subject is a member of its own task. This insert is what actually
            // claims the record, so it is also what detects a concurrent creation.
            // `subject_pid` in $values is the same "page this covers" value every
            // other addMember() call passes as homePid - for a page subject that is
            // the page's own uid, matching TaskAutoCreationService::derivePid().
            $this->addMember(
                $taskUid,
                $subjectTable,
                $subjectUid,
                self::ORIGIN_SUBJECT,
                (int)($values['subject_pid'] ?? 0),
            );
        } catch (UniqueConstraintViolationException) {
            // Someone else got there first - drop our now-orphaned task and use theirs.
            $connection->delete(self::TABLE, ['uid' => $taskUid]);
            $winner = $this->findOpenBySubject($subjectTable, $subjectUid);
            if ($winner !== null) {
                return $winner;
            }
            throw new \RuntimeException(
                sprintf('Could not resolve open Editorial Flow task for %s:%d', $subjectTable, $subjectUid),
                1754563200,
            );
        }

        $created = $this->findByUid($taskUid);
        if ($created === null) {
            throw new \RuntimeException(
                sprintf('Editorial Flow task for %s:%d vanished right after insert', $subjectTable, $subjectUid),
                1754563201,
            );
        }
        return $created;
    }

    /**
     * A task for a page that does not exist yet - "Neue Seite erstellen" on the
     * "+ New task" wizard. `subject_uid` stays 0 until an editor drags the ticket
     * into a review stage (see TaskAjaxController::moveStageAction()'s
     * materializePendingPage()), which is what actually creates the page.
     *
     * Deliberately NOT findOrCreateOpenForSubject(): there is no real subject to
     * dedupe against yet, and there never should be - each "Neue Seite erstellen"
     * click is its own new, distinct ticket, not something to find-or-reuse. No
     * member row either, for the same reason findOrCreateOpenForSubject() adds
     * one for the subject itself: there is nothing to claim yet.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function createPendingPageTask(int $parentPid, array $values): array
    {
        return $this->createPendingSubjectTask($parentPid, 'pages', $values);
    }

    /**
     * Create a task for a page or record that does not exist yet.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function createPendingSubjectTask(int $subjectPid, string $subjectTable, array $values): array
    {
        if ($subjectPid < 1 || $subjectTable === '') {
            throw new \InvalidArgumentException('A pending subject task requires a table and planning page.');
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, array_merge($values, [
            'subject_table' => $subjectTable,
            'subject_uid' => 0,
            'subject_pid' => $subjectPid,
            'crdate' => $GLOBALS['EXEC_TIME'],
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ]));
        $taskUid = (int)$connection->lastInsertId();

        $created = $this->findByUid($taskUid);
        if ($created === null) {
            throw new \RuntimeException(
                sprintf('Editorial Flow pending-subject task vanished right after insert (uid %d)', $taskUid),
                1786300000,
            );
        }
        return $created;
    }

    /**
     * Give a pending task its real subject once the page or record it was
     * waiting for has actually been created. Claims the new subject as the
     * task's own member too, mirroring what
     * findOrCreateOpenForSubject() does for a subject that exists from the start.
     */
    public function attachCreatedSubject(
        int $taskUid,
        string $subjectTable,
        int $subjectUid,
        int $subjectPid,
    ): void {
        if ($subjectTable === '' || $subjectUid < 1 || $subjectPid < 1) {
            throw new \InvalidArgumentException('A created subject requires a table, uid and page.');
        }

        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'subject_table' => $subjectTable,
                'subject_uid' => $subjectUid,
                'subject_pid' => $subjectPid,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            ['uid' => $taskUid],
        );
        $this->addMember($taskUid, $subjectTable, $subjectUid, self::ORIGIN_SUBJECT, $subjectPid);
    }

    /**
     * Claim a record for a task. Throws UniqueConstraintViolationException when the
     * record already belongs to another open task - callers decide whether that is
     * an error or simply "leave it where the editor put it".
     *
     * `pid` mirrors `home_pid`: the page the record's content actually lives on.
     * Written into the standard `pid` column too (previously left at its default
     * 0) so a query can filter "everything under this page" directly instead of
     * joining back through every record_uid one at a time.
     */
    public function addMember(
        int $taskUid,
        string $recordTable,
        int $recordUid,
        string $origin,
        int $homePid = 0,
        bool $shared = false,
    ): void {
        $this->connectionPool->getConnectionForTable(self::TABLE_ITEM)->insert(self::TABLE_ITEM, [
            'pid' => $homePid,
            'task' => $taskUid,
            'record_table' => $recordTable,
            'record_uid' => $recordUid,
            'origin' => $origin,
            'home_pid' => $homePid,
            'shared' => $shared ? 1 : 0,
            'closed' => 0,
            'crdate' => $GLOBALS['EXEC_TIME'],
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ]);
    }

    /**
     * Attach a record unless it already belongs to an open task.
     *
     * @return bool true when this call claimed the record
     */
    public function addMemberIfUnclaimed(
        int $taskUid,
        string $recordTable,
        int $recordUid,
        string $origin,
        int $homePid = 0,
        bool $shared = false,
    ): bool {
        try {
            $this->addMember($taskUid, $recordTable, $recordUid, $origin, $homePid, $shared);
            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Move a record's membership to another existing task.
     *
     * The "add to task" half of the wizard, and also how an editor resolves the
     * cross-page case: content reached through a shortcut can be moved onto the task
     * of the page they are actually working on, instead of the page it lives on.
     * `origin` becomes manual so a later resync does not treat it as auto-collected.
     */
    public function moveMemberToTask(string $recordTable, int $recordUid, int $targetTaskUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE_ITEM)->update(
            self::TABLE_ITEM,
            [
                'task' => $targetTaskUid,
                'origin' => self::ORIGIN_MANUAL,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            [
                'record_table' => $recordTable,
                'record_uid' => $recordUid,
                'closed' => 0,
                'deleted' => 0,
            ],
        );
    }

    /**
     * Members that need an editor's attention: content living on another page, or
     * reused elsewhere. Both mean "changing this changes more than this page".
     *
     * @return list<array<string, mixed>>
     */
    public function findWarnedMembers(int $taskUid, int $subjectPid): array
    {
        return array_values(array_filter(
            $this->findMembers($taskUid),
            static fn (array $member): bool => (int)($member['shared'] ?? 0) === 1
                || ((int)($member['home_pid'] ?? 0) > 0 && (int)$member['home_pid'] !== $subjectPid),
        ));
    }

    /**
     * Every record a task ever covered, including the ones close() retired.
     *
     * findMembers() filters `closed = 0`, which is right for an open task and
     * empties out completely for a closed one - close() marks every member row
     * closed along with the task. That left a finished task's ticket claiming
     * "nothing edited yet" over work that plainly happened.
     *
     * Only ever called for a closed task, so the two readers cannot be confused:
     * an open task's closed member rows are records that were detached or moved
     * away, and those genuinely do not belong to it any more.
     *
     * Soft-deleted rows stay excluded, but the archive may still be smaller than
     * the task once was: close()'s collision fallback hard-deletes a member row
     * when every slot in one_open_task_per_record is taken. That row is gone,
     * and no reader can bring it back.
     *
     * @return list<array<string, mixed>>
     */
    public function findArchivedMembers(int $taskUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE_ITEM)
            ->where(
                $queryBuilder->expr()->eq('task', $queryBuilder->createNamedParameter($taskUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findMembers(int $taskUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        // DeletedRestriction is a no-op here: it only ever adds a constraint for
        // tables it finds in the TCA schema, and this table has none.
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE_ITEM)
            ->where(
                $queryBuilder->expr()->eq('task', $queryBuilder->createNamedParameter($taskUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * The board-wide equivalent of findMembers(): every open member row for a
     * batch of tasks in one query, grouped by task uid in PHP. Exists so a
     * whole board's conflict check costs one query for its members plus one
     * per distinct record table, not one findMembers() call per card.
     *
     * @param list<int> $taskUids
     * @return array<int, list<array<string, mixed>>> task uid => its members
     */
    public function findMembersForTasks(array $taskUids): array
    {
        $taskUids = array_values(array_unique(array_filter($taskUids, static fn (int $uid): bool => $uid > 0)));
        if ($taskUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_ITEM)
            ->where(
                $queryBuilder->expr()->in('task', $queryBuilder->createNamedParameter($taskUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $membersByTask = [];
        foreach ($rows as $row) {
            $membersByTask[(int)$row['task']][] = $row;
        }

        return $membersByTask;
    }

    /**
     * Every open task touching a page - its own subject task (or a subject
     * task for a page-like record that lives on it) plus any task whose
     * membership reaches onto it, e.g. a detached content element's own task.
     * Excludes Done: this feeds the Visual Editor's task picker
     * (EditorialFlowController docs: "Backlog through the stage just before
     * Done"), where the point is choosing among tasks still in flight.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllOpenForPage(int $pageUid): array
    {
        if ($pageUid < 1) {
            return [];
        }

        $subjectQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $subjectQueryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $subjectTaskUids = $subjectQueryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $subjectQueryBuilder->expr()->eq('subject_pid', $subjectQueryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $subjectQueryBuilder->expr()->eq('closed', $subjectQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $subjectQueryBuilder->expr()->eq('deleted', $subjectQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        $itemQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $itemQueryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $memberTaskUids = $itemQueryBuilder
            ->select('task')
            ->from(self::TABLE_ITEM)
            ->where(
                $itemQueryBuilder->expr()->eq('pid', $itemQueryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $itemQueryBuilder->expr()->eq('closed', $itemQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $itemQueryBuilder->expr()->eq('deleted', $itemQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        $taskUids = array_values(array_unique(array_map('intval', array_merge($subjectTaskUids, $memberTaskUids))));
        if ($taskUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($taskUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('state', $queryBuilder->createNamedParameter(TaskState::DONE->value)),
            )
            ->orderBy('tstamp', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Move a record out of its current task into a new task of its own.
     *
     * The editor's escape hatch from aggregation: "this one element is its own piece
     * of work". Implemented as a membership move, so the page's task can never take
     * it back - the unique key sees the slot as occupied.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed> the new task
     */
    public function detachIntoOwnTask(string $recordTable, int $recordUid, array $values): array
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, array_merge($values, [
            'subject_table' => $recordTable,
            'subject_uid' => $recordUid,
            'crdate' => $GLOBALS['EXEC_TIME'],
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ]));
        $taskUid = (int)$connection->lastInsertId();

        // Re-point the existing membership rather than delete-then-insert, so the
        // record is never momentarily unclaimed and the unique key never trips.
        $this->connectionPool->getConnectionForTable(self::TABLE_ITEM)->update(
            self::TABLE_ITEM,
            [
                'task' => $taskUid,
                'origin' => self::ORIGIN_MANUAL,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            [
                'record_table' => $recordTable,
                'record_uid' => $recordUid,
                'closed' => 0,
                'deleted' => 0,
            ],
        );

        $created = $this->findByUid($taskUid);
        if ($created === null) {
            throw new \RuntimeException(
                sprintf('Detached Editorial Flow task for %s:%d vanished right after insert', $recordTable, $recordUid),
                1754563202,
            );
        }
        return $created;
    }

    /**
     * Update the editable task details an editor can refine after auto-creation.
     *
     * The task already exists at this point - the save that triggered it has
     * happened - so this does not create or move anything, only replaces the
     * human-facing metadata with what the wizard collected.
     */
    public function updateDetails(int $taskUid, string $title, string $description, int $assignee): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'title' => $title,
                'description' => $description,
                'assignee' => $assignee,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            ['uid' => $taskUid],
        );
    }

    /**
     * Move a task onto a workspace version. Only widens state from an unversioned
     * one - a task already in review is not dragged back to IN_PROGRESS just
     * because someone touched one of its members again.
     */
    public function attachWorkspace(int $taskUid, int $workspaceUid, int $stageUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'workspace_uid' => $workspaceUid,
                'stage_uid' => $stageUid,
                'state' => TaskState::fromStageId($stageUid)->value,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            ['uid' => $taskUid],
        );
    }

    /**
     * Close a task once everything it covers has gone live. Members are closed too,
     * which releases their slot in the unique key for future work on the same records.
     */
    public function close(int $taskUid, int $beUserId): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'state' => TaskState::DONE->value,
                'closed' => 1,
                'closed_at' => $GLOBALS['EXEC_TIME'],
                'closed_by' => $beUserId,
                'tstamp' => $GLOBALS['EXEC_TIME'],
                // The workspace the task was finished in is KEPT.
                //
                // It used to be zeroed, because belongsInColumn() required
                // workspace_uid === 0 for a Done match and a closed task that
                // kept its workspace landed in no column at all - or worse, in
                // whichever stage column its old stage_uid still matched.
                //
                // But that uid is the only key into what the task actually did:
                // sys_history records workspace edits under the workspace they
                // happened in, and core never rewrites that column when
                // publishing. Zeroing it here left every closed task's ticket
                // reporting "nothing edited yet" over a full trail it could no
                // longer address. belongsInColumn() now short-circuits on
                // `closed` instead, which says what was actually meant: a closed
                // task is in Done because it is closed, not because its
                // workspace was blanked.
            ],
            ['uid' => $taskUid],
        );

        $itemConnection = $this->connectionPool->getConnectionForTable(self::TABLE_ITEM);
        try {
            $itemConnection->update(
                self::TABLE_ITEM,
                ['closed' => 1, 'tstamp' => $GLOBALS['EXEC_TIME']],
                ['task' => $taskUid],
            );
        } catch (UniqueConstraintViolationException) {
            // A record can only ever hold one closed-and-not-deleted slot in
            // one_open_task_per_record - the bulk update above hit a record that
            // was already closed once before under an earlier task (edited again
            // later, e.g. after being detached and re-claimed). That older row
            // already carries this record's closed history; this one adds
            // nothing, so it is superseded row by row instead of closed: the
            // records that CAN close still do, and only the genuine collisions
            // fall back further - same three-step retirement as
            // RepairTaskDataCommand::retireItem(), because marking just
            // `deleted => 1` here can itself collide with an older row already
            // sitting on (record_table, record_uid, closed=0, deleted=1).
            foreach ($this->findMembers($taskUid) as $member) {
                $itemUid = (int)$member['uid'];
                try {
                    $itemConnection->update(
                        self::TABLE_ITEM,
                        ['closed' => 1, 'tstamp' => $GLOBALS['EXEC_TIME']],
                        ['uid' => $itemUid],
                    );
                    continue;
                } catch (UniqueConstraintViolationException) {
                }

                try {
                    $itemConnection->update(
                        self::TABLE_ITEM,
                        ['closed' => 1, 'deleted' => 1, 'tstamp' => $GLOBALS['EXEC_TIME']],
                        ['uid' => $itemUid],
                    );
                    continue;
                } catch (UniqueConstraintViolationException) {
                }

                // Nothing about this row is worth keeping: the record's history
                // already lives on the rows it collided with, and a claim
                // nobody can see must not keep blocking the record.
                $itemConnection->delete(self::TABLE_ITEM, ['uid' => $itemUid]);
            }
        }
    }

    /**
     * All tasks below one or more pages, for the board - open ones for every other
     * column, and closed ones for Done. One query - the cards are grouped in PHP,
     * so adding review stages never adds queries.
     *
     * Deliberately not filtered by `closed`: Done is a real board column
     * (BoardColumnRegistry::getColumns()), and a closed task belongs there, not
     * nowhere - excluding closed tasks here was the reason a published task used
     * to vanish from the board instead of landing in Done.
     *
     * Not workspace-filtered on purpose: a task belonging to a workspace other than
     * the currently active one is still returned, so the board can show it (badged,
     * read-only) instead of it silently vanishing - see
     * EditorialFlowController::buildBoard().
     *
     * @param list<int> $pageUids
     * @return list<array<string, mixed>>
     */
    public function findForBoard(array $pageUids): array
    {
        if ($pageUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->in('subject_pid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('tstamp', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Open tasks assigned to one editor - the "my tasks" view.
     *
     * @return list<array<string, mixed>>
     */
    public function findOpenByAssignee(int $beUserId, int $limit = 10): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('assignee', $queryBuilder->createNamedParameter($beUserId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Open tasks nobody has taken yet. The board's "up for grabs" list - planning
     * deliberately allows leaving a task unassigned so an editor can pick it up.
     *
     * @return list<array<string, mixed>>
     */
    public function findUnassigned(int $limit = 10): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('assignee', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Mirror the column a task now sits in.
     *
     * For core stage columns this is only a read cache: TYPO3 has already written
     * t3ver_stage, and this keeps the board sortable without touching every version.
     *
     * DONE is refused outright. It is the one state that carries a second fact -
     * `closed` - and close() is the only thing that writes both together.
     * Reaching Done through here produces a task that looks finished on the
     * board and is still open in the database, which is how a task ended up in
     * the Done column while remaining fully writable. Unreachable from
     * legitimate callers (fromStageId() never returns DONE), and that is the
     * point of asserting it rather than trusting it.
     */
    public function moveToColumn(int $taskUid, string $state, int $stageUid): void
    {
        if ($state === TaskState::DONE->value) {
            throw new \InvalidArgumentException(
                'A task reaches Done by being closed, not by being moved. Use close().',
                1787654321,
            );
        }

        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'state' => $state,
                'stage_uid' => $stageUid,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            ['uid' => $taskUid],
        );
    }

    /**
     * Give the task an owner. Passing 0 puts it back up for grabs.
     */
    public function assignTo(int $taskUid, int $beUserId): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'assignee' => $beUserId,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            ['uid' => $taskUid],
        );
    }
}
