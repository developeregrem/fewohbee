<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Subsidiary;
use App\Twig\OpeningHoursExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OpeningHoursExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        // Deliberately different from the locales used below, so a test that passes
        // can only be reading the translator and not the ambient default.
        \Locale::setDefault('en_US');
    }

    public function testConsecutiveWeekdaysWithEqualHoursAreFolded(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setOpeningHours([
            1 => [['08:00', '12:00'], ['16:00', '19:00']],
            2 => [['08:00', '12:00'], ['16:00', '19:00']],
            3 => [['08:00', '12:00'], ['16:00', '19:00']],
            4 => [['08:00', '12:00'], ['16:00', '19:00']],
            5 => [['08:00', '12:00'], ['16:00', '19:00']],
            6 => [['09:00', '12:00']],
        ]);

        self::assertSame(
            'Mo.–Fr. 08:00–12:00, 16:00–19:00 · Sa. 09:00–12:00',
            $this->extension('de')->openingHours($subsidiary)
        );
    }

    public function testAClosedDayInterruptsTheFolding(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setOpeningHours([
            1 => [['08:00', '12:00']],
            2 => [['08:00', '12:00']],
            // Wednesday closed.
            4 => [['08:00', '12:00']],
            5 => [['08:00', '12:00']],
        ]);

        self::assertSame(
            'Mo.–Di. 08:00–12:00 · Do.–Fr. 08:00–12:00',
            $this->extension('de')->openingHours($subsidiary)
        );
    }

    /**
     * The regression this extension exists for: a workflow mail is rendered from the
     * command line, where \Locale::getDefault() is whatever the server was built with.
     * The weekday names must follow the application's locale regardless.
     */
    public function testWeekdayNamesFollowTheTranslatorAndNotTheAmbientLocale(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setOpeningHours([1 => [['08:00', '12:00']]]);

        self::assertSame('Mo. 08:00–12:00', $this->extension('de')->openingHours($subsidiary));
        self::assertSame('Mon 08:00–12:00', $this->extension('en')->openingHours($subsidiary));
    }

    public function testUnconfiguredHoursRenderAsAnEmptyString(): void
    {
        self::assertSame('', $this->extension('de')->openingHours(new Subsidiary()));
    }

    public function testNoBranchRendersAsAnEmptyString(): void
    {
        // invoice.subsidiary is nullable, so the snippet's data-if must get '' not a crash.
        self::assertSame('', $this->extension('de')->openingHours(null));
    }

    private function extension(string $locale): OpeningHoursExtension
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn($locale);

        return new OpeningHoursExtension($translator);
    }
}
