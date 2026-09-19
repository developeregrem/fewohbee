<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Price;
use App\Entity\RoomCategory;
use App\Repository\PriceRepository;
use App\Service\PriceService;
use App\Service\ReservationPeriodService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** Covers the price logic that depends on a price being bound to several room categories. */
final class PriceServiceRoomCategoriesTest extends TestCase
{
    public function testGroupsPricesByTheirExactCategorySet(): void
    {
        $single = $this->makeCategory(1, 'Single');
        $double = $this->makeCategory(2, 'Double');

        $both = $this->makePrice('single and double', 2, $single, $double);
        // Same set, added in the other order: must land in the same group.
        $bothReversed = $this->makePrice('double and single', 2, $double, $single);
        $singleOnly = $this->makePrice('single only', 2, $single);

        $groups = $this->makeService()->groupByRoomCategories([$both, $singleOnly, $bothReversed]);

        self::assertCount(2, $groups);
        self::assertSame([$singleOnly], $groups[0]->apartmentPrices);
        // Categories inside a group keep their creation order.
        self::assertSame(['Single', 'Double'], $this->names($groups[1]->roomCategories));
        self::assertSame([$both, $bothReversed], $groups[1]->apartmentPrices);
    }

    public function testGroupsFollowCategoryCreationOrderThenCombinationsThenAllCategories(): void
    {
        // Ids reflect creation order; names deliberately sort the other way round.
        $single = $this->makeCategory(1, 'Single');
        $double = $this->makeCategory(2, 'Double');
        $apartment = $this->makeCategory(3, 'Apartment');

        $groups = $this->makeService()->groupByRoomCategories([
            $this->makePrice('breakfast', 1),
            $this->makePrice('single and apartment', 1, $single, $apartment),
            $this->makePrice('apartment', 2, $apartment),
            $this->makePrice('single and double', 1, $double, $single),
            $this->makePrice('double', 2, $double),
            $this->makePrice('single', 2, $single),
        ]);

        self::assertSame(
            [['Single'], ['Double'], ['Apartment'], ['Single', 'Double'], ['Single', 'Apartment'], []],
            array_map(fn ($group): array => $this->names($group->roomCategories), $groups),
        );
    }

    public function testSplitsEachGroupIntoRoomAndMiscPricesKeepingTheirOrder(): void
    {
        $single = $this->makeCategory(1, 'Single');
        $cleaning = $this->makePrice('cleaning', 1, $single);
        $roomOne = $this->makePrice('room for one', 2, $single);
        $parking = $this->makePrice('parking', 1, $single);
        $roomTwo = $this->makePrice('room for two', 2, $single);

        $groups = $this->makeService()->groupByRoomCategories([$cleaning, $roomOne, $parking, $roomTwo]);

        self::assertCount(1, $groups);
        self::assertSame([$roomOne, $roomTwo], $groups[0]->apartmentPrices);
        self::assertSame([$cleaning, $parking], $groups[0]->miscPrices);
    }

    public function testMiscPricesNeverConflictAndSkipTheQuery(): void
    {
        $priceRepository = $this->createMock(PriceRepository::class);
        $priceRepository->expects(self::never())->method('findConflictingPricesWithoutPeriod');
        $priceRepository->expects(self::never())->method('findConflictingPricesWithPeriod');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($priceRepository);
        $service = new PriceService($em, new ReservationPeriodService());

        $misc = $this->makePrice('breakfast', 1);

        self::assertCount(0, $service->findConflictingPrices($misc));
    }

    private function makeService(): PriceService
    {
        return new PriceService($this->createStub(EntityManagerInterface::class), new ReservationPeriodService());
    }

    private function makeCategory(int $id, string $name): RoomCategory
    {
        $category = new RoomCategory();
        $category->setName($name);
        (new \ReflectionProperty(RoomCategory::class, 'id'))->setValue($category, $id);

        return $category;
    }

    private function makePrice(string $description, int $type, RoomCategory ...$categories): Price
    {
        $price = new Price();
        $price->setDescription($description);
        $price->setType($type);
        foreach ($categories as $category) {
            $price->addRoomCategory($category);
        }

        return $price;
    }

    /**
     * @param list<RoomCategory> $categories
     *
     * @return list<string|null>
     */
    private function names(array $categories): array
    {
        return array_map(static fn (RoomCategory $category): ?string => $category->getName(), $categories);
    }
}
