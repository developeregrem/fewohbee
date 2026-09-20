<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\SubsidiaryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The form warns instead of silently storing a window the operator did not mean.
 */
final class SubsidiaryCheckInWindowValidationTest extends TestCase
{
    public function testAWindowInOrderIsAccepted(): void
    {
        self::assertFalse($this->isInvalid('17:00', '20:00'));
    }

    public function testAnOpenEndedWindowIsAccepted(): void
    {
        self::assertFalse($this->isInvalid('17:00', ''));
    }

    public function testNoTimesAtAllAreAccepted(): void
    {
        self::assertFalse($this->isInvalid('', ''));
    }

    /**
     * A window that never starts: the operator most likely meant to fill both fields.
     */
    public function testAClosingTimeWithoutAnOpeningOneIsRejected(): void
    {
        self::assertTrue($this->isInvalid('', '20:00'));
    }

    /**
     * A late reception that runs past midnight. Reported by Alex on #284: 17:00-02:00 is
     * a perfectly ordinary thing for a house to publish, and an earlier end must not be
     * read as a mistake.
     */
    public function testAWindowRunningPastMidnightIsAccepted(): void
    {
        self::assertFalse($this->isInvalid('17:00', '02:00'));
    }

    public function testAWindowEndingOneMinuteBeforeItStartsIsStillAWindow(): void
    {
        // 23 hours and 59 minutes long, not a negative window.
        self::assertFalse($this->isInvalid('17:00', '16:59'));
    }

    /**
     * Equal ends say nothing: neither a zero-length window nor a full day is a plausible
     * reading, so the operator is asked rather than guessed at.
     */
    public function testAWindowWithTwoIdenticalEndsIsRejected(): void
    {
        self::assertTrue($this->isInvalid('17:00', '17:00'));
    }

    private function isInvalid(string $from, string $until): bool
    {
        $request = new Request([], [
            'check-in-from-new' => $from,
            'check-in-until-new' => $until,
        ]);

        $service = new SubsidiaryService($this->createStub(EntityManagerInterface::class));

        return $service->hasInvalidCheckInWindow($request, 'new');
    }
}
