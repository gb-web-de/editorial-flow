<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use Doctrine\DBAL\Types\Types;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;

/**
 * The durable editorial record: what was decided, by whom, when.
 *
 * Why this exists next to sys_history - the reasoning matters, because the obvious
 * objection ("you are duplicating core's history") turns out to be wrong:
 *
 * - Core does NOT lose the trail on publish. RecordHistoryStore::publishRecord()
 *   calls migrateWorkspaceHistory(), which rewrites `recuid` from the version uid to
 *   the live uid. Every entry, including ACTION_STAGECHANGE rows carrying the stage
 *   comment and recipients, survives and stays reachable from the live record.
 * - What core does lose is *age*. EXT:scheduler registers sys_history in the table
 *   garbage collection task with an expirePeriod of **30 days** by default. An
 *   archived task therefore has no trail left after a month or two.
 *
 * So this table is not a second truth, it is the durable one. sys_history is a
 * volatile operational log; Editorial Flow keeps the decision itself (who moved this
 * from which stage to which, with what comment) and stores `history_uid` as a pointer
 * to core's full field-level detail *for as long as that detail exists*. Readers must
 * treat a dangling history_uid as "detail expired", never as an error.
 *
 * Field-level before/after values are deliberately NOT copied here. Those are bulky,
 * and for the common case - one edit that goes straight live - the sys_history row is
 * still there and the pointer alone is enough.
 */
final class ActivityLogger
{
    private const TABLE = 'tx_editorialflow_activity';

    public const EVENT_TASK_CREATED = 'task_created';
    public const EVENT_WORK_STARTED = 'work_started';
    public const EVENT_ASSIGNED = 'assigned';
    public const EVENT_STAGE_CHANGED = 'stage_changed';
    /**
     * One of the stage's acceptance criteria was ticked or un-ticked, payload
     * `{item, title, checked}`.
     *
     * An activity rather than a comment, deliberately. A comment per tick would
     * put six rows in the timeline for one review pass and bump the task's
     * comment counter with text nobody wrote - while what an editor actually
     * needs later is who confirmed what, and when, which is precisely what an
     * activity entry is. The prose version an editor reads as one block is the
     * acceptance record written when the task leaves the stage (see
     * StageTransitionService::transition()'s $acceptanceRecord).
     */
    public const EVENT_CHECKLIST_CHECKED = 'checklist_checked';
    /** One member went live while others are still pending; the task stays open. */
    public const EVENT_PUBLISHED = 'published';
    public const EVENT_CLOSED = 'closed';
    /** A member's pending version was thrown away; the record stays claimed. */
    public const EVENT_DISCARDED = 'discarded';
    /**
     * A record was re-pointed from one open task to another. Written on BOTH
     * tasks: on the source, so its trail does not simply lose an element without
     * saying where it went; on the target, so the element's arrival is explained.
     * Nothing is lost by the move itself - the workspace version belongs to the
     * record, not to the task - and that is exactly what this entry records.
     */
    public const EVENT_MEMBER_MOVED = 'member_moved';
    /** Same, for a record pulled out into a task created for it on the spot. */
    public const EVENT_MEMBER_SPLIT = 'member_split';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Record a decision at the moment it is made.
     *
     * @param array<string, mixed> $payload the essentials, durable
     * @param int $historyUid sys_history row holding the full detail, 0 if none
     * @return int uid of the written entry
     */
    public function log(int $taskUid, string $event, int $beUserId, array $payload = [], int $historyUid = 0): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'task' => $taskUid,
            'event' => $event,
            'be_user' => $beUserId,
            'history_uid' => $historyUid,
            // The array, not a JSON string - Doctrine's JsonType encodes it on
            // the way in. Handing it an already-encoded string encoded it a
            // second time, and every reader then decoded one layer and got a
            // string back - which is why WorkspaceIntegrationService::
            // decodePayload() silently returned nothing and the ticket never
            // showed a stage change's from/to.
            //
            // The type is passed explicitly rather than left for
            // Connection::insert() to infer from the `payload` column's schema
            // (ensureDatabaseValueTypes()): that inference only works when the
            // live database column is recognizable as JSON, which on MariaDB
            // requires a `json_valid` CHECK constraint. MariaDB does not always
            // add that constraint for a `json`-typed column (depends on
            // version/config), and Doctrine then reports the column as plain
            // text - silently skipping the encode and handing mysqli a raw PHP
            // array, which triggers "Array to string conversion". Naming the
            // type here makes the encode independent of what the database
            // happens to report.
            'payload' => $payload,
            'crdate' => $GLOBALS['EXEC_TIME'],
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ], [
            'payload' => Types::JSON,
        ]);

        // Returned so a comment can be anchored to the very entry it explains.
        return (int)$connection->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByTask(int $taskUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        // DeletedRestriction is a no-op here: it only ever adds a constraint for
        // tables it finds in the TCA schema, and this table has none.
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('task', $queryBuilder->createNamedParameter($taskUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('crdate', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Core's stage-change entries for a record, newest first.
     *
     * Used to pick up the sys_history uid of a stage change that core wrote, and to
     * reconcile transitions made outside the board (e.g. in the Workspaces module),
     * which Editorial Flow never sees happen.
     *
     * Pass the version uid while the version lives, or the live uid after publishing -
     * core migrates `recuid` to the live uid at publish time, so both are correct at
     * their respective moment.
     *
     * @return list<array<string, mixed>>
     */
    public function findStageChanges(string $table, int $recordUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_history');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'userid', 'tstamp', 'history_data')
            ->from('sys_history')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq(
                    'actiontype',
                    $queryBuilder->createNamedParameter(RecordHistoryStore::ACTION_STAGECHANGE, Connection::PARAM_INT),
                ),
            )
            ->orderBy('tstamp', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
