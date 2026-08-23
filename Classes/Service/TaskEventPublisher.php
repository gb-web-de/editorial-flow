<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use GbWeb\EditorialFlow\Domain\Model\TaskSnapshot;
use GbWeb\EditorialFlow\Event\TaskClosedEvent;
use GbWeb\EditorialFlow\Event\TaskCreatedEvent;
use GbWeb\EditorialFlow\Event\TaskStageChangedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Workspaces\Service\StagesService;

/**
 * Turns a task row into an event the rest of the world can act on.
 *
 * One place, because building the snapshot is the expensive half and every
 * dispatch site would otherwise resolve stage titles, workspace titles and
 * assignees for itself - four call sites, four chances to resolve one of them
 * differently.
 *
 * Nothing here fails loudly. A task event is a side effect of editorial work,
 * and an integration that cannot resolve a title must not be able to stop an
 * editor from moving a card; every lookup therefore degrades to what it knows.
 */
final class TaskEventPublisher
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly StagesService $stagesService,
        private readonly UriBuilder $uriBuilder,
    ) {
    }

    /**
     * @param array<string, mixed> $task
     */
    public function taskCreated(array $task, int $actorUid): void
    {
        $this->eventDispatcher->dispatch(new TaskCreatedEvent(
            $this->snapshot($task),
            (int)($task['auto_created'] ?? 0) === 1,
            $this->resolveActor($actorUid),
        ));
    }

    /**
     * @param array<string, mixed> $taskBefore the row as it was, for the from side
     * @param array<string, mixed> $taskAfter the row as it is now
     */
    public function taskStageChanged(array $taskBefore, array $taskAfter, int $actorUid): void
    {
        $this->eventDispatcher->dispatch(new TaskStageChangedEvent(
            $this->snapshot($taskAfter),
            (string)($taskBefore['state'] ?? ''),
            (int)($taskBefore['stage_uid'] ?? 0),
            $this->stageTitle((int)($taskBefore['stage_uid'] ?? 0)),
            $this->resolveActor($actorUid),
        ));
    }

    /**
     * @param array<string, mixed> $task
     */
    public function taskClosed(array $task, string $reason, int $actorUid): void
    {
        // `closed` is read off the row, and the row handed in here is the one
        // from before the close in at least one call site - so it is forced,
        // rather than reporting a closed task as open on the event that says it
        // was closed.
        $this->eventDispatcher->dispatch(new TaskClosedEvent(
            $this->snapshot(['closed' => 1] + $task),
            $reason,
            $this->resolveActor($actorUid),
        ));
    }

    /**
     * @param array<string, mixed> $task
     */
    private function snapshot(array $task): TaskSnapshot
    {
        $subjectTable = (string)($task['subject_table'] ?? '');
        $subjectUid = (int)($task['subject_uid'] ?? 0);
        $workspaceUid = (int)($task['workspace_uid'] ?? 0);
        $externalSystem = (string)($task['external_system'] ?? '');

        return new TaskSnapshot(
            uid: (int)($task['uid'] ?? 0),
            title: (string)($task['title'] ?? ''),
            description: (string)($task['description'] ?? ''),
            state: (string)($task['state'] ?? ''),
            priority: (int)($task['priority'] ?? 0),
            closed: (int)($task['closed'] ?? 0) === 1,
            stageUid: (int)($task['stage_uid'] ?? 0),
            stageTitle: $this->stageTitle((int)($task['stage_uid'] ?? 0)),
            workspaceUid: $workspaceUid,
            workspaceTitle: $this->workspaceTitle($workspaceUid),
            subjectTable: $subjectTable,
            subjectUid: $subjectUid,
            subjectTitle: $this->subjectTitle($subjectTable, $subjectUid),
            subjectPid: (int)($task['subject_pid'] ?? 0),
            assignee: $this->resolveAssignee((int)($task['assignee'] ?? 0)),
            externalReference: $externalSystem === '' ? null : [
                'system' => $externalSystem,
                'id' => (string)($task['external_ref'] ?? ''),
                'url' => (string)($task['external_url'] ?? ''),
            ],
            boardUrl: $this->boardUrl((int)($task['subject_pid'] ?? 0)),
        );
    }

    /**
     * Is there a language service to resolve a label with?
     *
     * Not defensive decoration. Both title lookups below end up in core methods
     * that reach for $GLOBALS['LANG'] and return-type-error when it is not
     * there - StagesService::getStageTitle() and, one layer down,
     * BackendUtility::getRecordTitle(). That global is simply not set in every
     * context a task is created from. The upgrade wizard is the case that found
     * it: MigrateExistingWorkspaceChangesToTasksUpdate creates tasks through
     * TaskAutoCreationService with no backend session and no language service,
     * and the unguarded version brought the whole migration down with a
     * TypeError thrown from inside core.
     *
     * A task event is a side effect of editorial work and must never be the
     * thing that breaks it. Without a language service the titles are dropped
     * and the uids - which is what a receiver maps on anyway - still go out.
     */
    private function hasLanguageService(): bool
    {
        return ($GLOBALS['LANG'] ?? null) instanceof LanguageService;
    }

    /**
     * Covers core's fixed stages and custom ones alike, by uid - which is the
     * only reason this does not need a workspace to ask about.
     */
    private function stageTitle(int $stageUid): string
    {
        return $this->hasLanguageService() ? $this->stagesService->getStageTitle($stageUid) : '';
    }

    private function workspaceTitle(int $workspaceUid): string
    {
        if ($workspaceUid < 1) {
            return 'Live';
        }

        return (string)(BackendUtility::getRecord('sys_workspace', $workspaceUid, 'title')['title'] ?? '');
    }

    private function subjectTitle(string $table, int $uid): string
    {
        if ($table === '' || $uid < 1 || !$this->hasLanguageService()) {
            return '';
        }
        $record = BackendUtility::getRecord($table, $uid);

        return $record === null ? '' : BackendUtility::getRecordTitle($table, $record);
    }

    /**
     * @return array{uid: int, username: string, email: string}|null
     */
    private function resolveAssignee(int $beUserId): ?array
    {
        if ($beUserId < 1) {
            return null;
        }
        $user = BackendUtility::getRecord('be_users', $beUserId, 'uid,username,email');
        if ($user === null) {
            return null;
        }

        return [
            'uid' => (int)$user['uid'],
            'username' => (string)$user['username'],
            'email' => (string)$user['email'],
        ];
    }

    /**
     * @return array{uid: int, username: string}|null
     */
    private function resolveActor(int $beUserId): ?array
    {
        if ($beUserId < 1) {
            return null;
        }
        $user = BackendUtility::getRecord('be_users', $beUserId, 'uid,username');
        if ($user === null) {
            return null;
        }

        return ['uid' => (int)$user['uid'], 'username' => (string)$user['username']];
    }

    /**
     * The board, scoped to the page the task is about - where a person following
     * a link from Jira or Trello actually wants to end up.
     *
     * Relative, not absolute: this runs in a backend request whose host TYPO3
     * knows, but also from a CLI or a queue worker where it does not, and a
     * webhook carrying "http://localhost/typo3/..." is worse than one carrying a
     * path the receiver can resolve against the site it already knows.
     */
    private function boardUrl(int $pageUid): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('web_editorialflow', ['id' => $pageUid]);
    }
}
