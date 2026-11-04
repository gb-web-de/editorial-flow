<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Unit\Setup;

use GbWeb\EditorialFlow\Setup\CommaList;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CommaListTest extends UnitTestCase
{
    #[Test]
    public function parseTrimsDropsEmptiesAndDuplicates(): void
    {
        self::assertSame(['News', 'Sport'], CommaList::parse(' News , ,Sport,News'));
    }

    #[Test]
    public function parseAcceptsARepeatedOption(): void
    {
        self::assertSame(['News', 'Sport', 'Kultur'], CommaList::parse(['News,Sport', 'Kultur']));
    }

    /**
     * The reason this class exists: setup adds to a group's module list and
     * must never drop a module the integrator gave it.
     */
    #[Test]
    public function mergeKeepsEverythingThatWasThere(): void
    {
        self::assertSame(
            'web_layout,records,web_editorialflow',
            CommaList::merge('web_layout,records', ['web_editorialflow', 'records']),
        );
    }

    #[Test]
    public function mergeIntoAnEmptyListHasNoLeadingComma(): void
    {
        self::assertSame('be_groups_3', CommaList::merge('', ['be_groups_3']));
    }

    #[Test]
    public function containsAllComparesWholeEntriesNotSubstrings(): void
    {
        self::assertTrue(CommaList::containsAll('be_groups_3,be_users_1', ['be_users_1']));
        self::assertFalse(
            CommaList::containsAll('be_groups_13', ['be_groups_1']),
            'be_groups_1 is not a member just because be_groups_13 is',
        );
    }
}
