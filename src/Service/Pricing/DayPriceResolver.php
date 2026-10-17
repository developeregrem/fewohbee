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

use App\Dto\Pricing\NightAdjustment;
use App\Entity\Appartment;
use App\Entity\DayPrice;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Repository\DayPriceRepository;
use App\Repository\PriceRepository;
use App\Service\AppSettingsService;
use App\Service\OnlineBooking\OnlineBookingConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Turns the day prices of a room's category and subsidiary into changes of the price list.
 *
 * A day price names the room price of a night for a number of guests. It changes the price list
 * by the percentage between that amount and what the list asks for those guests on that night -
 * for a one-night stay through the reference origin (the online booking's, otherwise the first).
 * Every other occupancy, origin and stay length changes by the same percentage, so one day price
 * covers all price rows of the category. Set by hand it is taken as it is; from an assistant or
 * another program it is held within the limits for price changes.
 */
class DayPriceResolver implements ResetInterface
{
    private ?ReservationOrigin $origin = null;
    private bool $originResolved = false;

    public function __construct(
        private readonly DayPriceRepository $dayPrices,
        private readonly PriceRepository $prices,
        private readonly OnlineBookingConfigService $onlineBooking,
        private readonly EntityManagerInterface $em,
        private readonly AppSettingsService $settings,
    ) {
    }

    public function reset(): void
    {
        $this->origin = null;
        $this->originResolved = false;
    }

    /**
     * The day prices of the nights from $from up to, excluding, $toExclusive as adjustments.
     * A day price whose occupancy has no price on its night - or only a flat one - is skipped.
     *
     * @return array<string, NightAdjustment> keyed by Y-m-d
     */
    public function adjustments(Appartment $room, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        $subsidiary = $room->getObject();
        $category = $room->getRoomCategory();
        $origin = $this->referenceOrigin();
        if (null === $subsidiary || null === $category || null === $origin) {
            return [];
        }
        $dayPrices = $this->dayPrices->findForWindow($subsidiary, $category, $from, $toExclusive);
        if ([] === $dayPrices) {
            return [];
        }

        $settings = $this->settings->getSettings();
        $referenceRows = [];
        $result = [];
        foreach ($dayPrices as $date => $dayPrice) {
            $persons = $dayPrice->getPersons();
            $referenceRows[$persons] ??= $this->referenceRows($room, $origin, $persons, $from, $toExclusive);
            $listed = $this->listedPrice($referenceRows[$persons], $dayPrice);
            if (null === $listed) {
                continue;
            }
            $percent = ($dayPrice->getAmount() / $listed - 1) * 100;
            $kept = $dayPrice->getSource()->isLimited()
                ? max($settings->getPriceChangeMinPercent(), min($settings->getPriceChangeMaxPercent(), $percent))
                : $percent;
            $result[$date] = new NightAdjustment($kept, [], abs($kept - $percent) > 0.001, $dayPrice);
        }

        return $result;
    }

    /** The origin day prices are stated for: the online booking's, otherwise the first one. */
    public function referenceOrigin(): ?ReservationOrigin
    {
        if (!$this->originResolved) {
            $this->origin = $this->onlineBooking->getReservationOrigin()
                ?? $this->em->getRepository(ReservationOrigin::class)->findOneBy([], ['id' => 'ASC']);
            $this->originResolved = true;
        }

        return $this->origin;
    }

    /**
     * The room total the price list asks on the night for the day price's guests, null when
     * there is nothing to compare with.
     *
     * @param list<Price> $rows in priority order
     */
    private function listedPrice(array $rows, DayPrice $dayPrice): ?float
    {
        foreach ($rows as $row) {
            if (!$row->coversNight($dayPrice->getNight())) {
                continue;
            }
            if ($row->getIsFlatPrice()) {
                return null;
            }
            $listed = (float) $row->getPrice() * ($row->getIsPerRoom() ? 1 : $dayPrice->getPersons());

            return $listed > 0.0 ? $listed : null;
        }

        return null;
    }

    /**
     * Price rows for a one-night stay, in priority order. A category that only sells longer stays
     * is compared with the rows of its shortest minimum stay instead.
     *
     * @return list<Price>
     */
    private function referenceRows(Appartment $room, ReservationOrigin $origin, int $persons, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        $sample = new Reservation();
        $sample->setAppartment($room);
        $sample->setReservationOrigin($origin);
        $sample->setPersons($persons);
        $sample->setStartDate(\DateTime::createFromImmutable($from));
        $sample->setEndDate(\DateTime::createFromImmutable($toExclusive));

        $rows = $this->prices->findApartmentPrices($sample, 1);
        if ([] !== $rows) {
            return $rows;
        }
        $rows = $this->prices->findApartmentPrices($sample, 730);
        // Rows with special periods keep their precedence; within them the shortest stay counts.
        usort($rows, static fn (Price $a, Price $b): int => [$a->getAllPeriods() ? 1 : 0, (int) $a->getMinStay()] <=> [$b->getAllPeriods() ? 1 : 0, (int) $b->getMinStay()]);

        return $rows;
    }
}
