<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Dashboard;

use GbWeb\EditorialFlow\Dashboard\Widget\RecentActivityWidget;
use GbWeb\EditorialFlow\Dashboard\Widget\RecentCommentsWidget;
use GbWeb\EditorialFlow\Dashboard\Widget\TaskOverviewWidget;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfiguration;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Both dashboard widgets join comments/activity back to their task's title so an
 * editor scanning the dashboard knows *which* task a line is about, not just that
 * something happened. RecentActivityWidget's template already referenced
 * `activity.task_title`, but nothing ever populated that key - it silently
 * rendered empty, which reading the code did not catch, only running it did.
 */
final class DashboardWidgetsResolveTaskTitlesTest extends FunctionalTestCase
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
        $this->setUpBackendUser(1);
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

    private function createTask(string $title): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task');
        $connection->insert('tx_editorialflow_task', [
            'title' => $title,
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => 'in_progress',
        ]);

        return (int)$connection->lastInsertId();
    }

    #[Test]
    public function recentActivityWidgetShowsTheTaskTitleItReferences(): void
    {
        $taskUid = $this->createTask('About us');
        $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_activity')->insert(
            'tx_editorialflow_activity',
            ['task' => $taskUid, 'event' => 'work_started', 'crdate' => time()],
        );

        $widget = new RecentActivityWidget(
            new WidgetConfiguration('test', 'test', [], 'Test', '', '', 'medium', 'medium'),
            $this->get(BackendViewFactory::class),
            $this->get(ConnectionPool::class),
        );

        $content = $widget->renderWidget($this->context())->content;

        self::assertStringContainsString('About us', $content, 'the task title must appear, not just the event name');
    }

    #[Test]
    public function recentCommentsWidgetShowsTheTaskTitleItReferences(): void
    {
        $taskUid = $this->createTask('Products');
        $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_comment')->insert(
            'tx_editorialflow_comment',
            ['task' => $taskUid, 'content' => 'Looks good to me.', 'crdate' => time()],
        );

        $widget = new RecentCommentsWidget(
            new WidgetConfiguration('test', 'test', [], 'Test', '', '', 'medium', 'medium'),
            $this->get(BackendViewFactory::class),
            $this->get(ConnectionPool::class),
        );

        $content = $widget->renderWidget($this->context())->content;

        self::assertStringContainsString('Products', $content);
        self::assertStringContainsString('Looks good to me.', $content);
    }

    /**
     * The same class of bug as the two above, in the widget an editor is most
     * likely to look at first: the template read `stats.*` while the widget
     * assigned `countsByState` and `unassigned`, so all six figures rendered
     * empty. Nothing caught it, because asserting that a template renders is
     * not the same as asserting what it renders.
     */
    #[Test]
    public function taskOverviewWidgetRendersRealNumbers(): void
    {
        // Written out rather than using createTask(), which fixes state to
        // in_progress - the point here is that each state lands in its own row.
        $this->createTaskWith(['title' => 'Backlog one', 'state' => 'backlog']);
        $this->createTaskWith(['title' => 'Backlog two', 'state' => 'backlog']);
        $this->createTaskWith(['title' => 'Being written', 'state' => 'in_progress', 'assignee' => 1]);
        $this->createTaskWith(['title' => 'Finished', 'state' => 'done', 'closed' => 1]);

        $widget = new TaskOverviewWidget(
            new WidgetConfiguration('test', 'test', [], 'Test', '', '', 'medium', 'medium'),
            $this->get(BackendViewFactory::class),
            $this->get(ConnectionPool::class),
        );

        $content = $widget->renderWidget($this->context())->content;

        self::assertMatchesRegularExpression(
            '/editorialflow-widget-stat-value">\s*3\s*</',
            $content,
            'three tasks are open - the closed one is not',
        );
        self::assertMatchesRegularExpression(
            '/editorialflow-widget-stat-value--done">\s*1\s*</',
            $content,
            'and "done" has to be counted on the closed side, where it actually lives',
        );
        self::assertMatchesRegularExpression('/Backlog<\/span><strong>2<\/strong>/', $content);
        self::assertMatchesRegularExpression('/In progress<\/span><strong>1<\/strong>/', $content);
        // Documented as deliberately prominent, and previously not rendered at
        // all. Two of the three open tasks have no assignee; the closed one does
        // not count, since nobody can pick up finished work.
        self::assertMatchesRegularExpression('/Unassigned<\/span><strong>2<\/strong>/', $content);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createTaskWith(array $overrides): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task');
        $connection->insert('tx_editorialflow_task', array_merge([
            'title' => 'Task',
            'subject_table' => 'pages',
            'subject_uid' => 1,
            'subject_pid' => 1,
            'state' => 'backlog',
            'closed' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }
}
