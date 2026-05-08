<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\GuestCategory;
use App\Entity\Reservation;
use App\Repository\GuestCategoryRepository;
use App\Service\InvoiceService;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ReservationGuestCountsTest extends TestCase
{
    public function testSetGuestCountsNormalizesValues(): void
    {
        $reservation = new Reservation();
        $reservation->setGuestCounts([1 => 2, 2 => 0, 3 => '3', 4 => -1]);

        self::assertSame([1 => 2, 3 => 3], $reservation->getGuestCounts());
        self::assertSame(2, $reservation->getCountForCategory(1));
        self::assertSame(0, $reservation->getCountForCategory(2));
    }

    public function testApplyGuestCountsRecomputesPersonsFromOccupancyFlags(): void
    {
        $adult = $this->makeCategory(1, isCountedInOccupancy: true, group: GuestStatisticalGroup::ADULT);
        $child = $this->makeCategory(2, isCountedInOccupancy: true, group: GuestStatisticalGroup::CHILD);
        $infant = $this->makeCategory(3, isCountedInOccupancy: false, group: GuestStatisticalGroup::INFANT);

        $service = $this->makeService([1 => $adult, 2 => $child, 3 => $infant]);

        $reservation = new Reservation();
        $service->applyGuestCounts($reservation, [1 => 2, 2 => 1, 3 => 1]);

        // Infant does not count toward bed capacity
        self::assertSame(3, $reservation->getPersons());
        self::assertSame([1 => 2, 2 => 1, 3 => 1], $reservation->getGuestCounts());
    }

    public function testGetCountByFlagAggregatesAcrossCategories(): void
    {
        $adult = $this->makeCategory(1, isCountedInOccupancy: true, group: GuestStatisticalGroup::ADULT);
        $child = $this->makeCategory(2, isCountedInOccupancy: true, group: GuestStatisticalGroup::CHILD);

        $service = $this->makeService([1 => $adult, 2 => $child]);

        $reservation = new Reservation();
        $reservation->setGuestCounts([1 => 2, 2 => 3]);

        self::assertSame(2, $service->getCountByFlag($reservation, 'isAdult'));
        self::assertSame(5, $service->getCountByFlag($reservation, 'isCountedInOccupancy'));
    }

    public function testIsAdultRuleSatisfied(): void
    {
        $adult = $this->makeCategory(1, isCountedInOccupancy: true, group: GuestStatisticalGroup::ADULT);
        $child = $this->makeCategory(2, isCountedInOccupancy: true, group: GuestStatisticalGroup::CHILD);

        $service = $this->makeService([1 => $adult, 2 => $child]);

        $withAdult = new Reservation();
        $withAdult->setGuestCounts([1 => 1, 2 => 2]);
        self::assertTrue($service->isAdultRuleSatisfied($withAdult));

        $childrenOnly = new Reservation();
        $childrenOnly->setGuestCounts([2 => 3]);
        self::assertFalse($service->isAdultRuleSatisfied($childrenOnly));

        $childrenOnly->setAdultRuleOverride(true);
        self::assertTrue($service->isAdultRuleSatisfied($childrenOnly));
    }

    /**
     * @param array<int, GuestCategory> $byId
     */
    private function makeService(array $byId): ReservationService
    {
        $repo = $this->createStub(GuestCategoryRepository::class);
        $repo->method('findBy')->willReturnCallback(function (array $criteria) use ($byId): array {
            $ids = $criteria['id'] ?? [];

            return array_values(array_intersect_key($byId, array_flip(array_map('intval', $ids))));
        });

        return new ReservationService(
            $this->createStub(EntityManagerInterface::class),
            new RequestStack(),
            $this->createStub(InvoiceService::class),
            $this->createStub(EventDispatcherInterface::class),
            $repo,
        );
    }

    private function makeCategory(
        int $id,
        bool $isCountedInOccupancy,
        GuestStatisticalGroup $group,
    ): GuestCategory {
        $category = new GuestCategory();
        $category->setIsCountedInOccupancy($isCountedInOccupancy);
        $category->setStatisticalGroup($group);
        $category->setName('cat'.$id);
        $category->setAcronym('C'.$id);
        // Inject id (entity has no setter for it — use reflection)
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($category, $id);

        return $category;
    }
}
