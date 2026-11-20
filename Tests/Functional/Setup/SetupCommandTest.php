<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Setup;

use GbWeb\EditorialFlow\Command\CheckInstallationCommand;
use GbWeb\EditorialFlow\Command\SetupCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Each scenario of `editorialflow:setup`, run for real against DataHandler.
 *
 * What matters most is what setup does NOT do: it never takes a module away
 * from a group, never takes over a page another group owns, never drops a
 * workspace member. Most tests below assert one of those next to the thing
 * that was added.
 */
final class SetupCommandTest extends FunctionalTestCase
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

    /**
     * The shared page fixture brings a workspace along for other tests; setup
     * tests start from an installation that has none.
     */
    private function importPages(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')->truncate('sys_workspace');
    }

    #[Test]
    public function freshBuildsEverythingOnAnEmptyDatabase(): void
    {
        [$status] = $this->runSetup(['scenario' => 'fresh']);

        self::assertSame(Command::SUCCESS, $status);

        $pages = $this->rows('pages');
        self::assertCount(1, $pages);
        self::assertSame(1, (int)$pages[0]['is_siteroot']);

        $group = $this->groupTitled('Editors');
        self::assertSame('web_editorialflow,web_layout,records,media_management', $group['groupMods']);
        self::assertSame((string)$pages[0]['uid'], (string)$group['db_mountpoints']);
        self::assertSame(0, (int)$group['workspace_perms'], 'an editor who may edit Live never gets a task');
        self::assertSame((int)$group['uid'], (int)$pages[0]['perms_groupid']);

        $workspace = $this->workspaceTitled('Editors');
        self::assertSame('be_groups_' . $group['uid'], $workspace['members']);
        self::assertSame(2, (int)$workspace['custom_stages'], 'without the counter nobody can leave Editing');

        $stages = $this->rows('sys_workspace_stage');
        self::assertSame(['Review', 'Approval'], array_column($stages, 'title'));
        foreach ($stages as $stage) {
            self::assertSame('be_groups_' . $group['uid'], $stage['responsible_persons']);
        }
    }

    #[Test]
    public function freshKeepsAPageTreeThatIsAlreadyThere(): void
    {
        $this->importPages();

        [, $display] = $this->runSetup(['scenario' => 'fresh']);

        self::assertStringContainsString('Keep the page tree', $display);
        self::assertCount(2, $this->rows('pages'));
    }

    #[Test]
    public function singleGroupAddsTheBoardToAnExistingGroupAndNothingElse(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/existing_tree.csv');
        $before = $this->groupTitled('Redaktion');

        $this->runSetup(['scenario' => 'single-group', '--group' => ['Redaktion']]);

        $after = $this->groupTitled('Redaktion');
        self::assertSame('web_layout,records,web_editorialflow', $after['groupMods']);
        unset($before['groupMods'], $after['groupMods'], $before['tstamp'], $after['tstamp']);
        self::assertSame($before, $after, 'setup changed more than the module list of an existing group');

        self::assertSame('be_groups_10', $this->workspaceTitled('Redaktion')['members']);
    }

    #[Test]
    public function aNewGroupNeverTakesOverPagesOfAnotherGroup(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/existing_tree.csv');

        [, $display] = $this->runSetup(['scenario' => 'single-group', '--group' => ['Neue Redaktion']]);

        $owners = array_column($this->rows('pages'), 'perms_groupid', 'uid');
        $newGroup = (int)$this->groupTitled('Neue Redaktion')['uid'];
        self::assertSame(10, (int)$owners[1]);
        self::assertSame(10, (int)$owners[2]);
        self::assertSame($newGroup, (int)$owners[3], 'only the page nobody owned is handed over');
        self::assertStringContainsString('2 page(s) already belong to another group', $display);
    }

    #[Test]
    public function groupPerWorkspaceKeepsTheGroupsApart(): void
    {
        $this->importPages();

        $this->runSetup(['scenario' => 'group-per-workspace', '--group' => ['News,Sport'], '--stages' => 'Lektorat']);

        $news = $this->groupTitled('News');
        $sport = $this->groupTitled('Sport');
        $shared = $this->groupTitled('Editorial Flow Editors (shared)');
        self::assertSame('be_groups_' . $news['uid'], $this->workspaceTitled('News')['members']);
        self::assertSame('be_groups_' . $sport['uid'], $this->workspaceTitled('Sport')['members']);
        self::assertSame(['Lektorat', 'Lektorat'], array_column($this->rows('sys_workspace_stage'), 'title'));

        // A page belongs to one group only, so both reach the pages through a
        // shared group - and neither includes the other, or Sport would be in
        // News's workspace too.
        self::assertSame((string)$shared['uid'], (string)$news['subgroup']);
        self::assertSame((string)$shared['uid'], (string)$sport['subgroup']);
        self::assertSame('web_editorialflow', $news['groupMods']);
        self::assertStringNotContainsString('web_editorialflow', (string)$shared['groupMods'], 'the shared group must not show up as a board group without workspace');
        foreach ($this->rows('pages') as $page) {
            self::assertSame((int)$shared['uid'], (int)$page['perms_groupid']);
        }
    }

    #[Test]
    public function groupPerWorkspacePassesTheCheck(): void
    {
        $this->importPages();
        $this->runSetup(['scenario' => 'group-per-workspace', '--group' => ['News,Sport'], '--owner-group' => ['Chefredaktion']]);

        $tester = new CommandTester($this->get(CheckInstallationCommand::class));
        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertStringNotContainsString('warning', $tester->getDisplay());
        self::assertSame(
            (string)$this->groupTitled('Editorial Flow Editors (shared)')['uid'],
            (string)$this->groupTitled('Chefredaktion')['subgroup'],
            'the owners need the pages, and must not be pulled into one group\'s workspace',
        );
    }

    /**
     * The large-tree case: only the access group is new, and the groups it is
     * hung into change in exactly one field.
     */
    #[Test]
    public function existingGroupsOnlyHangsAnAccessGroupIntoTheTree(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/existing_tree.csv');
        $pagesBefore = $this->rows('pages');
        $before = $this->groupTitled('Online-Desk');

        [$status] = $this->runSetup(['scenario' => 'existing-groups', '--attach-to-editors' => true]);

        self::assertSame(Command::SUCCESS, $status);
        $access = $this->groupTitled('Editorial Flow Access');
        self::assertSame('web_editorialflow', $access['groupMods']);
        self::assertSame('', (string)$access['tables_modify'], 'the access group must not carry permissions of its own');

        // Both groups with the Page module, nothing else.
        self::assertSame('11,' . $access['uid'], $this->groupTitled('Redaktion')['subgroup']);
        self::assertSame((string)$access['uid'], $this->groupTitled('Online-Desk')['subgroup']);
        self::assertSame('', (string)$this->groupTitled('Bildredaktion')['subgroup']);
        self::assertSame('', (string)$this->groupTitled('Redaktion Basis')['subgroup']);

        $after = $this->groupTitled('Online-Desk');
        unset($before['subgroup'], $after['subgroup'], $before['tstamp'], $after['tstamp']);
        self::assertSame($before, $after);
        self::assertSame($pagesBefore, $this->rows('pages'), 'page permissions are not setup\'s business here');
        self::assertSame([], $this->rows('sys_workspace'), 'no --workspace, so no workspace');
    }

    #[Test]
    public function existingGroupsCanPutTheTreeIntoOneWorkspace(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/existing_tree.csv');

        $this->runSetup(['scenario' => 'existing-groups', '--attach-to' => ['Redaktion', '12'], '--workspace' => 'Redaktion']);

        self::assertSame('be_groups_10,be_groups_12', $this->workspaceTitled('Redaktion')['members']);
    }

    #[Test]
    public function anOwnerGroupInheritsTheEditorsAndOwnsTheWorkspace(): void
    {
        $this->importPages();

        $this->runSetup([
            'scenario' => 'single-group',
            '--group' => ['Redaktion'],
            '--owner-group' => ['Chefredaktion'],
            '--owner' => ['admin'],
        ]);

        $editors = $this->groupTitled('Redaktion');
        $owners = $this->groupTitled('Chefredaktion');
        self::assertSame((string)$editors['uid'], (string)$owners['subgroup']);
        self::assertSame('be_users_1,be_groups_' . $owners['uid'], $this->workspaceTitled('Redaktion')['adminusers']);
    }

    #[Test]
    public function anEditorCreatedBySetupWorksInTheWorkspaceFromTheStart(): void
    {
        $this->importPages();

        [$status, $display] = $this->runSetup([
            'scenario' => 'group-per-workspace',
            '--group' => ['News,Sport'],
            '--editor' => ['jana:Sport'],
            '--editor-password' => 'Password.1',
        ]);

        self::assertSame(Command::SUCCESS, $status, $display);
        $jana = $this->userNamed('jana');
        self::assertSame((string)$this->groupTitled('Sport')['uid'], (string)$jana['usergroup']);
        self::assertSame((int)$this->workspaceTitled('Sport')['uid'], (int)$jana['workspace_id']);
        self::assertSame(0, (int)$jana['workspace_perms'], 'an editor who may edit Live keeps every change off the board');
        self::assertSame(0, (int)$jana['admin']);
        self::assertSame(0, (int)$jana['disable'], 'a disabled editor only ever sees "login failed"');
        self::assertNotSame('Password.1', $jana['password'], 'the password must be stored hashed');
    }

    #[Test]
    public function anExistingUserOnlyJoinsTheGroup(): void
    {
        $this->importPages();
        $before = $this->userNamed('editor');

        $this->runSetup(['scenario' => 'single-group', '--group' => ['Redaktion'], '--editor' => ['editor']]);

        $after = $this->userNamed('editor');
        self::assertSame((string)$this->groupTitled('Redaktion')['uid'], (string)$after['usergroup']);
        self::assertSame($before['password'], $after['password']);
        self::assertSame($before['workspace_perms'], $after['workspace_perms']);
    }

    #[Test]
    public function aNewEditorWithoutPasswordStopsBeforeAnythingIsWritten(): void
    {
        [$status, $display] = $this->runSetup(['scenario' => 'fresh', '--editor' => ['jana']]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('EDITORIALFLOW_EDITOR_PASSWORD', $display);
        self::assertSame([], $this->rows('be_groups'));
    }

    #[Test]
    public function anExistingWorkspaceKeepsItsMembersAndOwners(): void
    {
        $this->importPages();
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'pid' => 0,
            'title' => 'Redaktion',
            'members' => 'be_users_2',
            'adminusers' => 'be_users_2',
        ]);

        $this->runSetup(['scenario' => 'single-group', '--group' => ['Redaktion'], '--owner' => ['admin'], '--stages' => 'none']);

        $workspace = $this->workspaceTitled('Redaktion');
        self::assertSame('be_users_2,be_groups_' . $this->groupTitled('Redaktion')['uid'], $workspace['members']);
        self::assertSame('be_users_2,be_users_1', $workspace['adminusers']);
    }

    #[Test]
    public function runningItAgainChangesNothing(): void
    {
        $this->importPages();
        $arguments = ['scenario' => 'single-group', '--group' => ['Redaktion'], '--owner' => ['admin'], '--editor' => ['jana'], '--editor-password' => 'Password.1'];
        $this->runSetup($arguments);
        $before = [$this->rows('be_groups'), $this->rows('sys_workspace'), $this->rows('sys_workspace_stage'), $this->rows('pages'), $this->rows('be_users')];

        [, $display] = $this->runSetup($arguments);

        self::assertSame($before, [$this->rows('be_groups'), $this->rows('sys_workspace'), $this->rows('sys_workspace_stage'), $this->rows('pages'), $this->rows('be_users')]);
        self::assertStringContainsString('Keep group "Redaktion"', $display);
        self::assertStringContainsString('Keep workspace "Redaktion"', $display);
        self::assertStringContainsString('Everything is already in place', $display);
    }

    #[Test]
    public function aDryRunChangesNothing(): void
    {
        [$status, $display] = $this->runSetup(['scenario' => 'fresh', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Create editor group "Editors"', $display);
        self::assertSame([], $this->rows('pages'));
        self::assertSame([], $this->rows('be_groups'));
        self::assertSame([], $this->rows('sys_workspace'));
    }

    #[Test]
    public function anUnknownOwnerStopsBeforeAnythingIsWritten(): void
    {
        [$status, $display] = $this->runSetup(['scenario' => 'fresh', '--owner' => ['nobody']]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('backend:user:create', $display);
        self::assertSame([], $this->rows('be_groups'));
    }

    #[Test]
    public function withoutAScenarioAndWithoutATerminalItSaysWhichOnesExist(): void
    {
        [$status, $display] = $this->runSetup([]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('existing-groups', $display);
    }

    #[Test]
    public function theWizardAsksAndThenDoesTheSameAsTheOptions(): void
    {
        $this->importPages();
        $tester = new CommandTester($this->get(SetupCommand::class));
        $tester->setInputs([
            'single-group',  // scenario
            'Redaktion',     // group
            'Redaktion',     // workspace
            'Lektorat',      // stages
            '',              // owners
            '',              // editors
            'yes',           // apply
        ]);

        $status = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertSame(['Lektorat'], array_column($this->rows('sys_workspace_stage'), 'title'));
        self::assertSame('be_groups_' . $this->groupTitled('Redaktion')['uid'], $this->workspaceTitled('Redaktion')['members']);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: int, 1: string}
     */
    private function runSetup(array $arguments): array
    {
        $tester = new CommandTester($this->get(SetupCommand::class));
        $status = $tester->execute($arguments, ['interactive' => false]);

        return [$status, $tester->getDisplay()];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupTitled(string $title): array
    {
        $rows = array_values(array_filter($this->rows('be_groups'), static fn (array $row): bool => $row['title'] === $title));
        self::assertCount(1, $rows, sprintf('expected exactly one group "%s"', $title));

        return $rows[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function userNamed(string $username): array
    {
        $rows = array_values(array_filter($this->rows('be_users'), static fn (array $row): bool => $row['username'] === $username));
        self::assertCount(1, $rows, sprintf('expected exactly one user "%s"', $username));

        return $rows[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceTitled(string $title): array
    {
        $rows = array_values(array_filter($this->rows('sys_workspace'), static fn (array $row): bool => $row['title'] === $title));
        self::assertCount(1, $rows, sprintf('expected exactly one workspace "%s"', $title));

        return $rows[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
