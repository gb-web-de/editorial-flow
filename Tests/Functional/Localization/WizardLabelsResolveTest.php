<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Localization;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A label key that does not exist resolves to an empty string rather than
 * failing, so a typo in one reaches the editor as a blank error message and no
 * other test notices. These assert the keys the wizard actually asks for.
 *
 * The wizard's JavaScript reads the same file as the `editorial_flow.messages`
 * translation domain, and there a missing key is worse than blank: core's
 * LabelProvider.get() throws, which rejects the dynamic step import and leaves
 * the editor an empty wizard. Those keys are scraped from the modules too.
 */
final class WizardLabelsResolveTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['editorial_flow'];

    protected array $coreExtensionsToLoad = ['workspaces', 'dashboard'];

    #[Test]
    public function everyLabelTheWizardAsksForHasText(): void
    {
        $languageService = $this->get(LanguageServiceFactory::class)->create('default');

        $missing = [];
        foreach ($this->wizardLabelKeys() as $key) {
            if (trim($languageService->sL('editorial_flow.messages:' . $key)) === '') {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing, 'These label keys resolve to nothing: ' . implode(', ', $missing));
    }

    #[Test]
    public function everyErrorShownToAnEditorCarriesItsOwnCode(): void
    {
        $languageService = $this->get(LanguageServiceFactory::class)->create('default');

        $codes = [];
        foreach ($this->wizardLabelKeys() as $key) {
            // The two namespaces that hold messages *about* a failure. Not
            // every key mentioning "error" is one - `step.error.title` names
            // the wizard's error step, and a heading carries no code.
            if (!str_starts_with($key, 'wizard.error.') && !str_starts_with($key, 've.error.')) {
                continue;
            }

            $label = $languageService->sL('editorial_flow.messages:' . $key);
            self::assertMatchesRegularExpression(
                '/\(CF-\d{4}\)$/',
                $label,
                sprintf('"%s" is shown to an editor but carries no error code.', $key),
            );

            preg_match('/\(CF-(\d{4})\)$/', $label, $matches);
            $codes[] = $matches[1];
        }

        self::assertSame(array_unique($codes), $codes, 'Two different errors share one code.');
    }

    /**
     * @return list<string>
     */
    private function wizardLabelKeys(): array
    {
        return array_values(array_unique([
            ...$this->providerLabelKeys(),
            ...$this->controllerLabelKeys(),
            ...$this->javaScriptLabelKeys(),
        ]));
    }

    /**
     * The controller translates through its own label() helper rather than the
     * provider's translate(), so its keys need scraping of their own. veLabel()
     * is the Visual Editor's wrapper around the same helper and is caught by the
     * same pattern.
     *
     * Several of these carry an `?: 'English fallback'` so a missing key degrades
     * to readable text rather than to a blank message. That is deliberate - some
     * of them are persisted into a comment an editor reads back later - but it
     * also means nothing else would ever notice the key being gone, which is
     * what this scrape is for.
     *
     * @return list<string>
     */
    private function controllerLabelKeys(): array
    {
        $source = file_get_contents(__DIR__ . '/../../../Classes/Controller/TaskAjaxController.php');
        self::assertIsString($source);

        preg_match_all("/(?:ve)?[Ll]abel\('([^']+)'/", $source, $matches);

        $keys = array_values(array_unique($matches[1]));
        self::assertNotEmpty($keys, 'No label() calls found - has the helper been renamed?');

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function providerLabelKeys(): array
    {
        $source = file_get_contents(__DIR__ . '/../../../Classes/Wizard/TaskWizardProvider.php');
        self::assertIsString($source);

        preg_match_all("/translate\('([^']+)'/", $source, $matches);

        $keys = array_values(array_unique($matches[1]));
        self::assertNotEmpty($keys, 'No translate() calls found - has the provider been renamed?');

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function javaScriptLabelKeys(): array
    {
        $keys = [];
        $modules = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../../Resources/Public/JavaScript'),
        );

        foreach ($modules as $module) {
            if (!$module->isFile() || $module->getExtension() !== 'js') {
                continue;
            }

            $source = file_get_contents($module->getPathname());
            self::assertIsString($source);

            preg_match_all("/labels\.get\('([^']+)'/", $source, $matches);
            $keys = [...$keys, ...$matches[1]];
        }

        $keys = array_values(array_unique($keys));
        self::assertNotEmpty($keys, 'No labels.get() calls found - has the label import been renamed?');

        return $keys;
    }
}
