<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Unit\Setup;

use GbWeb\EditorialFlow\Setup\InvalidSetupOptions;
use GbWeb\EditorialFlow\Setup\SetupOptions;
use GbWeb\EditorialFlow\Setup\SetupScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SetupOptionsTest extends UnitTestCase
{
    #[Test]
    public function freshNeedsNoOptionsAtAll(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::Fresh, []);

        self::assertSame([SetupOptions::DEFAULT_GROUP], $options->groups);
        self::assertSame(SetupOptions::DEFAULT_GROUP, $options->workspace, 'the workspace is named after the group');
        self::assertSame(SetupOptions::DEFAULT_STAGES, $options->stages);
        self::assertSame('/', $options->siteBase);
        self::assertFalse($options->dryRun);
    }

    #[Test]
    public function singleGroupTakesAnyGroupName(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, ['group' => 'Online-Redaktion']);

        self::assertSame(['Online-Redaktion'], $options->groups);
        self::assertSame(['Online-Redaktion' => ['Online-Redaktion']], $options->workspacesWithMemberGroups());
    }

    #[Test]
    public function singleGroupCanWorkInAWorkspaceOfAnotherName(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::SingleGroup, ['group' => 'Redaktion', 'workspace' => 'Entwurf']);

        self::assertSame(['Entwurf' => ['Redaktion']], $options->workspacesWithMemberGroups());
    }

    #[Test]
    public function groupPerWorkspaceGivesEveryGroupItsOwnWorkspace(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::GroupPerWorkspace, ['group' => ['News,Sport', 'Kultur']]);

        self::assertSame(
            ['News' => ['News'], 'Sport' => ['Sport'], 'Kultur' => ['Kultur']],
            $options->workspacesWithMemberGroups(),
        );
    }

    #[Test]
    public function existingGroupsDefaultsToTheAccessGroupAndLeavesWorkspacesAlone(): void
    {
        $options = SetupOptions::fromArray(SetupScenario::ExistingGroups, ['attach-to-editors' => true]);

        self::assertSame([SetupOptions::DEFAULT_ACCESS_GROUP], $options->groups);
        self::assertNull($options->workspace);
    }

    #[Test]
    public function stagesCanBeSwitchedOff(): void
    {
        self::assertSame([], SetupOptions::fromArray(SetupScenario::Fresh, ['stages' => 'none'])->stages);
    }

    #[Test]
    public function stagesKeepTheirOrder(): void
    {
        self::assertSame(
            ['Lektorat', 'Rechtliche Prüfung', 'Freigabe'],
            SetupOptions::fromArray(SetupScenario::Fresh, ['stages' => 'Lektorat, Rechtliche Prüfung ,Freigabe'])->stages,
        );
    }

    public static function invalidOptions(): \Generator
    {
        yield 'two groups for single-group' => [SetupScenario::SingleGroup, ['group' => 'A,B'], 'exactly one group'];
        yield 'no groups for group-per-workspace' => [SetupScenario::GroupPerWorkspace, [], 'Name the groups'];
        yield 'workspace for group-per-workspace' => [SetupScenario::GroupPerWorkspace, ['group' => 'A', 'workspace' => 'X'], '--workspace does not apply'];
        yield 'existing-groups without targets' => [SetupScenario::ExistingGroups, [], '--attach-to'];
        yield 'attach-to outside existing-groups' => [SetupScenario::SingleGroup, ['attach-to' => 'X'], 'only applies'];
        yield 'core stage as custom stage' => [SetupScenario::Fresh, ['stages' => 'Review,Ready to publish'], 'fixed stages'];
        yield 'group title too long' => [SetupScenario::SingleGroup, ['group' => str_repeat('x', 51)], 'longer than 50'];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('invalidOptions')]
    #[Test]
    public function refusesOptionsThatCannotWork(SetupScenario $scenario, array $values, string $expectedMessage): void
    {
        $this->expectException(InvalidSetupOptions::class);
        $this->expectExceptionMessage($expectedMessage);

        SetupOptions::fromArray($scenario, $values);
    }
}
