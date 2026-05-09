<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\GuestCategory;
use App\Repository\GuestCategoryRepository;
use App\Service\GuestCategoryAgeMapper;
use PHPUnit\Framework\TestCase;

final class GuestCategoryAgeMapperTest extends TestCase
{
    public function testAdultsOnlyMapsToAdultCategory(): void
    {
        $adult = $this->cat(1, GuestStatisticalGroup::ADULT, sortOrder: 10);
        $child = $this->cat(2, GuestStatisticalGroup::CHILD, sortOrder: 20, minAge: 6, maxAge: 17);

        $mapper = $this->makeMapper([$adult, $child]);
        self::assertSame([1 => 2], $mapper->map(2, []));
    }

    public function testChildAgeMapsToCorrectAgeBucket(): void
    {
        $adult = $this->cat(1, GuestStatisticalGroup::ADULT, sortOrder: 10);
        $infant = $this->cat(2, GuestStatisticalGroup::INFANT, sortOrder: 20, minAge: 0, maxAge: 2);
        $childSmall = $this->cat(3, GuestStatisticalGroup::CHILD, sortOrder: 30, minAge: 3, maxAge: 9);
        $childBig = $this->cat(4, GuestStatisticalGroup::CHILD, sortOrder: 40, minAge: 10, maxAge: 17);

        $mapper = $this->makeMapper([$adult, $infant, $childSmall, $childBig]);
        // 2 adults, ages 1, 5, 12 → infant + childSmall + childBig
        self::assertSame(
            [1 => 2, 2 => 1, 3 => 1, 4 => 1],
            $mapper->map(2, [1, 5, 12]),
        );
    }

    public function testMultipleChildrenSameAgeAggregate(): void
    {
        $adult = $this->cat(1, GuestStatisticalGroup::ADULT, sortOrder: 10);
        $child = $this->cat(2, GuestStatisticalGroup::CHILD, sortOrder: 20, minAge: 6, maxAge: 17);

        $mapper = $this->makeMapper([$adult, $child]);
        self::assertSame([1 => 2, 2 => 3], $mapper->map(2, [8, 10, 14]));
    }

    public function testAgeOutsideAllRangesIsDropped(): void
    {
        $adult = $this->cat(1, GuestStatisticalGroup::ADULT, sortOrder: 10);
        $child = $this->cat(2, GuestStatisticalGroup::CHILD, sortOrder: 20, minAge: 6, maxAge: 17);

        $mapper = $this->makeMapper([$adult, $child]);
        // Age 3 falls outside the 6–17 range; nothing matches → drop.
        self::assertSame([1 => 2, 2 => 1], $mapper->map(2, [3, 8]));
    }

    public function testNullBoundsTreatedAsUnbounded(): void
    {
        $adult = $this->cat(1, GuestStatisticalGroup::ADULT, sortOrder: 10);
        $catchAll = $this->cat(2, GuestStatisticalGroup::CHILD, sortOrder: 20, minAge: null, maxAge: null);

        $mapper = $this->makeMapper([$adult, $catchAll]);
        self::assertSame([1 => 1, 2 => 2], $mapper->map(1, [0, 99]));
    }

    public function testOverlappingRangesUseSortOrderTiebreak(): void
    {
        $adult = $this->cat(1, GuestStatisticalGroup::ADULT, sortOrder: 10);
        $childA = $this->cat(2, GuestStatisticalGroup::CHILD, sortOrder: 30, minAge: 6, maxAge: 12);
        $childB = $this->cat(3, GuestStatisticalGroup::CHILD, sortOrder: 20, minAge: 6, maxAge: 12);

        $mapper = $this->makeMapper([$adult, $childA, $childB]);
        // Both match age 8; lower sortOrder (childB at 20) wins.
        self::assertSame([1 => 1, 3 => 1], $mapper->map(1, [8]));
    }

    public function testSingleUnboundedNonAdultCategoryAcceptsAnyAge(): void
    {
        // Hotelier configured a single catch-all child category (no age
        // bounds). The wizard can omit the age picker and submit any
        // placeholder age — the unbounded category matches every age.
        $adult = $this->cat(1, GuestStatisticalGroup::ADULT, sortOrder: 10);
        $child = $this->cat(2, GuestStatisticalGroup::CHILD, sortOrder: 20, minAge: null, maxAge: null);

        $mapper = $this->makeMapper([$adult, $child]);
        self::assertSame([1 => 2, 2 => 3], $mapper->map(2, [0, 0, 0]));
    }

    public function testOtherCategoriesAreIgnored(): void
    {
        $adult = $this->cat(1, GuestStatisticalGroup::ADULT, sortOrder: 10);
        $other = $this->cat(2, GuestStatisticalGroup::OTHER, sortOrder: 20, minAge: 0, maxAge: 99);
        $child = $this->cat(3, GuestStatisticalGroup::CHILD, sortOrder: 30, minAge: 6, maxAge: 17);

        $mapper = $this->makeMapper([$adult, $other, $child]);
        self::assertSame([1 => 1, 3 => 1], $mapper->map(1, [10]));
    }

    public function testEmptyInputProducesEmptyMap(): void
    {
        $adult = $this->cat(1, GuestStatisticalGroup::ADULT, sortOrder: 10);
        $mapper = $this->makeMapper([$adult]);
        self::assertSame([], $mapper->map(0, []));
    }

    /**
     * @param GuestCategory[] $categories
     */
    private function makeMapper(array $categories): GuestCategoryAgeMapper
    {
        $repo = $this->createStub(GuestCategoryRepository::class);
        $repo->method('findActiveOrdered')->willReturn($categories);

        return new GuestCategoryAgeMapper($repo);
    }

    private function cat(int $id, GuestStatisticalGroup $group, int $sortOrder, ?int $minAge = null, ?int $maxAge = null): GuestCategory
    {
        $c = new GuestCategory();
        $c->setName('cat'.$id);
        $c->setAcronym('C'.$id);
        $c->setStatisticalGroup($group);
        $c->setSortOrder($sortOrder);
        $c->setMinAge($minAge);
        $c->setMaxAge($maxAge);
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($c, $id);

        return $c;
    }
}
