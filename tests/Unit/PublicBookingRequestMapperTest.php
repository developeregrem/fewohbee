<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\GuestCategory;
use App\Entity\RoomCategory;
use App\Exception\PublicBookingException;
use App\Repository\GuestCategoryRepository;
use App\Service\GuestCategoryAgeMapper;
use App\Service\PublicBookingRequestMapper;
use App\Service\ReservationPeriodService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers the mapping of one public booking POST onto the request DTO — the field
 * formats the wizard emits, and the validation that guards every later step.
 */
final class PublicBookingRequestMapperTest extends TestCase
{
    /** @param array<string, mixed> $post */
    private function map(array $post, ?Appartment $calendarRoom = null): \App\Dto\PublicBooking\PublicBookingRequest
    {
        return $this->makeMapper()->map(new Request([], $post), $calendarRoom, 'DE');
    }

    /**
     * @param GuestCategory[]  $categories
     * @param array<int, int>  $mappedCounts result the age mapper returns for any input
     */
    private function makeMapper(array $categories = [], array $mappedCounts = []): PublicBookingRequestMapper
    {
        $repository = $this->createStub(GuestCategoryRepository::class);
        $repository->method('findActiveOrdered')->willReturn($categories);

        $ageMapper = $this->createStub(GuestCategoryAgeMapper::class);
        $ageMapper->method('map')->willReturn($mappedCounts);

        return new PublicBookingRequestMapper($repository, $ageMapper, new ReservationPeriodService());
    }

    /** @return array<string, string> a request that passes date validation */
    private function validDates(): array
    {
        return [
            'dateFrom' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'dateTo' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
        ];
    }

    /** Standard occupancy fields should be parsed correctly. */
    public function testParsesStandardOccupancyFields(): void
    {
        $booking = $this->map($this->validDates() + [
            'occ_category:1_p1' => '0',
            'occ_category:1_p2' => '1',
            'occ_category:2_p1' => '2',
            'intent' => 'preview',
        ]);

        self::assertSame([
            'category:1' => [1 => 0, 2 => 1],
            'category:2' => [1 => 2],
        ], $booking->occupancySelection);
        self::assertSame('preview', $booking->intent);
    }

    /** Fields without the occ_ prefix should be ignored. */
    public function testIgnoresNonOccupancyFields(): void
    {
        $booking = $this->map($this->validDates() + [
            'qty_category:1' => '2',
            'occ_category:1_p2' => '1',
        ]);

        self::assertSame(['category:1' => [2 => 1]], $booking->occupancySelection);
    }

    /** A request without any occupancy field yields an empty selection. */
    public function testRequestWithoutOccupancyFieldsYieldsEmptySelection(): void
    {
        $booking = $this->map($this->validDates());

        self::assertSame([], $booking->occupancySelection);
        self::assertSame([], $booking->extrasSelection);
        self::assertSame([], $booking->guestCounts);
        self::assertSame('availability', $booking->intent);
    }

    /** Apartment-type keys should be parsed correctly too. */
    public function testParsesApartmentTypeKeys(): void
    {
        $booking = $this->map($this->validDates() + ['occ_apartment:42_p3' => '1']);

        self::assertSame(['apartment:42' => [3 => 1]], $booking->occupancySelection);
    }

    /** Invalid persons value (0 or negative) should be excluded. */
    public function testExcludesInvalidPersonsValues(): void
    {
        $booking = $this->map($this->validDates() + [
            'occ_category:1_p0' => '1',
            'occ_category:1_p-1' => '1',
            'occ_category:1_p2' => '1',
        ]);

        self::assertSame(['category:1' => [2 => 1]], $booking->occupancySelection);
    }

    /** Extras arrive as extra_{priceId}; zero quantities drop out. */
    public function testParsesExtrasAndDropsZeroQuantities(): void
    {
        $booking = $this->map($this->validDates() + [
            'extra_7' => '2',
            'extra_9' => '0',
            'extra_0' => '3',
            'extras_broken' => '1',
        ]);

        self::assertSame([7 => 2], $booking->extrasSelection);
    }

    /** Same-day arrivals should be accepted by the public booking validation. */
    public function testAllowsSameDayArrival(): void
    {
        $today = new \DateTimeImmutable('today');
        $tomorrow = new \DateTimeImmutable('tomorrow');

        $booking = $this->map([
            'dateFrom' => $today->format('Y-m-d'),
            'dateTo' => $tomorrow->format('Y-m-d'),
            'persons' => '2',
            'roomsCount' => '1',
        ]);

        self::assertSame($today->format('Y-m-d'), $booking->dateFrom->format('Y-m-d'));
        self::assertSame($tomorrow->format('Y-m-d'), $booking->dateTo->format('Y-m-d'));
        self::assertSame(2, $booking->persons);
        self::assertSame(1, $booking->roomsCount);
    }

    /** Past arrivals should still be rejected. */
    public function testRejectsPastArrival(): void
    {
        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.arrival_must_be_future');

        $this->map([
            'dateFrom' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d'),
            'dateTo' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
        ]);
    }

    /** Missing dates are rejected before anything else is looked at. */
    public function testRejectsMissingDates(): void
    {
        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.dates_required');

        $this->map(['persons' => '2']);
    }

    /** Departure before arrival is rejected. */
    public function testRejectsDepartureBeforeArrival(): void
    {
        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.departure_after_arrival');

        $this->map([
            'dateFrom' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d'),
            'dateTo' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d'),
        ]);
    }

    /** Excessive public booking periods are rejected before pricing work starts. */
    public function testRejectsExcessivePeriod(): void
    {
        $this->expectException(PublicBookingException::class);
        $this->expectExceptionMessage('online_booking.error.booking_horizon_exceeded');

        $this->map([
            'dateFrom' => '2026-09-12',
            'dateTo' => '4026-09-13',
        ]);
    }

    /** The booker payload is collected verbatim; the country falls back to the locale. */
    public function testMapsBookerAndDefaultsCountry(): void
    {
        $booking = $this->map($this->validDates() + [
            'firstname' => 'Max',
            'lastname' => 'Muster',
            'email' => 'max@example.com',
            'comment' => 'spät anreisen',
        ]);

        self::assertSame('Max', $booking->booker->firstname);
        self::assertSame('max@example.com', $booking->booker->email);
        self::assertSame('spät anreisen', $booking->booker->comment);
        self::assertSame('DE', $booking->booker->country);
        self::assertSame('', $booking->booker->company);
        self::assertSame('Muster', $booking->booker->toArray()['lastname']);
    }

    /** A submitted country wins over the locale default and is normalised. */
    public function testUppercasesSubmittedCountry(): void
    {
        $booking = $this->map($this->validDates() + ['country' => 'at']);

        self::assertSame('AT', $booking->booker->country);
    }

    /**
     * The occupancy the guest is booked for follows the guest categories that take a
     * bed — an infant in a cot must not inflate it.
     */
    public function testDerivesOccupancyFromBedTakingGuestCategoriesOnly(): void
    {
        $mapper = $this->makeMapper(
            [
                $this->guestCategory(1, GuestStatisticalGroup::ADULT, true),
                $this->guestCategory(2, GuestStatisticalGroup::INFANT, false),
            ],
            [1 => 2, 2 => 1],
        );

        $booking = $mapper->map(new Request([], $this->validDates() + [
            'adults' => '2',
            'childAges' => ['1'],
            'persons' => '9',
        ]), null, 'DE');

        self::assertSame([1 => 2, 2 => 1], $booking->guestCounts);
        self::assertSame(2, $booking->persons);
    }

    /** Without guest categories the plain persons field stays authoritative. */
    public function testFallsBackToPersonsFieldWithoutGuestCounts(): void
    {
        $booking = $this->map($this->validDates() + ['persons' => '3']);

        self::assertSame(3, $booking->persons);
    }

    /** Calendar mode derives the selection from the chosen room, ignoring posted occ_ fields. */
    public function testCalendarModeDerivesSelectionFromChosenRoom(): void
    {
        $booking = $this->map(
            $this->validDates() + ['intent' => 'preview', 'persons' => '2', 'occ_category:9_p4' => '1'],
            $this->calendarRoom(5),
        );

        self::assertSame(['category:5' => [2 => 1]], $booking->occupancySelection);
    }

    /** On the first step the calendar room must not pre-empt the availability query. */
    public function testCalendarRoomDoesNotDeriveSelectionOnAvailabilityStep(): void
    {
        $booking = $this->map(
            $this->validDates() + ['intent' => 'availability', 'persons' => '2'],
            $this->calendarRoom(5),
        );

        self::assertSame([], $booking->occupancySelection);
    }

    private function guestCategory(int $id, GuestStatisticalGroup $group, bool $countedInOccupancy): GuestCategory
    {
        $category = $this->createStub(GuestCategory::class);
        $category->method('getId')->willReturn($id);
        $category->method('getStatisticalGroup')->willReturn($group);
        $category->method('isCountedInOccupancy')->willReturn($countedInOccupancy);

        return $category;
    }

    private function calendarRoom(int $categoryId): Appartment
    {
        $category = $this->createStub(RoomCategory::class);
        $category->method('getId')->willReturn($categoryId);

        $room = $this->createStub(Appartment::class);
        $room->method('getRoomCategory')->willReturn($category);

        return $room;
    }
}
