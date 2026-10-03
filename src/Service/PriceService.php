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

use App\Dto\PriceBreakdown;
use App\Dto\PriceBreakdownLine;
use App\Dto\PriceCategoryGroup;
use App\Dto\Pricing\NightAdjustment;
use App\Dto\Pricing\NightRate;
use App\Dto\Pricing\PricePromise;
use App\Dto\Pricing\RateAdjustment;
use App\Entity\AccountingAccount;
use App\Entity\Enum\ModifierType;
use App\Entity\Enum\PercentageBase;
use App\Entity\Enum\PriceComponentAllocationType;
use App\Entity\GuestCategory;
use App\Entity\GuestCategoryModifier;
use App\Entity\Price;
use App\Entity\PriceComponent;
use App\Entity\PricePeriod;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Repository\GuestCategoryModifierRepository;
use App\Repository\GuestCategoryRepository;
use App\Service\Pricing\DynamicRateResolver;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;

class PriceService implements ResetInterface
{
    private $em;

    /**
     * Guest categories and the modifiers valid on a night are configuration: pricing asks for the
     * same rows again for every reservation and every night of it. Remembering them for the request
     * turns one query per night into one query. ResetInterface clears them between requests, so a
     * worker process never prices with the configuration of the request before.
     *
     * @var array<int, GuestCategory>|null
     */
    private ?array $guestCategories = null;

    /** @var array<string, list<GuestCategoryModifier>> keyed by night, Y-m-d */
    private array $modifiersPerNight = [];

    public function __construct(
        EntityManagerInterface $em,
        private readonly ReservationPeriodService $reservationPeriodService,
        private readonly ?GuestCategoryRepository $guestCategoryRepository = null,
        private readonly ?GuestCategoryModifierRepository $modifierRepository = null,
        private readonly ?DynamicRateResolver $dynamicRates = null,
    ) {
        $this->em = $em;
    }

    public function reset(): void
    {
        $this->guestCategories = null;
        $this->modifiersPerNight = [];
    }

    /**
     * All guest categories by id, loaded once per request.
     *
     * @return array<int, GuestCategory>
     */
    public function guestCategories(): array
    {
        if (null !== $this->guestCategories) {
            return $this->guestCategories;
        }

        $categories = [];
        if (null !== $this->guestCategoryRepository) {
            foreach ($this->guestCategoryRepository->findAll() as $guestCategory) {
                $categories[$guestCategory->getId()] = $guestCategory;
            }
        }

        return $this->guestCategories = $categories;
    }

    /**
     * The guest category modifiers valid on that night, loaded once per night and request.
     *
     * @return list<GuestCategoryModifier>
     */
    private function modifiersOn(\DateTimeInterface $night): array
    {
        if (null === $this->modifierRepository) {
            return [];
        }

        return $this->modifiersPerNight[$night->format('Y-m-d')] ??= $this->modifierRepository->findActiveOn($night);
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

        // Berechnungsart als Radio (flat | per_room | per_person)
        $calcType = (string) $request->request->get('calculation-type-'.$id, 'per_person');
        $price->setIsFlatPrice('flat' === $calcType);
        $price->setIsPerRoom('per_room' === $calcType);

        if (1 == $price->getType() && null != $request->request->get('isDefaultActiveInReservationCreation-'.$id)) {
            $price->setIsDefaultActiveInReservationCreation(true);
        } else {
            $price->setIsDefaultActiveInReservationCreation(false);
        }

        // Off means the house sells this on site: a portal neither brokered nor
        // processed it, so none of its fees are charged on it. On is the ordinary
        // case and how the switch starts out, so a price saved without touching
        // it keeps counting towards a portal's fees as it did before.
        //
        // Asked for miscellaneous prices only. A night is the thing the portal
        // brokered - the calculator counts it towards the fees whatever this
        // says - so the form does not offer the switch there, and a missing
        // field must not be read as an answer of "no".
        $price->setBrokered(1 != $price->getType() || $request->request->getBoolean('brokered-'.$id));

        $mandatoryOnline = 1 == $price->getType() && null != $request->request->get('isMandatoryOnline-'.$id);
        $bookableOnline = 1 == $price->getType() && null != $request->request->get('isBookableOnline-'.$id);
        // Pflicht impliziert online verfügbar — auch wenn der Switch im UI gesperrt war.
        if ($mandatoryOnline) {
            $bookableOnline = true;
        }
        $price->setIsBookableOnline($bookableOnline);
        $price->setIsMandatoryOnline($mandatoryOnline);

        $this->setRoomCategories($request, $price, $id);

        if (2 == $price->getType()) {
            $price->setNumberOfPersons($request->request->get('number-of-persons-'.$id));
            $price->setMinStay($request->request->get('min-stay-'.$id));
        } else {
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
     * Returns the active apartment prices that would compete with the given one for the same night:
     * same occupancy and minimum stay, at least one shared room category, reservation origin and
     * weekday, and overlapping periods. Misc prices never conflict — several of them may apply to
     * the same stay.
     *
     * @return ArrayCollection<int, Price>
     */
    public function findConflictingPrices(Price $price): ArrayCollection
    {
        if (2 !== (int) $price->getType()) {
            return new ArrayCollection();
        }

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

    /**
     * Groups prices for the settings overview by their exact set of room categories and splits
     * each group into apartment and misc prices. Each price appears in exactly one group.
     *
     * Groups follow the order in which the categories were created (id): single categories first,
     * then combinations of several, then prices for every category. Within a group the given
     * order of the prices is kept.
     *
     * @param Price[] $prices
     *
     * @return list<PriceCategoryGroup>
     */
    public function groupByRoomCategories(array $prices): array
    {
        $groups = [];
        foreach ($prices as $price) {
            $categories = $price->getRoomCategories()->toArray();
            usort($categories, static fn (RoomCategory $a, RoomCategory $b): int => $a->getId() <=> $b->getId());
            $ids = array_map(static fn (RoomCategory $category): int => (int) $category->getId(), $categories);

            $key = implode(',', $ids);
            $groups[$key] ??= ['categories' => $categories, 'ids' => $ids, 'apartment' => [], 'misc' => []];
            $groups[$key][2 === (int) $price->getType() ? 'apartment' : 'misc'][] = $price;
        }

        // Before prices could have several categories the overview was ordered by category id.
        // Keeping single categories first and in that order leaves the familiar sequence intact;
        // combinations follow, and "all categories" (no ids) comes last.
        uasort($groups, static function (array $a, array $b): int {
            $sizeA = [] === $a['ids'] ? PHP_INT_MAX : count($a['ids']);
            $sizeB = [] === $b['ids'] ? PHP_INT_MAX : count($b['ids']);

            // Equal sizes compare the id lists element by element.
            return [$sizeA, $a['ids']] <=> [$sizeB, $b['ids']];
        });

        return array_values(array_map(
            static fn (array $group): PriceCategoryGroup => new PriceCategoryGroup($group['categories'], $group['apartment'], $group['misc']),
            $groups,
        ));
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
     *
     * @param Collection<int, Price>|null $prices
     *
     * @return array<int, list<Price>|null>
     *
     * @throws \App\Exception\InvalidReservationPeriodException when the reservation period is unsafe to process
     */
    public function getPricesForReservationDays(Reservation $reservation, int $type, ?Collection $prices = null): array
    {
        // Guard before repository work or the per-night result allocation. A mistyped
        // year previously allowed this loop to grow until PHP exhausted its memory.
        $days = $this->reservationPeriodService->validate(
            $reservation->getStartDate(),
            $reservation->getEndDate(),
        )->nights;
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
     * The room price of every night of the stay, index 0 being the arrival night. A day-use stay
     * (arrival = departure) has one entry for its day, as it is billed like one night.
     *
     * Nights covered by the reservation's price promise come from the promise; all others, and
     * every night with $ignorePromise, from the current price rows. A promise only counts while
     * it was made for what the reservation is now (see PricePromise::contextKey()), and a promised
     * night whose price row no longer exists is priced from the current rows as well.
     *
     * Price rules change the current rows for what is priced now: a stay not saved yet (quote,
     * new booking) and, with $ignorePromise, the promise being built for a booking. A saved
     * booking without a promise keeps the plain price list. Flat prices are never changed.
     *
     * @return array<int, NightRate|null> null for a night without any applicable price
     *
     * @throws \App\Exception\InvalidReservationPeriodException when the reservation period is unsafe to process
     */
    public function getNightRates(Reservation $reservation, bool $ignorePromise = false): array
    {
        $nights = $this->reservationPeriodService->validate(
            $reservation->getStartDate(),
            $reservation->getEndDate(),
        )->nights;
        $promise = $ignorePromise ? null : $this->applicablePromise($reservation);
        $promisedPrices = null !== $promise ? $this->findPricesById($promise->priceIds()) : [];
        $start = \DateTimeImmutable::createFromInterface($reservation->getStartDate())->setTime(0, 0);

        $live = null;
        $adjustments = null;
        $rates = [];
        for ($i = 0, $count = max(1, $nights); $i < $count; ++$i) {
            $night = $start->modify('+'.$i.' day');
            $promised = $promise?->night($night);
            $promisedPrice = null !== $promised ? ($promisedPrices[$promised->priceId] ?? null) : null;
            if (null !== $promised && null !== $promisedPrice) {
                $rates[$i] = NightRate::promised($night, $promisedPrice, $promised);
                continue;
            }

            $live ??= $this->getPricesForReservationDays($reservation, 2);
            $price = $live[$i][0] ?? null;
            if (!$price instanceof Price) {
                $rates[$i] = null;
                continue;
            }
            $rate = NightRate::live($night, $price);
            $adjustments ??= null === $reservation->getId() || $ignorePromise
                ? $this->ruleAdjustments($reservation, $start, $start->modify('+'.$count.' day'))
                : [];
            $adjustment = $adjustments[$night->format('Y-m-d')] ?? null;
            if (null !== $adjustment && null !== $this->dynamicRates && !$rate->isFlatPrice && 0.0 !== round($adjustment->percent, 2)) {
                $rate = $rate->adjusted($this->dynamicRates->apply($rate->unit, $adjustment), RateAdjustment::from($rate->unit, $adjustment));
            }
            $rates[$i] = $rate;
        }

        return $rates;
    }

    /**
     * Price rule adjustments for the room of the reservation; the reservation itself does not
     * count towards the occupancy it is priced by.
     *
     * @return array<string, NightAdjustment>
     */
    private function ruleAdjustments(Reservation $reservation, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        $apartment = $reservation->getAppartment();
        $category = $apartment?->getRoomCategory();
        if (null === $this->dynamicRates || null === $category) {
            return [];
        }

        return $this->dynamicRates->adjustments($apartment->getObject(), $category, $from, $toExclusive, excludingReservationId: $reservation->getId());
    }

    /** The stored price promise of the reservation, null when it has none or it is unreadable. */
    public function promiseOf(Reservation $reservation): ?PricePromise
    {
        return PricePromise::fromArray($reservation->getPricePromise());
    }

    /** The price promise, as long as it was made for what the reservation is now. */
    private function applicablePromise(Reservation $reservation): ?PricePromise
    {
        $promise = $this->promiseOf($reservation);

        return null !== $promise && $promise->context === PricePromise::contextKey($reservation) ? $promise : null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Price> keyed by id
     */
    public function findPricesById(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $prices = [];
        foreach ($this->em->getRepository(Price::class)->findBy(['id' => $ids]) as $price) {
            $prices[(int) $price->getId()] = $price;
        }

        return $prices;
    }

    /**
     * Builds a per-night PriceBreakdown for the apartment price (type=2),
     * applying GuestCategoryModifiers per non-ADULT category from
     * Reservation.guestCounts. ADULT counts use the unmodified base price.
     *
     * Promised nights carry the guest lines of the promise instead, so a
     * modifier changed after booking does not reach the booking.
     *
     * @return PriceBreakdown[] keyed by day index (0..nights-1)
     */
    public function getPriceBreakdownForReservation(Reservation $reservation, bool $ignorePromise = false): array
    {
        $rates = $this->getNightRates($reservation, $ignorePromise);
        $days = $this->reservationPeriodService->validate(
            $reservation->getStartDate(),
            $reservation->getEndDate(),
        )->nights;
        $guestCounts = $reservation->getGuestCounts();

        $categories = $this->guestCategories();

        $result = [];
        $curDate = clone $reservation->getStartDate();
        for ($i = 0; $i < $days; ++$i) {
            $night = (clone $curDate)->add(new \DateInterval('P'.$i.'D'));
            $rate = $rates[$i] ?? null;
            $price = $rate?->price;
            $breakdown = new PriceBreakdown($night, $price, $rate);

            if (null === $rate || empty($guestCounts)) {
                $result[$i] = $breakdown;
                continue;
            }

            if (null !== $rate->promisedLines) {
                foreach ($rate->promisedLines as $line) {
                    // A deleted guest category is no longer part of the reservation's counts either.
                    $category = $categories[$line->categoryId] ?? null;
                    if (null !== $category) {
                        $breakdown->addLine(new PriceBreakdownLine($category, $line->count, (float) $line->unit, null, $line->adjustment));
                    }
                }
                $result[$i] = $breakdown;
                continue;
            }

            $basePerHead = (float) $rate->unit;
            $modifiers = $this->modifiersOn($night);

            // "Minimum full-fare guests" rule of the room type: the first N
            // occupants always pay the regular per-head rate; modifiers only
            // apply to guests beyond that threshold. Compute, per non-adult
            // occupancy category, how many of its heads must stay at full fare.
            // The rule is read from the booked room: a price can serve several
            // room categories with different thresholds.
            $fullFarePerCategory = $this->computeFullFareSlots(
                $guestCounts,
                $categories,
                $reservation->getAppartment()?->getRoomCategory(),
            );

            foreach ($guestCounts as $catId => $count) {
                $count = (int) $count;
                if ($count <= 0) {
                    continue;
                }
                $category = $categories[$catId] ?? null;
                if (null === $category) {
                    continue;
                }

                $modifier = $this->pickModifier($modifiers, $category);
                $fullFare = $fullFarePerCategory[$catId] ?? 0;
                $discounted = $count - $fullFare;

                // Heads occupying a full-fare slot are billed at the base rate
                // (no modifier); only the surplus heads receive the modifier.
                if ($fullFare > 0) {
                    $breakdown->addLine(new PriceBreakdownLine($category, $fullFare, $basePerHead, null));
                }
                if ($discounted > 0) {
                    $unit = $this->applyModifier($basePerHead, $modifier, $category);
                    $breakdown->addLine(new PriceBreakdownLine($category, $discounted, $unit, $modifier));
                }
            }

            $result[$i] = $breakdown;
        }

        return $result;
    }

    /**
     * Apartment total per night (after modifier deltas), expressed in the
     * requested net/gross flavor. Used as the bemessungsgrundlage for
     * percentage-based tourist taxes (Dresden/Berlin city tax models).
     *
     * Per-head pricing: sum(count × unit_with_modifier) per night.
     * Flat-price / per-room: basePrice once per night (occupancy-independent).
     *
     * @return array<string, float> keyed by Y-m-d, value = apartment total in the requested base
     */
    public function getApartmentTotalsPerNight(Reservation $reservation, PercentageBase $target): array
    {
        $breakdowns = $this->getPriceBreakdownForReservation($reservation);
        $result = [];
        foreach ($breakdowns as $breakdown) {
            $price = $breakdown->basePrice;
            $rate = $breakdown->rate;
            if (null === $price || null === $rate) {
                continue;
            }
            if ($rate->isFlatPrice || $rate->isPerRoom) {
                $stored = (float) $rate->unit;
            } else {
                // Mirror the apartment invoice row: numberOfPersons × per-head,
                // then apply modifier deltas (only for occupancy-counted, non-adult
                // categories — same rule as buildApartmentModifierPositions). This
                // makes the city-tax base match the effective apartment total
                // visible on the invoice (room row + modifier row).
                $perHead = (float) $rate->unit;
                $numPersons = (int) $price->getNumberOfPersons();
                $stored = $perHead * $numPersons;
                foreach ($breakdown->lines as $line) {
                    if (null === $line->adjustment) {
                        continue;
                    }
                    if (!$line->category->isCountedInOccupancy()) {
                        continue;
                    }
                    if ($line->category->isAdult()) {
                        continue;
                    }
                    $delta = ($line->unitPrice - $perHead) * $line->count;
                    $stored += $delta;
                }
            }
            $vat = null !== $price->getVat() ? (float) $price->getVat() : 0.0;
            $storedIncludesVat = $rate->includesVat;

            $value = $this->convertNetGross($stored, $vat, $storedIncludesVat, $target);
            $result[$breakdown->night->format('Y-m-d')] = $value;
        }

        return $result;
    }

    private function convertNetGross(float $stored, float $vatPercent, bool $storedIncludesVat, PercentageBase $target): float
    {
        if ($vatPercent <= 0.0) {
            return $stored;
        }
        $factor = 1.0 + $vatPercent / 100.0;
        if (PercentageBase::NET === $target) {
            return $storedIncludesVat ? $stored / $factor : $stored;
        }

        return $storedIncludesVat ? $stored : $stored * $factor;
    }

    /**
     * Distributes the room type's "minimum full-fare guests" across the booked
     * non-adult occupancy categories.
     *
     * Adults always pay full fare and consume slots first. Remaining slots are
     * filled by the non-adult occupancy categories with the lowest sortOrder
     * first (i.e. the categories the hotelier ranked highest — typically older
     * children with the smallest discount), so the largest discounts survive for
     * the surplus guests. Non-occupancy categories (e.g. infants in a cot) are
     * not part of room occupancy and never consume a slot.
     *
     * @param array<int|string, int|string> $guestCounts  {categoryId: count}
     * @param array<int, GuestCategory>     $categories   {categoryId: GuestCategory}
     * @param RoomCategory|null             $roomCategory category of the booked room; null means no rule
     *
     * @return array<int, int> {categoryId: number of heads to keep at full fare}
     */
    private function computeFullFareSlots(array $guestCounts, array $categories, ?RoomCategory $roomCategory): array
    {
        $minFullPayers = $roomCategory?->getMinFullPayers() ?? 0;
        if ($minFullPayers <= 0) {
            return [];
        }

        // Adults (counted in occupancy) fill full-fare slots first.
        $adultHeads = 0;
        $nonAdultCategories = [];
        foreach ($guestCounts as $catId => $count) {
            $count = (int) $count;
            if ($count <= 0) {
                continue;
            }
            $category = $categories[$catId] ?? null;
            if (null === $category || !$category->isCountedInOccupancy()) {
                continue;
            }
            if ($category->isAdult()) {
                $adultHeads += $count;
                continue;
            }
            $nonAdultCategories[(int) $catId] = ['count' => $count, 'category' => $category];
        }

        $remainingSlots = $minFullPayers - $adultHeads;
        if ($remainingSlots <= 0) {
            return [];
        }

        // Lowest sortOrder first; stable tie-break on id for determinism.
        uasort(
            $nonAdultCategories,
            static fn (array $a, array $b): int => [$a['category']->getSortOrder(), $a['category']->getId()]
                <=> [$b['category']->getSortOrder(), $b['category']->getId()]
        );

        $slots = [];
        foreach ($nonAdultCategories as $catId => $entry) {
            if ($remainingSlots <= 0) {
                break;
            }
            $take = min($entry['count'], $remainingSlots);
            $slots[$catId] = $take;
            $remainingSlots -= $take;
        }

        return $slots;
    }

    /**
     * @param GuestCategoryModifier[] $modifiers
     */
    private function pickModifier(array $modifiers, GuestCategory $category): ?GuestCategoryModifier
    {
        foreach ($modifiers as $mod) {
            if ($mod->getCategory()?->getId() === $category->getId()) {
                return $mod;
            }
        }

        return null;
    }

    private function applyModifier(float $base, ?GuestCategoryModifier $modifier, GuestCategory $category): float
    {
        // ADULT category never uses a modifier — it is the base reference.
        if (null === $modifier || $category->isAdult()) {
            return $base;
        }
        $value = $modifier->getValueAsFloat();

        return match ($modifier->getType()) {
            ModifierType::SURCHARGE_ABSOLUTE => $base + $value,
            ModifierType::DISCOUNT_PERCENT => max(0.0, $base * (1.0 - $value / 100.0)),
            ModifierType::FLAT_RATE => $value,
            ModifierType::FREE => 0.0,
        };
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
     * Syncs the posted room categories onto the price. An empty selection is kept empty: for misc
     * prices it means "every room category", apartment prices are rejected by the controller.
     */
    private function setRoomCategories(Request $request, Price $price, int|string $id): void
    {
        $categoryIds = array_filter(
            $request->request->all('category-'.$id),
            static fn (mixed $value): bool => is_scalar($value) && ctype_digit((string) $value),
        );
        $selected = [] === $categoryIds
            ? []
            : $this->em->getRepository(RoomCategory::class)->findBy(['id' => array_map('intval', $categoryIds)]);

        foreach ($selected as $category) {
            $price->addRoomCategory($category);
        }
        // Categories deselected in the form are no longer part of the post body.
        foreach ($price->getRoomCategories()->toArray() as $category) {
            if (!in_array($category, $selected, true)) {
                $price->removeRoomCategory($category);
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
            $descriptions = $request->request->all('perioddescription-'.$id) ?? [];

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
                $pricePeriod->setDescription(mb_substr((string) ($descriptions[$key] ?? ''), 0, 100));

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
