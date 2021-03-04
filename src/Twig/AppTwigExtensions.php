<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

use App\Entity\Reservation;

class AppTwigExtensions extends AbstractExtension
{
    private $em;
	private $requestStack;
	
	public function __construct(EntityManagerInterface $em, RequestStack $requestStack)
    {
        $this->em = $em;
		$this->requestStack = $requestStack;
    }
	
	public function getFunctions()
    {
        return array(
            new TwigFunction('date_difference', array($this, 'dateDifferenceFilter')),
            new TwigFunction('reservation_date_compare', array($this, 'reservationDateCompareFilter')),
            new TwigFunction('get_reservations_for_period', array($this, 'getReservationsForPeriodFilter')),
            new TwigFunction('is_single_reservation_for_day', array($this, 'isSingleReservationForDayFilter')),
            new TwigFunction('get_letter_count_for_display', array($this, 'getLetterCountForDisplayFilter')),
            new TwigFunction('get_date_diff_amount', array($this, 'getDateDiffAmountFilter')),
            new TwigFunction('is_decimal_place_0', array($this, 'isDecimalPlace0')),
            new TwigFunction('getLocalizedMonth', array($this, 'getLocalizedMonthFilter')),
            new TwigFunction('getActiveRouteName', array($this, 'getActiveRouteNameFilter')),
            new TwigFunction('getLocalizedDate', array($this, 'getLocalizedDateFilter')),
            new TwigFunction('existsById', array($this, 'existsById')),
        );
    }

	public function dateDifferenceFilter($startDate, $endDate) {
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $interval = date_diff($start, $end);
		
        // return number of days
        return $interval->format('%a');
    }
	/**
     * compares the reservation start and end date with the displayed period. if start or end lies inside displayed period or not
     */
    public function reservationDateCompareFilter($date, $reservation, $type = 'start') {
        if ($type == 'start' && $reservation->getStartDate()->getTimestamp() < $date) {
            return true;
        } else if ($type == 'end' && $reservation->getEndDate()->getTimestamp() > $date) {

            return true;
        } else {
            return false;
        }
    }
	
	public function getReservationsForPeriodFilter($today, $intervall, $appartment) {
        $reservations = $this->em->getRepository(Reservation::class)->loadReservationsForPeriodForSingleAppartment($today, $intervall, $appartment);

        return $reservations;
    }
	
	public function isSingleReservationForDayFilter($today, $period, $reservationIdx, $reservations, $type = 'start') {
        $currentReservation = $reservations[$reservationIdx];
        if ($type == 'end') {
            $compareReservationIdx = $reservationIdx + 1;
            // wenn es eine nachfolgende reservierung gibt und diese nicht am gleichen tag startet wie die andere endet
            if (array_key_exists($compareReservationIdx, $reservations) &&
                $reservations[$compareReservationIdx]->getStartDate()->getTimestamp() == $currentReservation->getEndDate()->getTimestamp()
            ) {
                return false;
            } else { // entweder es gibt keine nachfolgende oder am gleichen tag beginnt keine neue reservierung
                // wenn das ende innerhalb des anzeigezeitraumes liegt
                if ($currentReservation->getEndDate()->getTimestamp() <= $today + ($period * 3600 * 24)) {
                    return true;
                } else {
                    return false;
                }
            }
        } else {
            $compareReservationIdx = $reservationIdx - 1;
            // wenn es eine vorherige reservierung gibt und diese nicht am gleichen tag endet wie die andere startet
            if (array_key_exists($compareReservationIdx, $reservations) &&
                $reservations[$compareReservationIdx]->getEndDate()->getTimestamp() == $currentReservation->getStartDate()->getTimestamp()
            ) {
                return false;
            } else { // entweder es gibt keine vorherige oder am gleichen tag endet keine neue reservierung
                // wenn der start innerhalb des anzeigezeitraumes liegt
                if ($currentReservation->getStartDate()->getTimestamp() >= $today) {
                    return true;
                } else {
                    return false;
                }
            }
        }

        return $reservations;
    }
	
	public function getLetterCountForDisplayFilter($period, $intervall) {
        if ($period > 4) {
            return pow($period, 2) - 2;
        } else {
            return ($period * 2) - 1;
        }
    }
	
	public function getDateDiffAmountFilter($start, $end) {
        $interval = $start->diff($end);
        return $interval->format('%a');
    }
	
	// prüft einen float-Wert, ob die Nachkommastellen 0 sind
	public function isDecimalPlace0($float) {
        // prüfe 1. Nachkommastelle, ob sie 0 ist
        if ((($float * 10) % 10) === 0) {
            // prüfe 2. Nachkommastelle, ob sie 0 ist
            if ((($float * 100) % 10) === 0) {
                return true;
            }
        }
        return false;
    }
	
	public function getLocalizedMonthFilter($monthNumber, $pattern, $locale) {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::FULL);
        $formatter->setPattern($pattern);
        return $formatter->format(mktime(0, 0, 0, $monthNumber+1, 0, 0));
    }
	
	public function getActiveRouteNameFilter() {
        $route = $this->requestStack->getCurrentRequest()->get('_route');

        return $route;
    }
	
	public function getLocalizedDateFilter($date, $pattern, $locale) {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::FULL);
        $formatter->setPattern($pattern);
        return $formatter->format($date);
    }
    
    public function existsById($array, $compare) {
        foreach($array as $single) {
            if($single->getId() === $compare->getId()) {
                return true;
            }
        }
        return false;
    }
}
