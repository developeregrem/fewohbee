<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Pricing;

use App\Entity\Appartment;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Service\Api\RateCalendarService;
use App\Service\AvailabilityService;
use Symfony\Component\Clock\ClockInterface;

/**
 * The nights of the price calendar for one room category in one subsidiary and a number of
 * guests: the room price a guest gets today, the price list it comes from, what changed it -
 * price rules or a day price - and how many rooms are booked. Prices are those of a one-night
 * stay through the origin day prices are stated for (DayPriceResolver::referenceOrigin()).
 */
class PriceCalendarService
{
    public function __construct(
        private readonly AppartmentRepository $apartments,
        private readonly RateCalendarService $rateCalendar,
        private readonly DayPriceResolver $dayPrices,
        private readonly AvailabilityService $availability,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<array{night: \DateTimeImmutable, past: bool, price: float|null, base: float|null, percent: float, rules: list<array{name: string, percent: float}>, dayPrice: array{amount: float, persons: int, source: string, sourceLabel: string|null}|null, limited: bool, booked: int, sellable: int}>|null
     *                                                                                                                                                                                                                   null when the subsidiary has no room of the category
     */
    public function nights(Subsidiary $subsidiary, RoomCategory $category, int $persons, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): ?array
    {
        $room = $this->sampleRoom($subsidiary, $category);
        if (null === $room) {
            return null;
        }
        $origin = $this->dayPrices->referenceOrigin();
        $lastNight = $toExclusive->modify('-1 day');

        $rates = [];
        if (null !== $origin) {
            foreach ($this->rateCalendar->build($room, $from, $lastNight, 1, [$persons], $origin) as $day) {
                $rates[$day['date']] = $day['rates'][0] ?? null;
            }
        }
        $occupancy = $this->availability->getRoomNightsPerDay((int) $subsidiary->getId(), $category->getId(), $from, $toExclusive);
        $today = $this->clock->now()->setTime(0, 0);

        $nights = [];
        for ($night = $from; $night < $toExclusive; $night = $night->modify('+1 day')) {
            $date = $night->format('Y-m-d');
            $rate = $rates[$date] ?? null;
            $heads = 'per_person_night' === ($rate['pricingModel'] ?? null) ? $persons : 1;
            $day = $occupancy[$date] ?? ['rooms' => 0, 'booked' => 0, 'blocked' => 0];
            $nights[] = [
                'night' => $night,
                'past' => $night < $today,
                'price' => $rate['perNight'] ?? null,
                'base' => null === $rate || null === $rate['perNight'] ? null : $rate['baseUnitPrice'] * $heads,
                'percent' => (float) ($rate['adjustmentPercent'] ?? 0.0),
                'rules' => $rate['priceRules'] ?? [],
                'dayPrice' => $rate['dayPrice'] ?? null,
                'limited' => (bool) ($rate['limited'] ?? false),
                'booked' => $day['booked'],
                'sellable' => max(0, $day['rooms'] - $day['blocked']),
            ];
        }

        return $nights;
    }

    /** The first active room of the category in the subsidiary; prices are the category's. */
    public function sampleRoom(Subsidiary $subsidiary, RoomCategory $category): ?Appartment
    {
        foreach ($this->apartments->findAllByProperty($subsidiary->getId()) as $room) {
            if ($room->getRoomCategory()?->getId() === $category->getId()) {
                return $room;
            }
        }

        return null;
    }
}
