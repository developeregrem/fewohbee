<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\PriceBreakdown;
use App\Entity\Appartment;
use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\Enum\ModifierType;
use App\Entity\Enum\PercentageBase;
use App\Entity\Enum\RoomCategoryKind;
use App\Entity\GuestCategory;
use App\Entity\GuestCategoryModifier;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
use App\Repository\GuestCategoryModifierRepository;
use App\Repository\GuestCategoryRepository;
use App\Service\PriceService;
use App\Service\ReservationPeriodService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guest category modifiers on the surcharge a per-room price charges for every person beyond the
 * included ones.
 */
final class PriceServiceExtraPersonSurchargeTest extends TestCase
{
    private const ADULT = 1;
    private const CHILD = 2;
    private const TEEN = 3;
    private const INFANT = 4;

    /** @return iterable<string, array{0: array<int, int>, 1: float}> */
    public static function occupancies(): iterable
    {
        yield '1 adult' => [[self::ADULT => 1], 76.0];
        yield '2 adults' => [[self::ADULT => 2], 98.0];
        yield '1 adult + 1 child' => [[self::ADULT => 1, self::CHILD => 1], 84.0];
        yield '2 adults + 1 child' => [[self::ADULT => 2, self::CHILD => 1], 106.0];
        yield '2 adults + 2 children' => [[self::ADULT => 2, self::CHILD => 2], 114.0];
    }

    /**
     * 76 € for the first guest, 22 € for every further adult, 8 € for every child.
     *
     * @param array<int, int> $guestCounts
     */
    #[DataProvider('occupancies')]
    public function testAChildRateAppliesToTheSurchargeOfARoomPrice(array $guestCounts, float $perNight): void
    {
        $reservation = $this->reservation($guestCounts);
        $service = $this->service($this->roomPrice('76.00', 1, '22.00'), [$this->modifier(self::CHILD, ModifierType::FLAT_RATE, '8')]);

        $totals = $service->getApartmentTotalsPerNight($reservation, PercentageBase::GROSS);

        self::assertSame($perNight, $totals['2026-06-01']);
    }

    public function testAdultsAreIncludedFirstSoTheChildPaysItsOwnRate(): void
    {
        $night = $this->night([self::CHILD => 1, self::ADULT => 1], '76.00', 1, '22.00', [$this->modifier(self::CHILD, ModifierType::FLAT_RATE, '8')]);

        self::assertCount(1, $night->extraPersonLines ?? []);
        $line = $night->extraPersonLines[0];
        self::assertSame(self::CHILD, $line->category->getId());
        self::assertSame(1, $line->count);
        self::assertSame(8.0, $line->unitPrice);
        self::assertSame(ModifierType::FLAT_RATE, $line->adjustment?->type);
        self::assertSame([], $night->lines, 'a room price has no per-head lines');
    }

    public function testAChildWithinTheIncludedPersonsPaysNothing(): void
    {
        $night = $this->night([self::ADULT => 1, self::CHILD => 1], '80.00', 2, '15.00', [$this->modifier(self::CHILD, ModifierType::FLAT_RATE, '8')]);

        self::assertSame([], $night->extraPersonLines);
        self::assertSame(0.0, $night->extraPersonTotal(2));
    }

    public function testFurtherAdultsPayTheFullSurcharge(): void
    {
        $night = $this->night([self::ADULT => 3], '80.00', 2, '15.00', []);

        self::assertCount(1, $night->extraPersonLines ?? []);
        self::assertSame(1, $night->extraPersonLines[0]->count);
        self::assertSame(15.0, $night->extraPersonLines[0]->unitPrice);
        self::assertNull($night->extraPersonLines[0]->adjustment);
    }

    /** @return iterable<string, array{0: ModifierType, 1: string, 2: float}> */
    public static function modifiers(): iterable
    {
        yield 'discount' => [ModifierType::DISCOUNT_PERCENT, '50', 11.0];
        yield 'surcharge' => [ModifierType::SURCHARGE_ABSOLUTE, '5', 27.0];
        yield 'flat rate' => [ModifierType::FLAT_RATE, '8', 8.0];
        yield 'free' => [ModifierType::FREE, '0', 0.0];
    }

    #[DataProvider('modifiers')]
    public function testTheModifierChangesTheSurchargeLikeAPerHeadRate(ModifierType $type, string $value, float $childPays): void
    {
        $night = $this->night([self::ADULT => 1, self::CHILD => 1], '76.00', 1, '22.00', [$this->modifier(self::CHILD, $type, $value)]);

        self::assertSame($childPays, $night->extraPersonTotal(2));
    }

    public function testTheOlderChildIsIncludedSoTheLargerDiscountStaysWithTheFurtherGuest(): void
    {
        // Teens rank before children (lower sortOrder) and take the remaining included seat.
        $night = $this->night([self::ADULT => 1, self::TEEN => 1, self::CHILD => 1], '90.00', 2, '20.00', [
            $this->modifier(self::TEEN, ModifierType::DISCOUNT_PERCENT, '25'),
            $this->modifier(self::CHILD, ModifierType::FREE, '0'),
        ]);

        self::assertCount(1, $night->extraPersonLines ?? []);
        self::assertSame(self::CHILD, $night->extraPersonLines[0]->category->getId());
        self::assertSame(0.0, $night->extraPersonTotal(3));
    }

    public function testMinFullPayersKeepsTheFirstFurtherGuestsAtTheFullSurcharge(): void
    {
        // Included: the adult. Full surcharge: the first child (3 full-fare guests). Child rate: the second.
        $night = $this->night([self::ADULT => 1, self::CHILD => 3], '76.00', 1, '22.00', [$this->modifier(self::CHILD, ModifierType::FLAT_RATE, '8')], minFullPayers: 3);

        self::assertCount(2, $night->extraPersonLines ?? []);
        self::assertSame([2, 22.0, null], [$night->extraPersonLines[0]->count, $night->extraPersonLines[0]->unitPrice, $night->extraPersonLines[0]->adjustment]);
        self::assertSame([1, 8.0], [$night->extraPersonLines[1]->count, $night->extraPersonLines[1]->unitPrice]);
        self::assertSame(52.0, $night->extraPersonTotal(4));
    }

    public function testGuestsNotCountedInTheOccupancyPayNothing(): void
    {
        $night = $this->night([self::ADULT => 2, self::INFANT => 1], '80.00', 1, '15.00', []);

        self::assertCount(1, $night->extraPersonLines ?? []);
        self::assertSame(self::ADULT, $night->extraPersonLines[0]->category->getId());
        self::assertSame(15.0, $night->extraPersonTotal(2));
    }

    public function testAnEventSpaceKeepsOneSurchargeForEveryone(): void
    {
        $reservation = $this->reservation([self::ADULT => 50, self::CHILD => 10], RoomCategoryKind::EVENT_SPACE);
        $service = $this->service($this->roomPrice('500.00', 50, '5.00'), [$this->modifier(self::CHILD, ModifierType::FREE, '0')]);

        $night = $service->getPriceBreakdownForReservation($reservation)[0];

        self::assertNull($night->extraPersonLines);
        self::assertSame(50.0, $night->extraPersonTotal(60));
    }

    public function testGuestCountsThatDoNotAddUpToThePersonsBillThePersons(): void
    {
        // A deleted guest category: the counts know one guest less than the reservation has.
        $reservation = $this->reservation([self::ADULT => 1, self::CHILD => 1, 99 => 1]);
        $reservation->setPersons(3);
        $service = $this->service($this->roomPrice('76.00', 1, '22.00'), [$this->modifier(self::CHILD, ModifierType::FLAT_RATE, '8')]);

        $night = $service->getPriceBreakdownForReservation($reservation)[0];

        self::assertNull($night->extraPersonLines);
        self::assertSame(44.0, $night->extraPersonTotal(3));
    }

    public function testWithoutGuestCountsEveryFurtherPersonPaysTheSurcharge(): void
    {
        $reservation = $this->reservation([]);
        $reservation->setPersons(3);
        $service = $this->service($this->roomPrice('76.00', 1, '22.00'), []);

        $night = $service->getPriceBreakdownForReservation($reservation)[0];

        self::assertNull($night->extraPersonLines);
        self::assertSame(44.0, $night->extraPersonTotal(3));
    }

    /**
     * @param array<int, int>         $guestCounts
     * @param GuestCategoryModifier[] $modifiers
     */
    private function night(array $guestCounts, string $roomPrice, int $included, string $surcharge, array $modifiers, int $minFullPayers = 0): PriceBreakdown
    {
        $reservation = $this->reservation($guestCounts, minFullPayers: $minFullPayers);

        return $this->service($this->roomPrice($roomPrice, $included, $surcharge), $modifiers)->getPriceBreakdownForReservation($reservation)[0];
    }

    /** @param array<int, int> $guestCounts */
    private function reservation(array $guestCounts, RoomCategoryKind $kind = RoomCategoryKind::ACCOMMODATION, int $minFullPayers = 0): Reservation
    {
        $roomCategory = (new RoomCategory())->setKind($kind)->setMinFullPayers($minFullPayers);
        $room = new Appartment();
        $room->setRoomCategory($roomCategory);

        $r = new Reservation();
        $r->setAppartment($room);
        $r->setStartDate(new \DateTime('2026-06-01'));
        $r->setEndDate(new \DateTime('2026-06-02'));
        $r->setGuestCounts($guestCounts);
        $r->setPersons(array_sum(array_diff_key($guestCounts, [self::INFANT => true])));

        return $r;
    }

    /** @param GuestCategoryModifier[] $modifiers */
    private function service(Price $price, array $modifiers): PriceService
    {
        $categories = [
            $this->category(self::ADULT, GuestStatisticalGroup::ADULT, 1),
            $this->category(self::TEEN, GuestStatisticalGroup::CHILD, 2),
            $this->category(self::CHILD, GuestStatisticalGroup::CHILD, 3),
            $this->category(self::INFANT, GuestStatisticalGroup::CHILD, 4, counted: false),
        ];
        $catRepo = $this->createStub(GuestCategoryRepository::class);
        $catRepo->method('findAll')->willReturn($categories);
        $modRepo = $this->createStub(GuestCategoryModifierRepository::class);
        $modRepo->method('findActiveOn')->willReturn($modifiers);

        return new class($this->createStub(EntityManagerInterface::class), $catRepo, $modRepo, $price) extends PriceService {
            public function __construct(
                EntityManagerInterface $em,
                GuestCategoryRepository $catRepo,
                GuestCategoryModifierRepository $modRepo,
                private readonly Price $price,
            ) {
                parent::__construct($em, new ReservationPeriodService(), $catRepo, $modRepo);
            }

            public function getPricesForReservationDays(Reservation $reservation, int $type, ?\Doctrine\Common\Collections\Collection $prices = null): array
            {
                return [[$this->price]];
            }
        };
    }

    private function roomPrice(string $amount, int $included, string $surcharge): Price
    {
        $p = new Price();
        $p->setPrice($amount);
        $p->setVat(7.0);
        $p->setIncludesVat(true);
        $p->setIsPerRoom(true);
        $p->setNumberOfPersons($included);
        $p->setExtraPersonSurcharge($surcharge);
        $p->setDescription('apt');

        return $p;
    }

    private function category(int $id, GuestStatisticalGroup $group, int $sortOrder, bool $counted = true): GuestCategory
    {
        $c = new GuestCategory();
        $c->setName('cat'.$id);
        $c->setAcronym('C'.$id);
        $c->setStatisticalGroup($group);
        $c->setSortOrder($sortOrder);
        $c->setIsCountedInOccupancy($counted);
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($c, $id);

        return $c;
    }

    private function modifier(int $categoryId, ModifierType $type, string $value): GuestCategoryModifier
    {
        $category = new GuestCategory();
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($category, $categoryId);

        $m = new GuestCategoryModifier();
        $m->setCategory($category);
        $m->setType($type);
        $m->setValue($value);

        return $m;
    }
}
