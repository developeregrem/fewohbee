<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;

/**
 * Translation files are YAML, and English is the source language of the translation platform.
 * German and English are written together and share their keys; community languages come from
 * the platform and may only translate keys the English file has. A lost placeholder would leave
 * the parameter as literal text in the page, so every translation keeps the English ones.
 */
final class TranslationFilesTest extends TestCase
{
    /** Overrides of bundle translations; the bundle ships the English text itself. */
    private const BUNDLE_DOMAINS = ['ResetPasswordBundle'];

    private const PLACEHOLDER = '/%[A-Za-z0-9_.]+%|\{\{ ?[A-Za-z0-9_.]+ ?\}\}/';

    public function testTranslationFilesAreYaml(): void
    {
        $other = array_filter(
            array_map(static fn (string $file): string => substr($file, \strlen(self::directory()) + 1), self::files()),
            static fn (string $file): bool => !str_ends_with($file, '.yaml'),
        );

        self::assertSame([], array_values($other), 'Translation files must be <domain>.<locale>.yaml.');
    }

    #[DataProvider('germanAndEnglishFiles')]
    public function testGermanAndEnglishShareTheirKeys(string $base): void
    {
        self::assertFileExists($base.'.de.yaml');
        self::assertFileExists($base.'.en.yaml');

        $de = self::messages($base, 'de');
        $en = self::messages($base, 'en');

        self::assertSame([], array_keys(array_diff_key($en, $de)), 'Keys missing in German.');
        self::assertSame([], array_keys(array_diff_key($de, $en)), 'Keys missing in English.');
    }

    /** A loop, not a data provider: there may be no community language yet. */
    public function testCommunityTranslationsOnlyHaveEnglishKeys(): void
    {
        $problems = [];
        foreach (self::localeFiles() as [$base, $locale]) {
            if (\in_array($locale, ['de', 'en'], true)) {
                continue;
            }
            $file = self::label($base).'.'.$locale.'.yaml';
            if (!is_file($base.'.en.yaml')) {
                $problems[$file] = 'no English source file';
                continue;
            }
            $unknown = array_keys(array_diff_key(self::messages($base, $locale), self::messages($base, 'en')));
            if ([] !== $unknown) {
                $problems[$file] = $unknown;
            }
        }

        self::assertSame([], $problems, 'Community translations may only translate keys of the English file.');
    }

    #[DataProvider('translatedFiles')]
    public function testTranslationKeepsThePlaceholders(string $base, string $locale): void
    {
        $en = self::messages($base, 'en');
        $broken = [];
        foreach (self::messages($base, $locale) as $key => $text) {
            if (isset($en[$key]) && self::placeholders($text) !== self::placeholders($en[$key])) {
                $broken[] = $key;
            }
        }

        self::assertSame([], $broken, 'Placeholders differ from the English text.');
    }

    /** @return iterable<string, array{string}> */
    public static function germanAndEnglishFiles(): iterable
    {
        $bases = [];
        foreach (self::localeFiles() as [$base, $locale]) {
            if (\in_array($locale, ['de', 'en'], true)) {
                $bases[$base] = true;
            }
        }

        foreach (array_keys($bases) as $base) {
            yield self::label($base) => [$base];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function translatedFiles(): iterable
    {
        foreach (self::localeFiles() as [$base, $locale]) {
            if ('en' !== $locale && is_file($base.'.en.yaml')) {
                yield self::label($base).'.'.$locale => [$base, $locale];
            }
        }
    }

    /**
     * YAML translation files outside the bundle overrides, as [path without ".<locale>.yaml", locale].
     *
     * @return list<array{string, string}>
     */
    private static function localeFiles(): array
    {
        $result = [];
        foreach (self::files() as $file) {
            if (1 !== preg_match('/^(?<base>.+\/(?<domain>[^\/.]+))\.(?<locale>[A-Za-z_]+)\.yaml$/', $file, $match)) {
                continue;
            }
            if (!\in_array($match['domain'], self::BUNDLE_DOMAINS, true)) {
                $result[] = [$match['base'], $match['locale']];
            }
        }

        return $result;
    }

    /** @return list<string> */
    private static function files(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::directory(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ('.gitignore' !== $file->getFilename()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return array<string, string> */
    private static function messages(string $base, string $locale): array
    {
        $domain = basename($base);

        return (new YamlFileLoader())->load($base.'.'.$locale.'.yaml', $locale, $domain)->all($domain);
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $match);
        $placeholders = array_values(array_unique($match[0]));
        sort($placeholders);

        return $placeholders;
    }

    private static function directory(): string
    {
        return \dirname(__DIR__, 3).'/translations';
    }

    private static function label(string $base): string
    {
        return substr($base, \strlen(self::directory()) + 1);
    }
}
