<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\OnlineBooking\OccupancyPreselector;
use PHPUnit\Framework\TestCase;

final class OccupancyPreselectorTest extends TestCase
{
    private OccupancyPreselector $preselector;

    protected function setUp(): void
    {
        $this->preselector = new OccupancyPreselector();
    }

    public function testSingleRoomTypePreselectsTheOccupancyMatchingTheParty(): void
    {
        $availability = [$this->row('category:1', 3, [1, 2, 3, 4])];

        self::assertSame(['category:1' => [2 => 1]], $this->preselector->preselect($availability, 1, 2));
    }

    public function testCategoryTooSmallForThePartyLeavesTheOtherOneAsSoleChoice(): void
    {
        $availability = [
            $this->row('category:1', 1, [1]),
            $this->row('category:2', 1, [1, 2]),
        ];

        self::assertSame(['category:2' => [2 => 1]], $this->preselector->preselect($availability, 1, 2));
    }

    public function testTwoFittingCategoriesLeaveTheChoiceToTheGuest(): void
    {
        $availability = [
            $this->row('category:1', 1, [1, 2]),
            $this->row('category:2', 1, [1, 2]),
        ];

        self::assertSame([], $this->preselector->preselect($availability, 1, 2));
    }

    public function testSeveralRoomsOfOneTypeArePreselectedWhenOnlyOneSplitWorks(): void
    {
        $availability = [$this->row('category:1', 2, [1, 2])];

        self::assertSame(['category:1' => [2 => 2]], $this->preselector->preselect($availability, 2, 4));
    }

    public function testSeveralPossibleSplitsLeaveTheChoiceToTheGuest(): void
    {
        $availability = [$this->row('category:1', 2, [1, 2, 3])];

        // 1+3 and 2+2 both seat four guests in two rooms.
        self::assertSame([], $this->preselector->preselect($availability, 2, 4));
    }

    public function testOptionLimitedByRoomCapacitiesIsRespected(): void
    {
        // Two rooms of the type, but only one of them takes two or more guests.
        $availability = [$this->row('category:1', 2, [1, 2, 3], [1 => 2, 2 => 1, 3 => 1])];

        self::assertSame(['category:1' => [1 => 1, 3 => 1]], $this->preselector->preselect($availability, 2, 4));
    }

    public function testNothingIsPreselectedWhenNoCombinationFits(): void
    {
        $availability = [$this->row('category:1', 1, [1, 2])];

        self::assertSame([], $this->preselector->preselect($availability, 2, 4));
    }

    public function testMoreRoomsThanGuestsNeverFit(): void
    {
        $availability = [$this->row('category:1', 3, [1, 2])];

        self::assertSame([], $this->preselector->preselect($availability, 3, 2));
    }

    public function testLargeGroupWithASingleFittingSplitIsFound(): void
    {
        // Only the first type sleeps six; twenty rooms for 120 guests leave no choice.
        $availability = [$this->row('category:1', 20, [1, 2, 3, 4, 5, 6])];
        for ($i = 2; $i <= 12; ++$i) {
            $availability[] = $this->row('category:'.$i, 20, [1, 2, 3, 4, 5]);
        }

        self::assertSame(['category:1' => [6 => 20]], $this->preselector->preselect($availability, 20, 120));
    }

    public function testLargeGroupSearchWithManySplitsEndsWithoutPreselection(): void
    {
        $availability = [];
        for ($i = 1; $i <= 12; ++$i) {
            $availability[] = $this->row('category:'.$i, 20, [1, 2, 3, 4, 5, 6]);
        }

        self::assertSame([], $this->preselector->preselect($availability, 20, 70));
    }

    /**
     * @param list<int>       $persons
     * @param array<int, int> $occupancyAvailableCounts
     *
     * @return array{typeKey: string, availableCount: int, occupancyOptions: list<array{persons: int}>, occupancyAvailableCounts: array<int, int>}
     */
    private function row(string $typeKey, int $availableCount, array $persons, array $occupancyAvailableCounts = []): array
    {
        return [
            'typeKey' => $typeKey,
            'availableCount' => $availableCount,
            'occupancyOptions' => array_map(static fn (int $p): array => ['persons' => $p], $persons),
            'occupancyAvailableCounts' => $occupancyAvailableCounts + array_fill_keys($persons, $availableCount),
        ];
    }
}
