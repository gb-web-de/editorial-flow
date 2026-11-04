<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Unit\Setup;

use GbWeb\EditorialFlow\Setup\SetupScenario;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SetupScenarioTest extends UnitTestCase
{
    /**
     * These strings are typed by people and written into deployment scripts
     * and the documentation - renaming one breaks both.
     */
    #[Test]
    public function scenarioNamesAreStable(): void
    {
        self::assertSame(['fresh', 'single-group', 'group-per-workspace', 'existing-groups'], SetupScenario::values());
    }

    #[Test]
    public function everyScenarioHasALabelForTheWizard(): void
    {
        foreach (SetupScenario::cases() as $scenario) {
            self::assertNotSame('', $scenario->label());
        }
    }
}
