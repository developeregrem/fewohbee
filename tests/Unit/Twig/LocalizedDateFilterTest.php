<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Service\Localization\ContentTranslator;
use App\Service\Localization\GuestLocaleService;
use App\Service\Localization\GuestSalutations;
use App\Twig\ContentTranslationExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\LocaleSwitcher;

final class LocalizedDateFilterTest extends TestCase
{
    /** @return iterable<string, array{string, string|\DateTimeInterface, string}> */
    public static function dates(): iterable
    {
        yield 'German' => ['de', '2026-11-28', '28.11.2026'];
        yield 'English' => ['en', '2026-11-28', 'Nov 28, 2026'];
        yield 'date object' => ['de', new \DateTimeImmutable('2026-03-01'), '01.03.2026'];
        // Late evening at +02:00 is already the next day in UTC; the calendar day must win.
        yield 'offset timezone keeps the day' => ['de', new \DateTimeImmutable('2026-11-28 23:30', new \DateTimeZone('+02:00')), '28.11.2026'];
    }

    #[DataProvider('dates')]
    public function testFormatsInTheActiveLanguage(string $locale, string|\DateTimeInterface $date, string $expected): void
    {
        self::assertSame($expected, $this->extension($locale)->localizedDate($date));
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function passthrough(): iterable
    {
        yield 'null' => [null, ''];
        yield 'empty' => ['', ''];
        yield 'not a date' => ['next week', 'next week'];
        // createFromFormat would roll this over to March; the guest's input is shown as sent.
        yield 'impossible date' => ['2026-02-30', '2026-02-30'];
    }

    #[DataProvider('passthrough')]
    public function testLeavesNonDatesAlone(?string $value, string $expected): void
    {
        self::assertSame($expected, $this->extension('de')->localizedDate($value));
    }

    private function extension(string $locale): ContentTranslationExtension
    {
        return new ContentTranslationExtension(
            $this->createStub(ContentTranslator::class),
            $this->createStub(GuestLocaleService::class),
            new LocaleSwitcher($locale, []),
            $this->createStub(GuestSalutations::class),
        );
    }
}
