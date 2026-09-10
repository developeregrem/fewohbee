<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Subsidiary;
use PHPUnit\Framework\TestCase;

final class SubsidiaryCheckInTimesTest extends TestCase
{
    public function testNewSubsidiaryPublishesNoCheckInTimes(): void
    {
        $subsidiary = new Subsidiary();

        self::assertNull($subsidiary->getCheckInFrom());
        self::assertNull($subsidiary->getCheckInUntil());
        self::assertNull($subsidiary->getCheckOutUntil());
        self::assertNull($subsidiary->getCheckInNote());
    }

    public function testTheTimesAreKeptAsGiven(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setCheckInFrom(new \DateTimeImmutable('17:00'));
        $subsidiary->setCheckInUntil(new \DateTimeImmutable('20:00'));
        $subsidiary->setCheckOutUntil(new \DateTimeImmutable('10:00'));

        self::assertSame('17:00', $subsidiary->getCheckInFrom()?->format('H:i'));
        self::assertSame('20:00', $subsidiary->getCheckInUntil()?->format('H:i'));
        self::assertSame('10:00', $subsidiary->getCheckOutUntil()?->format('H:i'));
    }

    /**
     * An arrival window without an upper bound is a normal case, not an incomplete one:
     * "from 17:00" is a complete statement, unlike a half-filled opening-hours range.
     */
    public function testAnOpenEndedArrivalWindowIsAllowed(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setCheckInFrom(new \DateTimeImmutable('17:00'));

        self::assertSame('17:00', $subsidiary->getCheckInFrom()?->format('H:i'));
        self::assertNull($subsidiary->getCheckInUntil());
    }

    public function testAnEmptyNoteBecomesNoNote(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setCheckInNote('   ');

        self::assertNull($subsidiary->getCheckInNote());
    }

    public function testTheNoteIsTrimmed(): void
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setCheckInNote('  Später nach Absprache  ');

        self::assertSame('Später nach Absprache', $subsidiary->getCheckInNote());
    }
}
