<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Dashboard;

use GbWeb\EditorialFlow\Dashboard\Widget\RecentActivityWidget;
use GbWeb\EditorialFlow\Dashboard\Widget\RecentCommentsWidget;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Service\TaskReadAccess;
use GbWeb\EditorialFlow\Service\TaskWorkspaceScope;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfiguration;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A dashboard widget is granted per backend group, and knows nothing about
 * pages. Before, "Recent comments" and "Recent activity" showed every comment
 * and every decision on every task of the installation to anyone the widget
 * was granted to - a side door past the board, which only shows a task to
 * whoever may see its page.
 *
 * Two sites: the editor has the first mounted, the outsider the second.
 */
final class DashboardWidgetsRespectTaskAccessTest extends FunctionalTestCase
{
    private const EDITOR = 2;
    private const OUTSIDER = 3;

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

    private int $ownTask;
    private int $otherSitesTask;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');

        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('pages')
            ->insert('pages', ['uid' => 3, 'pid' => 0, 'title' => 'Other site', 'doktype' => 1]);
        $pool->getConnectionForTable('pages')
            ->update('pages', ['perms_everybody' => 31], ['deleted' => 0]);
        $groups = $pool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 50, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1', 'tables_select' => 'pages', 'workspace_perms' => 1]);
        $groups->insert('be_groups', ['uid' => 51, 'pid' => 0, 'title' => 'Other site', 'db_mountpoints' => '3', 'tables_select' => 'pages', 'workspace_perms' => 1]);
        $users = $pool->getConnectionForTable('be_users');
        $users->update('be_users', ['usergroup' => '50'], ['uid' => self::EDITOR]);
        $users->insert('be_users', ['uid' => self::OUTSIDER, 'pid' => 0, 'username' => 'outsider', 'usergroup' => '51']);

        $this->ownTask = $this->createTask('About us rework', 2);
        $this->otherSitesTask = $this->createTask('Other site launch', 3);
    }

    #[Test]
    public function recentCommentsShowsOnlyCommentsOnTasksTheViewerMaySee(): void
    {
        $this->comment($this->ownTask, 'Looks good to me.', 100);
        $this->comment($this->otherSitesTask, 'Launch price is 49 EUR.', 200);

        $this->setUpBackendUser(self::EDITOR);
        $content = $this->renderComments(10);
        self::assertStringContainsString('Looks good to me.', $content);
        self::assertStringNotContainsString('49 EUR', $content);
        self::assertStringNotContainsString('Other site launch', $content);

        $this->setUpBackendUser(self::OUTSIDER);
        $content = $this->renderComments(10);
        self::assertStringContainsString('49 EUR', $content);
        self::assertStringNotContainsString('Looks good to me.', $content);
    }

    #[Test]
    public function recentActivityShowsOnlyTasksTheViewerMaySee(): void
    {
        $this->activity($this->ownTask, 100);
        $this->activity($this->otherSitesTask, 200);

        $this->setUpBackendUser(self::EDITOR);
        $content = $this->renderActivity(10);

        self::assertStringContainsString('About us rework', $content);
        self::assertStringNotContainsString('Other site launch', $content);
    }

    /**
     * Filtering only the newest $limit rows would leave the editor an empty
     * widget whenever the other site was busier lately. The widget reads on
     * until it has $limit rows the viewer may see.
     */
    #[Test]
    public function aBusierOtherSiteDoesNotEmptyTheWidget(): void
    {
        $this->comment($this->ownTask, 'Looks good to me.', 100);
        foreach (range(1, 6) as $minute) {
            $this->comment($this->otherSitesTask, 'Other site remark ' . $minute, 200 + $minute);
        }

        $this->setUpBackendUser(self::EDITOR);
        $content = $this->renderComments(1);

        self::assertStringContainsString('Looks good to me.', $content);
        self::assertStringNotContainsString('Other site remark', $content);
    }

    #[Test]
    public function anAdminStillSeesEverything(): void
    {
        $this->comment($this->ownTask, 'Looks good to me.', 100);
        $this->comment($this->otherSitesTask, 'Launch price is 49 EUR.', 200);

        $this->setUpBackendUser(1);
        $content = $this->renderComments(10);

        self::assertStringContainsString('Looks good to me.', $content);
        self::assertStringContainsString('49 EUR', $content);
    }

    private function renderComments(int $limit): string
    {
        $widget = new RecentCommentsWidget(
            new WidgetConfiguration('test', 'test', [], 'Test', '', '', 'medium', 'medium'),
            $this->get(BackendViewFactory::class),
            $this->get(ConnectionPool::class),
            $this->taskReadAccess(),
            ['limit' => $limit],
        );

        return $widget->renderWidget($this->context())->content;
    }

    private function renderActivity(int $limit): string
    {
        $widget = new RecentActivityWidget(
            new WidgetConfiguration('test', 'test', [], 'Test', '', '', 'medium', 'medium'),
            $this->get(BackendViewFactory::class),
            $this->get(ConnectionPool::class),
            $this->taskReadAccess(),
            ['limit' => $limit],
        );

        return $widget->renderWidget($this->context())->content;
    }

    private function taskReadAccess(): TaskReadAccess
    {
        return new TaskReadAccess(new TaskWorkspaceScope($this->get(Context::class)), $this->get(TaskRepository::class));
    }

    private function context(): WidgetContext
    {
        return new WidgetContext(
            'test',
            [],
            new WidgetConfiguration('test', 'test', [], 'Test', '', '', 'medium', 'medium'),
            new Settings([], []),
            new ServerRequest(),
        );
    }

    private function createTask(string $title, int $pageUid): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task');
        $connection->insert('tx_editorialflow_task', [
            'title' => $title,
            'subject_table' => 'pages',
            'subject_uid' => $pageUid,
            'subject_pid' => $pageUid,
            'state' => 'in_progress',
        ]);

        return (int)$connection->lastInsertId();
    }

    private function comment(int $taskUid, string $content, int $crdate): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_comment')->insert(
            'tx_editorialflow_comment',
            ['task' => $taskUid, 'content' => $content, 'crdate' => $crdate],
        );
    }

    private function activity(int $taskUid, int $crdate): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_activity')->insert(
            'tx_editorialflow_activity',
            ['task' => $taskUid, 'event' => 'work_started', 'crdate' => $crdate],
        );
    }
}
