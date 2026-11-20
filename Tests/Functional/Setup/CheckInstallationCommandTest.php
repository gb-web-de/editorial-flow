<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Setup;

use GbWeb\EditorialFlow\Command\CheckInstallationCommand;
use GbWeb\EditorialFlow\Command\SetupCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class CheckInstallationCommandTest extends FunctionalTestCase
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

    private function importPages(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')->truncate('sys_workspace');
    }

    #[Test]
    public function anInstallationWithoutWorkspaceFails(): void
    {
        $this->importPages();

        [$status, $display] = $this->runCheck();

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('There is no workspace', $display);
        self::assertStringContainsString('No backend group has the board module', $display);
    }

    #[Test]
    public function aFreshSetupPassesTheCheck(): void
    {
        (new CommandTester($this->get(SetupCommand::class)))->execute(['scenario' => 'fresh', '--owner' => ['admin']], ['interactive' => false]);

        [$status, $display] = $this->runCheck();

        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertStringContainsString('The board is ready to use', $display);
    }

    /**
     * The bug WORKSPACE-STAGES.md is about: stage records without core's
     * counter. It looks like a permission problem and is not one.
     */
    #[Test]
    public function aBrokenStageCounterIsFoundAndRepaired(): void
    {
        (new CommandTester($this->get(SetupCommand::class)))->execute(['scenario' => 'fresh'], ['interactive' => false]);
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')->update('sys_workspace', ['custom_stages' => 0], ['deleted' => 0]);

        [$status, $display] = $this->runCheck();
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('stage counter says 0', $display);

        [$status, $display] = $this->runCheck(['--fix' => true]);
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertStringContainsString('Repaired the stage counter of 1 workspace(s)', $display);
    }

    #[Test]
    public function aGroupWithTheBoardButNoWorkspaceIsReported(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/existing_tree.csv');
        (new CommandTester($this->get(SetupCommand::class)))->execute(
            ['scenario' => 'existing-groups', '--attach-to' => ['Online-Desk']],
            ['interactive' => false],
        );

        [, $display] = $this->runCheck();

        self::assertStringContainsString('Group "Online-Desk" can open the board but is not a member of any workspace', $display);
        self::assertStringContainsString('Group "Online-Desk" may edit Live', $display);
    }

    /**
     * An editor made in the backend user form may edit Live by default (TCA
     * default 1) - the check names them, and --fix-live-editing switches it off for
     * exactly the users who can open the board.
     */
    #[Test]
    public function editorsWhoMayEditLiveAreFoundAndCanBeSwitchedToWorkspacesOnly(): void
    {
        (new CommandTester($this->get(SetupCommand::class)))->execute(['scenario' => 'fresh', '--owner' => ['admin']], ['interactive' => false]);
        $group = (int)$this->getConnectionPool()->getConnectionForTable('be_groups')->select(['uid'], 'be_groups', ['title' => 'Editors'])->fetchOne();
        $users = $this->getConnectionPool()->getConnectionForTable('be_users');
        // What the backend user form leaves behind: TCA default 1.
        $users->update('be_users', ['usergroup' => (string)$group, 'workspace_perms' => 1], ['username' => 'editor']);
        $users->insert('be_users', ['pid' => 0, 'username' => 'outsider', 'workspace_perms' => 1, 'usergroup' => '']);

        [, $display] = $this->runCheck();
        self::assertStringContainsString('1 editor(s) may edit Live', $display);
        self::assertStringContainsString(': editor.', $display);

        [, $display] = $this->runCheck(['--fix-live-editing' => true]);
        self::assertStringContainsString('1 user(s) now edit in workspaces only: editor.', $display);
        self::assertSame(0, (int)$users->select(['workspace_perms'], 'be_users', ['username' => 'editor'])->fetchOne());
        self::assertSame(1, (int)$users->select(['workspace_perms'], 'be_users', ['username' => 'outsider'])->fetchOne(), 'a user without the board is none of the check\'s business');
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: int, 1: string}
     */
    private function runCheck(array $arguments = []): array
    {
        $tester = new CommandTester($this->get(CheckInstallationCommand::class));
        $status = $tester->execute($arguments, ['interactive' => false]);

        return [$status, $tester->getDisplay()];
    }
}
