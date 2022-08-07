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

use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;

class StatisticsService
{
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function loadUtilizationForYear($objectId, $year, $beds)
    {
        $data = [];
        // each month of the year
        for ($i = 1; $i <= 12; ++$i) {
            $reservations = $this->em->getRepository(Reservation::class)->loadReservationsForMonth($i, $year, $objectId);

            $startDate = new \DateTime($year.'-'.$i.'-01');
            $endDate = new \DateTime($year.'-'.$i.'-'.$startDate->format('t'));
            $interval = new \DateInterval('P1D');
            $stays = 0;
            $maxStays = $beds * $startDate->format('t');

            foreach ($reservations as $reservation) {
                // check if startdate of reservation is not in the month, we want to utilize
                if ($reservation->getStartDate() < $startDate) {
                    $resStart = $startDate;
                } else {
                    $resStart = $reservation->getStartDate();
                }
                // same with end date of reservation
                if ($reservation->getEndDate() > $endDate) {
                    $resEnd = $endDate;
                    $resEnd->add($interval); // we have to add one day otherwise the end of month will not be counted
                } else {
                    $resEnd = $reservation->getEndDate();
                }

                $diffInterval = date_diff($resStart, $resEnd);
                $stays += $diffInterval->days * $reservation->getPersons();

                // var_dump($reservation->getStartDate());
            }
            $utilization = $stays * 100.0 / $maxStays;
            $data[] = [$i, $utilization]; // x,y values
        }

        return $data;
    }
}
