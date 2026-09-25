<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use PHPUnit\Framework\TestCase;

final class GuestCheckInTest extends TestCase
{
    public function testRepeatedSubmissionKeepsTheFirstTimestamp(): void
    {
        $checkIn = new GuestCheckIn(new Reservation(), 'selector');
        $first = new \DateTimeImmutable('2026-09-01 10:00');
        $second = new \DateTimeImmutable('2026-09-02 12:00');

        $checkIn->recordSubmission(['v' => 1, 'arrivalTime' => '17:00'], $first);
        $checkIn->recordSubmission(['v' => 1, 'arrivalTime' => '18:00'], $second);

        self::assertSame(GuestCheckInStatus::SUBMITTED, $checkIn->getStatus());
        self::assertSame($first, $checkIn->getFirstSubmittedAt());
        self::assertSame($second, $checkIn->getLastSubmittedAt());
        self::assertSame('18:00', $checkIn->getPayload()['arrivalTime'] ?? null);
    }

    public function testApplyingDropsTheSubmittedData(): void
    {
        $checkIn = new GuestCheckIn(new Reservation(), 'selector');
        $checkIn->recordSubmission(['v' => 1], new \DateTimeImmutable());
        $appliedAt = new \DateTimeImmutable('2026-09-03 09:00');

        $checkIn->markApplied($appliedAt);

        self::assertSame(GuestCheckInStatus::APPLIED, $checkIn->getStatus());
        self::assertSame($appliedAt, $checkIn->getAppliedAt());
        self::assertFalse($checkIn->hasPayload());
    }

    public function testDiscardingReopensTheCheckIn(): void
    {
        $checkIn = new GuestCheckIn(new Reservation(), 'selector');
        $checkIn->recordSubmission(['v' => 1], new \DateTimeImmutable());

        $checkIn->discard();

        self::assertSame(GuestCheckInStatus::OPEN, $checkIn->getStatus());
        self::assertFalse($checkIn->hasPayload());
    }
}
