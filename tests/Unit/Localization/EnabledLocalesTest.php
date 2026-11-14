<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Yaml\Yaml;

/**
 * The application languages are offered to guests, named and formatted through Intl. A code Intl
 * does not know as a language (e.g. the country code "gr" instead of "el" for Greek) would show
 * up as the bare code and quietly fall back to English for country names, dates and numbers.
 */
final class EnabledLocalesTest extends TestCase
{
    #[DataProvider('configuredLocales')]
    public function testConfiguredLocaleIsALanguageKnownToIntl(string $locale): void
    {
        self::assertTrue(
            Languages::exists($locale),
            \sprintf('"%s" in framework.enabled_locales / translator.fallbacks is no ISO 639 language code.', $locale),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function configuredLocales(): iterable
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/translation.yaml');
        $framework = $config['framework'] ?? [];
        $locales = [...($framework['enabled_locales'] ?? []), ...($framework['translator']['fallbacks'] ?? [])];

        foreach (array_unique($locales) as $locale) {
            yield $locale => [$locale];
        }
    }
}
