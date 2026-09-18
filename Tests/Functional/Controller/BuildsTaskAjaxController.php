<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Controller;

use GbWeb\EditorialFlow\Controller\TaskAjaxController;
use GbWeb\EditorialFlow\Domain\Repository\CommentRepository;
use GbWeb\EditorialFlow\Domain\Repository\TaskChecklistRepository;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Notification\AssignmentNotificationService;
use GbWeb\EditorialFlow\Service\ActiveTaskSession;
use GbWeb\EditorialFlow\Service\ActivityLogger;
use GbWeb\EditorialFlow\Service\PendingPageHandoff;
use GbWeb\EditorialFlow\Service\PendingSubjectHandoff;
use GbWeb\EditorialFlow\Service\RecordCreationTargetProvider;
use GbWeb\EditorialFlow\Service\ReferenceInspector;
use GbWeb\EditorialFlow\Service\StageTransitionService;
use GbWeb\EditorialFlow\Service\TaskEventPublisher;
use GbWeb\EditorialFlow\Service\TaskMemberSynchronizer;
use GbWeb\EditorialFlow\Service\TaskPublishGate;
use GbWeb\EditorialFlow\Service\TaskSubjectRegistry;
use GbWeb\EditorialFlow\Service\WorkspaceConflictDetector;
use GbWeb\EditorialFlow\Service\WorkspaceIntegrationService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\DiffUtility;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Workspaces\Authorization\WorkspacePublishGate;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceRepository;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceStageRepository;
use TYPO3\CMS\Workspaces\Service\HistoryService;
use TYPO3\CMS\Workspaces\Service\StagesService;

/**
 * Builds TaskAjaxController the way a functional test needs it.
 *
 * Symfony's ->get() has no "construct with one argument overridden" operation,
 * so a test that wants its own logger has to rebuild the controller by hand.
 * Everything else comes from the container where it is public;
 * CommentRepository and WorkspaceIntegrationService are not (neither is
 * injected anywhere but this controller), so those two are constructed directly
 * from public core services rather than made public to satisfy a test.
 *
 * Extracted because three test cases needed the same thirty lines. It is a
 * trait rather than a base class so a test keeps FunctionalTestCase as its
 * parent and stays free to extend something else later.
 */
trait BuildsTaskAjaxController
{
    private function buildTaskAjaxController(?LoggerInterface $logger = null): TaskAjaxController
    {
        $connectionPool = $this->get(ConnectionPool::class);
        $taskRepository = $this->get(TaskRepository::class);
        $checklistRepository = $this->get(TaskChecklistRepository::class);
        $activityLogger = $this->get(ActivityLogger::class);

        return new TaskAjaxController(
            $taskRepository,
            new CommentRepository($connectionPool),
            $checklistRepository,
            $this->get(TaskSubjectRegistry::class),
            $this->get(TaskMemberSynchronizer::class),
            $this->get(ReferenceInspector::class),
            $activityLogger,
            $this->get(ActiveTaskSession::class),
            $this->get(PendingPageHandoff::class),
            $this->get(PendingSubjectHandoff::class),
            $this->get(RecordCreationTargetProvider::class),
            $this->get(AssignmentNotificationService::class),
            new WorkspaceIntegrationService(
                $connectionPool,
                $taskRepository,
                $checklistRepository,
                $activityLogger,
                $this->get(HistoryService::class),
                $this->get(IconFactory::class),
                $this->get(WorkspaceStageRepository::class),
                $this->get(WorkspaceRepository::class),
                $this->get(StagesService::class),
                $this->get(WorkspaceConflictDetector::class),
                $this->get(TcaSchemaFactory::class),
                $this->get(DiffUtility::class),
            ),
            // Constructed rather than fetched: TaskPublishGate is private like
            // every other service of this extension, and the core gate it wraps
            // is public already.
            new TaskPublishGate($this->get(WorkspacePublishGate::class)),
            $this->get(StageTransitionService::class),
            $this->get(TaskEventPublisher::class),
            $this->get(StagesService::class),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
            $logger ?? new NullLogger(),
            $this->get(WorkspaceConflictDetector::class),
        );
    }
}
