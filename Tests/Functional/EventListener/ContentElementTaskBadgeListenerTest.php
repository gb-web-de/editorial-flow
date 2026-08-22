<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\EventListener;

use GbWeb\EditorialFlow\Domain\Model\TaskState;
use GbWeb\EditorialFlow\EventListener\ContentElementTaskBadgeListener;
use GbWeb\EditorialFlow\Service\ActiveTaskSession;
use GbWeb\EditorialFlow\Service\TaskColor;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\View\Event\AfterPageContentPreviewRenderedEvent;
use TYPO3\CMS\Backend\View\PageLayoutContext;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Domain\RecordFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The Page module used to say nothing about an element another task already
 * holds, while the Visual Editor marked the very same element with a bubble -
 * so the safest-looking place to edit was the one with no warning in it.
 */
final class ContentElementTaskBadgeListenerTest extends FunctionalTestCase
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
        $GLOBALS['BE_USER']->setWorkspace(1);
        // The badge names the element the split/move buttons act on, and
        // BackendUtility::getRecordTitle() resolves labels through LANG. Always
        // present in a real Page module request, never in a synthetic one.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    private function subject(): ContentElementTaskBadgeListener
    {
        return $this->get(ContentElementTaskBadgeListener::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createTask(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task');
        $connection->insert('tx_editorialflow_task', array_merge([
            'title' => 'Rewrite the intro',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => TaskState::IN_PROGRESS->value,
            'workspace_uid' => 1,
            'closed' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    private function addMember(int $taskUid, int $recordUid): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task_item')->insert(
            'tx_editorialflow_task_item',
            ['task' => $taskUid, 'record_table' => 'tt_content', 'record_uid' => $recordUid, 'pid' => 2, 'closed' => 0],
        );
    }

    private function renderPreviewFor(int $contentUid): string
    {
        $row = $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->select(['*'], 'tt_content', ['uid' => $contentUid])
            ->fetchAssociative();
        self::assertIsArray($row);

        $record = $this->get(RecordFactory::class)->createResolvedRecordFromDatabaseRow('tt_content', $row);
        $context = $this->createMock(PageLayoutContext::class);

        $event = new AfterPageContentPreviewRenderedEvent(
            'tt_content',
            'text',
            $record,
            $context,
            '<p>the element as it renders today</p>',
        );
        $this->subject()->__invoke($event);

        return $event->getPreviewContent();
    }

    #[Test]
    public function anElementHeldByATaskIsNamedAboveItsPreview(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 10);

        $output = $this->renderPreviewFor(10);

        self::assertStringContainsString('editorialflow-element-badge', $output);
        // Never colour alone - the task is named, not just tinted.
        self::assertStringContainsString('Rewrite the intro', $output);
        self::assertStringContainsString('--editorialflow-task-hue: ' . TaskColor::hueFor($taskUid), $output);
        // The element's own preview survives; the badge is added, not swapped in.
        self::assertStringContainsString('the element as it renders today', $output);
    }

    #[Test]
    public function anUnclaimedElementIsLeftExactlyAsItWas(): void
    {
        $this->createTask();

        $output = $this->renderPreviewFor(11);

        self::assertSame('<p>the element as it renders today</p>', $output);
    }

    /**
     * The task the editor declared in the Visual Editor reads differently from
     * somebody else's - that difference is the whole point of the marker.
     */
    #[Test]
    public function theEditorsOwnTaskIsMarkedApartFromTheRest(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 10);
        $this->get(ActiveTaskSession::class)->remember($GLOBALS['BE_USER'], 2, $taskUid);

        $output = $this->renderPreviewFor(10);

        self::assertStringContainsString('editorialflow-element-badge--active', $output);
    }

    /**
     * "Meet editors where they are": the badge answers who owns this element,
     * and the two follow-up questions are answered right there rather than only
     * inside the ticket. The buttons carry nothing but data - the behaviour is
     * task/membership.js', which board.js already registers in this module.
     */
    #[Test]
    public function theBadgeOffersSplittingAndMovingTheElement(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 10);

        $output = $this->renderPreviewFor(10);

        self::assertStringContainsString('data-editorialflow-split="1"', $output);
        self::assertStringContainsString('data-editorialflow-move="1"', $output);
        self::assertStringContainsString('data-table="tt_content"', $output);
        self::assertStringContainsString('data-uid="10"', $output);
        // The dialogs say which record they are about, so the badge has to name it.
        self::assertStringContainsString('data-title="Intro text"', $output);
    }

    /**
     * A task cannot be split from its own subject - detachAction() refuses it
     * with cannot-split-task-from-itself - so the button that would produce
     * exactly that error is not rendered. Moving it elsewhere is still fine.
     */
    #[Test]
    public function anElementThatIsItsOwnTasksSubjectCannotBeSplitFromIt(): void
    {
        $taskUid = $this->createTask(['subject_table' => 'tt_content', 'subject_uid' => 10]);
        $this->addMember($taskUid, 10);

        $output = $this->renderPreviewFor(10);

        self::assertStringNotContainsString('data-editorialflow-split', $output);
        self::assertStringContainsString('data-editorialflow-move="1"', $output);
    }

    #[Test]
    public function aFinishedTaskNoLongerHoldsItsElements(): void
    {
        $taskUid = $this->createTask(['state' => TaskState::DONE->value, 'closed' => 1]);
        $this->addMember($taskUid, 10);

        $output = $this->renderPreviewFor(10);

        self::assertStringNotContainsString('editorialflow-element-badge', $output);
    }

    /**
     * The distinction the badge had been hiding.
     *
     * syncPageMembers() claims every trackable record on the page a task
     * covers, so most badges mean "sits here", not "was worked on". Rendering
     * both the same way made a page of nine elements look like nine pieces of
     * work when two had actually been touched - which is exactly how it was
     * reported.
     */
    #[Test]
    public function anElementNobodyEditedSaysSoRatherThanLookingLikeWork(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 10);

        $output = $this->renderPreviewFor(10);

        self::assertStringContainsString('editorialflow-element-badge--untouched', $output);
        // Wording first, styling second - the state must survive greyscale.
        self::assertStringContainsString('not edited yet', $output);
    }

    #[Test]
    public function anElementWithPendingChangesIsNotMarkedUntouched(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 10);
        $this->editInWorkspace(10, 'Intro text (draft)');

        $output = $this->renderPreviewFor(10);

        self::assertStringNotContainsString('editorialflow-element-badge--untouched', $output);
        self::assertStringNotContainsString('not edited yet', $output);
        self::assertStringContainsString('editorialflow-element-badge', $output);
    }

    private function editInWorkspace(int $uid, string $header): void
    {
        $GLOBALS['BE_USER']->setWorkspace(1);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [$uid => ['header' => $header]]], []);
        $dataHandler->process_datamap();
    }
}
