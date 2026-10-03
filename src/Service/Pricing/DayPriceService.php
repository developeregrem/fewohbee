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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sets and removes day prices - from the price calendar and, later, from AI assistants and
 * other programs. Like every change of the price list it first gives open bookings from before
 * price promises a promise at the current prices.
 */
class DayPriceService
{
    public const MAX_NIGHTS = 366;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DayPriceRepository $dayPrices,
        private readonly PricePromiseService $pricePromises,
        private readonly DynamicRateResolver $dynamicRates,
        private readonly DayPriceResolver $dayPriceResolver,
        private readonly ClockInterface $clock,
    ) {
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

        $this->pricePromises->promiseOpenReservations();
        $now = $this->clock->now();
        $today = $now->setTime(0, 0);
        $existing = $this->dayPrices->findForWindow($subsidiary, $category, $first, $last->modify('+1 day'));

        $changed = 0;
        for ($night = max($first, $today); $night <= $last; $night = $night->modify('+1 day')) {
            if (!in_array((int) $night->format('N'), $weekdays, true)) {
                continue;
            }
            $dayPrice = $existing[$night->format('Y-m-d')] ?? null;
            if (null === $amount) {
                if (null !== $dayPrice) {
                    $this->em->remove($dayPrice);
                    ++$changed;
                }
                continue;
            }
            if (null === $dayPrice) {
                $dayPrice = new DayPrice($subsidiary, $category, $night);
                $this->em->persist($dayPrice);
            }
            $dayPrice->set($amount, $persons, $source, $sourceLabel, $now);
            ++$changed;
        }

        $this->em->flush();
        $this->dayPrices->deleteBefore($today);
        $this->dynamicRates->reset();
        $this->dayPriceResolver->reset();

        return $changed;
    }
}
