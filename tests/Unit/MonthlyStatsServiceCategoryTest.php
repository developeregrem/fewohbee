<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\MonthlyStatsSnapshot;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Repository\PostalCodeDataRepository;
use App\Repository\ReservationRepository;
use App\Repository\ReservationStatusRepository;
use App\Service\InvoiceService;
use App\Service\MonthlyStatsService;
use App\Service\StatisticsService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Verifies the guestCategory-aware tourism buckets introduced in Phase 4a.
 */
final class MonthlyStatsServiceCategoryTest extends TestCase
{
    public function testOvernightsByGuestCategoryAggregatesCountsTimesNights(): void
    {
        $adultCatId = 1;
        $childCatId = 2;
        $statusId = 1;

        // 3 customers so customerCount === persons (3 adults+1 kid = 4 persons; mismatch -> use 4 customers).
        // Keep persons aligned with customers to exercise the per-customer loop.
        $reservation = $this->makeReservation(
            '2026-03-10',
            '2026-03-13', // 3 nights
            persons: 3,
            statusId: $statusId,
            guestCounts: [$adultCatId => 2, $childCatId => 1],
            customers: [
                $this->makeCustomer('DE'),
                $this->makeCustomer('DE'),
                $this->makeCustomer('DE'),
            ],
        );

        $metrics = $this->buildMetrics([$reservation], $statusId);

        self::assertSame(6, $metrics['tourism']['overnights_by_guest_category'][$adultCatId]);
        self::assertSame(3, $metrics['tourism']['overnights_by_guest_category'][$childCatId]);
    }

    public function testOvernightsByCountryAndCategorySplitsProportionallyAcrossCustomers(): void
    {
        $adultCatId = 1;
        $statusId = 1;

        // 2 adults, 2 customers (one DE, one CH); persons === customerCount → per-customer loop.
        // catNights = 2 × 3 = 6; share = 1/2 → DE=3.0, CH=3.0.
        $reservation = $this->makeReservation(
            '2026-03-10',
            '2026-03-13',
            persons: 2,
            statusId: $statusId,
            guestCounts: [$adultCatId => 2],
            customers: [$this->makeCustomer('DE'), $this->makeCustomer('CH')],
        );

        $metrics = $this->buildMetrics([$reservation], $statusId);

        self::assertEqualsWithDelta(3.0, $metrics['tourism']['overnights_by_country_and_category']['DE'][$adultCatId], 0.0001);
        self::assertEqualsWithDelta(3.0, $metrics['tourism']['overnights_by_country_and_category']['CH'][$adultCatId], 0.0001);
        self::assertSame(6, $metrics['tourism']['overnights_by_guest_category'][$adultCatId]);
    }

    public function testBookerFallbackAttributesAllCountsToBookerCountry(): void
    {
        $adultCatId = 1;
        $statusId = 1;

        // persons=2, customerCount=0 → useBookerFallback=true.
        $reservation = $this->makeReservation(
            '2026-03-01',
            '2026-03-05', // 4 nights
            persons: 2,
            statusId: $statusId,
            guestCounts: [$adultCatId => 2],
            customers: [],
            booker: $this->makeCustomer('AT'),
        );

        $metrics = $this->buildMetrics([$reservation], $statusId);

        self::assertSame(8, $metrics['tourism']['overnights_by_guest_category'][$adultCatId]);
        self::assertEqualsWithDelta(8.0, $metrics['tourism']['overnights_by_country_and_category']['AT'][$adultCatId], 0.0001);
    }

    public function testPartialMonthOverlapClipsNights(): void
    {
        $adultCatId = 1;
        $statusId = 1;

        // Reservation 2026-02-28 → 2026-03-04; only 3 nights fall into March (Mar 1, 2, 3).
        $reservation = $this->makeReservation(
            '2026-02-28',
            '2026-03-04',
            persons: 1,
            statusId: $statusId,
            guestCounts: [$adultCatId => 1],
            customers: [$this->makeCustomer('DE')],
        );

        $metrics = $this->buildMetrics([$reservation], $statusId);

        self::assertSame(3, $metrics['tourism']['overnights_by_guest_category'][$adultCatId]);
        self::assertEqualsWithDelta(3.0, $metrics['tourism']['overnights_by_country_and_category']['DE'][$adultCatId], 0.0001);
    }

    /**
     * @param Reservation[] $reservations
     */
    private function buildMetrics(array $reservations, int $defaultStatusId): array
    {
        $appartmentRepo = $this->createStub(AppartmentRepository::class);
        $appartmentRepo->method('loadSumBedsMinForObject')->willReturn(10);
        $appartmentRepo->method('loadRoomCountForObject')->willReturn(5);

        $reservationRepo = $this->createStub(ReservationRepository::class);
        $reservationRepo->method('loadReservationsForMonth')->willReturn($reservations);
        $reservationRepo->method('loadUtilizationForDay')->willReturn(0);

        $statusRepo = $this->createStub(ReservationStatusRepository::class);
        $statusRepo->method('findDefaultIds')->willReturn([$defaultStatusId]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('getRepository')->willReturnCallback(static function (string $class) use ($appartmentRepo, $reservationRepo, $statusRepo) {
            return match ($class) {
                Appartment::class => $appartmentRepo,
                Reservation::class => $reservationRepo,
                ReservationStatus::class => $statusRepo,
                MonthlyStatsSnapshot::class => null,
                Subsidiary::class => null,
                default => null,
            };
        });

        $registry = $this->createStub(ManagerRegistry::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $postalRepo = $this->createStub(PostalCodeDataRepository::class);

        $service = new MonthlyStatsService(
            $em,
            $registry,
            $this->createStub(StatisticsService::class),
            $this->createStub(InvoiceService::class),
            $translator,
            $postalRepo,
        );

        $subsidiary = new Subsidiary();
        $subsidiary->setId(42);

        $payload = $service->buildMetrics(3, 2026, $subsidiary);

        return $payload['metrics'];
    }

    private function makeReservation(
        string $start,
        string $end,
        int $persons,
        int $statusId,
        array $guestCounts,
        array $customers,
        ?Customer $booker = null,
    ): Reservation {
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime($start));
        $reservation->setEndDate(new \DateTime($end));
        $reservation->setPersons($persons);
        $reservation->setGuestCounts($guestCounts);

        $status = new ReservationStatus();
        (new \ReflectionProperty(ReservationStatus::class, 'id'))->setValue($status, $statusId);
        $reservation->setReservationStatus($status);

        foreach ($customers as $customer) {
            $reservation->addCustomer($customer);
        }
        if (null !== $booker) {
            $reservation->setBooker($booker);
        }

        return $reservation;
    }

    private function makeCustomer(string $country): Customer
    {
        $customer = new Customer();
        $address = new CustomerAddresses();
        $address->setType('CUSTOMER_ADDRESS_TYPE_PRIVATE');
        $address->setCountry($country);
        $customer->addCustomerAddress($address);

        return $customer;
    }
}
