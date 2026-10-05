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

use App\Entity\DayPrice;
use App\Entity\Enum\DayPriceSource;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\DayPriceRepository;
use App\Service\AppSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sets and removes day prices - from the price calendar, AI assistants and other programs. Like
 * every change of the price list it first gives open bookings from before price promises a
 * promise at the current prices.
 */
class DayPriceService
{
    /** Longest range the price calendar sets at once. */
    public const MAX_NIGHTS = 366;
    /** Most nights a program may send in one request. */
    public const MAX_NIGHTS_AT_ONCE = 1000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DayPriceRepository $dayPrices,
        private readonly PricePromiseService $pricePromises,
        private readonly DynamicRateResolver $dynamicRates,
        private readonly DayPriceResolver $dayPriceResolver,
        private readonly PriceCalendarService $calendar,
        private readonly AppSettingsService $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * What setting these day prices would do, night by night: the current price and what it
     * comes from, and for an amount the price guests would pay - day prices from assistants and
     * programs are held within the limits. A night is skipped when it is past, has no room
     * price, keeps a day price set by hand, already has exactly this day price, or has no day
     * price to remove. Prices are those of the price calendar: one night for $persons guests.
     *
     * @param array<string, float|null> $amounts keyed by night, Y-m-d
     *
     * @return list<array{night: string, currentPrice: float|null, currentFrom: string, newDayPrice: float|null, effectivePrice: float|null, heldWithinLimits: bool, skipped: string|null, current: DayPrice|null}>
     */
    public function preview(
        Subsidiary $subsidiary,
        RoomCategory $category,
        array $amounts,
        int $persons,
        DayPriceSource $source,
        ?string $sourceLabel = null,
        bool $keepManual = false,
    ): array {
        if ([] === $amounts) {
            return [];
        }
        ksort($amounts);
        $today = $this->clock->now()->setTime(0, 0);
        // Past nights are skipped; the window to price starts today at the earliest.
        $from = max($today, new \DateTimeImmutable((string) array_key_first($amounts)));
        $to = max($from, (new \DateTimeImmutable((string) array_key_last($amounts)))->modify('+1 day'));
        $calendar = [];
        foreach ($this->calendar->nights($subsidiary, $category, $persons, $from, $to) ?? [] as $day) {
            $calendar[$day['night']->format('Y-m-d')] = $day;
        }
        $existing = $this->dayPrices->findForWindow($subsidiary, $category, $from, $to);
        $settings = $this->settings->getSettings();

        $rows = [];
        foreach ($amounts as $date => $amount) {
            $day = $calendar[$date] ?? ['past' => true, 'price' => null, 'base' => null, 'rules' => [], 'dayPrice' => null];
            $current = $existing[$date] ?? null;
            $effective = null;
            $limited = false;
            if (null !== $amount && null !== $day['base'] && $day['base'] > 0) {
                $percent = ($amount / $day['base'] - 1) * 100;
                $kept = $source->isLimited()
                    ? max($settings->getPriceChangeMinPercent(), min($settings->getPriceChangeMaxPercent(), $percent))
                    : $percent;
                $effective = round($day['base'] * (1 + $kept / 100), 2);
                $limited = abs($kept - $percent) > 0.001;
            }
            $rows[] = [
                'night' => (string) $date,
                'currentPrice' => $day['price'],
                'currentFrom' => null !== $day['dayPrice'] ? 'day_price_'.$day['dayPrice']['source'] : ([] !== $day['rules'] ? 'price_rules' : 'price_list'),
                'newDayPrice' => $amount,
                'effectivePrice' => $effective,
                'heldWithinLimits' => $limited,
                'skipped' => match (true) {
                    $day['past'] => 'night_is_past',
                    null === $day['price'] => 'no_price_for_this_night',
                    $keepManual && DayPriceSource::MANUAL === $current?->getSource() => 'day_price_set_by_hand',
                    null === $amount && null === $current => 'no_day_price_to_remove',
                    null !== $amount && null !== $current && $this->isSame($current, $amount, $persons, $source, $sourceLabel) => 'unchanged',
                    default => null,
                },
                'current' => $current,
            ];
        }

        return $rows;
    }

    /**
     * Sets the day price of the room category in the subsidiary on every night from $first to
     * $last, both inclusive, that falls on one of $weekdays - or removes it with a null amount,
     * so the price list and the price rules apply again. Nights before today are left out.
     *
     * @param list<int> $weekdays ISO weekdays of the nights; Sunday is 7
     *
     * @return int the number of nights changed
     *
     * @throws \InvalidArgumentException for a range longer than MAX_NIGHTS or an invalid amount
     */
    public function set(
        Subsidiary $subsidiary,
        RoomCategory $category,
        \DateTimeImmutable $first,
        \DateTimeImmutable $last,
        array $weekdays,
        ?float $amount,
        int $persons,
        DayPriceSource $source = DayPriceSource::MANUAL,
        ?string $sourceLabel = null,
    ): int {
        $first = $first->setTime(0, 0);
        $last = $last->setTime(0, 0);
        if ($last < $first || $first->diff($last)->days >= self::MAX_NIGHTS) {
            throw new \InvalidArgumentException('A day price range covers between one and '.self::MAX_NIGHTS.' nights.');
        }

        $amounts = [];
        for ($night = $first; $night <= $last; $night = $night->modify('+1 day')) {
            if (in_array((int) $night->format('N'), $weekdays, true)) {
                $amounts[$night->format('Y-m-d')] = $amount;
            }
        }

        return $this->setNights($subsidiary, $category, $amounts, $persons, $source, $sourceLabel)['changed'];
    }

    /**
     * Sets the day price of each night to its amount, or removes it for a null amount. Nights
     * before today are left out; with $keepManual, so are nights whose day price was set by hand.
     * A night that already has exactly this day price stays untouched, so sending the same
     * prices again changes nothing.
     *
     * @param array<string, float|null> $amounts keyed by night, Y-m-d
     *
     * @return array{changed: int, created: int, updated: int, removed: int, unchanged: int, keptManual: list<string>, past: list<string>}
     *
     * @throws \InvalidArgumentException for more than MAX_NIGHTS_AT_ONCE nights or an invalid night
     */
    public function setNights(
        Subsidiary $subsidiary,
        RoomCategory $category,
        array $amounts,
        int $persons,
        DayPriceSource $source,
        ?string $sourceLabel = null,
        bool $keepManual = false,
    ): array {
        if (count($amounts) > self::MAX_NIGHTS_AT_ONCE) {
            throw new \InvalidArgumentException('At most '.self::MAX_NIGHTS_AT_ONCE.' nights can be changed at once.');
        }
        $nights = [];
        foreach (array_keys($amounts) as $date) {
            $nights[$date] = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date)
                ?: throw new \InvalidArgumentException('Nights are given as YYYY-MM-DD.');
        }
        $result = ['changed' => 0, 'created' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0, 'keptManual' => [], 'past' => []];
        if ([] === $nights) {
            return $result;
        }

        $this->pricePromises->promiseOpenReservations();
        $now = $this->clock->now();
        $today = $now->setTime(0, 0);
        $existing = $this->dayPrices->findForWindow($subsidiary, $category, min($nights), max($nights)->modify('+1 day'));

        foreach ($nights as $date => $night) {
            if ($night < $today) {
                $result['past'][] = $date;
                continue;
            }
            $dayPrice = $existing[$date] ?? null;
            if ($keepManual && DayPriceSource::MANUAL === $dayPrice?->getSource()) {
                $result['keptManual'][] = $date;
                continue;
            }
            $amount = $amounts[$date];
            if (null === $amount) {
                if (null === $dayPrice) {
                    ++$result['unchanged'];
                } else {
                    $this->em->remove($dayPrice);
                    ++$result['removed'];
                }
                continue;
            }
            if (null === $dayPrice) {
                $dayPrice = new DayPrice($subsidiary, $category, $night);
                $this->em->persist($dayPrice);
                ++$result['created'];
            } elseif ($this->isSame($dayPrice, $amount, $persons, $source, $sourceLabel)) {
                ++$result['unchanged'];
                continue;
            } else {
                ++$result['updated'];
            }
            $dayPrice->set($amount, $persons, $source, $sourceLabel, $now);
        }
        $result['changed'] = $result['created'] + $result['updated'] + $result['removed'];

        $this->em->flush();
        $this->dayPrices->deleteBefore($today);
        $this->dynamicRates->reset();
        $this->dayPriceResolver->reset();

        return $result;
    }

    private function isSame(DayPrice $dayPrice, float $amount, int $persons, DayPriceSource $source, ?string $sourceLabel): bool
    {
        return abs($dayPrice->getAmount() - $amount) < 0.005 && $dayPrice->getPersons() === $persons
            && $dayPrice->getSource() === $source && $dayPrice->getSourceLabel() === $sourceLabel;
    }
}
