<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ReservationStatus;
use App\Service\DisplayNameResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DisplayNameResolverTest extends TestCase
{
    private const LOCALES = ['de', 'en'];

    public function testUserCreatedStatusIsReturnedVerbatim(): void
    {
        $status = (new ReservationStatus())->setName('Abgereist/Abgerechnet');

        self::assertSame('Abgereist/Abgerechnet', $this->makeResolver([])->resolve($status));
    }

    public function testSystemStatusIsLabelledFromItsCode(): void
    {
        // The stored name is the German literal the migration wrote into every
        // existing installation — the translation has to win over it.
        $status = (new ReservationStatus())
            ->setName('Storniert / No-Show')
            ->setCode(ReservationStatus::CODE_CANCELED_NOSHOW);

        $resolver = $this->makeResolver(['status.canceled_noshow' => 'Canceled / No-show']);

        self::assertSame('Canceled / No-show', $resolver->resolve($status));
    }

    public function testStoredNameIsUsedWhenTheKeyHasNoTranslation(): void
    {
        $status = (new ReservationStatus())
            ->setName('Storniert / No-Show')
            ->setCode('some_future_code');

        // Whoever adds a system code without translations must still not see a
        // raw key in the UI.
        self::assertSame('Storniert / No-Show', $this->makeResolver([])->resolve($status));
    }

    public function testSortingFollowsTheTranslatedNameNotTheStoredOne(): void
    {
        $resolver = $this->makeResolver(['status.canceled_noshow' => 'Canceled / No-show']);

        $statuses = [
            (new ReservationStatus())->setName('Option'),
            (new ReservationStatus())->setName('Storniert / No-Show')->setCode(ReservationStatus::CODE_CANCELED_NOSHOW),
            (new ReservationStatus())->setName('Bestätigt'),
        ];

        $sorted = array_map(
            fn (ReservationStatus $s) => $resolver->resolve($s),
            $resolver->sortByDisplayName($statuses),
        );

        // Sorting on the stored names would have put the system status last.
        self::assertSame(['Bestätigt', 'Canceled / No-show', 'Option'], $sorted);
    }

    /**
     * A system label is only translatable if its key exists in every shipped
     * locale — otherwise the resolver silently falls back to the literal from
     * the migration and the bug is back. This guards that contract so a new
     * system code cannot be merged without its translations.
     */
    public function testEverySystemStatusCodeHasATranslationInEveryLocale(): void
    {
        $codes = [];
        foreach ((new \ReflectionClass(ReservationStatus::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'CODE_') && \is_string($value)) {
                $codes[] = $value;
            }
        }
        self::assertNotEmpty($codes, 'No system status codes found — the guard would pass vacuously.');

        foreach (self::LOCALES as $locale) {
            $catalogue = $this->catalogue($locale);

            foreach ($codes as $code) {
                self::assertArrayHasKey(
                    'status.'.$code,
                    $catalogue,
                    "Missing {$locale} translation for system status code '{$code}'.",
                );
            }
        }
    }

    /**
     * @param array<string, string> $catalogue
     */
    private function makeResolver(array $catalogue): DisplayNameResolver
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id) => $catalogue[$id] ?? $id,
        );

        return new DisplayNameResolver($translator);
    }

    /**
     * Load the ReservationStatus messages domain, flattened to dotted keys.
     *
     * @return array<string, string>
     */
    private function catalogue(string $locale): array
    {
        $base = \dirname(__DIR__, 2).'/translations/ReservationStatus/messages.'.$locale;

        if (is_file($base.'.yaml')) {
            return (new YamlFileLoader())->load($base.'.yaml', $locale)->all('messages');
        }

        if (is_file($base.'.xlf')) {
            return (new XliffFileLoader())->load($base.'.xlf', $locale)->all('messages');
        }

        self::fail("No ReservationStatus translation file for locale '{$locale}'.");
    }
}
