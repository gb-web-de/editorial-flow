<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Hooks;

use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Service\TaskMemberSynchronizer;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A record CREATED inside a workspace is as much pending work as an edited
 * one, and has to reach the board the same way.
 *
 * It did not: TaskAutoCreationService::captureEdit() returned on every status
 * but 'update', so the one thing a coach does most - write a new blog post,
 * by hand or through EXT:handball's match report module, which creates the
 * page and its content in one DataHandler call inside the team workspace -
 * never opened a task. The post sat in the workspace, invisible on the board,
 * until somebody happened to edit it a second time.
 *
 * Driven through DataHandler with NEW... keys, the same way the page tree's
 * "new page" and every programmatic import create records.
 */
final class NewRecordInWorkspaceTest extends FunctionalTestCase
{
    /**
     * @var string[]
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-workspaces',
        'typo3/cms-dashboard',
    ];

    /**
     * @var string[]
     */
    protected array $testExtensionsToLoad = [
        'gb-web/editorial-flow',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function creatingAPageInAWorkspaceOpensATaskForIt(): void
    {
        $pageUid = $this->createBlogPostInWorkspace('Match report: C1 wins at home');

        $tasks = $this->openTasks();
        self::assertCount(1, $tasks);
        self::assertSame('pages', $tasks[0]['subject_table']);
        self::assertSame($pageUid, (int)$tasks[0]['subject_uid']);
        self::assertSame(1, (int)$tasks[0]['workspace_uid']);
        self::assertSame('Match report: C1 wins at home', $tasks[0]['title']);
        self::assertSame(1, (int)$tasks[0]['auto_created']);
    }

    /**
     * Page and content arrive in ONE datamap, the way the match report module
     * writes them. The content has to end up on the page's card, not on cards
     * of its own and not on no card at all.
     */
    #[Test]
    public function contentCreatedWithThePageJoinsThePagesTask(): void
    {
        $pageUid = $this->createBlogPostInWorkspace('Match report', ['First half', 'Second half']);

        $taskUid = (int)$this->openTasks()[0]['uid'];
        $members = array_map(
            static fn (array $member): string => $member['record_table'] . ':' . $member['record_uid'],
            $this->get(TaskRepository::class)->findMembers($taskUid),
        );

        self::assertContains('pages:' . $pageUid, $members);
        self::assertCount(2, array_filter($members, static fn (string $m): bool => str_starts_with($m, 'tt_content:')));
        self::assertCount(1, $this->openTasks(), 'content must not open cards of its own');
    }

    /**
     * The board can only move or publish what it recognises as pending. A
     * workspace-born record is its own version (core keeps it as one row with
     * t3ver_state 1), so it has to count - otherwise the new card could never
     * leave the Editing column.
     */
    #[Test]
    public function aNewPagesTaskHasItsRecordsPending(): void
    {
        $pageUid = $this->createBlogPostInWorkspace('Match report', ['Body']);
        $taskUid = (int)$this->openTasks()[0]['uid'];

        $pairs = $this->get(TaskMemberSynchronizer::class)->findPendingVersionPairsByTable($taskUid, 1);

        self::assertSame([['live' => $pageUid, 'version' => $pageUid]], $pairs['pages'] ?? null);
        self::assertCount(1, $pairs['tt_content'] ?? []);
    }

    /**
     * Nobody is sitting in front of a form waiting for a follow-up question
     * when a record is created programmatically, and new content on a page
     * belongs to that page's task without asking.
     */
    #[Test]
    public function creatingARecordQueuesNoFollowUpWizard(): void
    {
        $this->createBlogPostInWorkspace('Match report', ['Body']);

        self::assertNull($GLOBALS['BE_USER']->getSessionData('editorial_flow_pending_wizard'));
    }

    #[Test]
    public function newContentOnAnExistingPageJoinsThatPagesOpenTaskQuietly(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task')->insert('tx_editorialflow_task', [
            'title' => 'About us',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => 'in_progress',
            'workspace_uid' => 1,
            'assignee' => 1,
            'closed' => 0,
        ]);

        $contentUid = $this->createInWorkspace(['tt_content' => ['NEW1' => ['pid' => 2, 'header' => 'Late addition', 'CType' => 'text']]])['NEW1'];

        $task = $this->get(TaskRepository::class)->findOpenTaskByMember('tt_content', $contentUid);
        self::assertNotNull($task);
        self::assertSame(2, (int)$task['subject_uid']);
        self::assertNull($GLOBALS['BE_USER']->getSessionData('editorial_flow_pending_wizard'));
    }

    #[Test]
    public function creatingAPageOnLiveOpensNoTask(): void
    {
        $GLOBALS['BE_USER']->setWorkspace(0);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => ['NEW1' => ['pid' => 1, 'title' => 'Straight to live']]], []);
        $dataHandler->process_datamap();

        self::assertSame([], $this->openTasks());
    }

    /**
     * @param list<string> $contentHeaders
     */
    private function createBlogPostInWorkspace(string $title, array $contentHeaders = []): int
    {
        $dataMap = ['pages' => ['NEWpage' => ['pid' => 1, 'title' => $title, 'doktype' => 1]]];
        foreach ($contentHeaders as $index => $header) {
            $dataMap['tt_content']['NEWce' . $index] = ['pid' => 'NEWpage', 'header' => $header, 'CType' => 'text'];
        }

        return $this->createInWorkspace($dataMap)['NEWpage'];
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $dataMap
     * @return array<string, int>
     */
    private function createInWorkspace(array $dataMap): array
    {
        $GLOBALS['BE_USER']->setWorkspace(1);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);

        return array_map('intval', $dataHandler->substNEWwithIDs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openTasks(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_task');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder->select('*')->from('tx_editorialflow_task')
            ->where($queryBuilder->expr()->eq('closed', 0))
            ->executeQuery()->fetchAllAssociative();
    }
}
