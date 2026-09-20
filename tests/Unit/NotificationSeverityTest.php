<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\NotificationSeverity;
use PHPUnit\Framework\TestCase;

/**
 * The notification badge is drawn on two different backgrounds, and the colours
 * that work on one are not the ones that work on the other.
 */
final class NotificationSeverityTest extends TestCase
{
    public function testTheInfoBadgeIsNeverTheNavbarColour(): void
    {
        // The navbar is bg-primary (#2196f3). An info badge in the same class was
        // literally invisible — contrast 1.0 against its own background.
        self::assertStringNotContainsString(
            'bg-primary',
            NotificationSeverity::INFO->badgeClassOnPrimary()
        );
    }

    public function testEverySeverityCarriesAFillAndATextColourOnTheNavbar(): void
    {
        foreach (NotificationSeverity::cases() as $severity) {
            $classes = $severity->badgeClassOnPrimary();
            self::assertMatchesRegularExpression('/\bbg-[a-z]+\b/', $classes, $severity->value);
            self::assertMatchesRegularExpression('/\btext-[a-z]+\b/', $classes, $severity->value);
        }
    }

    public function testTheAmberBadgeUsesDarkTextInBothContexts(): void
    {
        // White on #ff9800 only reaches 2.2:1; dark text reaches 7.4:1.
        self::assertStringContainsString('text-dark', NotificationSeverity::WARNING->badgeClass());
        self::assertStringContainsString('text-dark', NotificationSeverity::WARNING->badgeClassOnPrimary());
    }

    public function testCriticalStaysRedOnBothBackgrounds(): void
    {
        // Whatever else changes, a conflict has to keep reading as urgent.
        self::assertStringContainsString('bg-danger', NotificationSeverity::CRITICAL->badgeClass());
        self::assertStringContainsString('bg-danger', NotificationSeverity::CRITICAL->badgeClassOnPrimary());
    }

    public function testCriticalOutranksTheOthers(): void
    {
        self::assertGreaterThan(NotificationSeverity::WARNING->weight(), NotificationSeverity::CRITICAL->weight());
        self::assertGreaterThan(NotificationSeverity::INFO->weight(), NotificationSeverity::WARNING->weight());
    }
}
