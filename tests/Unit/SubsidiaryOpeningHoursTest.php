<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Subsidiary;
use PHPUnit\Framework\TestCase;

final class SubsidiaryOpeningHoursTest extends TestCase
{
    public function testNewSubsidiaryHasNoOpeningHours(): void
    {
        self::assertSame([], (new Subsidiary())->getOpeningHours());
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
}
