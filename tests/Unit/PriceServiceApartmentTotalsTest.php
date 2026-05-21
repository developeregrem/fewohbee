<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\Enum\ModifierType;
use App\Entity\Enum\PercentageBase;
use App\Entity\GuestCategory;
use App\Entity\GuestCategoryModifier;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Repository\GuestCategoryModifierRepository;
use App\Repository\GuestCategoryRepository;
use App\Service\PriceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PriceServiceApartmentTotalsTest extends TestCase
{
    public function testPerHeadNetFromGrossStoredPrice(): void
    {
        // 119 € gross per head, 19% VAT, 2 adults → 238 € gross → 200 € net per night
        $price = $this->makePrice('119.00', includesVat: true, vat: 19.0, flat: false, perRoom: false);
        $service = $this->makeService($price);

        $totals = $service->getApartmentTotalsPerNight($this->reservation(), PercentageBase::NET);

        self::assertSame(['2026-06-01' => 200.0, '2026-06-02' => 200.0], $totals);
    }

    public function testPerHeadGrossFromNetStoredPrice(): void
    {
        // 100 € net per head, 19% VAT, 2 adults → 200 € net → 238 € gross
        $price = $this->makePrice('100.00', includesVat: false, vat: 19.0, flat: false, perRoom: false);
        $service = $this->makeService($price);

        $totals = $service->getApartmentTotalsPerNight($this->reservation(), PercentageBase::GROSS);

        self::assertSame(['2026-06-01' => 238.0, '2026-06-02' => 238.0], $totals);
    }

    public function testFlatPriceIgnoresGuestCount(): void
    {
        $price = $this->makePrice('150.00', includesVat: true, vat: 7.0, flat: true, perRoom: false);
        $service = $this->makeService($price);

        $netTotals = $service->getApartmentTotalsPerNight($this->reservation(), PercentageBase::NET);
        $grossTotals = $service->getApartmentTotalsPerNight($this->reservation(), PercentageBase::GROSS);

        self::assertEqualsWithDelta(140.187, $netTotals['2026-06-01'], 0.01);
        self::assertSame(150.0, $grossTotals['2026-06-01']);
    }

    public function testPerRoomIgnoresGuestCount(): void
    {
        $price = $this->makePrice('120.00', includesVat: false, vat: 7.0, flat: false, perRoom: true);
        $service = $this->makeService($price);

        $grossTotals = $service->getApartmentTotalsPerNight($this->reservation(), PercentageBase::GROSS);

        self::assertEqualsWithDelta(128.4, $grossTotals['2026-06-01'], 0.01);
    }

    public function testZeroVatTreatsNetAndGrossEqually(): void
    {
        $price = $this->makePrice('100.00', includesVat: true, vat: 0.0, flat: false, perRoom: false);
        $service = $this->makeService($price);

        $net = $service->getApartmentTotalsPerNight($this->reservation(), PercentageBase::NET);
        $gross = $service->getApartmentTotalsPerNight($this->reservation(), PercentageBase::GROSS);

        self::assertSame($net, $gross);
        self::assertSame(200.0, $net['2026-06-01']); // 100€ × 2 adults

    }

    public function testApartmentBaseMatchesInvoiceLineWithModifier(): void
    {
        // Reproduziert den User-Case: Doppelzimmer (numberOfPersons=2),
        // Per-Head 50€ brutto. Reservation: 1 Erw + 1 Kind (Pauschal 14€) + 1 Baby (kein Modifier).
        // Apartment-Rechnungszeile: 2 × 3 × 50 = 300€ brutto
        // Modifier-Delta: 1 × 3 × (14 - 50) = -108€
        // Effektive Apartment-Basis pro Nacht: 100 + (-36) = 64€ brutto
        // 3 Nächte total: 192€. Das Baby (ohne Modifier) verändert die Basis NICHT
        // mehr — anders als bei der naiven guestCounts-Summe (50+14+50=114).
        $price = new Price();
        $price->setPrice('50.00');
        $price->setVat(7.0);
        $price->setIncludesVat(true);
        $price->setIsFlatPrice(false);
        $price->setNumberOfPersons(2);

        $adult = $this->makeCategory(1, GuestStatisticalGroup::ADULT, true);
        $child = $this->makeCategory(2, GuestStatisticalGroup::CHILD, true);
        $infant = $this->makeCategory(3, GuestStatisticalGroup::INFANT, true);

        $childModifier = new GuestCategoryModifier();
        $childModifier->setCategory($child);
        $childModifier->setType(ModifierType::FLAT_RATE);
        $childModifier->setValue('14.00');

        $service = $this->makeServiceWithCategories($price, [$adult, $child, $infant], [$childModifier]);

        $r = new Reservation();
        $r->setStartDate(new \DateTime('2026-06-01'));
        $r->setEndDate(new \DateTime('2026-06-04'));
        $r->setGuestCounts([1 => 1, 2 => 1, 3 => 1]);

        $totals = $service->getApartmentTotalsPerNight($r, PercentageBase::GROSS);

        self::assertSame(64.0, $totals['2026-06-01']);
        self::assertSame(64.0, $totals['2026-06-02']);
        self::assertSame(64.0, $totals['2026-06-03']);
    }

    private function makeCategory(int $id, GuestStatisticalGroup $group, bool $countedInOccupancy = true): GuestCategory
    {
        $c = new GuestCategory();
        $c->setName('cat'.$id);
        $c->setAcronym('C'.$id);
        $c->setStatisticalGroup($group);
        $c->setIsCountedInOccupancy($countedInOccupancy);
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($c, $id);

        return $c;
    }

    private function makeServiceWithCategories(Price $price, array $categories, array $modifiers): PriceService
    {
        $catRepo = $this->createStub(GuestCategoryRepository::class);
        $catRepo->method('findAll')->willReturn($categories);

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

    private function reservation(): Reservation
    {
        $r = new Reservation();
        $r->setStartDate(new \DateTime('2026-06-01'));
        $r->setEndDate(new \DateTime('2026-06-03'));
        $r->setGuestCounts([1 => 2]);

        return $r;
    }

    private function makeService(Price $price): PriceService
    {
        $adult = new GuestCategory();
        $adult->setName('Erw');
        $adult->setAcronym('ERW');
        $adult->setStatisticalGroup(GuestStatisticalGroup::ADULT);
        (new \ReflectionProperty(GuestCategory::class, 'id'))->setValue($adult, 1);

        $catRepo = $this->createStub(GuestCategoryRepository::class);
        $catRepo->method('findAll')->willReturn([$adult]);

        $modRepo = $this->createStub(GuestCategoryModifierRepository::class);
        $modRepo->method('findActiveOn')->willReturn([]);

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

    private function makePrice(string $value, bool $includesVat, float $vat, bool $flat, bool $perRoom): Price
    {
        $p = new Price();
        $p->setPrice($value);
        $p->setVat($vat);
        $p->setIncludesVat($includesVat);
        $p->setIsFlatPrice($flat);
        if ($perRoom) {
            $p->setIsPerRoom(true);
        }
        $p->setNumberOfPersons(2); // Doppelzimmer pattern; for flat/perRoom this is irrelevant
        $p->setDescription('apt');

        return $p;
    }
}
