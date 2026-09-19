<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\GuestCategoryRepository;
use App\Service\OnlineBooking\OnlineBookingConfigService;
use App\Service\OnlineBooking\OnlineBookingRestrictionService;
use App\Service\OnlineBooking\PublicBookingAbuseProtectionService;
use App\Service\OnlineBooking\PublicBookingCalendarService;
use App\Service\OnlineBooking\PublicBookingRequestMapper;
use App\Service\OnlineBooking\PublicBookingService;
use App\Service\OnlineBooking\PublicBookingViewModelFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for calendar-specific public booking view data.
 */
final class PublicBookingViewModelFactoryTest extends TestCase
{
    public function testMonthsUntilExcludesAMonthContainingOnlyTheDepartureBoundary(): void
    {
        $firstOfThisMonth = (new \DateTimeImmutable('today'))->modify('first day of this month');
        $exclusiveEnd = $firstOfThisMonth->modify('+2 months');

        self::assertSame(2, $this->makeFactory()->monthsUntil($exclusiveEnd));
    }

    /** Build the factory with inert collaborators for its date-only helper. */
    private function makeFactory(): PublicBookingViewModelFactory
    {
        return new PublicBookingViewModelFactory(
            $this->createStub(OnlineBookingConfigService::class),
            $this->createStub(OnlineBookingRestrictionService::class),
            $this->createStub(PublicBookingAbuseProtectionService::class),
            $this->createStub(PublicBookingCalendarService::class),
            $this->createStub(PublicBookingService::class),
            $this->createStub(PublicBookingRequestMapper::class),
            $this->createStub(GuestCategoryRepository::class),
            $this->createStub(LoggerInterface::class),
        );
    }
}
