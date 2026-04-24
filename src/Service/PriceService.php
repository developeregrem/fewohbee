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

namespace App\Service;

use App\Entity\AccountingAccount;
use App\Entity\Enum\PriceComponentAllocationType;
use App\Entity\Price;
use App\Entity\PriceComponent;
use App\Entity\PricePeriod;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class PriceService
{
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function getPriceFromForm(Request $request, $id = 'new')
    {
        $price = new Price();

        if ('new' !== $id) {
            $price = $this->em->getRepository(Price::class)->find($id);
        }

        $price->setDescription($request->request->get('description-'.$id));
        $price->setPrice(str_replace(',', '.', $request->request->get('price-'.$id)));
        $price->setVat((float) str_replace(',', '.', $request->request->get('vat-'.$id)));
        $price->setType($request->request->get('type-'.$id));

        $this->setOrigins($request, $price, $id);

        if (null == $request->request->get('allperiods-'.$id)) {
            $this->setPeriods($request, $price, $id);
            $price->setAllPeriods(false);
        } else {
            $price->setAllPeriods(true);
        }

        if (null != $request->request->get('active-'.$id)) {
            $price->setActive(true);
        } else {
            $price->setActive(false);
        }

        if (null != $request->request->get('alldays-'.$id)) {
            $price->setAllDays(true);
            $price->setMonday(true);
            $price->setTuesday(true);
            $price->setWednesday(true);
            $price->setThursday(true);
            $price->setFriday(true);
            $price->setSaturday(true);
            $price->setSunday(true);
        } else {
            $noDaySelected = true;

            if (null != $request->request->get('monday-'.$id)) {
                if ($noDaySelected) {
                    $noDaySelected = false;
                }
                $price->setMonday(true);
            } else {
                $price->setMonday(false);
            }

            if (null != $request->request->get('tuesday-'.$id)) {
                if ($noDaySelected) {
                    $noDaySelected = false;
                }
                $price->setTuesday(true);
            } else {
                $price->setTuesday(false);
            }

            if (null != $request->request->get('wednesday-'.$id)) {
                if ($noDaySelected) {
                    $noDaySelected = false;
                }
                $price->setWednesday(true);
            } else {
                $price->setWednesday(false);
            }

            if (null != $request->request->get('thursday-'.$id)) {
                if ($noDaySelected) {
                    $noDaySelected = false;
                }
                $price->setThursday(true);
            } else {
                $price->setThursday(false);
            }

            if (null != $request->request->get('friday-'.$id)) {
                if ($noDaySelected) {
                    $noDaySelected = false;
                }
                $price->setFriday(true);
            } else {
                $price->setFriday(false);
            }

            if (null != $request->request->get('saturday-'.$id)) {
                if ($noDaySelected) {
                    $noDaySelected = false;
                }
                $price->setSaturday(true);
            } else {
                $price->setSaturday(false);
            }

            if (null != $request->request->get('sunday-'.$id)) {
                if ($noDaySelected) {
                    $noDaySelected = false;
                }
                $price->setSunday(true);
            } else {
                $price->setSunday(false);
            }

            if ($noDaySelected) {
                $price->setAllDays(true);
            } else {
                $price->setAllDays(false);
            }
        }

        if (null != $request->request->get('includesVat-'.$id)) {
            $price->setIncludesVat(true);
        } else {
            $price->setIncludesVat(false);
        }

        if (null != $request->request->get('isFlatPrice-'.$id)) {
            $price->setIsFlatPrice(true);
        } else {
            $price->setIsFlatPrice(false);
        }

        if (null != $request->request->get('isPerRoom-'.$id)) {
            $price->setIsPerRoom(true);
        } else {
            $price->setIsPerRoom(false);
        }

        if ($price->getIsFlatPrice()) {
            $price->setIsPerRoom(false);
        }

        if (1 == $price->getType() && null != $request->request->get('isDefaultActiveInReservationCreation-'.$id)) {
            $price->setIsDefaultActiveInReservationCreation(true);
        } else {
            $price->setIsDefaultActiveInReservationCreation(false);
        }

        if (1 == $price->getType() && null != $request->request->get('isBookableOnline-'.$id)) {
            $price->setIsBookableOnline(true);
        } else {
            $price->setIsBookableOnline(false);
        }

        if (2 == $price->getType()) {
            $price->setNumberOfPersons($request->request->get('number-of-persons-'.$id));
            $price->setMinStay($request->request->get('min-stay-'.$id));
            $category = $this->em->getRepository(RoomCategory::class)->find($request->request->get('category-'.$id));
            $price->setRoomCategory($category);
        } else {
            $price->setRoomCategory(null);
            $price->setNumberOfPersons(null);
            $price->setMinStay(null);
        }

        $this->setComponents($request, $price, $id);

        $revenueAccountId = $request->request->get('revenue-account-'.$id);
        $price->setRevenueAccount($this->resolveAccount($revenueAccountId));

        return $price;
    }

    private function resolveAccount(mixed $id): ?AccountingAccount
    {
        if (null === $id || '' === $id) {
            return null;
        }

        return $this->em->getRepository(AccountingAccount::class)->find((int) $id);
    }

    /**
     * Sync price components (packages) from the POSTed form fields. When "is-package" is not set,
     * any existing components are removed.
     */
    private function setComponents(Request $request, Price $price, $id): void
    {
        // Packages are currently only supported for misc prices (type=1). Clear otherwise.
        $isPackage = 1 === (int) $price->getType() && null != $request->request->get('is-package-'.$id);

        if (!$isPackage) {
            foreach ($price->getComponents()->toArray() as $existing) {
                $price->removeComponent($existing);
            }

            return;
        }

        $descriptions = $request->request->all('component-desc-'.$id) ?? [];
        $vats = $request->request->all('component-vat-'.$id) ?? [];
        $types = $request->request->all('component-type-'.$id) ?? [];
        $values = $request->request->all('component-value-'.$id) ?? [];
        $accounts = $request->request->all('component-account-'.$id) ?? [];
        $remainderIdx = $request->request->get('component-remainder-'.$id, '');

        $keys = array_keys($descriptions);
        $sortOrder = 0;
        $kept = new ArrayCollection();

        foreach ($keys as $key) {
            $desc = trim((string) ($descriptions[$key] ?? ''));
            if ('' === $desc) {
                continue;
            }

            $component = new PriceComponent();
            $component->setDescription($desc);
            $component->setVat((float) str_replace(',', '.', (string) ($vats[$key] ?? '0')));
            $type = ('amount' === ($types[$key] ?? 'percent'))
                ? PriceComponentAllocationType::AMOUNT
                : PriceComponentAllocationType::PERCENT;
            $component->setAllocationType($type);
            $component->setAllocationValue((float) str_replace(',', '.', (string) ($values[$key] ?? '0')));
            $component->setIsRemainder((string) $key === (string) $remainderIdx);
            $component->setSortOrder($sortOrder++);
            $component->setRevenueAccount($this->resolveAccount($accounts[$key] ?? null));

            $price->addComponent($component);
            $kept->add($component);
        }

        // Drop components that existed before but were removed in the form.
        foreach ($price->getComponents()->toArray() as $existing) {
            if (!$kept->contains($existing)) {
                $price->removeComponent($existing);
            }
        }
    }

    /**
     * Validates a price's package components. Returns a list of translation keys describing
     * each error; an empty array means the price is valid (or is not a package at all).
     */
    public function validateComponents(Price $price): array
    {
        if (!$price->isPackage()) {
            return [];
        }

        $errors = [];
        $total = (float) $price->getPrice();
        $percentSum = 0.0;
        $amountSum = 0.0;
        $remainderCount = 0;

        foreach ($price->getComponents() as $component) {
            if ('' === trim($component->getDescription())) {
                $errors[] = 'price.package.error.description_required';
            }

            if ($component->getVat() < 0) {
                $errors[] = 'price.package.error.vat_negative';
            }

            if ($component->isRemainder()) {
                ++$remainderCount;
                continue; // remainder component's value is derived, skip sum checks
            }

            if ($component->getAllocationValue() <= 0) {
                $errors[] = 'price.package.error.value_required';
            }

            if (PriceComponentAllocationType::PERCENT === $component->getAllocationType()) {
                $percentSum += $component->getAllocationValue();
            } else {
                $amountSum += $component->getAllocationValue();
            }
        }

        if ($remainderCount > 1) {
            $errors[] = 'price.package.error.multiple_remainder';
        }

        $epsilon = 0.01;

        if (0 === $remainderCount) {
            // Without remainder: percent part must fill the remaining amount exactly.
            $percentBrutto = $total * $percentSum / 100.0;
            $covered = $percentBrutto + $amountSum;
            if (abs($covered - $total) > $epsilon) {
                $errors[] = 'price.package.error.sum_mismatch';
            }
        } else {
            // With remainder: percent must be <= 100, amounts must be <= total.
            if ($percentSum > 100.0 + $epsilon) {
                $errors[] = 'price.package.error.percent_over_100';
            }
            if ($amountSum > $total + $epsilon) {
                $errors[] = 'price.package.error.amount_over_total';
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Expands a package price into per-component aggregates suitable for building N invoice positions.
     * Each returned aggregate mirrors the shape produced by InvoiceService::computeMiscPriceAggregates()
     * so it can be fed directly into createMiscPositionsFromAggregates().
     *
     * @param Price $price       the package price (expected to satisfy $price->isPackage())
     * @param float $unitPrice   unit (bulk) price per item - usually $price->getPrice() but can be overridden by user edits
     * @param int   $amount      quantity of items (copied onto each resulting aggregate)
     * @param bool  $includesVat whether $unitPrice is gross (true) or net (false)
     *
     * @return array<int, array{price: Price, component: PriceComponent, amount: int, unitPrice: float, includesVat: bool}>
     */
    public function expandPackage(Price $price, float $unitPrice, int $amount, bool $includesVat): array
    {
        if (!$price->isPackage()) {
            return [];
        }

        $components = $price->getComponents()->toArray();
        usort($components, static fn (PriceComponent $a, PriceComponent $b) => $a->getSortOrder() <=> $b->getSortOrder());

        $results = [];
        $allocated = 0.0;
        $remainderIndex = null;

        foreach ($components as $idx => $component) {
            if ($component->isRemainder()) {
                $remainderIndex = $idx;
                $results[$idx] = null;
                continue;
            }

            if (PriceComponentAllocationType::PERCENT === $component->getAllocationType()) {
                $componentUnit = round($unitPrice * $component->getAllocationValue() / 100.0, 2);
            } else {
                $componentUnit = round($component->getAllocationValue(), 2);
            }

            $allocated += $componentUnit;
            $results[$idx] = [
                'price' => $price,
                'component' => $component,
                'amount' => $amount,
                'unitPrice' => $componentUnit,
                'includesVat' => $includesVat,
            ];
        }

        if (null !== $remainderIndex) {
            $remainderUnit = round($unitPrice - $allocated, 2);
            if ($remainderUnit < 0) {
                $remainderUnit = 0.0;
            }
            $results[$remainderIndex] = [
                'price' => $price,
                'component' => $components[$remainderIndex],
                'amount' => $amount,
                'unitPrice' => $remainderUnit,
                'includesVat' => $includesVat,
            ];
        } else {
            // Absorb rounding residue into the last non-zero component so the sum matches unitPrice exactly.
            $residue = round($unitPrice - $allocated, 2);
            if (0.0 !== $residue && [] !== $results) {
                $lastKey = array_key_last($results);
                $results[$lastKey]['unitPrice'] = round($results[$lastKey]['unitPrice'] + $residue, 2);
            }
        }

        return array_values(array_filter($results));
    }

    public function getActiveMiscellaneousPrices(): ?array
    {
        return $this->em->getRepository(Price::class)->getActiveMiscellaneousPrices();
    }

    public function getActiveAppartmentPrices(): ?array
    {
        return $this->em->getRepository(Price::class)->getActiveAppartmentPrices();
    }

    /**
     * Returns a list of conflicting prices.
     *
     * @return Doctrine\Common\Collections\ArrayCollection
     */
    public function findConflictingPrices(Price $price)
    {
        $prices = [];
        // find conflicts when no season is given
        if ($price->getAllPeriods()) {
            $prices = $this->em->getRepository(Price::class)->findConflictingPricesWithoutPeriod($price);
        } else {
            // // find conflicts when a season is given
            $prices = $this->em->getRepository(Price::class)->findConflictingPricesWithPeriod($price);
        }

        return new ArrayCollection($prices);
    }

    public function deletePrice(Price $price): bool
    {
        $this->em->remove($price);
        $this->em->flush();

        return true;
    }

    /**
     * Based on the given reservation, price categories will be returned for each day of stay ordered by priority
     * The result is an array where ech key represents a day of stay. idx 0 startday idx, 1 next day, ...
     */
    public function getPricesForReservationDays(Reservation $reservation, int $type, ?Collection $prices = null): array
    {
        $days = $this->getDateDiff($reservation->getStartDate(), $reservation->getEndDate());
        if (1 === $type && null === $prices) {
            $prices = $this->em->getRepository(Price::class)->findMiscPrices($reservation);
        } elseif (null === $prices) {
            $prices = $this->em->getRepository(Price::class)->findApartmentPrices($reservation, $days);
        } // else use prices from method param

        $result = [];
        $curDate = clone $reservation->getStartDate();
        for ($i = 0; $i <= $days; ++$i) {
            $result[$i] = null;
            $curDate = $curDate->add(new \DateInterval('P'.(0 === $i ? 0 : 1).'D'));
            // echo $curDate->format("Y-m-d");
            /* @var $price Price */
            foreach ($prices as $price) {
                // without periods
                if ($price->getAllPeriods()) {
                    if ($this->isWeekDayMatch($price, $curDate)) {
                        $result[$i][] = $price;
                        // apartment prices can have only one price per day, others can have more than one
                        if (2 === $type) {
                            // found one, go to next day
                            break;
                        }
                    }
                }
                // with periods
                $periods = $price->getPricePeriods();
                foreach ($periods as $pricePeriod) {
                    // prices are already sorted by priority, therefore we can accept the first matching one
                    // first we need to check if the current date is in between the price season
                    if ($this->isDateBetween($curDate, $pricePeriod->getStart(), $pricePeriod->getEnd())) {
                        // second, we need to check if the weekday match
                        if ($this->isWeekDayMatch($price, $curDate)) {
                            $result[$i][] = $price;
                            // apartment prices can have only one price per day, others can have more than one
                            if (2 === $type) {
                                // found one, go to next day and break outer price loop
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Will look for uniqe prices that are valid for the given reservations.
     *
     * @return Collection
     */
    public function getUniquePricesForReservations(array $reservations, int $type)
    {
        $uniquePrices = new ArrayCollection();
        foreach ($reservations as $reservation) {
            $pricesPerDay = $this->getPricesForReservationDays($reservation, $type);
            foreach ($pricesPerDay as $day => $prices) {
                if (null === $prices) {
                    continue;
                }
                foreach ($prices as $price) {
                    if (!$uniquePrices->contains($price)) {
                        $uniquePrices[] = $price;
                    }
                }
            }
        }

        return $uniquePrices;
    }

    private function getDateDiff(\DateTime $start, \DateTime $end): int
    {
        $interval = date_diff($start, $end);

        // return number of days, minimum 1 for same-day bookings
        return max(1, (int) $interval->format('%a'));
    }

    private function isDateBetween(\DateTime $cur, \DateTime $start, \DateTime $end)
    {
        if (($cur >= $start) && ($cur <= $end)) {
            return true;
        }

        return false;
    }

    private function isWeekDayMatch(Price $price, \DateTime $curr)
    {
        if ($price->getAllDays()) {
            return true;
        }

        $dayOfWeek = $curr->format('N'); // 1 = Mon, 7 = Sun
        switch ($dayOfWeek) {
            case 1:
                if ($price->getMonday()) {
                    return true;
                }
                break;
            case 2:
                if ($price->getTuesday()) {
                    return true;
                }
                break;
            case 3:
                if ($price->getWednesday()) {
                    return true;
                }
                break;
            case 4:
                if ($price->getThursday()) {
                    return true;
                }
                break;
            case 5:
                if ($price->getFriday()) {
                    return true;
                }
                break;
            case 6:
                if ($price->getSaturday()) {
                    return true;
                }
                break;
            case 7:
                if ($price->getSunday()) {
                    return true;
                }
                break;
        }

        return false;
    }

    /**
     * Helper funtion to set posted reservation origins.
     *
     * @param type $id
     */
    private function setOrigins(Request $request, Price $price, $id): void
    {
        $origins = $request->request->all('origin-'.$id) ?? [];
        $allAddedOrigins = new ArrayCollection();

        $originsDb = $this->em->getRepository(ReservationOrigin::class)->findById($origins);
        // now add all origins
        foreach ($originsDb as $originDb) {
            $price->addReservationOrigin($originDb);
            $allAddedOrigins->add($originDb);
        }

        // when a origin is deleted in the frontend it is not in the post body anymore, therefore we need to find it and remove it from db
        $allAndRemovableOrigins = $price->getReservationOrigins();
        foreach ($allAndRemovableOrigins as $origin) {
            if (!$allAddedOrigins->contains($origin)) {
                $price->removeReservationOrigin($origin);
            }
        }
    }

    /**
     * Helper function to set posted periods, if a period exists the existing one is used otherwise a new one is created.
     *
     * @param type $id
     */
    private function setPeriods(Request $request, Price $price, $id): void
    {
        $allAddedPeriods = new ArrayCollection();
        $periodIds = array_unique($request->request->all('period-'.$id) ?? []);
        // loop over all posted periods (new and existing ones)
        foreach ($periodIds as $id) {
            $starts = $request->request->all('periodstart-'.$id) ?? [];
            $ends = $request->request->all('periodend-'.$id) ?? [];

            foreach ($starts as $key => $start) {
                if ('new' !== $id) {
                    /* @var $pricePeriod PricePeriod */
                    $pricePeriod = $this->em->getRepository(PricePeriod::class)->find($id);
                    // check if the period exists and the period is part of the current price category
                    if (!$pricePeriod instanceof PricePeriod || $pricePeriod->getPrice() !== $price) {
                        $pricePeriod = new PricePeriod();
                    }
                } else {
                    $pricePeriod = new PricePeriod();
                }
                $pricePeriod->setStart(new \DateTime($start));
                $pricePeriod->setEnd(new \DateTime($ends[$key]));

                $allAddedPeriods->add($pricePeriod);
                $price->addPricePeriod($pricePeriod);
            }
        }

        // when a period is deleted in the frontend it is not in the post body anymore, therefore we need to find it and remove it from db
        $allAndRemovablePeriods = $price->getPricePeriods();
        foreach ($allAndRemovablePeriods as $period) {
            if (!$allAddedPeriods->contains($period)) {
                $price->removePricePeriod($period);
            }
        }
    }
}
