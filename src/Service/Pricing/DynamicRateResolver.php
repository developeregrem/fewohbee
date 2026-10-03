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
use App\Entity\Enum\PriceRuleCondition;
use App\Entity\PriceRule;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\PriceRuleRepository;
use App\Repository\SubsidiaryRepository;
use App\Service\AppSettingsService;
use App\Service\AvailabilityService;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decides which price rules apply to the nights of a stay and by how much they change the room
 * price. All matching rules are added up and the sum is held within the configured limits.
 *
 * Nights before today are never changed: the rules are about selling the nights ahead. The
 * occupancy of a night is the booked share of the sellable rooms - the whole subsidiary for a
 * rule that covers all room categories, otherwise its categories in that subsidiary. A rule set
 * to count its subsidiaries together adds up the rooms of all subsidiaries it covers.
 */
class DynamicRateResolver implements ResetInterface
{
    /** @var list<PriceRule>|null */
    private ?array $enabledRules = null;

    /** @var array<string, array<string, array{rooms: int, booked: int, blocked: int, available: int}>> */
    private array $roomNights = [];

    /** @var list<int>|null */
    private ?array $subsidiaryIds = null;

    public function __construct(
        private readonly PriceRuleRepository $rules,
        private readonly AvailabilityService $availability,
        private readonly AppSettingsService $settings,
        private readonly ClockInterface $clock,
        private readonly SubsidiaryRepository $subsidiaries,
    ) {
    }

    public function reset(): void
    {
        $this->enabledRules = null;
        $this->roomNights = [];
        $this->subsidiaryIds = null;
    }

    /**
     * The adjustment of each night from $from up to, excluding, $toExclusive for a room of
     * $category in $subsidiary. Nights no rule applies to are missing from the result.
     *
     * @param list<PriceRule>|null $rules                  rules to evaluate instead of the saved enabled ones (preview)
     * @param int|null             $excludingReservationId a booking that must not count towards occupancy, e.g. the one being priced
     *
     * @return array<string, NightAdjustment> keyed by Y-m-d
     */
    public function adjustments(
        ?Subsidiary $subsidiary,
        RoomCategory $category,
        \DateTimeImmutable $from,
        \DateTimeImmutable $toExclusive,
        ?array $rules = null,
        ?int $excludingReservationId = null,
    ): array {
        $rules = array_values(array_filter(
            $rules ?? $this->enabledRules(),
            static fn (PriceRule $rule): bool => $rule->appliesTo($subsidiary, $category),
        ));
        if ([] === $rules) {
            return [];
        }

        $settings = $this->settings->getSettings();
        $today = $this->clock->now()->setTime(0, 0);
        $first = $from->setTime(0, 0);
        $window = [$first, $toExclusive, $excludingReservationId];

        $result = [];
        for ($night = max($first, $today); $night < $toExclusive; $night = $night->modify('+1 day')) {
            $daysAhead = (int) $today->diff($night)->days;
            $matching = array_values(array_filter(
                $rules,
                fn (PriceRule $rule): bool => $rule->coversNight($night) && $this->conditionMet($rule, $night, $daysAhead, $subsidiary, $window),
            ));
            if ([] === $matching) {
                continue;
            }
            $sum = array_sum(array_map(static fn (PriceRule $rule): float => $rule->getPercent(), $matching));
            $limited = max($settings->getPriceChangeMinPercent(), min($settings->getPriceChangeMaxPercent(), $sum));
            $result[$night->format('Y-m-d')] = new NightAdjustment($limited, $matching, abs($limited - $sum) > 0.001);
        }

        return $result;
    }

    /** The unit price after the adjustment, rounded as configured. */
    public function apply(string $unit, NightAdjustment $adjustment): string
    {
        $changed = (float) $unit * (1 + $adjustment->percent / 100);

        return PricePromise::money(max(0.0, $this->settings->getSettings()->getPriceChangeRounding()->round($changed)));
    }

    /**
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int|null} $window the stay and the booking left out of the occupancy
     */
    private function conditionMet(PriceRule $rule, \DateTimeImmutable $night, int $daysAhead, ?Subsidiary $subsidiary, array $window): bool
    {
        return match ($rule->getCondition()) {
            PriceRuleCondition::ALWAYS => true,
            PriceRuleCondition::LAST_MINUTE => $daysAhead <= $rule->getDays(),
            PriceRuleCondition::EARLY_BIRD => $daysAhead >= $rule->getDays(),
            PriceRuleCondition::OCCUPANCY_HIGH => ($this->occupancy($rule, $night, $subsidiary, $window) ?? -1.0) >= $rule->getOccupancy(),
            PriceRuleCondition::OCCUPANCY_LOW => $daysAhead <= $rule->getDays()
                && null !== ($occupancy = $this->occupancy($rule, $night, $subsidiary, $window))
                && $occupancy <= $rule->getOccupancy(),
        };
    }

    /**
     * Booked share of the sellable rooms in percent, null when there is nothing to sell.
     *
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int|null} $window
     */
    private function occupancy(PriceRule $rule, \DateTimeImmutable $night, ?Subsidiary $subsidiary, array $window): ?float
    {
        if (null === $subsidiary?->getId()) {
            return null;
        }
        $categoryIds = $rule->isAllCategories()
            ? [null]
            : array_map(static fn (RoomCategory $category): ?int => $category->getId(), $rule->getCategories()->getValues());
        $subsidiaryIds = match (true) {
            !$rule->isOccupancyAcrossSubsidiaries() => [$subsidiary->getId()],
            $rule->isAllSubsidiaries() => $this->subsidiaryIds ??= $this->subsidiaries->loadAllIds(),
            default => array_map(static fn (Subsidiary $selected): int => (int) $selected->getId(), $rule->getSubsidiaries()->getValues()),
        };

        $sellable = 0;
        $booked = 0;
        foreach ($subsidiaryIds as $subsidiaryId) {
            foreach ($categoryIds as $categoryId) {
                $day = $this->roomNights($subsidiaryId, $categoryId, $window)[$night->format('Y-m-d')] ?? null;
                if (null !== $day) {
                    $sellable += $day['rooms'] - $day['blocked'];
                    $booked += $day['booked'];
                }
            }
        }

        return $sellable > 0 ? $booked * 100.0 / $sellable : null;
    }

    /**
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int|null} $window
     *
     * @return array<string, array{rooms: int, booked: int, blocked: int, available: int}>
     */
    private function roomNights(int $subsidiaryId, ?int $categoryId, array $window): array
    {
        [$from, $toExclusive, $excludingId] = $window;
        $key = implode('|', [$subsidiaryId, $categoryId ?? '*', $from->format('Y-m-d'), $toExclusive->format('Y-m-d'), $excludingId ?? '-']);

        return $this->roomNights[$key] ??= $this->availability->getRoomNightsPerDay($subsidiaryId, $categoryId, $from, $toExclusive, $excludingId);
    }

    /** @return list<PriceRule> */
    private function enabledRules(): array
    {
        return $this->enabledRules ??= $this->rules->findEnabled();
    }
}
