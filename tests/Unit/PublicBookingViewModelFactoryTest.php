<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\GuestCategoryRepository;
use App\Service\OnlineBookingConfigService;
use App\Service\OnlineBookingRestrictionService;
use App\Service\PublicBookingAbuseProtectionService;
use App\Service\PublicBookingCalendarService;
use App\Service\PublicBookingRequestMapper;
use App\Service\PublicBookingService;
use App\Service\PublicBookingViewModelFactory;
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
