<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Dashboard\Widget;

use GbWeb\EditorialFlow\Service\TaskReadAccess;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;

/**
 * What happened lately across the editorial board: stage moves, assignments,
 * comments, closures.
 *
 * Reads tx_editorialflow_activity rather than sys_history on purpose - the activity
 * trail is the durable one (sys_history is garbage-collected after 30 days by
 * default), and it holds the editorial decisions rather than field-level noise.
 */
final readonly class RecentActivityWidget implements WidgetRendererInterface
{
    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private BackendViewFactory $backendViewFactory,
        private ConnectionPool $connectionPool,
        private TaskReadAccess $taskReadAccess,
        /** @var array{limit?: int} */
        private array $options = [],
    ) {
    }

    /**
     * @return SettingDefinition[]
     */
    public function getSettingsDefinitions(): array
    {
        return [
            new SettingDefinition(
                key: 'limit',
                type: 'int',
                default: (int)($this->options['limit'] ?? 15),
                label: 'Number of entries',
                readonly: array_key_exists('limit', $this->options),
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        // SettingsInterface extends ContainerInterface, so get() takes no default -
        // has() must be checked first.
        $limit = $context->settings->has('limit')
            ? (int)$context->settings->get('limit')
            : (int)($this->options['limit'] ?? 15);

        $view = $this->backendViewFactory->create($context->request, ['gb-web/editorial-flow']);
        $view->assignMultiple([
            'activities' => $this->findRecent($limit),
            'configuration' => $this->configuration,
        ]);

        return new WidgetResult(
            content: $view->render('Dashboard/RecentActivity'),
            refreshable: true,
        );
    }

    /**
     * Only entries of tasks the viewer may read (TaskReadAccess): a widget is
     * granted per group, and before this it showed every decision on every
     * task of the installation to anyone it was granted to.
     *
     * @return list<array<string, mixed>>
     */
    private function findRecent(int $limit): array
    {
        $activities = $this->taskReadAccess->firstReadable(
            $GLOBALS['BE_USER'],
            fn (int $offset, int $count): array => $this->fetchActivities($offset, $count),
            $limit,
        );

        if ($activities === []) {
            return [];
        }

        // The template shows which task each entry belongs to
        // (Resources/Private/Templates/Dashboard/RecentActivity.html references
        // `activity.task_title`), but nothing here ever provided it - the column
        // does not exist on tx_editorialflow_activity, so it silently rendered
        // empty. One batch query for the titles, not one per row.
        $taskUids = array_values(array_unique(array_map(static fn (array $row): int => (int)$row['task'], $activities)));
        $taskTitles = $this->findTaskTitles($taskUids);

        foreach ($activities as &$activity) {
            $activity['task_title'] = $taskTitles[(int)$activity['task']] ?? sprintf('Task #%d', $activity['task']);
        }

        return $activities;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchActivities(int $offset, int $count): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_editorialflow_activity');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('a.*')
            ->from('tx_editorialflow_activity', 'a')
            ->orderBy('a.crdate', 'DESC')
            // A tie-breaker, so paging through the feed never repeats or skips
            // entries written in the same second.
            ->addOrderBy('a.uid', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($count)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param list<int> $taskUids
     * @return array<int, string>
     */
    private function findTaskTitles(array $taskUids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_editorialflow_task');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $rows = $queryBuilder
            ->select('uid', 'title')
            ->from('tx_editorialflow_task')
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter(
                $taskUids,
                Connection::PARAM_INT_ARRAY,
            )))
            ->executeQuery()
            ->fetchAllAssociative();

        $titles = [];
        foreach ($rows as $row) {
            $titles[(int)$row['uid']] = (string)$row['title'];
        }

        return $titles;
    }
}
