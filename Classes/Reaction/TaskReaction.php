<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Reaction;

use GbWeb\EditorialFlow\Domain\Repository\CommentRepository;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Service\ActivityLogger;
use GbWeb\EditorialFlow\Service\TaskEventPublisher;
use GbWeb\EditorialFlow\Service\TaskSubjectRegistry;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Reactions\Model\ReactionInstruction;
use TYPO3\CMS\Reactions\Reaction\ReactionInterface;

/**
 * The incoming half of the Jira/Trello connection: an external system creates,
 * comments on or closes a task here.
 *
 * This is a publicly reachable endpoint - core's ReactionResolver middleware
 * authenticates the request by the reaction's own secret and runs it as the
 * backend user configured on the sys_reaction record - so everything it is
 * handed is treated as hostile input:
 *
 * - The subject table is checked against TaskSubjectRegistry, never taken as
 *   given. Same rule as every ajax endpoint in this extension.
 * - Permission is re-derived for the reaction's user against the real record.
 *   Being able to reach the endpoint is not permission to edit a page.
 * - A task is addressed only by the external reference it was created with,
 *   never by its uid. An external system that could name a task uid could reach
 *   every task in the installation, including ones it has nothing to do with.
 * - Refusals name a stable code for a log and say nothing about what is in the
 *   installation. "That task is not known here" is the whole answer.
 *
 * Retried deliveries are expected - that is what a webhook does when it gets no
 * 2xx - so `create` is idempotent: an external reference that already has an
 * open task returns that task rather than opening a second one.
 *
 * Registered from Configuration/Services.php rather than by the usual
 * Classes/* autowiring, because ReactionInterface lives in
 * typo3/cms-reactions and this extension does not require that package. See
 * that file.
 */
final class TaskReaction implements ReactionInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly TaskRepository $taskRepository,
        private readonly CommentRepository $commentRepository,
        private readonly ActivityLogger $activityLogger,
        private readonly TaskSubjectRegistry $subjectRegistry,
        private readonly TaskEventPublisher $taskEventPublisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getType(): string
    {
        return 'editorial-flow-task';
    }

    public static function getDescription(): string
    {
        return 'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:reaction.task';
    }

    public static function getIconIdentifier(): string
    {
        return 'actions-exchange';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function react(ServerRequestInterface $request, array $payload, ReactionInstruction $reaction): ResponseInterface
    {
        $action = (string)($payload['action'] ?? '');

        return match ($action) {
            'create' => $this->createTask($payload),
            'comment' => $this->commentOnTask($payload),
            'close' => $this->closeTask($payload),
            default => $this->refuse(
                'unknown-action',
                'Expected "action" to be one of: create, comment, close.',
                ['action' => $action],
            ),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createTask(array $payload): ResponseInterface
    {
        $reference = $this->externalReference($payload);
        if ($reference === null) {
            return $this->refuse(
                'missing-external-reference',
                'Expected "externalSystem" and "externalRef" so this task can be recognised again.',
            );
        }

        // Idempotent by design: a webhook that got no 2xx will send this again,
        // and the second delivery must not put a second card on the board.
        $existing = $this->taskRepository->findOpenByExternalReference($reference['system'], $reference['id']);
        if ($existing !== null) {
            return $this->accept(['task' => (int)$existing['uid'], 'created' => false]);
        }

        $table = (string)($payload['subject']['table'] ?? 'pages');
        $uid = (int)($payload['subject']['uid'] ?? 0);
        $refusal = $this->assertMayEdit($table, $uid);
        if ($refusal !== null) {
            return $refusal;
        }

        $title = trim((string)($payload['title'] ?? ''));
        if ($title === '') {
            return $this->refuse('missing-title', 'A task needs a title.');
        }

        $task = $this->taskRepository->findOrCreateOpenForSubject($table, $uid, [
            'title' => $title,
            'description' => trim((string)($payload['description'] ?? '')),
            'subject_pid' => $table === 'pages' ? $uid : (int)(BackendUtility::getRecord($table, $uid, 'pid')['pid'] ?? 0),
            'state' => 'backlog',
            'external_system' => $reference['system'],
            'external_ref' => $reference['id'],
            'external_url' => $reference['url'],
            // Not auto_created: nobody was editing. This was planned, just not
            // here - which is exactly what the board's "planned" reading means.
            'auto_created' => 0,
        ]);
        $taskUid = (int)$task['uid'];
        $beUserId = $this->backendUserId();

        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_TASK_CREATED, $beUserId, [
            'subjectTable' => $table,
            'subjectUid' => $uid,
            'externalSystem' => $reference['system'],
            'externalRef' => $reference['id'],
        ]);
        $this->taskEventPublisher->taskCreated(
            $this->taskRepository->findByUid($taskUid) ?? $task,
            $beUserId,
        );

        return $this->accept(['task' => $taskUid, 'created' => true], 201);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function commentOnTask(array $payload): ResponseInterface
    {
        $task = $this->findTaskFor($payload);
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $content = trim((string)($payload['content'] ?? ''));
        if ($content === '') {
            return $this->refuse('comment-empty', 'A comment cannot be empty.');
        }

        // Prefixed with where it came from. A comment appearing in the ticket
        // under the reaction's user with no other explanation reads as something
        // a colleague wrote, which is exactly what it is not.
        $reference = $this->externalReference($payload);
        $prefix = $reference === null ? '' : sprintf('[%s %s] ', $reference['system'], $reference['id']);

        $this->commentRepository->add((int)$task['uid'], $prefix . $content, $this->backendUserId());

        return $this->accept(['task' => (int)$task['uid']]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function closeTask(array $payload): ResponseInterface
    {
        $task = $this->findTaskFor($payload);
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $taskUid = (int)$task['uid'];
        $beUserId = $this->backendUserId();

        // Versions still pending are left exactly where they are - the same as
        // TaskCloseMode::KEEP. Discarding an editor's unpublished work because a
        // Jira ticket was dragged to Done is not a decision an external system
        // gets to make.
        $this->taskRepository->close($taskUid, $beUserId);
        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_CLOSED, $beUserId, [
            'reason' => 'external',
            'externalSystem' => (string)($payload['externalSystem'] ?? ''),
            'externalRef' => (string)($payload['externalRef'] ?? ''),
        ]);
        $this->taskEventPublisher->taskClosed($task, 'external', $beUserId);

        return $this->accept(['task' => $taskUid, 'closed' => true]);
    }

    /**
     * The task an external reference points at, or the refusal to send back.
     *
     * By reference only - never by uid. A payload that could name a task uid
     * would let whoever holds the reaction's secret reach every task in the
     * installation, including the ones their system has nothing to do with.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|ResponseInterface
     */
    private function findTaskFor(array $payload): array|ResponseInterface
    {
        $reference = $this->externalReference($payload);
        if ($reference === null) {
            return $this->refuse(
                'missing-external-reference',
                'Expected "externalSystem" and "externalRef" to say which task this is about.',
            );
        }

        $task = $this->taskRepository->findOpenByExternalReference($reference['system'], $reference['id']);
        if ($task === null) {
            // Deliberately the same answer for "never existed" and "already
            // closed": which one it is says something about this installation
            // that the caller has no business learning.
            return $this->refuse(
                'task-not-found',
                'No open task is known for that reference.',
                ['system' => $reference['system'], 'reference' => $reference['id']],
            );
        }

        // Asked again on every action, not only on create. A task carries an
        // external reference for the rest of its life, and the page it is about
        // can change hands - or the reaction's user can lose access to it -
        // long after the task was opened. Commenting and closing are writes on
        // the subject like any other.
        $refusal = $this->assertMayEdit((string)$task['subject_table'], (int)$task['subject_uid']);
        if ($refusal !== null) {
            return $refusal;
        }

        return $task;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{system: string, id: string, url: string}|null
     */
    private function externalReference(array $payload): ?array
    {
        $system = trim((string)($payload['externalSystem'] ?? ''));
        $reference = trim((string)($payload['externalRef'] ?? ''));
        if ($system === '' || $reference === '') {
            return null;
        }

        $url = trim((string)($payload['externalUrl'] ?? ''));

        return [
            // Bounded to what the columns hold, so a long value is truncated
            // here rather than by the database.
            'system' => mb_substr($system, 0, 64),
            'id' => mb_substr($reference, 0, 255),
            'url' => str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
                ? mb_substr($url, 0, 2048)
                : '',
        ];
    }

    /**
     * Same bar as TaskAjaxController::assertMayEdit(): the reaction's user has to
     * be allowed to edit the record a task would be about. Reaching the endpoint
     * is authentication, not authorisation.
     */
    private function assertMayEdit(string $table, int $uid): ?ResponseInterface
    {
        if ($uid < 1) {
            return $this->refuse('missing-record-uid', 'No record was specified.', ['table' => $table]);
        }
        if (!$this->subjectRegistry->isTrackable($table)) {
            return $this->refuse(
                'table-not-trackable',
                'That kind of record cannot be tracked here.',
                ['table' => $table],
            );
        }

        $record = BackendUtility::getRecord($table, $uid);
        if ($record === null) {
            return $this->refuse('record-not-found', 'That record does not exist.', ['table' => $table, 'uid' => $uid]);
        }

        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return $this->refuse('no-backend-user', 'This reaction has no backend user to act as.');
        }

        $page = $table === 'pages' ? $record : BackendUtility::getRecord('pages', (int)($record['pid'] ?? 0));
        $permission = $table === 'pages' ? Permission::PAGE_EDIT : Permission::CONTENT_EDIT;
        if ($page === null || !$backendUser->doesUserHaveAccess($page, $permission)) {
            return $this->refuse(
                'no-edit-permission',
                'The reaction\'s user may not edit that record.',
                ['table' => $table, 'uid' => $uid],
            );
        }
        if (!$backendUser->checkRecordEditAccess($table, $record)->isAllowed) {
            return $this->refuse(
                'record-edit-not-allowed',
                'The reaction\'s user may not edit that record.',
                ['table' => $table, 'uid' => $uid],
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function accept(array $data, int $status = 200): ResponseInterface
    {
        return $this->json(['success' => true] + $data, $status);
    }

    /**
     * @param array<string, mixed> $context for the log only - the caller gets the
     *        code and the sentence, never the installation's internals
     */
    private function refuse(string $code, string $message, array $context = []): ResponseInterface
    {
        $this->logger->notice($code, $context + ['message' => $message, 'reaction' => self::getType()]);

        return $this->json(['success' => false, 'code' => $code, 'message' => $message], 400);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streamFactory->createStream(json_encode($data, JSON_THROW_ON_ERROR)));
    }

    private function backendUserId(): int
    {
        return (int)($this->getBackendUser()?->user['uid'] ?? 0);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }
}
