<?php

declare(strict_types=1);

namespace App\Service\Pricing;

use App\Dto\Pricing\SpecialPricePlan;
use App\Dto\Pricing\SpecialPriceRequest;
use App\Entity\Price;
use App\Entity\PriceComponent;
use App\Entity\PricePeriod;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Exception\SpecialPriceException;
use App\Repository\PricePeriodRepository;
use App\Repository\PriceRepository;
use App\Repository\ReservationRepository;
use App\Service\PriceService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lets an apartment price apply in a special period (trade fair, festival, ...) without a web
 * session; used by the MCP tools.
 *
 * Rows with special periods take precedence over year-round rows, so a special price never
 * conflicts with the base price. It does conflict with other special rows for the same nights
 * (see SpecialPricePlan); the price list form refuses to save such a state, and so does apply()
 * unless the request allows overwriting, which cuts the new nights out of the competing periods.
 * Periods that do not compete stay untouched, including special rows with a different minimum
 * stay: per night the row with the higher minimum stay wins once the stay is long enough.
 */
class SpecialPriceService
{
    public const MAX_NIGHTS = 366;
    public const MAX_YEARS_AHEAD = 3;
    public const MAX_AMOUNT = 100000;
    private const APARTMENT_PRICE = 2;
    private const MIN_DESCRIPTION_LENGTH = 3;
    private const MAX_DESCRIPTION_LENGTH = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PriceRepository $priceRepository,
        private readonly PricePeriodRepository $pricePeriodRepository,
        private readonly ReservationRepository $reservationRepository,
        private readonly PriceService $priceService,
    ) {
    }

    /**
     * Validates the request and works out what it would change, without saving anything.
     *
     * @throws SpecialPriceException with a message that is safe to show to clients
     */
    public function plan(SpecialPriceRequest $request, ?\DateTimeImmutable $today = null): SpecialPricePlan
    {
        $source = $this->priceRepository->find($request->priceId);
        if (!$source instanceof Price || self::APARTMENT_PRICE !== (int) $source->getType()) {
            throw new SpecialPriceException('Unknown room price id. Special prices are based on a room price row (type "apartment").');
        }
        if (!$source->getActive()) {
            throw new SpecialPriceException('This price row is inactive.');
        }
        $this->validatePeriod($request, $today ?? new \DateTimeImmutable('today'));
        $this->validateText($request->periodDescription, 'periodDescription');

        if ($request->createsRow()) {
            if ($request->amount <= 0 || $request->amount > self::MAX_AMOUNT) {
                throw new SpecialPriceException(\sprintf("'amount' must be greater than 0 and at most %d.", self::MAX_AMOUNT));
            }
            if (null !== $request->rowDescription) {
                $this->validateText($request->rowDescription, 'rowDescription');
            }
        } else {
            if ($source->getAllPeriods()) {
                throw new SpecialPriceException('This price row applies all year. Pass an amount to create a special price row from it instead.');
            }
            foreach ($source->getPricePeriods() as $period) {
                [$start, $end] = self::dates($period);
                if ($start <= $request->lastNight && $end >= $request->firstNight) {
                    throw new SpecialPriceException(\sprintf('The price row already has a period from %s to %s that overlaps these nights.', $start->format('Y-m-d'), $end->format('Y-m-d')));
                }
            }
        }

        $conflictIds = $this->priceRepository->findConflictingPriceIdsForPeriod($source, $request->firstNight, $request->lastNight);
        if (!$request->createsRow()) {
            // The row's own periods were checked above; it cannot compete with itself.
            $conflictIds = array_values(array_diff($conflictIds, [(int) $source->getId()]));
        }

        $conflicts = [];
        foreach ($this->pricePeriodRepository->findOverlappingForPrices($conflictIds, $request->firstNight, $request->lastNight) as $period) {
            $price = $period->getPrice();
            if (!$price instanceof Price) {
                continue;
            }
            [$start, $end] = self::dates($period);
            $conflicts[(int) $price->getId()]['price'] = $price;
            $conflicts[(int) $price->getId()]['periods'][] = [
                'period' => $period,
                'remaining' => self::cut($start, $end, $request->firstNight, $request->lastNight),
            ];
        }

        $otherStayLengthIds = $this->priceRepository->findPriceIdsForOtherStayLengthsForPeriod($source, $request->firstNight, $request->lastNight);
        if (!$request->createsRow()) {
            $otherStayLengthIds = array_values(array_diff($otherStayLengthIds, [(int) $source->getId()]));
        }

        return new SpecialPricePlan(
            $request,
            $source,
            array_values($conflicts),
            $this->reservationRepository->findUninvoicedForPriceChange(
                $request->firstNight,
                $request->lastNight,
                array_values(array_map(static fn (RoomCategory $category): int => (int) $category->getId(), $source->getRoomCategories()->toArray())),
                array_values(array_map(static fn (ReservationOrigin $origin): int => (int) $origin->getId(), $source->getReservationOrigins()->toArray())),
                null !== $source->getNumberOfPersons() ? (int) $source->getNumberOfPersons() : null,
            ),
            [] !== $otherStayLengthIds ? array_values($this->priceRepository->findBy(['id' => $otherStayLengthIds], ['minStay' => 'ASC'])) : [],
        );
    }

    /**
     * Saves a plan: cuts the new nights out of the competing periods (only when allowed) and adds
     * the period to the source row or to a new copy of it.
     *
     * @return Price the row that now carries the special period
     *
     * @throws SpecialPriceException when the plan has conflicts it may not overwrite, or the copy is invalid
     */
    public function apply(SpecialPricePlan $plan): Price
    {
        if (!$plan->canApply()) {
            throw new SpecialPriceException('Other special price rows apply on these nights. Show the conflicts to the user and, if they agree, preview again with overwriteConflicts.');
        }
        $request = $plan->request;
        $target = $request->createsRow() ? $this->copyRow($plan->source, (float) $request->amount, $this->rowDescription($plan)) : $plan->source;

        return $this->em->wrapInTransaction(function () use ($plan, $request, $target): Price {
            foreach ($plan->conflicts as $conflict) {
                foreach ($conflict['periods'] as $entry) {
                    $this->cutOut($conflict['price'], $entry['period'], $entry['remaining']);
                }
            }

            $period = (new PricePeriod())
                ->setStart(\DateTime::createFromImmutable($request->firstNight))
                ->setEnd(\DateTime::createFromImmutable($request->lastNight))
                ->setDescription($request->periodDescription);
            $target->addPricePeriod($period);
            $this->em->persist($target);
            $this->em->flush();

            return $target;
        });
    }

    /**
     * What is left of the period $start..$end once $cutStart..$cutEnd is taken out (all dates
     * inclusive, the ranges must overlap): nothing, one shortened range or two ranges.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public static function cut(\DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeImmutable $cutStart, \DateTimeImmutable $cutEnd): array
    {
        $remaining = [];
        if ($start < $cutStart) {
            $remaining[] = [$start, $cutStart->modify('-1 day')];
        }
        if ($end > $cutEnd) {
            $remaining[] = [$cutEnd->modify('+1 day'), $end];
        }

        return $remaining;
    }

    /**
     * The description of a new row: the given one, or the source row's with the period label.
     */
    public function rowDescription(SpecialPricePlan $plan): string
    {
        $description = $plan->request->rowDescription ?? $plan->source->getDescription().' – '.$plan->request->periodDescription;

        return mb_substr(trim($description), 0, self::MAX_DESCRIPTION_LENGTH);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function dates(PricePeriod $period): array
    {
        return [
            \DateTimeImmutable::createFromInterface($period->getStart() ?? new \DateTimeImmutable())->setTime(0, 0),
            \DateTimeImmutable::createFromInterface($period->getEnd() ?? new \DateTimeImmutable())->setTime(0, 0),
        ];
    }

    private function validatePeriod(SpecialPriceRequest $request, \DateTimeImmutable $today): void
    {
        if ($request->lastNight < $request->firstNight) {
            throw new SpecialPriceException("'lastNight' must not be before 'firstNight'.");
        }
        if ($request->firstNight < $today) {
            throw new SpecialPriceException('Special prices can only be added for future nights.');
        }
        if ((int) $request->firstNight->diff($request->lastNight)->days >= self::MAX_NIGHTS) {
            throw new SpecialPriceException(\sprintf('A special period must not exceed %d nights.', self::MAX_NIGHTS));
        }
        if ($request->lastNight > $today->modify(\sprintf('+%d years', self::MAX_YEARS_AHEAD))) {
            throw new SpecialPriceException(\sprintf('Special prices can be added at most %d years ahead.', self::MAX_YEARS_AHEAD));
        }
    }

    private function validateText(string $text, string $parameter): void
    {
        $length = mb_strlen(trim($text));
        if ($length < self::MIN_DESCRIPTION_LENGTH || $length > self::MAX_DESCRIPTION_LENGTH) {
            throw new SpecialPriceException(\sprintf("'%s' needs %d to %d characters.", $parameter, self::MIN_DESCRIPTION_LENGTH, self::MAX_DESCRIPTION_LENGTH));
        }
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $remaining
     */
    private function cutOut(Price $price, PricePeriod $period, array $remaining): void
    {
        if ([] === $remaining) {
            $price->removePricePeriod($period);
            $this->em->remove($period);

            return;
        }

        $period->setStart(\DateTime::createFromImmutable($remaining[0][0]));
        $period->setEnd(\DateTime::createFromImmutable($remaining[0][1]));
        if (isset($remaining[1])) {
            $price->addPricePeriod((new PricePeriod())
                ->setStart(\DateTime::createFromImmutable($remaining[1][0]))
                ->setEnd(\DateTime::createFromImmutable($remaining[1][1]))
                ->setDescription($period->getDescription()));
        }
    }

    /**
     * A new special row with the same rules as $source (occupancy, minimum stay, weekdays, room
     * categories, origins, accounting) and the given amount; its only period is added by apply().
     */
    private function copyRow(Price $source, float $amount, string $description): Price
    {
        $copy = new Price();
        $copy->setType(self::APARTMENT_PRICE);
        $copy->setDescription($description);
        $copy->setPrice(round($amount, 2));
        $copy->setVat((float) $source->getVat());
        $copy->setIncludesVat((bool) $source->getIncludesVat());
        $copy->setIsFlatPrice((bool) $source->getIsFlatPrice());
        $copy->setIsPerRoom($source->getIsPerRoom());
        $copy->setNumberOfPersons($source->getNumberOfPersons());
        $copy->setMinStay($source->getMinStay());
        $copy->setActive(true);
        $copy->setAllDays($source->getAllDays());
        $copy->setMonday($source->getMonday());
        $copy->setTuesday($source->getTuesday());
        $copy->setWednesday($source->getWednesday());
        $copy->setThursday($source->getThursday());
        $copy->setFriday($source->getFriday());
        $copy->setSaturday($source->getSaturday());
        $copy->setSunday($source->getSunday());
        $copy->setAllPeriods(false);
        $copy->setBrokered($source->isBrokered());
        $copy->setIsBookableOnline($source->getIsBookableOnline());
        $copy->setIsMandatoryOnline($source->getIsMandatoryOnline());
        $copy->setIsDefaultActiveInReservationCreation($source->getIsDefaultActiveInReservationCreation());
        $copy->setRevenueAccount($source->getRevenueAccount());
        foreach ($source->getReservationOrigins() as $origin) {
            $copy->addReservationOrigin($origin);
        }
        foreach ($source->getRoomCategories() as $category) {
            $copy->addRoomCategory($category);
        }
        foreach ($source->getComponents() as $component) {
            $copy->addComponent((new PriceComponent())
                ->setDescription($component->getDescription())
                ->setVat($component->getVat())
                ->setAllocationType($component->getAllocationType())
                ->setAllocationValue($component->getAllocationValue())
                ->setIsRemainder($component->isRemainder())
                ->setSortOrder($component->getSortOrder())
                ->setRevenueAccount($component->getRevenueAccount()));
        }

        if ([] !== $this->priceService->validateComponents($copy)) {
            throw new SpecialPriceException('The price row is split into package components that do not fit the new amount. Create this special price in FewohBee instead.');
        }

        return $copy;
    }
}
