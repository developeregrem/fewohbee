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

namespace App\Dto\Pricing;

use App\Entity\Reservation;

/**
 * The price a reservation was booked at, stored on the reservation as compact JSON.
 *
 * Covers what decides the amount: per night the price row, unit price, calculation type, the
 * gross/net flag and the per-guest-category lines; per booked extra its unit price. Everything
 * else (VAT rate, descriptions, revenue accounts, tourist tax) stays live. The promise holds no
 * personal data and no history: it is overwritten when the booking changes.
 *
 * Stored format (version 1); consecutive nights with equal values form one segment:
 *
 *     {"v":1,"at":"2026-10-02","ctx":"c3|s1|o2|p3|g1:2,4:1",
 *      "n":[{"f":"2026-12-20","c":3,"p":17,"u":"89.00","t":"p","g":true,
 *            "l":[[1,2,"44.50"],[4,1,"22.25","discount_percent","50.00"]],
 *            "d":{"b":"80.00","pct":"11.25","r":[["Wochenende","10.00"],["Messe","1.25"]]}}],
 *      "x":[{"p":5,"u":"12.50","g":true}]}
 *
 * `d` is only present when price rules changed the night: the row's unit price before, the
 * total percentage and the rules by name, so the booking can explain its price later.
 *
 * `ctx` identifies what the nights were priced for (room category, subsidiary, origin, persons,
 * guest counts). Promised nights only apply while it still matches the reservation; a booking
 * changed in a way the promise does not cover falls back to the live price list.
 */
final readonly class PricePromise
{
    public const VERSION = 1;

    /**
     * @param array<string, PromisedNight> $nights keyed by night, Y-m-d
     * @param array<int, PromisedExtra>    $extras keyed by price id
     */
    public function __construct(
        public string $promisedOn,
        public string $context,
        public array $nights,
        public array $extras,
    ) {
    }

    /**
     * Reads a stored promise. Anything unreadable - unknown version, damaged data - yields null,
     * which prices the reservation from the current price list as before promises existed.
     *
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): ?self
    {
        if (null === $data || self::VERSION !== ($data['v'] ?? null)
            || !is_string($data['at'] ?? null) || !is_string($data['ctx'] ?? null)) {
            return null;
        }

        $nights = [];
        foreach ((array) ($data['n'] ?? []) as $segment) {
            if (!is_array($segment) || !is_string($segment['f'] ?? null) || !is_int($segment['c'] ?? null) || $segment['c'] < 1) {
                return null;
            }
            $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $segment['f']);
            $night = PromisedNight::fromPayload($segment);
            if (false === $first || null === $night) {
                return null;
            }
            for ($i = 0; $i < $segment['c']; ++$i) {
                $nights[$first->modify('+'.$i.' day')->format('Y-m-d')] = $night;
            }
        }

        $extras = [];
        foreach ((array) ($data['x'] ?? []) as $row) {
            $extra = PromisedExtra::fromArray($row);
            if (null === $extra) {
                return null;
            }
            $extras[$extra->priceId] = $extra;
        }

        return new self($data['at'], $data['ctx'], $nights, $extras);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $nights = $this->nights;
        ksort($nights);
        $segments = [];
        $previousNight = null;
        $previousPayload = null;
        foreach ($nights as $date => $night) {
            $payload = $night->payload();
            $last = array_key_last($segments);
            if (null !== $last && $payload === $previousPayload
                && self::nextDay($previousNight) === $date) {
                ++$segments[$last]['c'];
            } else {
                $segments[] = ['f' => $date, 'c' => 1] + $payload;
            }
            $previousNight = $date;
            $previousPayload = $payload;
        }

        $extras = $this->extras;
        ksort($extras);

        return [
            'v' => self::VERSION,
            'at' => $this->promisedOn,
            'ctx' => $this->context,
            'n' => $segments,
            'x' => array_values(array_map(static fn (PromisedExtra $extra): array => $extra->toArray(), $extras)),
        ];
    }

    public function night(\DateTimeInterface $night): ?PromisedNight
    {
        return $this->nights[$night->format('Y-m-d')] ?? null;
    }

    public function extra(int $priceId): ?PromisedExtra
    {
        return $this->extras[$priceId] ?? null;
    }

    /** @return list<int> */
    public function priceIds(): array
    {
        $ids = array_map(static fn (PromisedNight $night): int => $night->priceId, array_values($this->nights));
        $ids = array_merge($ids, array_keys($this->extras));
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * What the room nights of a reservation were priced for. Dates are not part of it: moving or
     * extending a stay keeps the promise for the nights that remain.
     */
    public static function contextKey(Reservation $reservation): string
    {
        $apartment = $reservation->getAppartment();
        $guestCounts = $reservation->getGuestCounts();
        ksort($guestCounts);
        $guests = [];
        foreach ($guestCounts as $categoryId => $count) {
            $guests[] = $categoryId.':'.$count;
        }

        return implode('|', [
            'c'.($apartment?->getRoomCategory()?->getId() ?? '-'),
            's'.($apartment?->getObject()?->getId() ?? '-'),
            'o'.($reservation->getReservationOrigin()?->getId() ?? '-'),
            'p'.(int) $reservation->getPersons(),
            'g'.implode(',', $guests),
        ]);
    }

    private static function nextDay(string $date): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return false === $day ? '' : $day->modify('+1 day')->format('Y-m-d');
    }

    /** Amounts are kept as strings with two decimals, as the price columns store them. */
    public static function money(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
