<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Subsidiary;
use PHPUnit\Framework\TestCase;

final class SubsidiaryOpeningHoursTest extends TestCase
{
    protected function setUp(): void
    {
        // getOpeningHoursFormatted() reads the default locale, so the expected weekday
        // abbreviations below stay stable regardless of the machine running the suite.
        \Locale::setDefault('de_DE');
    }

    public function testNewSubsidiaryHasNoOpeningHours(): void
    {
        $subsidiary = new Subsidiary();

        self::assertSame([], $subsidiary->getOpeningHours());
        self::assertSame('', $subsidiary->getOpeningHoursFormatted());
    }

    public function testHalfFilledAndEmptyRangesAreDropped(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setOpeningHours([
            1 => [['08:00', '12:00'], ['16:00', '']],
            2 => [['', ''], ['', '18:00']],
        ]);

        self::assertSame([1 => [['08:00', '12:00']]], $subsidiary->getOpeningHours());
    }

    public function testAnEntirelyEmptyGridBecomesNoOpeningHours(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setOpeningHours([
            1 => [['', ''], ['', '']],
            2 => [['', '']],
        ]);

        self::assertSame([], $subsidiary->getOpeningHours());
        self::assertSame('', $subsidiary->getOpeningHoursFormatted());
    }

    public function testWeekdaysOutsideTheIsoRangeAreIgnored(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setOpeningHours([
            0 => [['08:00', '12:00']],
            8 => [['08:00', '12:00']],
            3 => [['09:00', '13:00']],
        ]);

        self::assertSame([3 => [['09:00', '13:00']]], $subsidiary->getOpeningHours());
    }

    public function testStringKeysFromJsonDecodingAreAccepted(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setOpeningHours(['5' => [['10:00', '14:00']]]);

        self::assertSame([5 => [['10:00', '14:00']]], $subsidiary->getOpeningHours());
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
            $subsidiary->getOpeningHoursFormatted()
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
            $subsidiary->getOpeningHoursFormatted()
        );
    }

    public function testWeekdayNamesFollowTheLocale(): void
    {
        \Locale::setDefault('en_GB');

        $subsidiary = new Subsidiary();
        $subsidiary->setOpeningHours([7 => [['10:00', '12:00']]]);

        self::assertSame('Sun 10:00–12:00', $subsidiary->getOpeningHoursFormatted());
    }
}
