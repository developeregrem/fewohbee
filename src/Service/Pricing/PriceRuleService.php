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

use App\Dto\Pricing\PriceRuleData;
use App\Entity\Enum\PriceRounding;
use App\Entity\PriceRule;
use App\Service\AppSettingsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saves price rules and their limits. Every change first gives open bookings from before price
 * promises a promise at the current prices, so a new or changed rule only reaches stays priced
 * from now on.
 */
class PriceRuleService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PricePromiseService $pricePromises,
        private readonly DynamicRateResolver $dynamicRates,
        private readonly AppSettingsService $settings,
    ) {
    }

    /**
     * Writes validated form input onto a rule, or onto a new detached one for a preview. Values
     * the condition does not use are dropped. Does not flush.
     */
    public function apply(PriceRuleData $data, ?PriceRule $rule = null): PriceRule
    {
        $rule ??= new PriceRule();
        $rule->setName($data->name);
        $rule->setCondition($data->condition, $data->days, $data->occupancy);
        $rule->setOccupancyAcrossSubsidiaries($data->occupancyAcrossSubsidiaries);
        $rule->setPercent($data->percent());
        $rule->setWeekdays($data->weekdays);
        // The operator picks the last night; storage is half-open.
        $rule->setPeriod($data->firstNight, $data->lastNight?->modify('+1 day'));
        $rule->setSubsidiaries($data->allSubsidiaries, $data->subsidiaries);
        $rule->setCategories($data->allCategories, $data->categories);
        $rule->setEnabled($data->enabled);

        return $rule;
    }

    public function save(PriceRuleData $data, ?PriceRule $rule = null): PriceRule
    {
        $this->pricePromises->promiseOpenReservations();
        $rule = $this->apply($data, $rule);
        $this->em->persist($rule);
        $this->em->flush();
        $this->dynamicRates->reset();

        return $rule;
    }

    public function toggle(PriceRule $rule): void
    {
        $this->pricePromises->promiseOpenReservations();
        $rule->setEnabled(!$rule->isEnabled());
        $this->em->flush();
        $this->dynamicRates->reset();
    }

    public function delete(PriceRule $rule): void
    {
        $this->pricePromises->promiseOpenReservations();
        $this->em->remove($rule);
        $this->em->flush();
        $this->dynamicRates->reset();
    }

    /** Both limits as positive numbers: how far rules may lower and raise a price in total. */
    public function saveLimits(int $maxDecrease, int $maxIncrease, PriceRounding $rounding): void
    {
        $this->pricePromises->promiseOpenReservations();
        $settings = $this->settings->getSettings();
        $settings->setPriceChangeLimits(-$maxDecrease, $maxIncrease);
        $settings->setPriceChangeRounding($rounding);
        $this->settings->saveSettings($settings);
    }
}
