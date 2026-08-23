<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\View;

use GbWeb\EditorialFlow\Service\TaskColor;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Renders the board template for real.
 *
 * This exists because of a bug the whole rest of the suite happily missed: the
 * template carried an HTML comment mentioning a ViewHelper tag in angle brackets.
 * Fluid parses ViewHelper syntax inside HTML comments too, so that opened a node
 * which was never closed, and the module died with a parse error on first open -
 * while every unit and functional test stayed green, because nothing rendered it.
 *
 * Any test that actually renders would have caught it. So: always render.
 */
final class TemplateRendersTest extends FunctionalTestCase
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

    private function render(array $variables): string
    {
        $viewFactory = $this->get(ViewFactoryInterface::class);
        $view = $viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:editorial_flow/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:editorial_flow/Resources/Private/Partials/'],
            layoutRootPaths: ['EXT:editorial_flow/Resources/Private/Layouts/'],
        ));
        $view->assignMultiple($variables);

        return $view->render('EditorialFlow/Index');
    }

    #[Test]
    public function theTemplateParsesAndRendersWithoutAPageSelected(): void
    {
        $output = $this->render(['pageSelected' => false, 'columns' => []]);

        self::assertStringContainsString('Select a page in the page tree', $output);
    }

    #[Test]
    public function theBoardRendersItsColumnsAndCards(): void
    {
        $output = $this->render([
            'pageSelected' => true,
            'columns' => [
                [
                    'key' => 'backlog',
                    'label' => 'Backlog',
                    'state' => 'backlog',
                    'stageUid' => null,
                    'acceptsDrop' => true,
                    'cards' => [
                        [
                            'uid' => 1,
                            'title' => 'About us',
                            'subject_table' => 'pages',
                            'subject_uid' => 2,
                            'state' => 'review',
                            'stage_uid' => 1,
                            'workspace_uid' => 1,
                            'auto_created' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('Backlog', $output);
        self::assertStringContainsString('About us', $output);
        self::assertStringContainsString('pages:2', $output);
        // The badge that marks a task nobody planned.
        self::assertStringContainsString('editorialflow-badge-auto', $output);
        self::assertStringContainsString('data-editorialflow-state="review"', $output);
        self::assertStringContainsString('data-editorialflow-stage="1"', $output);
        self::assertStringContainsString('data-editorialflow-workspace="1"', $output);
        // The live region every board change is announced through.
        self::assertStringContainsString('aria-live="polite"', $output);
    }

    /**
     * BoardColumnRegistry::buildStageColumn() merges every accessible other
     * workspace's own stage chain into one column - on an installation with
     * many workspaces, that inline name list once grew the whole column to
     * the width of the entire board. Past contributingWorkspaceCount > 3,
     * Index.html folds the names behind a details/summary disclosure instead;
     * this proves that branch actually parses and renders (see this class's
     * own docblock for why "always render" is the point of this file), and
     * that the %1$d placeholder in column.workspaceCount is real vsprintf
     * syntax rather than a Fluid-style {0} that silently never substitutes.
     */
    #[Test]
    public function aColumnWithManyContributingWorkspacesCollapsesTheirNamesBehindADisclosure(): void
    {
        $output = $this->render([
            'pageSelected' => true,
            'columns' => [
                [
                    'key' => 'stage-0',
                    'label' => 'Editing',
                    'state' => 'in_progress',
                    'stageUid' => 0,
                    'acceptsDrop' => true,
                    'colorShared' => true,
                    'colorOwn' => false,
                    'style' => '',
                    'contributingWorkspaceTitles' => 'Editorial, Legal, Marketing, Sales, Support',
                    'contributingWorkspaceCount' => 5,
                    'cards' => [],
                ],
            ],
        ]);

        self::assertStringContainsString('editorialflow-column-subtitle--collapsible', $output);
        self::assertStringContainsString('5 workspaces', $output);
        self::assertStringContainsString('Editorial, Legal, Marketing, Sales, Support', $output);
    }

    #[Test]
    public function aColumnWithFewContributingWorkspacesListsThemInline(): void
    {
        $output = $this->render([
            'pageSelected' => true,
            'columns' => [
                [
                    'key' => 'stage-0',
                    'label' => 'Editing',
                    'state' => 'in_progress',
                    'stageUid' => 0,
                    'acceptsDrop' => true,
                    'colorShared' => true,
                    'colorOwn' => false,
                    'style' => '',
                    'contributingWorkspaceTitles' => 'Editorial, Legal',
                    'contributingWorkspaceCount' => 2,
                    'cards' => [],
                ],
            ],
        ]);

        self::assertStringNotContainsString('editorialflow-column-subtitle--collapsible', $output);
        self::assertStringContainsString('Editorial, Legal', $output);
    }

    #[Test]
    public function aPlannedTaskShowsNoAutoBadge(): void
    {
        $output = $this->render([
            'pageSelected' => true,
            'columns' => [
                [
                    'key' => 'planned',
                    'label' => 'Planned',
                    'state' => 'planned',
                    'stageUid' => null,
                    'acceptsDrop' => true,
                    'cards' => [
                        [
                            'uid' => 2,
                            'title' => 'Products',
                            'subject_table' => 'pages',
                            'subject_uid' => 3,
                            'auto_created' => 0,
                        ],
                    ],
                ],
            ],
        ]);

        // Asserted on the auto badge's own class, not on the badge container:
        // the container renders unconditionally, so a looser substring check passed
        // for the wrong reason.
        self::assertStringNotContainsString('editorialflow-badge-auto', $output);
    }

    #[Test]
    public function thePageModuleBannerUsesTheCurrentPageForTaskCreation(): void
    {
        $viewFactory = $this->get(ViewFactoryInterface::class);
        $view = $viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:editorial_flow/Resources/Private/Templates/'],
        ));
        $view->assignMultiple([
            'pageUid' => 2,
            'pageTitle' => 'About us',
            'tasks' => [],
            'activeTaskUid' => 0,
            'boardUrl' => '/typo3/module/web/EditorialFlow?id=2',
        ]);

        $output = $view->render('PageModule/Banner');

        self::assertStringContainsString('data-editorialflow-page="2"', $output);
        self::assertStringContainsString('data-editorialflow-page-title="About us"', $output);
        self::assertStringContainsString('Plan task for this page', $output);
    }

    /**
     * A page normally carries several open tasks - its own plus whatever
     * claimed a content element on it. The banner named one of them, which made
     * the rest invisible to an editor who never opens the board.
     */
    #[Test]
    public function thePageModuleBannerListsEveryTaskAndMarksTheActiveOne(): void
    {
        $viewFactory = $this->get(ViewFactoryInterface::class);
        $view = $viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:editorial_flow/Resources/Private/Templates/'],
        ));
        $view->assignMultiple([
            'pageUid' => 2,
            'pageTitle' => 'About us',
            'tasks' => [
                [
                    'uid' => 7,
                    'title' => 'Rewrite the intro',
                    'state' => 'in_progress',
                    'auto_created' => 0,
                    'hue' => TaskColor::hueFor(7),
                    'isActive' => true,
                    'assigneeName' => 'Erin Editor',
                ],
                [
                    'uid' => 8,
                    'title' => 'Fix the footer',
                    'state' => 'review',
                    'auto_created' => 1,
                    'hue' => TaskColor::hueFor(8),
                    'isActive' => false,
                    'assigneeName' => '',
                ],
            ],
            'activeTaskUid' => 7,
            'boardUrl' => '/typo3/module/web/EditorialFlow?id=2',
        ]);

        $output = $view->render('PageModule/Banner');

        self::assertStringContainsString('Rewrite the intro', $output);
        self::assertStringContainsString('Fix the footer', $output);
        // Colour is never the only signal - the active task says so in words too.
        self::assertStringContainsString('editorialflow-page-banner-task--active', $output);
        self::assertStringContainsString('you are working on this', $output);
        // Each dot carries the same hue the Visual Editor draws that task in.
        self::assertStringContainsString('--editorialflow-task-hue: ' . TaskColor::hueFor(7), $output);
        // Only the unassigned one offers to be taken.
        self::assertSame(1, substr_count($output, 'editorialflow-action-assign'));
    }

    #[Test]
    public function theTicketViewRendersDiffsCommentsAndActivity(): void
    {
        $viewFactory = $this->get(ViewFactoryInterface::class);
        $view = $viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:editorial_flow/Resources/Private/Templates/'],
        ));
        $view->assignMultiple([
            'task' => [
                'uid' => 1,
                'state' => 'in_progress',
                'priority' => 2,
                'workspace_uid' => 1,
                'stage_uid' => 0,
                'auto_created' => 1,
                'description' => 'Rework the intro section.',
                'subject_pid' => 2,
            ],
            'subject' => ['table' => 'pages', 'uid' => 2, 'title' => 'About us'],
            'assignee' => null,
            'editUrl' => '/typo3/record/edit',
            'members' => [
                [
                    'record_table' => 'tt_content', 'record_uid' => 10, 'home_pid' => 2,
                    'title' => 'Intro text', 'icon' => '', 'isForeign' => false,
                    'isShared' => true, 'needsAttention' => true,
                ],
            ],
            'activities' => [['event' => 'work_started', 'crdate' => 1754563200]],
            'timeline' => [
                [
                    'type' => 'activity',
                    'crdate' => 1754563200,
                    'event' => 'stage_changed',
                    'beUser' => 'admin',
                    'payload' => ['from_state' => 'in_progress', 'to_state' => 'review'],
                    'historyUid' => 0,
                    // The comment explaining this very move, anchored to it.
                    'comments' => [['content' => 'Sent back, images are missing.']],
                ],
            ],
            'diffs' => [[
                'label' => 'Header', 'html' => '<ins>new</ins><del>old</del>',
                'user' => 'admin', 'datetime' => '2026-08-07 10:00',
            ]],
            'comments' => [],
        ]);

        $output = $view->render('EditorialFlow/Ticket');

        self::assertStringContainsString('About us', $output);
        // An unassigned task must read as "take me", not as an empty field.
        self::assertStringContainsString('Up for grabs', $output);
        // Core's rendered diff markup is passed through, not re-escaped away.
        self::assertStringContainsString('<ins>new</ins>', $output);
        // The comment must appear nested under the action it explains, not in a
        // disconnected list the reader has to correlate by timestamp.
        self::assertStringContainsString('stage_changed', $output);
        self::assertStringContainsString('Sent back, images are missing.', $output);
        self::assertStringContainsString('in_progress', $output);
        // Reused content is flagged, because changing it changes other pages.
        self::assertStringContainsString('needs-attention', $output);
        self::assertStringContainsString('reused elsewhere', $output);
        // ... and can be acted on right there: this member has no pending
        // version in this fixture, and split/move are deliberately NOT gated on
        // one - moving work that has not started yet is planning.
        self::assertStringContainsString('data-editorialflow-split="1"', $output);
        self::assertStringContainsString('data-editorialflow-move="1"', $output);
        // Comments are no longer a separate panel - they live inside the timeline
        // entry they explain, so there is no standalone "comments" list any more.
        self::assertStringNotContainsString('No comments yet', $output);
        // Core's rendered diff is shown, so the empty-state hint must NOT appear.
        self::assertStringNotContainsString('30 days', $output);
        // Closing is offered here and only here - see the comment in the
        // template for why it left the board card.
        self::assertStringContainsString('data-editorialflow-close="1"', $output);
    }

    /**
     * A closed task is an archive record. Offering to close it again would be a
     * button whose only possible answer is `task-closed`.
     */
    #[Test]
    public function aClosedTicketOffersNoCloseButton(): void
    {
        $output = $this->renderTicket(['closed' => 1, 'state' => 'done']);

        self::assertStringNotContainsString('data-editorialflow-close', $output);
    }

    /**
     * The board card deliberately does NOT offer closing: three buttons and an
     * assignee in a 310px column pushed the footer onto three lines, and
     * finishing a task is not the one-glance decision assigning yourself is.
     */
    #[Test]
    public function theBoardCardDoesNotOfferClosing(): void
    {
        $output = $this->render([
            'pageSelected' => true,
            'workspaceUid' => 1,
            'columns' => [
                [
                    'key' => 'stage-0',
                    'label' => 'Editing',
                    'state' => 'in_progress',
                    'stageUid' => 0,
                    'acceptsDrop' => true,
                    'checklistItemsJson' => '[]',
                    'cards' => [
                        [
                            'uid' => 1,
                            'title' => 'About us',
                            'state' => 'in_progress',
                            'subject_table' => 'pages',
                            'subject_uid' => 2,
                            'workspace_uid' => 1,
                            'stage_uid' => 0,
                            'closed' => 0,
                            'canAct' => true,
                        ],
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('editorialflow-card', $output);
        self::assertStringNotContainsString('data-editorialflow-close', $output);
    }

    /**
     * @param array<string, mixed> $taskOverrides
     */
    private function renderTicket(array $taskOverrides = []): string
    {
        $viewFactory = $this->get(ViewFactoryInterface::class);
        $view = $viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:editorial_flow/Resources/Private/Templates/'],
        ));
        $view->assignMultiple([
            'task' => array_merge([
                'uid' => 1,
                'state' => 'in_progress',
                'priority' => 2,
                'workspace_uid' => 1,
                'stage_uid' => 0,
                'auto_created' => 0,
                'description' => '',
                'subject_pid' => 2,
                'closed' => 0,
            ], $taskOverrides),
            'subject' => ['table' => 'pages', 'uid' => 2, 'title' => 'About us'],
            'assignee' => null,
            'editUrl' => '/typo3/record/edit',
            'members' => [],
            'diffs' => [],
            'timeline' => [],
            'activities' => [],
            'comments' => [],
        ]);

        return $view->render('EditorialFlow/Ticket');
    }

    /**
     * detachAction() refuses to split a task from its own subject, so the
     * button that could only ever produce that error is left out.
     */
    #[Test]
    public function theTaskSubjectItselfIsNotOfferedForSplitting(): void
    {
        $viewFactory = $this->get(ViewFactoryInterface::class);
        $view = $viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:editorial_flow/Resources/Private/Templates/'],
        ));
        $view->assignMultiple([
            'task' => ['uid' => 1, 'state' => 'backlog', 'priority' => 2, 'workspace_uid' => 0, 'subject_pid' => 2],
            'subject' => ['table' => 'pages', 'uid' => 2, 'title' => 'About us'],
            'assignee' => null,
            'editUrl' => '',
            'members' => [
                [
                    'record_table' => 'pages', 'record_uid' => 2, 'home_pid' => 2,
                    'title' => 'About us', 'icon' => '', 'isForeign' => false,
                    'isShared' => false, 'needsAttention' => false, 'isSubject' => true,
                ],
            ],
            'activities' => [],
            'timeline' => [],
            'diffs' => [],
            'comments' => [],
        ]);

        $output = $view->render('EditorialFlow/Ticket');

        self::assertStringNotContainsString('data-editorialflow-split', $output);
        self::assertStringNotContainsString('data-editorialflow-move', $output);
    }

    #[Test]
    public function anEmptyChangeListExplainsThatHistoryExpires(): void
    {
        $viewFactory = $this->get(ViewFactoryInterface::class);
        $view = $viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:editorial_flow/Resources/Private/Templates/'],
        ));
        $view->assignMultiple([
            'task' => ['uid' => 1, 'state' => 'backlog', 'priority' => 2, 'workspace_uid' => 0, 'subject_pid' => 2],
            'subject' => ['table' => 'pages', 'uid' => 2, 'title' => 'About us'],
            'assignee' => null,
            'editUrl' => '',
            'members' => [],
            'activities' => [],
            'timeline' => [],
            'diffs' => [],
            'comments' => [],
        ]);

        $output = $view->render('EditorialFlow/Ticket');

        // TYPO3 drops change history after 30 days by default. Saying so turns a
        // confusing blank panel into an explained one - "nothing here" must not
        // be misread as "nothing happened".
        self::assertStringContainsString('30 days', $output);
    }
}
