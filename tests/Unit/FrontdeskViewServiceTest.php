<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Service\FrontdeskViewService;
use PHPUnit\Framework\TestCase;

final class FrontdeskViewServiceTest extends TestCase
{
    public function testStandardStayCategories(): void
    {
        $service = new FrontdeskViewService();
        $apartment = $this->buildApartment(1);
        $reservation = $this->buildReservation(10, $apartment, '2026-01-10', '2026-01-13');
        $rows = [[
            'apartment' => $apartment,
            'apartmentReservations' => [$reservation],
        ]];

        $itemsArrival = $this->buildItems($service, $rows, '2026-01-10');
        $arrivalCategories = $this->findCategoriesByReservationId($itemsArrival, 10);
        self::assertContains('arrival', $arrivalCategories);

        $itemsInhouse = $this->buildItems($service, $rows, '2026-01-11');
        $inhouseCategories = $this->findCategoriesByReservationId($itemsInhouse, 10);
        self::assertContains('inhouse', $inhouseCategories);

        $itemsDeparture = $this->buildItems($service, $rows, '2026-01-13');
        $departureCategories = $this->findCategoriesByReservationId($itemsDeparture, 10);
        self::assertContains('departure', $departureCategories);
    }

    public function testTurnoverCategories(): void
    {
        $service = new FrontdeskViewService();
        $apartment = $this->buildApartment(2);
        $reservationA = $this->buildReservation(20, $apartment, '2026-01-10', '2026-01-13');
        $reservationB = $this->buildReservation(21, $apartment, '2026-01-13', '2026-01-15');
        $rows = [[
            'apartment' => $apartment,
            'apartmentReservations' => [$reservationA, $reservationB],
        ]];

        $items = $this->buildItems($service, $rows, '2026-01-13');
        $categoriesA = $this->findCategoriesByReservationId($items, 20);
        $categoriesB = $this->findCategoriesByReservationId($items, 21);

        self::assertContains('departure', $categoriesA);
        self::assertContains('arrival', $categoriesB);
        self::assertNotContains('inhouse', $categoriesB);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(FrontdeskViewService $service, array $rows, string $date): array
    {
        return $service->buildItems($rows, new \DateTimeImmutable($date), ['arrival', 'departure', 'inhouse']);
    }

    private function buildApartment(int $id): Appartment
    {
        $apartment = new Appartment();
        $apartment->setId($id);
        $apartment->setNumber('A'.$id);
        $apartment->setBedsMax(2);

        return $apartment;
    }

    private function buildReservation(int $id, Appartment $apartment, string $start, string $end): Reservation
    {
        $reservation = new Reservation();
        $reservation->setId($id);
        $reservation->setAppartment($apartment);
        $reservation->setStartDate(new \DateTime($start));
        $reservation->setEndDate(new \DateTime($end));

        return $reservation;
    }

    /**
     * @return array<int, string>
     */
    private function findCategoriesByReservationId(array $items, int $reservationId): array
    {
        foreach ($items as $item) {
            if ($item['reservation']->getId() === $reservationId) {
                return $item['categories'];
            }
        }

        return [];
    }
}
