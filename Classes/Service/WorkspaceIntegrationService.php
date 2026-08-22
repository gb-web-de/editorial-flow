<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use GbWeb\EditorialFlow\Domain\Repository\TaskChecklistRepository;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;
use TYPO3\CMS\Core\DataHandling\TableColumnType;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\DiffGranularity;
use TYPO3\CMS\Core\Utility\DiffUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceRepository;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceStageRepository;
use TYPO3\CMS\Workspaces\Exception\WorkspaceStageNotFoundException;
use TYPO3\CMS\Workspaces\Service\HistoryService;
use TYPO3\CMS\Workspaces\Service\StagesService;

final class WorkspaceIntegrationService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TaskRepository $taskRepository,
        private readonly TaskChecklistRepository $checklistRepository,
        private readonly ActivityLogger $activityLogger,
        private readonly HistoryService $historyService,
        private readonly IconFactory $iconFactory,
        private readonly WorkspaceStageRepository $workspaceStageRepository,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly StagesService $stagesService,
        private readonly WorkspaceConflictDetector $conflictDetector,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly DiffUtility $diffUtility,
    ) {
    }

    /**
     * Aggregates full task details, members, comments, activity log, and field diffs.
     *
     * @return array<string, mixed>|null
     */
    public function getTaskDetails(int $taskUid): ?array
    {
        $task = $this->taskRepository->findByUid($taskUid);
        if ($task === null) {
            return null;
        }

        $subjectTable = (string)$task['subject_table'];
        $subjectUid = (int)$task['subject_uid'];
        $subjectRecord = BackendUtility::getRecord($subjectTable, $subjectUid);

        // A closed task needs the other reader. close() marks every member row
        // closed along with the task, so findMembers() - which filters
        // closed = 0 - returns nothing at all for it, and the ticket claimed
        // "nothing edited yet" over a finished piece of work.
        $isClosed = (int)($task['closed'] ?? 0) === 1;
        $members = $isClosed
            ? $this->taskRepository->findArchivedMembers($taskUid)
            : $this->taskRepository->findMembers($taskUid);
        $warnedMembers = $this->taskRepository->findWarnedMembers($taskUid, (int)$task['subject_pid']);
        $comments = $this->getTaskComments($taskUid);
        $activities = $this->activityLogger->findByTask($taskUid);
        $workspaceUid = (int)$task['workspace_uid'];
        $decoratedMembers = $this->decorateMembers(
            $members,
            (int)$task['subject_pid'],
            $workspaceUid,
            $subjectTable,
            $subjectUid,
        );
        // The subject is always a member of its own task (see
        // TaskRepository::findOrCreateOpenForSubject()), so aggregating diffs across
        // every member already covers the subject too - no separate subject-only
        // call needed, and nothing to deduplicate. Also stamps `hasDiffs` onto each
        // member so Ticket.html can offer a "Diff" jump button only where there is
        // something to jump to.
        $diffs = $isClosed
            ? $this->getArchivedMemberDiffs($decoratedMembers, $workspaceUid, (int)($task['closed_at'] ?? 0))
            : $this->getAggregatedMemberDiffs($decoratedMembers);
        // "Covered records" is meant to show what this task actually did, not
        // every record that merely sits on the page - a page with a dozen
        // untouched content elements would otherwise bury the one that was
        // edited. A member qualifies once it has something to act on (a
        // pending version) or something to show (recorded diff history);
        // untouched members stay counted in $decoratedMembers for the diff
        // aggregation above, they just never reach the view.
        $coveredMembers = array_values(array_filter(
            $decoratedMembers,
            // hasConflict is included on its own: a member claimed onto this
            // task while its only real pending version sits in a different
            // workspace (see WorkspaceConflictDetector's docblock) has no
            // pending version or diff *in this task's own workspace* at all,
            // and hiding it here would defeat "must be shown" for exactly the
            // case this feature exists for.
            static fn (array $member): bool => ($member['hasPendingVersion'] ?? false)
                || ($member['hasDiffs'] ?? false)
                || ($member['hasConflict'] ?? false),
        ));
        // Empty before the task has a version at all - a checklist reviews
        // work against a stage, and there is no stage to review against yet.
        $checklist = $workspaceUid > 0
            ? $this->checklistRepository->findChecklistForTask($taskUid, $workspaceUid, (int)$task['stage_uid'])
            : [];

        $assigneeUser = null;
        $assigneeUid = (int)($task['assignee'] ?? 0);
        if ($assigneeUid > 0) {
            $userRecord = BackendUtility::getRecord('be_users', $assigneeUid, 'uid,username,realName');
            if ($userRecord) {
                $assigneeUser = [
                    'uid' => (int)$userRecord['uid'],
                    'name' => !empty($userRecord['realName']) ? $userRecord['realName'] : $userRecord['username'],
                ];
            }
        }

        return [
            'task' => $task,
            'subject' => [
                'table' => $subjectTable,
                'uid' => $subjectUid,
                'title' => $subjectRecord ? BackendUtility::getRecordTitle($subjectTable, $subjectRecord) : sprintf('%s:%d', $subjectTable, $subjectUid),
            ],
            'assignee' => $assigneeUser,
            'members' => $coveredMembers,
            'warnedCount' => count($warnedMembers),
            'comments' => $comments,
            'activities' => $activities,
            'timeline' => $this->buildTimeline($activities, $comments),
            'diffs' => $diffs,
            'checklist' => $checklist,
        ];
    }

    /**
     * The full change picture for a task: every member's own field diffs, not just
     * the subject's - a page task with several content-element members previously
     * only ever showed the page's own history, silently hiding what actually changed
     * inside those elements.
     *
     * Grouped by member (each member's own diffs already newest-first, per core's
     * HistoryService), not merge-sorted across members: `datetime` here is a
     * site-formatted string from BackendUtility::datetime(), not a raw timestamp,
     * so it cannot be compared reliably across records.
     *
     * Mutates $decoratedMembers in place, stamping `hasDiffs` onto each member -
     * cheaper than a second pass, and keeps "does this member have a diff to jump
     * to" defined in exactly one place.
     *
     * @param list<array<string, mixed>> $decoratedMembers
     * @return list<array{label: string, html: string, user: string, datetime: string, record: string, table: string, uid: int}>
     */
    private function getAggregatedMemberDiffs(array &$decoratedMembers): array
    {
        $diffs = [];
        foreach ($decoratedMembers as &$member) {
            $table = (string)$member['record_table'];
            $uid = (int)$member['record_uid'];
            // The version, never the live uid - see getRecordDiffs(). A member
            // without one has no history of its own to show here, and asking
            // for the live uid's would answer with somebody else's edits.
            $versionUid = (int)($member['versionUid'] ?? 0);
            $memberDiffs = $versionUid > 0 ? $this->getRecordDiffs($table, $versionUid) : [];
            $member['hasDiffs'] = $memberDiffs !== [];
            foreach ($memberDiffs as $diff) {
                $diff['record'] = ($member['title'] ?? '') !== ''
                    ? sprintf('%s (%s:%d)', $member['title'], $table, $uid)
                    : sprintf('%s:%d', $table, $uid);
                $diff['table'] = $table;
                $diff['uid'] = $uid;
                $diffs[] = $diff;
            }
        }
        unset($member);

        return $diffs;
    }

    /**
     * What a finished task changed, read back after its versions are gone.
     *
     * The version uid getAggregatedMemberDiffs() keys on does not survive
     * publishing, so a closed task has to be read a different way - and the
     * obvious different way is wrong. Passing the LIVE uid to core's
     * HistoryService looks like it would work, because publishing re-points the
     * version's sys_history rows at the live uid
     * (RecordHistoryStore::migrateWorkspaceHistory()). But that migration
     * rewrites `recuid` only and never touches the `workspace` column, while
     * RecordHistory::findEventsForRecord() filters on the READER's current
     * workspace and always lets `workspace = 0` through. Read from Live, the
     * live uid therefore returns everybody's Live edits, attributed to this
     * task - exactly the defect WorkspaceIntegrationDiffTest exists to prevent.
     *
     * So sys_history is queried directly and scoped by construction: rows for
     * this record, in the workspace this task was finished in, up to the moment
     * it closed. Nothing else can leak in, whatever workspace the reader is
     * sitting in.
     *
     * Only the changed field LABELS are reported, not rendered value diffs. The
     * old and new values are still in history_data, but rendering them needs the
     * record's TCA resolved as it was at the time, and "Header, Text and Page
     * title were changed" already answers what an archive is asked. The full
     * before/after belongs to the live record's own history in TYPO3's History
     * module, which is one click away and cannot go stale.
     *
     * @param list<array<string, mixed>> $decoratedMembers
     * @return list<array{label: string, html: string, user: string, datetime: string, record: string, table: string, uid: int}>
     */
    private function getArchivedMemberDiffs(array &$decoratedMembers, int $workspaceUid, int $closedAt): array
    {
        $diffs = [];
        foreach ($decoratedMembers as &$member) {
            $table = (string)$member['record_table'];
            $uid = (int)$member['record_uid'];
            // Both uids, because where the history sits depends on how the task
            // ended. Publishing migrates the version's sys_history rows onto the
            // live uid (RecordHistoryStore::migrateWorkspaceHistory); closing
            // with the version left pending does not, so those rows are still
            // filed under the version. A discarded version's rows stay under a
            // uid nothing resolves any more, and are correctly not found here -
            // the work was thrown away, and the trail says so by its absence.
            $recordUids = [$uid];
            $versionUid = (int)($member['versionUid'] ?? 0);
            if ($versionUid > 0 && $versionUid !== $uid) {
                $recordUids[] = $versionUid;
            }

            $memberDiffs = $workspaceUid > 0
                ? $this->getArchivedRecordDiffs($table, $recordUids, $workspaceUid, $closedAt)
                : [];
            $member['hasDiffs'] = $memberDiffs !== [];
            foreach ($memberDiffs as $diff) {
                $diff['record'] = ($member['title'] ?? '') !== ''
                    ? sprintf('%s (%s:%d)', $member['title'], $table, $uid)
                    : sprintf('%s:%d', $table, $uid);
                $diff['table'] = $table;
                $diff['uid'] = $uid;
                $diffs[] = $diff;
            }
        }
        unset($member);

        return $diffs;
    }

    /**
     * One archived record's changed fields, straight out of sys_history.
     *
     * @param list<int> $recordUids the live uid, plus the version's where it is
     *        still resolvable - see the caller for why both are needed
     * @return list<array{label: string, html: string, user: string, datetime: string}>
     */
    private function getArchivedRecordDiffs(string $table, array $recordUids, int $workspaceUid, int $closedAt): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_history');
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select('history_data', 'tstamp', 'userid')
            ->from('sys_history')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->in(
                    'recuid',
                    $queryBuilder->createNamedParameter($recordUids, Connection::PARAM_INT_ARRAY),
                ),
                // Never workspace 0. That is Live's own history, made by anyone
                // at any time, and it is not what this task did.
                $queryBuilder->expr()->eq('workspace', $queryBuilder->createNamedParameter($workspaceUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq(
                    'actiontype',
                    $queryBuilder->createNamedParameter(RecordHistoryStore::ACTION_STAGECHANGE, Connection::PARAM_INT),
                ),
            )
            ->orderBy('uid', 'DESC');

        if ($closedAt > 0) {
            // Whatever happened to the live record after this task ended belongs
            // to whoever did it, not to this archive.
            $queryBuilder->andWhere(
                $queryBuilder->expr()->lte('tstamp', $queryBuilder->createNamedParameter($closedAt, Connection::PARAM_INT)),
            );
        }

        $schema = $this->tcaSchemaFactory->has($table) ? $this->tcaSchemaFactory->get($table) : null;

        $diffs = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $data = json_decode((string)$row['history_data'], true);
            if (!is_array($data) || !is_array($data['newRecord'] ?? null)) {
                continue;
            }

            $user = BackendUtility::getRecord('be_users', (int)$row['userid'], 'username,realName') ?? [];
            $userName = (string)($user['realName'] ?? '') !== ''
                ? (string)$user['realName']
                : (string)($user['username'] ?? '');

            foreach (array_keys($data['newRecord']) as $field) {
                $field = (string)$field;
                $label = $schema !== null && $schema->hasField($field)
                    ? $this->getLanguageService()->sL($schema->getField($field)->getLabel())
                    : $field;

                $diffs[] = [
                    'label' => $label !== '' ? $label : $field,
                    // No rendered markup for an archive - see the docblock above.
                    'html' => '',
                    'user' => $userName,
                    'datetime' => BackendUtility::datetime((int)$row['tstamp']),
                ];
            }
        }

        return $diffs;
    }

    /**
     * Extract field-level diffs (old vs new value) from sys_history entries.
     *
     * Step-by-step change history: one entry per changed field per revision,
     * with core's rendered diff markup.
     *
     * $uid must be the WORKSPACE VERSION's uid, never the live one - the same
     * way core calls this service (Workspaces\Service\GridDataService::getRowDetails()
     * passes `t3ver_oid`'s version, never the live record). Workspace edits write
     * their sys_history rows against the version record, so the version uid is
     * what selects them; the live uid selects the live record's own history
     * instead, and RecordHistory::findEventsForRecord() lets `workspace = 0`
     * rows through unconditionally - even for a backend user sitting inside a
     * workspace. Passed the live uid, this method therefore reports edits made
     * directly in Live, by anyone, at any time, as though they were this task's
     * pending work.
     *
     * @param int $uid the workspace version's uid
     * @return list<array{label: string, html: string, user: string, datetime: string}>
     */
    public function getRecordDiffs(string $table, int $uid): array
    {
        // Delegated to core rather than diffed by hand. The previous
        // implementation read history_data['newFields'] / ['oldFields'], but core
        // writes 'newRecord' / 'oldRecord' - so it silently returned an empty diff
        // every time. Core also resolves processed values, FlexForm content and
        // TCA field labels, none of which a hand-rolled version had.
        //
        // HistoryService is flagged @internal, which is why it is used in exactly
        // this one place: if core changes it, only this method has to follow.
        $history = $this->historyService->getHistory($table, $uid);

        $diffs = [];
        foreach ($history as $entry) {
            $differences = $entry['differences'] ?? [];
            if (!is_array($differences)) {
                continue;
            }
            foreach ($differences as $difference) {
                $diffs[] = [
                    'label' => (string)($difference['label'] ?? ''),
                    // Already-rendered diff markup from core's DiffUtility.
                    'html' => (string)($difference['html'] ?? ''),
                    'user' => (string)($entry['user_realName'] ?: $entry['user'] ?? ''),
                    'datetime' => (string)($entry['datetime'] ?? ''),
                ];
            }
        }

        return $diffs;
    }

    /**
     * Get eligible backend user recipients for stage change notifications.
     *
     * @return list<array{uid: int, username: string, name: string}>
     */
    public function getStageRecipients(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $users = $queryBuilder
            ->select('uid', 'username', 'realName')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0)))
            ->orderBy('username', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $recipients = [];
        foreach ($users as $u) {
            $recipients[] = [
                'uid' => (int)$u['uid'],
                'username' => (string)$u['username'],
                'name' => !empty($u['realName']) ? (string)$u['realName'] : (string)$u['username'],
            ];
        }

        return $recipients;
    }

    /**
     * Turn the workspace dialog payload into the recipient array core expects.
     *
     * TYPO3's send-to-stage dialog submits backend user ids plus free-text email
     * addresses. DataHandler, however, records and mails a list of recipient
     * descriptors (`email`, optionally `lang`). Matching core's merge here keeps
     * the task board on the same contract as the native workspace popup.
     *
     * @param list<int> $selectedRecipientUids
     * @return list<array{email: string, lang?: string}>
     */
    public function buildNotificationRecipients(
        int $workspaceUid,
        int $stageUid,
        array $selectedRecipientUids,
        string $additionalRecipients,
    ): array {
        $additional = $this->normalizeAdditionalRecipients($additionalRecipients);
        if ($workspaceUid < 1) {
            return array_values($additional);
        }

        try {
            $workspaceRecord = $this->workspaceRepository->findByUid($workspaceUid);
            $stageRecord = $this->stagesService->getStage(
                $this->workspaceStageRepository->findAllStagesByWorkspace($this->getBackendUser(), $workspaceRecord),
                $stageUid,
            );
        } catch (\RuntimeException|WorkspaceStageNotFoundException) {
            return array_values($additional);
        }

        $selected = [];
        $allowedRecipients = $this->stagesService->getResponsibleBeUser($stageRecord);
        foreach ($selectedRecipientUids as $backendUserId) {
            $recipient = $this->recipientFromBackendUser($allowedRecipients[$backendUserId] ?? null);
            if ($recipient !== null) {
                $selected[$recipient['email']] = $recipient;
            }
        }

        if (!$stageRecord->isPreselectionChangeable && $stageRecord->preselectedRecipients !== []) {
            foreach ($this->stagesService->getBackendUsers($stageRecord->preselectedRecipients) as $backendUser) {
                $recipient = $this->recipientFromBackendUser($backendUser);
                if ($recipient !== null) {
                    $selected[$recipient['email']] = $recipient;
                }
            }
        }

        return array_values(array_merge($additional, $selected));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getTaskComments(int $taskUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_editorialflow_comment');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from('tx_editorialflow_comment')
            ->where($queryBuilder->expr()->eq('task', $queryBuilder->createNamedParameter($taskUid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
            ->orderBy('crdate', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Turn raw membership rows into something a ticket view can show: what the
     * record actually is, what it is called, and whether touching it reaches
     * beyond this page.
     *
     * Records can be pages just as well as content elements, so the icon is
     * resolved per record instead of assumed - that is the only honest way to
     * show a mixed member list.
     *
     * @param list<array<string, mixed>> $members
     * @return list<array<string, mixed>>
     */
    private function decorateMembers(
        array $members,
        int $subjectPid,
        int $workspaceUid,
        string $subjectTable = '',
        int $subjectUid = 0,
    ): array {
        // One conflict check for the whole ticket, not one per member - same
        // batching rule as PageModuleEventListener/EditorialFlowController.
        $liveUidsByTable = [];
        foreach ($members as $member) {
            $liveUidsByTable[(string)$member['record_table']][] = (int)$member['record_uid'];
        }
        $conflicts = $this->conflictDetector->findConflicts($liveUidsByTable);

        $decorated = [];
        foreach ($members as $member) {
            $table = (string)$member['record_table'];
            $uid = (int)$member['record_uid'];
            $record = BackendUtility::getRecord($table, $uid);

            $workspaceUidsInConflict = $conflicts[$table][$uid] ?? null;
            $member['hasConflict'] = $workspaceUidsInConflict !== null;
            if ($workspaceUidsInConflict !== null) {
                $otherWorkspaceUids = array_values(array_diff($workspaceUidsInConflict, [$workspaceUid]));
                $titles = $this->conflictDetector->resolveWorkspaceTitles($otherWorkspaceUids ?: $workspaceUidsInConflict);
                $member['conflictLabel'] = implode(', ', $titles);
            }

            $homePid = (int)($member['home_pid'] ?? 0);
            $member['title'] = $record !== null
                ? BackendUtility::getRecordTitle($table, $record)
                : sprintf('%s:%d', $table, $uid);
            $member['icon'] = $record !== null
                ? $this->iconFactory->getIconForRecord($table, $record, IconSize::SMALL)->render()
                : '';
            $member['isForeign'] = $homePid > 0 && $homePid !== $subjectPid;
            $member['isShared'] = (int)($member['shared'] ?? 0) === 1;
            $member['needsAttention'] = $member['isForeign'] || $member['isShared'];
            // Gates the preview/discard buttons: neither makes sense for a
            // record with nothing pending (e.g. the page subject itself,
            // before anyone has touched it in this workspace).
            //
            // The uid itself is kept, not just the yes/no: getAggregatedMemberDiffs()
            // needs exactly this record to read history from, and resolving it twice
            // would let the two answers drift apart.
            $version = $workspaceUid > 0
                ? BackendUtility::getWorkspaceVersionOfRecord($workspaceUid, $table, $uid, 'uid')
                : false;
            $member['versionUid'] = is_array($version) ? (int)$version['uid'] : 0;
            $member['hasPendingVersion'] = $member['versionUid'] > 0;
            // Gates the split/move buttons instead: a task's own subject cannot
            // be pulled out of itself, and TaskAjaxController::detachAction()
            // says so with `cannot-split-task-from-itself`. A button that is
            // certain to be refused does not belong in the ticket at all.
            $member['isSubject'] = $subjectTable !== '' && $table === $subjectTable && $uid === $subjectUid;
            $decorated[] = $member;
        }

        return $decorated;
    }

    /**
     * One chronological stream of what happened, with each comment attached to the
     * action it explains.
     *
     * Comments and actions are not separate stories. A stage comment *is* the
     * reason for that stage change, so showing them in two disconnected lists
     * forces the reader to correlate timestamps by hand. Anything anchored to an
     * activity is nested under it; genuinely standalone remarks stay as their own
     * entries.
     *
     * @param list<array<string, mixed>> $activities
     * @param list<array<string, mixed>> $comments
     * @return list<array<string, mixed>>
     */
    private function buildTimeline(array $activities, array $comments): array
    {
        $commentsByActivity = [];
        $standalone = [];
        foreach ($comments as $comment) {
            $anchor = (int)($comment['activity'] ?? 0);
            if ($anchor > 0) {
                $commentsByActivity[$anchor][] = $comment;
            } else {
                $standalone[] = $comment;
            }
        }

        $timeline = [];
        foreach ($activities as $activity) {
            $uid = (int)$activity['uid'];
            $timeline[] = [
                'type' => 'activity',
                'crdate' => (int)$activity['crdate'],
                'event' => (string)$activity['event'],
                'beUser' => $this->resolveUserName((int)($activity['be_user'] ?? 0)),
                'payload' => $this->decodePayload($activity['payload'] ?? null),
                'historyUid' => (int)($activity['history_uid'] ?? 0),
                'comments' => $commentsByActivity[$uid] ?? [],
            ];
        }
        foreach ($standalone as $comment) {
            $timeline[] = [
                'type' => 'comment',
                'crdate' => (int)$comment['crdate'],
                'beUser' => $this->resolveUserName((int)($comment['be_user'] ?? 0)),
                'content' => (string)($comment['content'] ?? ''),
                'comments' => [],
            ];
        }

        usort($timeline, static fn (array $a, array $b): int => $a['crdate'] <=> $b['crdate']);

        return $timeline;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (!is_string($payload) || $payload === '') {
            return [];
        }
        $decoded = json_decode($payload, true);
        // Rows written before ActivityLogger::log() stopped encoding the array
        // itself are stored one layer deeper (see the comment there). They are
        // archive records - an entry from last year has to stay readable, so the
        // extra layer is peeled rather than the rows rewritten.
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, array{email: string}>
     */
    private function normalizeAdditionalRecipients(string $additionalRecipients): array
    {
        $normalized = [];
        foreach (GeneralUtility::trimExplode(LF, $additionalRecipients, true) as $email) {
            if (!GeneralUtility::validEmail($email)) {
                continue;
            }
            $normalized[$email] = ['email' => $email];
        }

        return $normalized;
    }

    /**
     * Public because the assignment notification builds its recipient the same
     * way: same be_users record shape, same uc/lang fallback.
     *
     * @param array<string, mixed>|null $backendUser
     * @return array{email: string, lang?: string}|null
     */
    public function recipientFromBackendUser(?array $backendUser): ?array
    {
        if (!is_array($backendUser)) {
            return null;
        }

        $email = (string)($backendUser['email'] ?? '');
        if ($email === '' || !GeneralUtility::validEmail($email)) {
            return null;
        }

        $recipient = ['email' => $email];
        $language = '';
        if (!empty($backendUser['uc'])) {
            $userConfiguration = unserialize((string)$backendUser['uc'], ['allowed_classes' => false]);
            if (is_array($userConfiguration) && ($userConfiguration['lang'] ?? '') !== '') {
                $language = (string)$userConfiguration['lang'];
            }
        }
        if ($language === '') {
            $language = (string)($backendUser['lang'] ?? '');
        }
        if ($language !== '') {
            $recipient['lang'] = $language;
        }

        return $recipient;
    }

    /**
     * A backend user's display name, or a stand-in when there is nobody to
     * name. Public because TaskAjaxController needs the same answer for the
     * Visual Editor's task markers, and a second copy of this fallback chain
     * is exactly the kind of thing that drifts apart.
     */
    public function resolveUserName(int $beUserId): string
    {
        if ($beUserId < 1) {
            return 'System';
        }
        $user = BackendUtility::getRecord('be_users', $beUserId, 'username,realName');
        if ($user === null) {
            return 'unknown';
        }

        return trim((string)($user['realName'] ?? '')) !== ''
            ? (string)$user['realName']
            : (string)($user['username'] ?? 'unknown');
    }

    /**
     * The workspace-vs-workspace comparison behind the "Compare versions"
     * button - live value against each conflicting workspace's own pending
     * version of the same record, field by field. Deliberately not built on
     * sys_history (see getRecordDiffs() above): both workspaces' entries
     * interleave there into one undifferentiated trail with no workspace
     * attribution, unsuitable for an A-vs-B comparison. Reuses core's
     * DiffUtility field-by-field exactly the way
     * Workspaces\Service\GridDataService::getRowDetails() does for
     * version-vs-live, just against each workspace's version instead.
     *
     * File, inline and FlexForm fields are left out for now - each needs its
     * own comparison strategy (core's own getRowDetails() has ~80 lines just
     * for file references), and a simple field list already answers "where
     * do I need to look" for the common case. Revisit if usage shows it's
     * not enough.
     *
     * @param list<int> $workspaceUids at least 2, from WorkspaceConflictDetector
     * @return list<array{field: string, label: string, liveValue: string, cells: list<array{changed: bool, html: string}>, isTrueConflict: bool}>
     */
    public function buildConflictDiff(string $table, int $liveUid, array $workspaceUids): array
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return [];
        }
        $schema = $this->tcaSchemaFactory->get($table);
        $live = BackendUtility::getRecord($table, $liveUid) ?? [];
        if ($live === []) {
            return [];
        }

        $versions = [];
        foreach ($workspaceUids as $workspaceUid) {
            $versions[$workspaceUid] = BackendUtility::getWorkspaceVersionOfRecord($workspaceUid, $table, $liveUid) ?: [];
        }

        $skippedTypes = [
            TableColumnType::PASSTHROUGH,
            TableColumnType::NONE,
            TableColumnType::USER,
            TableColumnType::FILE,
            TableColumnType::INLINE,
            TableColumnType::FLEX,
        ];

        $rows = [];
        foreach (array_keys($live) as $field) {
            if (!$schema->hasField($field)) {
                continue;
            }
            $fieldTypeInformation = $schema->getField($field);
            $isSkippedType = false;
            foreach ($skippedTypes as $skippedType) {
                if ($fieldTypeInformation->isType($skippedType)) {
                    $isSkippedType = true;
                    break;
                }
            }
            if ($isSkippedType) {
                continue;
            }

            $liveValue = (string)(BackendUtility::getProcessedValue($table, $field, (string)($live[$field] ?? ''), 0, true) ?? ($live[$field] ?? ''));

            // One cell per workspace, in the same order as $workspaceUids for
            // every row - Fluid then zips this against the header via plain
            // parallel f:for loops, no dynamic array-key lookups needed.
            $cells = [];
            $changedValues = [];
            $touchedByAnyWorkspace = false;
            foreach ($workspaceUids as $workspaceUid) {
                $versionRawValue = (string)($versions[$workspaceUid][$field] ?? '');
                $changed = $versionRawValue !== (string)($live[$field] ?? '');
                if (!$changed) {
                    // This workspace never touched the field - not part of the story.
                    $cells[] = ['changed' => false, 'html' => ''];
                    continue;
                }
                $touchedByAnyWorkspace = true;
                $versionValue = (string)(BackendUtility::getProcessedValue($table, $field, $versionRawValue, 0, true) ?? $versionRawValue);
                $cells[] = [
                    'changed' => true,
                    'html' => $this->diffUtility->diff(strip_tags($liveValue), strip_tags($versionValue), DiffGranularity::WORD),
                ];
                $changedValues[$workspaceUid] = $versionValue;
            }
            if (!$touchedByAnyWorkspace) {
                continue;
            }

            $rows[] = [
                'field' => $field,
                'label' => $this->getLanguageService()->sL($fieldTypeInformation->getLabel()) ?: $field,
                'liveValue' => $liveValue,
                'cells' => $cells,
                // The genuinely dangerous case: two workspaces changed the SAME
                // field to DIFFERENT values, both away from live - a field only
                // one side touched isn't a conflict in the merge sense, it just
                // needs one human to notice both sides exist (the badges
                // upstream already do that); this flags where a decision is
                // actually needed.
                'isTrueConflict' => count(array_unique($changedValues)) > 1,
            ];
        }

        return $rows;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
