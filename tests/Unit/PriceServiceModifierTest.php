<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\Enum\ModifierType;
use App\Entity\GuestCategory;
use App\Entity\GuestCategoryModifier;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Repository\GuestCategoryModifierRepository;
use App\Repository\GuestCategoryRepository;
use App\Service\PriceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PriceServiceModifierTest extends TestCase
{
    public function testNoModifiersFallsBackToBasePricePerHead(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD);

        $reservation = $this->makeReservation([1 => 2, 2 => 1]);
        $service = $this->makeService([$adult, $child], [], $reservation, $this->makePrice('80.00'));

        $breakdowns = $service->getPriceBreakdownForReservation($reservation);

        self::assertCount(2, $breakdowns); // 2 nights
        $night0 = $breakdowns[0];
        self::assertSame(2, $night0->lines[0]->count);
        self::assertSame(80.0, $night0->lines[0]->unitPrice);
        self::assertSame(1, $night0->lines[1]->count);
        self::assertSame(80.0, $night0->lines[1]->unitPrice); // no modifier => base
        self::assertSame(240.0, $night0->total());
    }

    public function testDiscountPercentReducesNonAdultUnitPrice(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD);
        $modifier = $this->makeModifier($child, ModifierType::DISCOUNT_PERCENT, '50');

        $reservation = $this->makeReservation([1 => 2, 2 => 1]);
        $service = $this->makeService([$adult, $child], [$modifier], $reservation, $this->makePrice('100.00'));

        $night = $service->getPriceBreakdownForReservation($reservation)[0];
        $childLine = $night->lines[1];
        self::assertSame(50.0, $childLine->unitPrice);
        self::assertSame(250.0, $night->total());
    }

    public function testFlatRateOverridesBasePrice(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD);
        $modifier = $this->makeModifier($child, ModifierType::FLAT_RATE, '30.00');

        $reservation = $this->makeReservation([1 => 2, 2 => 2]);
        $service = $this->makeService([$adult, $child], [$modifier], $reservation, $this->makePrice('100.00'));

        $night = $service->getPriceBreakdownForReservation($reservation)[0];
        self::assertSame(30.0, $night->lines[1]->unitPrice);
        self::assertSame(260.0, $night->total());
    }

    public function testFreeYieldsZero(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $infant = $this->makeCategory(3, GuestStatisticalGroup::INFANT);
        $modifier = $this->makeModifier($infant, ModifierType::FREE, '0');

        $reservation = $this->makeReservation([1 => 2, 3 => 2]);
        $service = $this->makeService([$adult, $infant], [$modifier], $reservation, $this->makePrice('80.00'));

        $night = $service->getPriceBreakdownForReservation($reservation)[0];
        $infantLine = $night->lines[1];
        self::assertSame(0.0, $infantLine->unitPrice);
        self::assertSame(160.0, $night->total());
    }

    public function testSurchargeAbsoluteAddsToBase(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $other = $this->makeCategory(4, GuestStatisticalGroup::OTHER);
        $modifier = $this->makeModifier($other, ModifierType::SURCHARGE_ABSOLUTE, '15');

        $reservation = $this->makeReservation([1 => 1, 4 => 1]);
        $service = $this->makeService([$adult, $other], [$modifier], $reservation, $this->makePrice('100.00'));

        $night = $service->getPriceBreakdownForReservation($reservation)[0];
        self::assertSame(115.0, $night->lines[1]->unitPrice);
        self::assertSame(215.0, $night->total());
    }

    public function testAdultIsNeverModified(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        // A misconfigured FREE modifier on the adult category must be ignored.
        $modifier = $this->makeModifier($adult, ModifierType::FREE, '0');

        $reservation = $this->makeReservation([1 => 2]);
        $service = $this->makeService([$adult], [$modifier], $reservation, $this->makePrice('120.00'));

        $night = $service->getPriceBreakdownForReservation($reservation)[0];
        self::assertSame(120.0, $night->lines[0]->unitPrice);
        self::assertSame(240.0, $night->total());
    }

    public function testInactiveOrOutOfRangeModifierIsFilteredByRepository(): void
    {
        // The repository is responsible for active/date filtering, so simulate
        // it by returning an empty list — base price must apply.
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD);

        $reservation = $this->makeReservation([1 => 1, 2 => 1]);
        $service = $this->makeService([$adult, $child], [], $reservation, $this->makePrice('60.00'));

        $night = $service->getPriceBreakdownForReservation($reservation)[0];
        self::assertSame(60.0, $night->lines[1]->unitPrice);
        self::assertSame(120.0, $night->total());
    }

    public function testEmptyGuestCountsProducesNoLines(): void
    {
        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT);

        $reservation = $this->makeReservation([]);
        $service = $this->makeService([$adult], [], $reservation, $this->makePrice('80.00'));

        $night = $service->getPriceBreakdownForReservation($reservation)[0];
        self::assertSame([], $night->lines);
        self::assertSame(0.0, $night->total());
    }

    /**
     * @param GuestCategory[]         $categories
     * @param GuestCategoryModifier[] $modifiers
     */
    private function makeService(array $categories, array $modifiers, Reservation $reservation, Price $price): PriceService
    {
        $byId = [];
        foreach ($categories as $c) {
            $byId[$c->getId()] = $c;
        }

        $catRepo = $this->createStub(GuestCategoryRepository::class);
        $catRepo->method('findAll')->willReturn(array_values($byId));

        $modRepo = $this->createStub(GuestCategoryModifierRepository::class);
        $modRepo->method('findActiveOn')->willReturn($modifiers);

        $em = $this->createStub(EntityManagerInterface::class);

        return new class($em, $catRepo, $modRepo, $price) extends PriceService {
            public function __construct(
                EntityManagerInterface $em,
                GuestCategoryRepository $catRepo,
                GuestCategoryModifierRepository $modRepo,
                private readonly Price $price,
            ) {
                parent::__construct($em, $catRepo, $modRepo);
            }

            public function getPricesForReservationDays(Reservation $reservation, int $type, ?\Doctrine\Common\Collections\Collection $prices = null): array
            {
                $days = max(1, (int) $reservation->getStartDate()->diff($reservation->getEndDate())->format('%a'));
                $out = [];
                for ($i = 0; $i < $days; ++$i) {
                    $out[$i] = [$this->price];
                }

                return $out;
            }
        };
    }

    private function makeReservation(array $guestCounts): Reservation
    {
        $r = new Reservation();
        $r->setStartDate(new \DateTime('2026-06-01'));
        $r->setEndDate(new \DateTime('2026-06-03'));
        $r->setGuestCounts($guestCounts);

        return $r;
    }

    private function makeCategory(int $id, GuestStatisticalGroup $group): GuestCategory
    {
        $c = new GuestCategory();
        $c->setName('cat'.$id);
        $c->setAcronym('C'.$id);
        $c->setStatisticalGroup($group);
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($c, $id);

        return $c;
    }

    private function makeModifier(GuestCategory $category, ModifierType $type, string $value): GuestCategoryModifier
    {
        $m = new GuestCategoryModifier();
        $m->setCategory($category);
        $m->setType($type);
        $m->setValue($value);

        return $m;
    }

    private function makePrice(string $value): Price
    {
        $p = new Price();
        $p->setPrice($value);
        $p->setVat(7.0);
        $p->setDescription('apt');

        return $p;
    }
}
