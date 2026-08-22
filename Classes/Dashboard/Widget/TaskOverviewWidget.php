<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Dashboard\Widget;

use GbWeb\EditorialFlow\Domain\Model\TaskState;
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
 * How much work sits in each state, plus what nobody has picked up yet.
 *
 * The unassigned count is deliberately prominent: planning allows leaving a task
 * open so an editor can take it, and that only works if "up for grabs" is visible.
 */
final readonly class TaskOverviewWidget implements WidgetRendererInterface
{
    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private BackendViewFactory $backendViewFactory,
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return SettingDefinition[]
     */
    public function getSettingsDefinitions(): array
    {
        return [];
    }

    /**
     * Assigned as one `stats` array, which is what the template has always read.
     *
     * It used to be assigned as `countsByState` and `unassigned`, so every
     * number in the template resolved to nothing and the widget rendered six
     * empty slots - including the unassigned count, which this class's docblock
     * calls deliberately prominent and which the template never mentioned at
     * all. Flattened here rather than teaching the template to index a nested
     * array, so the two names cannot drift apart again without a test noticing.
     */
    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $countsByState = $this->countByState();

        $view = $this->backendViewFactory->create($context->request, ['gb-web/editorial-flow']);
        $view->assignMultiple([
            'stats' => [
                'total_open' => array_sum($countsByState),
                // Not from countByState(): that counts open tasks only, and a
                // task in DONE is by definition closed, so "done" could never
                // have appeared there however the template asked for it.
                'done' => $this->countClosed(),
                'backlog' => $countsByState[TaskState::BACKLOG->value] ?? 0,
                'planned' => $countsByState[TaskState::PLANNED->value] ?? 0,
                'in_progress' => $countsByState[TaskState::IN_PROGRESS->value] ?? 0,
                'review' => ($countsByState[TaskState::REVIEW->value] ?? 0)
                    + ($countsByState[TaskState::READY->value] ?? 0),
                'unassigned' => $this->countUnassigned(),
            ],
            'configuration' => $this->configuration,
        ]);

        return new WidgetResult(
            content: $view->render('Dashboard/TaskOverview'),
            refreshable: true,
        );
    }

    /**
     * One grouped query rather than one per column.
     *
     * @return array<string, int>
     */
    private function countByState(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_editorialflow_task');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $rows = $queryBuilder
            ->select('state')
            ->addSelectLiteral($queryBuilder->expr()->count('uid', 'amount'))
            ->from('tx_editorialflow_task')
            ->where($queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->groupBy('state')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row['state']] = (int)$row['amount'];
        }

        return $counts;
    }

    /**
     * Finished work, which lives on the other side of `closed` from everything
     * countByState() reports.
     */
    private function countClosed(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_editorialflow_task');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return (int)$queryBuilder
            ->count('uid')
            ->from('tx_editorialflow_task')
            ->where($queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    private function countUnassigned(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_editorialflow_task');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return (int)$queryBuilder
            ->count('uid')
            ->from('tx_editorialflow_task')
            ->where(
                $queryBuilder->expr()->eq('assignee', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }
}
