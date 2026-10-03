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
use App\Dto\Pricing\PricePromise;
use App\Entity\Appartment;
use App\Entity\PriceRule;
use App\Repository\AppartmentRepository;
use App\Repository\PriceRepository;
use App\Repository\PriceRuleRepository;
use App\Repository\SubsidiaryRepository;
use App\Service\Api\RateCalendarService;
use Symfony\Component\Clock\ClockInterface;

/**
 * What a price rule being edited would do over the coming nights, for the preview in the rule
 * form: on how many nights it applies, and for one room of its scope the price before and after
 * on the first of them - together with the other enabled rules, as a guest would see it. Works
 * on a detached draft, so nothing is saved.
 */
class PriceRulePreview
{
    public const NIGHTS = 60;

    public function __construct(
        private readonly DynamicRateResolver $dynamicRates,
        private readonly PriceRuleRepository $rules,
        private readonly SubsidiaryRepository $subsidiaries,
        private readonly AppartmentRepository $apartments,
        private readonly PriceRepository $prices,
        private readonly RateCalendarService $rateCalendar,
        private readonly DayPriceResolver $dayPrices,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param PriceRule|null $original the saved rule the draft replaces
     *
     * @return array{nights: int, matches: int, example: array{room: Appartment, persons: int, night: \DateTimeImmutable, before: float, after: float, percent: float, limited: bool}|null}
     */
    public function preview(PriceRule $draft, ?PriceRule $original): array
    {
        $result = ['nights' => self::NIGHTS, 'matches' => 0, 'example' => null];
        $room = $this->sampleRoom($draft);
        $category = $room?->getRoomCategory();
        if (null === $room || null === $category) {
            return $result;
        }

        $rules = array_values(array_filter(
            $this->rules->findEnabled(),
            static fn (PriceRule $rule): bool => null === $original || $rule->getId() !== $original->getId(),
        ));
        $rules[] = $draft;
        $from = $this->clock->now()->setTime(0, 0);
        $adjustments = $this->dynamicRates->adjustments($room, $from, $from->modify('+'.self::NIGHTS.' days'), $rules);

        $matching = array_filter($adjustments, static fn (NightAdjustment $adjustment): bool => in_array($draft, $adjustment->rules, true));
        $result['matches'] = count($matching);
        $first = array_key_first($matching);
        if (null !== $first) {
            $result['example'] = $this->example($room, new \DateTimeImmutable($first), $matching[$first]);
        }

        return $result;
    }

    /** The first active room the rule covers, in subsidiary and room order. */
    private function sampleRoom(PriceRule $draft): ?Appartment
    {
        $subsidiaries = $draft->isAllSubsidiaries() ? $this->subsidiaries->findAllOrdered() : $draft->getSubsidiaries()->getValues();
        foreach ($subsidiaries as $subsidiary) {
            foreach ($this->apartments->findAllByProperty($subsidiary->getId()) as $room) {
                $category = $room->getRoomCategory();
                if (null !== $category && ($draft->isAllCategories() || $draft->getCategories()->contains($category))) {
                    return $room;
                }
            }
        }

        return null;
    }

    /**
     * The room price for one night before and after the rules, for two guests where the room
     * has a price for two, otherwise for the smallest occupancy priced.
     *
     * @return array{room: Appartment, persons: int, night: \DateTimeImmutable, before: float, after: float, percent: float, limited: bool}|null
     */
    private function example(Appartment $room, \DateTimeImmutable $night, NightAdjustment $adjustment): ?array
    {
        $occupancies = $this->prices->findOccupanciesForRoomCategory($room->getRoomCategory());
        $origin = $this->dayPrices->referenceOrigin();
        if ([] === $occupancies || null === $origin) {
            return null;
        }
        $persons = in_array(2, $occupancies, true) ? 2 : $occupancies[0];

        $rate = $this->rateCalendar->build($room, $night, $night, 1, [$persons], $origin)[0]['rates'][0] ?? null;
        if (null === $rate || 'flat' === $rate['pricingModel']) {
            return null;
        }
        $heads = 'per_person_night' === $rate['pricingModel'] ? $persons : 1;
        $after = (float) $this->dynamicRates->apply(PricePromise::money($rate['baseUnitPrice']), $adjustment);

        return [
            'room' => $room,
            'persons' => $persons,
            'night' => $night,
            'before' => $rate['baseUnitPrice'] * $heads,
            'after' => $after * $heads,
            'percent' => $adjustment->percent,
            'limited' => $adjustment->limited,
        ];
    }
}
