<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\Tool\PriceTools;
use PHPUnit\Framework\TestCase;

final class RateCalendarPeriodsTest extends TestCase
{
    public function testMergesConsecutiveNightsWithIdenticalRates(): void
    {
        $season = [['occupancy' => 2, 'priceId' => 7, 'perNight' => 80.0]];
        $event = [['occupancy' => 2, 'priceId' => 9, 'perNight' => 120.0]];

        $periods = PriceTools::mergeNights([
            ['date' => '2026-10-01', 'rates' => $season],
            ['date' => '2026-10-02', 'rates' => $season],
            ['date' => '2026-10-03', 'rates' => $event],
            ['date' => '2026-10-04', 'rates' => []],
            ['date' => '2026-10-05', 'rates' => $season],
        ]);

        self::assertSame([
            ['firstNight' => '2026-10-01', 'lastNight' => '2026-10-02', 'rates' => $season],
            ['firstNight' => '2026-10-03', 'lastNight' => '2026-10-03', 'rates' => $event],
            ['firstNight' => '2026-10-04', 'lastNight' => '2026-10-04', 'rates' => []],
            ['firstNight' => '2026-10-05', 'lastNight' => '2026-10-05', 'rates' => $season],
        ], $periods);
    }
}
