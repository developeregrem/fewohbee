<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Enum\RoomCategoryKind;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
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
     * @param list<array{apartment: Appartment, apartmentReservations: list<Reservation>}> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(FrontdeskViewService $service, array $rows, string $date): array
    {
        return $service->buildItems($rows, new \DateTimeImmutable($date), ['arrival', 'departure', 'inhouse']);
    }

    public function testEventEndsOnItsLastDay(): void
    {
        $service = new FrontdeskViewService();
        $hall = $this->buildApartment(1)->setRoomCategory((new RoomCategory())->setKind(RoomCategoryKind::EVENT_SPACE));
        // Friday to Sunday, stored up to Monday; and one Saturday a week later, stored up to Sunday.
        $rows = [[
            'apartment' => $hall,
            'apartmentReservations' => [
                $this->buildReservation(10, $hall, '2026-01-09', '2026-01-12'),
                $this->buildReservation(11, $hall, '2026-01-17', '2026-01-18'),
            ],
        ]];

        self::assertSame(['inhouse'], $this->findCategoriesByReservationId($this->buildItems($service, $rows, '2026-01-10'), 10));
        self::assertSame(['departure'], $this->findCategoriesByReservationId($this->buildItems($service, $rows, '2026-01-11'), 10));
        self::assertSame([], $this->findCategoriesByReservationId($this->buildItems($service, $rows, '2026-01-12'), 10));
        self::assertSame(['arrival', 'departure'], $this->findCategoriesByReservationId($this->buildItems($service, $rows, '2026-01-17'), 11));
        self::assertSame([], $this->findCategoriesByReservationId($this->buildItems($service, $rows, '2026-01-18'), 11));
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
     * @param array<int, array<string, mixed>> $items
     *
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
