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

use App\Entity\Price;
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
        $price->setVat((float)str_replace(',', '.', $request->request->get('vat-'.$id)));
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

        return $price;
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

    public function deletePrice($id)
    {
        $price = $this->em->getRepository(Price::class)->find($id);

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

        // return number of days
        return (int) $interval->format('%a');
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
