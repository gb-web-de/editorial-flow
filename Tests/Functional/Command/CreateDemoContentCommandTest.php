<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Command;

use GbWeb\EditorialFlow\Command\CreateDemoContentCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The demo environment is what most of this extension is demonstrated and
 * tested against, so the things that make it useful have to actually be there.
 *
 * Three of them are easy to break silently and impossible to notice by reading
 * the output:
 *
 * - `custom_stages` is core's child counter for the workspace's stage relation,
 *   and workspaceCheckStageForCurrent() gates the whole responsible_persons
 *   mechanism behind it being > 0. Left at 0, every non-owner member is stuck at
 *   stage 0 and it looks exactly like a page permission problem. This is the bug
 *   WORKSPACE-STAGES.md was written about.
 * - Two workspaces sharing a stage TITLE is what BoardColumnRegistry merges into
 *   one column. If the demo stops producing that, the merge has nothing to merge
 *   and the feature goes untested everywhere.
 * - Re-running must not duplicate anything: this runs on every `ddev start`.
 */
final class CreateDemoContentCommandTest extends FunctionalTestCase
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
    public function itCreatesThreeWorkspacesAndNineUsers(): void
    {
        $this->runSeeder();

        self::assertSame(
            ['Editorial', 'Marketing', 'Quickfix'],
            array_column($this->workspaces(), 'title'),
        );
        self::assertCount(9, $this->demoUsers());
    }

    #[Test]
    public function everyWorkspaceWithStagesCarriesCoresOwnStageCounter(): void
    {
        $this->runSeeder();

        $counters = [];
        foreach ($this->workspaces() as $workspace) {
            $counters[(string)$workspace['title']] = (int)$workspace['custom_stages'];
        }

        self::assertSame(2, $counters['Editorial'], 'without this, no member can leave stage 0');
        self::assertSame(2, $counters['Marketing']);
        // Quickfix deliberately has no custom stages, and 0 is correct there:
        // the gate is never reached because there is no stage to be responsible
        // for in the first place.
        self::assertSame(0, $counters['Quickfix']);
    }

    #[Test]
    public function twoWorkspacesShareAStageTitleSoTheBoardHasSomethingToMerge(): void
    {
        $this->runSeeder();

        $reviewStages = array_filter(
            $this->stages(),
            static fn (array $stage): bool => $stage['title'] === 'Review',
        );

        self::assertCount(2, $reviewStages);
        self::assertCount(
            2,
            array_unique(array_column($reviewStages, 'parentid')),
            'the two "Review" stages must belong to different workspaces',
        );
    }

    #[Test]
    public function oneUserIsAMemberOfTwoWorkspacesAndAnotherOfNone(): void
    {
        $this->runSeeder();

        $users = $this->demoUsers();
        $both = 'be_users_' . $users['both'];

        $memberships = array_filter(
            $this->workspaces(),
            static fn (array $workspace): bool => str_contains((string)$workspace['members'], $both),
        );
        self::assertCount(2, $memberships, 'the cross-workspace conflict case needs someone who sees both sides');
        // Membership is what lets them enter the workspace at all; being
        // responsible for its Review stage is what lets them act there. The two
        // are separate in core and both are needed - see
        // oneReviewerIsResponsibleInTwoDifferentWorkspaces().

        // observer belongs to nothing, which is the case the board has to hold
        // up for: the module is reachable, no workspace is.
        foreach ($this->workspaces() as $workspace) {
            self::assertStringNotContainsString('be_users_' . $users['observer'], (string)$workspace['members']);
            self::assertStringNotContainsString('be_users_' . $users['observer'], (string)$workspace['adminusers']);
        }
    }

    /**
     * A reviewer who reviews in more than one workspace is the ordinary case for
     * a small editorial team, and it is the one the board's merged stage columns
     * exist for: the same person acts on cards from either workspace without
     * leaving the column they are looking at. Core allows it - responsible_persons
     * is per stage record, and nothing says a person may appear on only one.
     */
    #[Test]
    public function oneReviewerIsResponsibleInTwoDifferentWorkspaces(): void
    {
        $this->runSeeder();

        $both = 'be_users_' . $this->demoUsers()['both'];
        $stages = array_filter(
            $this->stages(),
            static fn (array $stage): bool => str_contains((string)$stage['responsible_persons'], $both),
        );

        self::assertCount(2, $stages);
        self::assertCount(
            2,
            array_unique(array_column($stages, 'parentid')),
            'both stages belong to the same workspace, so nothing crosses a workspace boundary',
        );
        self::assertSame(['Review', 'Review'], array_column($stages, 'title'));
    }

    /**
     * The other multi-stage case: two stages of the SAME workspace, so a card
     * stays with the same person as it moves from Review to Approval.
     */
    #[Test]
    public function oneUserIsResponsibleForTwoStagesOfOneWorkspace(): void
    {
        $this->runSeeder();

        $stagelead = 'be_users_' . $this->demoUsers()['stagelead'];
        $responsible = array_filter(
            $this->stages(),
            static fn (array $stage): bool => str_contains((string)$stage['responsible_persons'], $stagelead),
        );

        self::assertCount(2, $responsible);
        self::assertCount(1, array_unique(array_column($responsible, 'parentid')));
    }

    #[Test]
    public function reviewStagesAreSeededWithAcceptanceCriteria(): void
    {
        $this->runSeeder();

        $criteria = $this->criteria();
        self::assertNotSame([], $criteria);

        // Attached to a real stage record, not to one of core's fixed stage ids -
        // that is what makes them reachable from the workspace record's own form.
        foreach ($criteria as $criterion) {
            self::assertGreaterThan(0, (int)$criterion['stage_uid']);
        }
    }

    #[Test]
    public function runningItTwiceChangesNothing(): void
    {
        $this->runSeeder();
        $before = [$this->workspaces(), $this->stages(), $this->criteria(), $this->demoUsers()];

        $this->runSeeder();

        self::assertSame($before, [$this->workspaces(), $this->stages(), $this->criteria(), $this->demoUsers()]);
    }

    /**
     * Without --force and without a TTY, an existing workspace is kept. A DDEV
     * post-start hook has no TTY, so this is the path that actually runs on
     * every start - and it must never be the one that deletes an editor's work.
     */
    #[Test]
    public function aSecondNonInteractiveRunKeepsTheWorkspaceItFound(): void
    {
        $this->runSeeder();
        $uidBefore = $this->workspaces()[0]['uid'];

        $display = $this->runSeeder();

        self::assertStringContainsString('keeping them', $display);
        self::assertSame($uidBefore, $this->workspaces()[0]['uid'], 'the workspace was replaced, versions and all');
    }

    private function runSeeder(): string
    {
        $tester = new CommandTester($this->get(CreateDemoContentCommand::class));
        // Matches the DDEV post-start hook, which has no TTY either.
        $tester->execute([], ['interactive' => false]);

        return $tester->getDisplay();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workspaces(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_workspace');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'title', 'custom_stages', 'members', 'adminusers')
            ->from('sys_workspace')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stages(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_workspace_stage');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'parentid', 'title', 'responsible_persons')
            ->from('sys_workspace_stage')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function criteria(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_stage_checklist_item');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'workspace_uid', 'stage_uid', 'title', 'sorting')
            ->from('tx_editorialflow_stage_checklist_item')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, int> username => uid, admin fixtures excluded
     */
    private function demoUsers(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 'username')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)),
                $queryBuilder->expr()->eq('admin', $queryBuilder->createNamedParameter(0)),
            )
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $byName = [];
        foreach ($rows as $row) {
            $byName[(string)$row['username']] = (int)$row['uid'];
        }

        return $byName;
    }
}
