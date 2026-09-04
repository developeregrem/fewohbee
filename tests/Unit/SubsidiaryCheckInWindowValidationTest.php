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

    public function testAWindowEndingBeforeItBeginsIsRejected(): void
    {
        self::assertTrue($this->isInvalid('20:00', '17:00'));
    }

    /**
     * Equal ends leave no window at all, which is never what was meant.
     */
    public function testAZeroLengthWindowIsRejected(): void
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
