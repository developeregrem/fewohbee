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

namespace App\Twig;

use App\Entity\Reservation;
use App\Service\CalendarService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppTwigExtensions extends AbstractExtension
{
    private $em;
    private $requestStack;
    private $calendarService;

    public function __construct(EntityManagerInterface $em, RequestStack $requestStack, CalendarService $cs)
    {
        $this->em = $em;
        $this->requestStack = $requestStack;
        $this->calendarService = $cs;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('date_difference', [$this, 'dateDifferenceFilter']),
            new TwigFunction('reservation_date_compare', [$this, 'reservationDateCompareFilter']),
            new TwigFunction('get_reservations_for_period', [$this, 'getReservationsForPeriodFilter']),
            new TwigFunction('is_single_reservation_for_day', [$this, 'isSingleReservationForDayFilter']),
            new TwigFunction('get_letter_count_for_display', [$this, 'getLetterCountForDisplayFilter']),
            new TwigFunction('get_date_diff_amount', [$this, 'getDateDiffAmountFilter']),
            new TwigFunction('is_decimal_place_0', [$this, 'isDecimalPlace0']),
            new TwigFunction('getLocalizedMonth', [$this, 'getLocalizedMonthFilter']),
            new TwigFunction('getActiveRouteName', [$this, 'getActiveRouteNameFilter']),
            new TwigFunction('getLocalizedDate', [$this, 'getLocalizedDateFilter']),
            new TwigFunction('existsById', [$this, 'existsById']),
            new TwigFunction('getPublicdaysForDay', [$this, 'getPublicdaysForDay']),
            new TwigFunction('getReservationsForDay', [$this, 'getReservationsForDay']),
        ];
    }

    public function dateDifferenceFilter($startDate, $endDate)
    {
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $interval = date_diff($start, $end);

        // return number of days
        return $interval->format('%a');
    }

    /**
     * compares the reservation start and end date with the displayed period. if start or end lies inside displayed period or not.
     */
    public function reservationDateCompareFilter($date, $reservation, $type = 'start')
    {
        if ('start' == $type && $reservation->getStartDate()->getTimestamp() < $date) {
            return true;
        } elseif ('end' == $type && $reservation->getEndDate()->getTimestamp() > $date) {
            return true;
        } else {
            return false;
        }
    }

    public function getReservationsForPeriodFilter($today, $intervall, $appartment)
    {
        $reservations = $this->em->getRepository(Reservation::class)->loadReservationsForPeriodForSingleAppartment($today, $intervall, $appartment);

        return $reservations;
    }

    public function isSingleReservationForDayFilter($today, $period, $reservationIdx, $reservations, $type = 'start')
    {
        $currentReservation = $reservations[$reservationIdx];
        if ('end' == $type) {
            $compareReservationIdx = $reservationIdx + 1;
            // wenn es eine nachfolgende reservierung gibt und diese nicht am gleichen tag startet wie die andere endet
            if (array_key_exists($compareReservationIdx, $reservations)
                    && $reservations[$compareReservationIdx]->getStartDate()->getTimestamp() == $currentReservation->getEndDate()->getTimestamp()
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
            if (array_key_exists($compareReservationIdx, $reservations)
                    && $reservations[$compareReservationIdx]->getEndDate()->getTimestamp() == $currentReservation->getStartDate()->getTimestamp()
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

    public function getLetterCountForDisplayFilter($period, $intervall)
    {
        if ($period > 4) {
            return $period ** 2 - 2;
        } else {
            return ($period * 2) - 1;
        }
    }

    public function getDateDiffAmountFilter($start, $end)
    {
        $interval = $start->diff($end);

        return $interval->format('%a');
    }

    // prüft einen float-Wert, ob die Nachkommastellen 0 sind
    public function isDecimalPlace0($float)
    {
        // prüfe 1. Nachkommastelle, ob sie 0 ist
        if ((($float * 10) % 10) === 0) {
            // prüfe 2. Nachkommastelle, ob sie 0 ist
            if ((($float * 100) % 10) === 0) {
                return true;
            }
        }

        return false;
    }

    public function getLocalizedMonthFilter($monthNumber, $pattern, $locale)
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::FULL);
        $formatter->setPattern($pattern);

        return $formatter->format(mktime(0, 0, 0, $monthNumber + 1, 0, 0));
    }

    public function getActiveRouteNameFilter()
    {
        $route = $this->requestStack->getCurrentRequest()->get('_route');

        return $route;
    }

    public function getLocalizedDateFilter($date, $pattern, $locale)
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::FULL);
        $formatter->setPattern($pattern);

        return $formatter->format($date);
    }

    public function existsById($array, $compare)
    {
        foreach ($array as $single) {
            if ($single->getId() === $compare->getId()) {
                return true;
            }
        }

        return false;
    }

    public function getPublicdaysForDay($date, $code, $locale)
    {
        return $this->calendarService->getPublicdaysForDay($date, $code, $locale);
    }

    public function getReservationsForDay(\DateTimeInterface $day, array $reservations): array
    {
        $result = [];

        /* @var $reservation Reservation */
        foreach ($reservations as $reservation) {
            $start = new \DateTimeImmutable($reservation->getStartDate()->format('Y-m-d').' UTC');
            $end = new \DateTimeImmutable($reservation->getEndDate()->format('Y-m-d').' UTC');
            // todo store all reservation dates as UTC time
            if ($day >= $start && $day <= $end) {
                $result[] = $reservation;
            }
        }

        return $result;
    }
}
