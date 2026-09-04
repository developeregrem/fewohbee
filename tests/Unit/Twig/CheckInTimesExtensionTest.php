<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Subsidiary;
use App\Twig\CheckInTimesExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class CheckInTimesExtensionTest extends TestCase
{
    public function testAClosedWindowIsPrintedAsARange(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setCheckInFrom(new \DateTimeImmutable('17:00'));
        $subsidiary->setCheckInUntil(new \DateTimeImmutable('20:00'));
        $subsidiary->setCheckOutUntil(new \DateTimeImmutable('10:00'));

        self::assertSame(
            'Check-in 17:00–20:00 Uhr · Check-out bis 10:00 Uhr',
            $this->extension('de')->checkInTimes($subsidiary)
        );
    }

    /**
     * "From 17:00" and "17:00-20:00" are different promises to the guest, so an absent
     * upper bound must not be invented.
     */
    public function testAnOpenEndedWindowDropsTheUpperBound(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setCheckInFrom(new \DateTimeImmutable('17:00'));
        $subsidiary->setCheckOutUntil(new \DateTimeImmutable('10:00'));

        self::assertSame(
            'Check-in ab 17:00 Uhr · Check-out bis 10:00 Uhr',
            $this->extension('de')->checkInTimes($subsidiary)
        );
    }

    public function testEitherHalfCanStandAlone(): void
    {
        $checkOutOnly = new Subsidiary();
        $checkOutOnly->setCheckOutUntil(new \DateTimeImmutable('10:00'));

        self::assertSame('Check-out bis 10:00 Uhr', $this->extension('de')->checkInTimes($checkOutOnly));

        $checkInOnly = new Subsidiary();
        $checkInOnly->setCheckInFrom(new \DateTimeImmutable('17:00'));

        self::assertSame('Check-in ab 17:00 Uhr', $this->extension('de')->checkInTimes($checkInOnly));
    }

    /**
     * The regression the opening hours extension was moved out of the entity for: a
     * workflow mail is rendered from the command line, where the ambient locale is
     * whatever the server was built with. The wording must follow the application.
     */
    public function testTheWordingFollowsTheTranslatorAndNotTheAmbientLocale(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setCheckInFrom(new \DateTimeImmutable('17:00'));

        self::assertSame('Check-in ab 17:00 Uhr', $this->extension('de')->checkInTimes($subsidiary));
        self::assertSame('Check-in from 17:00', $this->extension('en')->checkInTimes($subsidiary));
    }

    public function testUnconfiguredTimesRenderAsAnEmptyString(): void
    {
        self::assertSame('', $this->extension('de')->checkInTimes(new Subsidiary()));
    }

    public function testNoBranchRendersAsAnEmptyString(): void
    {
        // invoice.subsidiary is nullable, so the snippet's data-if must get '' not a crash.
        self::assertSame('', $this->extension('de')->checkInTimes(null));
    }

    private function extension(string $locale): CheckInTimesExtension
    {
        $translator = new Translator($locale);
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'object.check_in.value.from' => 'Check-in ab %from% Uhr',
            'object.check_in.value.window' => 'Check-in %from%–%until% Uhr',
            'object.check_out.value' => 'Check-out bis %until% Uhr',
        ], 'de');
        $translator->addResource('array', [
            'object.check_in.value.from' => 'Check-in from %from%',
            'object.check_in.value.window' => 'Check-in %from%-%until%',
            'object.check_out.value' => 'Check-out by %until%',
        ], 'en');

        return new CheckInTimesExtension($translator);
    }
}
