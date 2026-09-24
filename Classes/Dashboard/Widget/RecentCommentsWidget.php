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
 * The one dashboard widget xima/xima-typo3-content-planner has that Editorial Flow
 * did not: a feed to catch up on discussion without opening every ticket.
 *
 * The other three xima widgets (ContentUpdateWidget, ConfigurableContentStatusWidget)
 * were deliberately not copied - RecentActivityWidget already covers "what recently
 * changed", and TaskOverviewWidget already covers "how much sits in each state".
 * xima's status widget is configurable because its status list is open-ended and
 * user-defined; Editorial Flow's states are a fixed, small set, so that knob would
 * configure nothing meaningful. This one had no equivalent at all.
 */
final readonly class RecentCommentsWidget implements WidgetRendererInterface
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
                default: (int)($this->options['limit'] ?? 10),
                label: 'Number of comments',
                readonly: array_key_exists('limit', $this->options),
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $limit = $context->settings->has('limit')
            ? (int)$context->settings->get('limit')
            : (int)($this->options['limit'] ?? 10);

        $view = $this->backendViewFactory->create($context->request, ['gb-web/editorial-flow']);
        $view->assignMultiple([
            'comments' => $this->findRecent($limit),
            'configuration' => $this->configuration,
        ]);

        return new WidgetResult(
            content: $view->render('Dashboard/RecentComments'),
            refreshable: true,
        );
    }

    /**
     * One query for the comments, then one for their tasks - not one join, since
     * the task title needs BackendUtility::getRecordTitle()-equivalent resolution
     * that a plain SQL join cannot give us, and not N+1 queries for N comments.
     *
     * Only comments on tasks the viewer may read (TaskReadAccess): a widget is
     * granted per group, and before this it showed every comment on every
     * task of the installation to anyone it was granted to.
     *
     * @return list<array<string, mixed>>
     */
    private function findRecent(int $limit): array
    {
        $comments = $this->taskReadAccess->firstReadable(
            $GLOBALS['BE_USER'],
            fn (int $offset, int $count): array => $this->fetchComments($offset, $count),
            $limit,
        );

        if ($comments === []) {
            return [];
        }

        $taskUids = array_values(array_unique(array_map(static fn (array $row): int => (int)$row['task'], $comments)));
        $taskTitles = $this->findTaskTitles($taskUids);

        foreach ($comments as &$comment) {
            $comment['taskTitle'] = $taskTitles[(int)$comment['task']] ?? sprintf('Task #%d', $comment['task']);
        }

        return $comments;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchComments(int $offset, int $count): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_editorialflow_comment');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from('tx_editorialflow_comment')
            ->orderBy('crdate', 'DESC')
            // A tie-breaker, so paging through the feed never repeats or skips
            // comments posted in the same second.
            ->addOrderBy('uid', 'DESC')
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
