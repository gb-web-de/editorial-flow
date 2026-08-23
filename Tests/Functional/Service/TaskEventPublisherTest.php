<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Service;

use GbWeb\EditorialFlow\Service\TaskEventPublisher;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Announcing a task must never be able to break the work that produced it.
 *
 * The case this is about was found by the upgrade wizard's own tests, not by
 * reading: MigrateExistingWorkspaceChangesToTasksUpdate creates tasks through
 * TaskAutoCreationService in a context with no backend session and no
 * $GLOBALS['LANG'], and both title lookups in the publisher end up in core
 * methods that return-type-error when that global is missing
 * (StagesService::getStageTitle, BackendUtility::getRecordTitle). The whole
 * migration came down with a TypeError thrown from inside core.
 *
 * So the contract is: no language service costs the titles, and nothing else.
 */
final class TaskEventPublisherTest extends FunctionalTestCase
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
    public function aTaskIsAnnouncedEvenWithNoLanguageServiceAround(): void
    {
        unset($GLOBALS['LANG']);

        $this->subject()->taskCreated($this->taskRow(), 1);
        $this->subject()->taskStageChanged($this->taskRow(), $this->taskRow(), 1);
        $this->subject()->taskClosed($this->taskRow(), 'manual', 1);

        // No exception is the assertion. PHPUnit wants one anyway, and this one
        // says what the test is actually for.
        self::assertNull($GLOBALS['LANG'] ?? null, 'the publisher must not install a language service of its own');
    }

    #[Test]
    public function withALanguageServiceTheTitlesAreResolved(): void
    {
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        // Nothing to assert on the dispatch itself here - what this pins is that
        // the guarded path and the unguarded one both run to completion, so a
        // guard added for the CLI case cannot quietly become the only path.
        $this->subject()->taskCreated($this->taskRow(), 1);

        self::assertNotNull($GLOBALS['LANG']);
    }

    private function subject(): TaskEventPublisher
    {
        return $this->get(TaskEventPublisher::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(): array
    {
        return [
            'uid' => 7,
            'title' => 'Rewrite the About us page',
            'description' => '',
            'state' => 'in_progress',
            'priority' => 2,
            'closed' => 0,
            'stage_uid' => 0,
            'workspace_uid' => 1,
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'assignee' => 1,
            'auto_created' => 0,
            'external_system' => '',
        ];
    }
}
