<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Dto\Pricing\GuestAdjustment;
use App\Dto\Pricing\PricePromise;
use App\Dto\Pricing\PromisedExtra;
use App\Dto\Pricing\PromisedLine;
use App\Dto\Pricing\PromisedNight;
use App\Dto\Pricing\RateAdjustment;
use App\Entity\Appartment;
use App\Entity\Enum\ModifierType;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricePromiseTest extends TestCase
{
    public function testConsecutiveEqualNightsAreStoredAsOneSegment(): void
    {
        $night = new PromisedNight(17, '89.00', false, false, true, [new PromisedLine(1, 2, '89.00')]);
        $promise = new PricePromise('2026-10-02', 'ctx', [
            '2026-12-21' => $night,
            '2026-12-20' => $night,
            '2026-12-22' => $night,
        ], []);

        $stored = $promise->toArray();

        self::assertCount(1, $stored['n']);
        self::assertSame('2026-12-20', $stored['n'][0]['f']);
        self::assertSame(3, $stored['n'][0]['c']);
        self::assertSame('p', $stored['n'][0]['t']);
    }

    public function testDifferentOrNonConsecutiveNightsStartNewSegments(): void
    {
        $weekday = new PromisedNight(17, '80.00', false, false, true);
        $weekend = new PromisedNight(18, '95.00', false, true, true);
        $promise = new PricePromise('2026-10-02', 'ctx', [
            '2026-12-18' => $weekday,
            '2026-12-19' => $weekend,
            '2026-12-21' => $weekend,
        ], []);

        $segments = $promise->toArray()['n'];

        self::assertSame(['2026-12-18', '2026-12-19', '2026-12-21'], array_column($segments, 'f'));
        self::assertSame('r', $segments[1]['t']);
    }

    public function testRoundTripKeepsNightsLinesAndExtras(): void
    {
        $line = new PromisedLine(4, 1, '22.25', new GuestAdjustment(ModifierType::DISCOUNT_PERCENT, 50.0));
        $promise = new PricePromise(
            '2026-10-02',
            'c3|s1|o2|p3|g1:2,4:1',
            ['2026-12-20' => new PromisedNight(17, '44.50', false, false, true, [new PromisedLine(1, 2, '44.50'), $line])],
            [5 => new PromisedExtra(5, '12.50', false)],
        );

        $read = PricePromise::fromArray($promise->toArray());

        self::assertNotNull($read);
        self::assertSame('2026-10-02', $read->promisedOn);
        self::assertSame('c3|s1|o2|p3|g1:2,4:1', $read->context);
        $night = $read->night(new \DateTimeImmutable('2026-12-20'));
        self::assertNotNull($night);
        self::assertSame('44.50', $night->unit);
        $adjustment = $night->lines[1]->adjustment;
        self::assertNotNull($adjustment);
        self::assertSame(ModifierType::DISCOUNT_PERCENT, $adjustment->type);
        self::assertSame(50.0, $adjustment->value);
        self::assertNull($read->night(new \DateTimeImmutable('2026-12-21')));
        self::assertSame('12.50', $read->extra(5)?->unit);
        self::assertFalse($read->extra(5)->includesVat);
        self::assertSame([5, 17], $read->priceIds());
    }

    public function testANightChangedByPriceRulesKeepsWhatTheRulesDid(): void
    {
        $adjustment = new RateAdjustment('80.00', 12.5, [['Weekend', 10.0], ['Fair', 2.5]]);
        $promise = new PricePromise('2026-10-02', 'ctx', ['2026-12-20' => new PromisedNight(17, '90.00', false, false, true, [], $adjustment)], []);

        $night = PricePromise::fromArray($promise->toArray())?->night(new \DateTimeImmutable('2026-12-20'));

        self::assertSame('90.00', $night?->unit);
        self::assertSame('80.00', $night->adjustment?->baseUnit);
        self::assertSame(12.5, $night->adjustment->percent);
        self::assertSame([['Weekend', 10.0], ['Fair', 2.5]], $night->adjustment->rules);
    }

    /** @return iterable<string, array{0: array<string, mixed>|null}> */
    public static function unreadablePromises(): iterable
    {
        yield 'none' => [null];
        yield 'unknown version' => [['v' => 2, 'at' => '2026-10-02', 'ctx' => 'x', 'n' => [], 'x' => []]];
        yield 'segment without count' => [['v' => 1, 'at' => '2026-10-02', 'ctx' => 'x', 'n' => [['f' => '2026-12-20', 'p' => 1, 'u' => '1.00', 't' => 'p', 'g' => true]], 'x' => []]];
        yield 'unknown calculation type' => [['v' => 1, 'at' => '2026-10-02', 'ctx' => 'x', 'n' => [['f' => '2026-12-20', 'c' => 1, 'p' => 1, 'u' => '1.00', 't' => 'z', 'g' => true]], 'x' => []]];
        yield 'unknown modifier type' => [['v' => 1, 'at' => '2026-10-02', 'ctx' => 'x', 'n' => [['f' => '2026-12-20', 'c' => 1, 'p' => 1, 'u' => '1.00', 't' => 'p', 'g' => true, 'l' => [[1, 1, '1.00', 'bogus', '5.00']]]], 'x' => []]];
        yield 'extra without unit' => [['v' => 1, 'at' => '2026-10-02', 'ctx' => 'x', 'n' => [], 'x' => [['p' => 5, 'g' => true]]]];
    }

    /** @param array<string, mixed>|null $data */
    #[DataProvider('unreadablePromises')]
    public function testUnreadableDataIsTreatedAsNoPromise(?array $data): void
    {
        self::assertNull(PricePromise::fromArray($data));
    }

    public function testContextFollowsRoomCategoryOriginAndGuestsButNotDates(): void
    {
        $reservation = $this->reservation();
        $context = PricePromise::contextKey($reservation);

        $reservation->setStartDate(new \DateTime('2027-01-01'));
        $reservation->setEndDate(new \DateTime('2027-01-09'));
        self::assertSame($context, PricePromise::contextKey($reservation));

        $reservation->setGuestCounts([1 => 2, 4 => 1]);
        self::assertNotSame($context, PricePromise::contextKey($reservation));
        self::assertSame('c3|s1|o2|p2|g1:2,4:1', PricePromise::contextKey($reservation));
    }

    private function reservation(): Reservation
    {
        $category = new RoomCategory();
        (new \ReflectionProperty(RoomCategory::class, 'id'))->setValue($category, 3);
        $subsidiary = new Subsidiary();
        (new \ReflectionProperty(Subsidiary::class, 'id'))->setValue($subsidiary, 1);
        $apartment = new Appartment();
        $apartment->setRoomCategory($category);
        $apartment->setObject($subsidiary);
        $origin = new ReservationOrigin();
        (new \ReflectionProperty(ReservationOrigin::class, 'id'))->setValue($origin, 2);

        $reservation = new Reservation();
        $reservation->setAppartment($apartment);
        $reservation->setReservationOrigin($origin);
        $reservation->setPersons(2);
        $reservation->setGuestCounts([1 => 2]);
        $reservation->setStartDate(new \DateTime('2026-12-20'));
        $reservation->setEndDate(new \DateTime('2026-12-23'));

        return $reservation;
    }
}
