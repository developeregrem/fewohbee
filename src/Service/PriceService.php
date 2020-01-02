<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <alex.pensionsverwaltung@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\Common\Collections\ArrayCollection;

use App\Entity\Price;
use App\Entity\ReservationOrigin;

class PriceService
{

    private $em = null;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function getPriceFromForm(Request $request, $id = 'new')
    {

        $price = new Price();

        if ($id !== 'new') {
            $price = $this->em->getRepository(Price::class)->find($id);
        }

        $price->setDescription($request->get("description-" . $id));
        $price->setPrice(str_replace(",", ".", $request->get("price-" . $id)));
        $price->setVat(str_replace(",", ".", $request->get("vat-" . $id)));
        $price->setType($request->get("type-" . $id));

        $origins = $request->get("origin-" . $id);
        if(is_array($origins)) {
            $originsDb = $this->em->getRepository(ReservationOrigin::class)->findById($origins);
            $originsPrice = $price->getReservationOrigins();
            // first remove all origins
            foreach($originsPrice as $originPrice) {
                $price->removeReservationOrigin($originPrice);
            }
            // now add all origins
            foreach($originsDb as $originDb) {
                $price->addReservationOrigin($originDb);
            }
        }

        if (strlen($request->get("seasonstart-" . $id)) != 0) {
            $price->setSeasonStart(new \DateTime($request->get("seasonstart-" . $id)));
            $price->setSeasonEnd(new \DateTime($request->get("seasonend-" . $id)));
        } else {
            $price->setSeasonStart(null);
            $price->setSeasonEnd(null);
        }

        if ($request->get("active-" . $id) != null) {
            $price->setActive(true);
        } else {
            $price->setActive(false);
        }

        if ($request->get("alldays-" . $id) != null) {
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

            if ($request->get("monday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
                $price->setMonday(true);
            } else {
                $price->setMonday(false);
            }

            if ($request->get("tuesday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
                $price->setTuesday(true);
            } else {
                $price->setTuesday(false);
            }

            if ($request->get("wednesday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
                $price->setWednesday(true);
            } else {
                $price->setWednesday(false);
            }

            if ($request->get("thursday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
                $price->setThursday(true);
            } else {
                $price->setThursday(false);
            }

            if ($request->get("friday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
                $price->setFriday(true);
            } else {
                $price->setFriday(false);
            }

            if ($request->get("saturday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
                $price->setSaturday(true);
            } else {
                $price->setSaturday(false);
            }

            if ($request->get("sunday-" . $id) != null) {
                if ($noDaySelected) $noDaySelected = false;
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

        if ($price->getType() == 2) {
            $price->setNumberOfBeds($request->get("number-of-beds-" . $id));
            $price->setNumberOfPersons($request->get("number-of-persons-" . $id));
            $price->setMinStay($request->get("min-stay-" . $id));
        } else {
            $price->setNumberOfBeds(null);
            $price->setNumberOfPersons(null);
            $price->setMinStay(null);
        }

        return $price;
    }
    
    /**
     * Returns a list of conflicting prices
     * @param Price $price
     * @return Doctrine\Common\Collections\ArrayCollection
     */
    public function findConflictingPrices(Price $price) {
        $prices = [];
        // find conflicts when no season is given
        if($price->getSeasonStart() === null or $price->getSeasonEnd() === null) {
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
}
