<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Service;

use GbWeb\EditorialFlow\Service\TaskMemberSynchronizer;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What counts as a pending version, and why there may only be one answer.
 *
 * findVersionUid() used to query t3ver_* by hand, and its fallback for a record
 * created directly inside a workspace accepted any `t3ver_oid = 0 &&
 * t3ver_wsid = N` row. Core's BackendUtility::getWorkspaceVersionOfRecord()
 * additionally requires `t3ver_state = NEW_PLACEHOLDER` on that branch, and
 * WorkspaceIntegrationService::decorateMembers() goes through core. So the two
 * disagreed about the same record: publish, stage change and close saw a
 * pending version that the ticket denied existed.
 *
 * These tests pin the agreement, not the implementation - they assert that both
 * paths answer the same thing, so the day someone reintroduces a hand-rolled
 * query it goes red for the right reason.
 */
final class TaskMemberSynchronizerTest extends FunctionalTestCase
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
        // setWorkspace(), never ->workspace = 1: only the setter validates the
        // workspace and populates workspaceRec, which DataHandler needs before
        // it will version anything.
        $GLOBALS['BE_USER']->setWorkspace(1);
    }

    private function subject(): TaskMemberSynchronizer
    {
        return $this->get(TaskMemberSynchronizer::class);
    }

    private function editInWorkspace(string $table, int $uid, array $fields, int $workspaceUid = 1): void
    {
        $GLOBALS['BE_USER']->setWorkspace($workspaceUid);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $fields]], []);
        $dataHandler->process_datamap();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function updateRecord(string $table, int $uid, array $fields): void
    {
        $this->getConnectionPool()->getConnectionForTable($table)->update($table, $fields, ['uid' => $uid]);
    }

    #[Test]
    public function anOrdinaryWorkspaceVersionIsFound(): void
    {
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (draft)']);

        $versionUid = $this->subject()->findVersionUid('tt_content', 10, 1);

        self::assertGreaterThan(0, $versionUid);
        self::assertNotSame(10, $versionUid, 'the version is its own record, not the live one');
    }

    /**
     * The regression this class exists for. A record carrying `t3ver_oid = 0`
     * and `t3ver_wsid = 1` but the DEFAULT state is not a new-in-workspace
     * record - core will not act on it, so neither may we.
     */
    #[Test]
    public function aWorkspaceMarkedRecordInTheDefaultStateIsNotAPendingVersion(): void
    {
        $this->updateRecord('tt_content', 11, [
            't3ver_oid' => 0,
            't3ver_wsid' => 1,
            't3ver_state' => VersionState::DEFAULT_STATE->value,
        ]);

        self::assertSame(0, $this->subject()->findVersionUid('tt_content', 11, 1));
        self::assertFalse(
            BackendUtility::getWorkspaceVersionOfRecord(1, 'tt_content', 11, 'uid'),
            'core is the reference: if it says no, findVersionUid() must say no too',
        );
    }

    /**
     * The case the hand-rolled fallback was written for, and which must keep
     * working: a record created inside the workspace has no live counterpart
     * pointing at it, so its own uid is the pending version.
     */
    #[Test]
    public function aRecordCreatedInsideTheWorkspaceIsItsOwnPendingVersion(): void
    {
        $this->updateRecord('tt_content', 11, [
            't3ver_oid' => 0,
            't3ver_wsid' => 1,
            't3ver_state' => VersionState::NEW_PLACEHOLDER->value,
        ]);

        self::assertSame(11, $this->subject()->findVersionUid('tt_content', 11, 1));
    }

    #[Test]
    public function aRecordWithNothingPendingHasNoVersion(): void
    {
        self::assertSame(0, $this->subject()->findVersionUid('tt_content', 10, 1));
    }

    /**
     * Live is not a workspace. Asking for one there is a caller error rather
     * than a lookup, and the answer must not depend on what happens to sit in
     * t3ver_wsid = 0.
     */
    #[Test]
    public function liveNeverHasAPendingVersion(): void
    {
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (draft)']);

        self::assertSame(0, $this->subject()->findVersionUid('tt_content', 10, 0));
    }

    /**
     * A version belongs to exactly one workspace - the whole point of
     * WorkspaceConflictDetector is that core lets a second workspace version the
     * same live record independently.
     */
    #[Test]
    public function aVersionIsNotVisibleFromAnotherWorkspace(): void
    {
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro (draft)']);

        self::assertGreaterThan(0, $this->subject()->findVersionUid('tt_content', 10, 1));
        self::assertSame(0, $this->subject()->findVersionUid('tt_content', 10, 2));
    }
}
