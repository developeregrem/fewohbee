<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;

use App\Service\CSRFProtectionService;
use App\Service\StatisticsService;
use App\Entity\Reservation;
use App\Entity\Subsidiary;
use App\Entity\Appartment;
use App\Entity\ReservationOrigin;

class StatisticsController extends AbstractController
{
    private $perPage = 15;

    public function __construct()
    {
    }

    /**
     * Index Action start page
     *
     * @return mixed
     */
    public function utilizationAction(RequestStack $requestStack)
    {
        $em = $this->getDoctrine()->getManager();
        $objects = $em->getRepository(Subsidiary::class)->findAll();
        $objectId = $requestStack->getSession()->get("reservation-overview-objectid", "all");
        
        $minStr = $em->getRepository(Reservation::class)->getMinEndDate();
        $maxStr = $em->getRepository(Reservation::class)->getMaxStartDate();
        $minDate = new \DateTime($minStr);
        $maxDate = new \DateTime($maxStr);
        
        return $this->render('Statistics/utilization.html.twig', array(
            'objects' => $objects,
            'objectId' => $objectId,
            'minYear' => $minDate->format("Y"),
            'maxYear' => $maxDate->format("Y")
        ));
    }
    
    public function getUtilizationForMonthAction(Request $request) {        
        $em = $this->getDoctrine()->getManager();
        
        $objectId = $request->get('objectId');
        $monthStart = $request->get('monthStart');
        $monthEnd = $request->get('monthEnd');
        $yearStart = $request->get('yearStart');
        $yearEnd = $request->get('yearEnd');
        $beds = $em->getRepository(Appartment::class)->loadSumBedsMinForObject($objectId);
        $beds = ($beds == 0 ? 1 : $beds);

        $start = new \DateTime($yearStart."-".$monthStart."-1");
        $end = new \DateTime($yearEnd."-".$monthEnd."-2");
        $diff = date_diff($start, $end);
        
        $currentDate = $start;        
        $interval = new \DateInterval('P1M');
        $result = Array();
        for($j = 0; $j <= $diff->m; $j++) {
            $days = $currentDate->format("t");
                        
            $tmpResult = Array('label'=> $this->getLocalizedDate($currentDate->format("n"), 'MMM', $request->getLocale()));
            $timeStartStr = $currentDate->format("Y-m-");
            $data = Array();
            for($i = 1; $i <= $days; $i++) {
                //var_dump($timeStartStr.$i);
                $utilization = $em->getRepository(Reservation::class)->loadUtilizationForDay($timeStartStr.$i, $objectId);
                $date = new \DateTime($timeStartStr.$i);
                // date in milliseconds, utilization in %
                $data[] = Array($i, $utilization*100/$beds); // x,y values
                //echo $i.':'.$utilization.'<br>';
            }
            $tmpResult['data'] = $data;
            $result[] = $tmpResult;
            $currentDate->add($interval);
        }        

        return new Response(
            json_encode($result),
            200,
            ['Content-Type' => 'application/json']
        );
    }
    
    public function getUtilizationForYearAction(StatisticsService $ss, Request $request) {        
        $em = $this->getDoctrine()->getManager();
        $objectId = $request->get('objectId');
        $yearStart = $request->get('yearStart');
        $yearEnd = $request->get('yearEnd');
        
        $beds = $em->getRepository(Appartment::class)->loadSumBedsMinForObject($objectId);
        $beds = ($beds == 0 ? 1 : $beds);
        
        $result = Array();
        for($y = $yearStart; $y <= $yearEnd; $y++) {            
            $tmpResult = Array('label'=> $y);
            $tmpResult['data'] = $ss->loadUtilizationForYear($objectId, $y, $beds);
            $result[] = $tmpResult;
        }
        
        return new Response(
            json_encode($result),
            200,
            ['Content-Type' => 'application/json']
        );
    }
    
    /**
     * Index Action reservationorigin page
     *
     * @return mixed
     */
    public function originAction(RequestStack $requestStack)
    {
        $em = $this->getDoctrine()->getManager();
        $objects = $em->getRepository(Subsidiary::class)->findAll();
        $objectId = $requestStack->getSession()->get("reservation-overview-objectid", "all");
        
        $minStr = $em->getRepository(Reservation::class)->getMinEndDate();
        $maxStr = $em->getRepository(Reservation::class)->getMaxStartDate();
        $minDate = new \DateTime($minStr);
        $maxDate = new \DateTime($maxStr);
        
        return $this->render('Statistics/reservationorigin.html.twig', array(
            'objects' => $objects,
            'objectId' => $objectId,
            'minYear' => $minDate->format("Y"),
            'maxYear' => $maxDate->format("Y")
        ));
    }
    
    /**
     * Load Statistics for origins for a given period
     * @param Request $request
     * @return Response
     */
    public function getOriginForMonthAction(Request $request) {        
        $em = $this->getDoctrine()->getManager();
        
        $objectId = $request->get('objectId');
        $monthStart = $request->get('monthStart');
        $monthEnd = $request->get('monthEnd');
        $yearStart = $request->get('yearStart');
        $yearEnd = $request->get('yearEnd');

        $start = new \DateTime($yearStart."-".$monthStart."-1");
        $tmpEnd = new \DateTime($yearEnd."-".$monthEnd."-1"); // set to first day, we need to figure out the number of days in this month
        $days = $tmpEnd->format("t");
        $end = new \DateTime($yearEnd."-".$monthEnd."-".$days); // now the correct end date with last day in this month

        $resultArr = $em->getRepository(Reservation::class)
                ->loadOriginStatisticForPeriod($start->format("Y-m-d"), $end->format("Y-m-d"), $objectId);

        $result = Array();
        foreach($resultArr as $single) {
            $origin = $em->getRepository(ReservationOrigin::class)->find($single['id']);
            $result[] = Array("label"=> $origin->getName(), "data"=>$single['origins']);
        }

        return new Response(
            json_encode($result),
            200,
            ['Content-Type' => 'application/json']
        );
    }
    
    /**
     * Load Statistics for origins per year
     * @param Request $request
     * @return Response
     */
    public function getOriginForYearAction(Request $request) {        
        $em = $this->getDoctrine()->getManager();
        $objectId = $request->get('objectId');
        $yearStart = $request->get('yearStart');
        $yearEnd = $request->get('yearEnd');
        
        $start = new \DateTime($yearStart."-01-1");
        $end = new \DateTime($yearEnd."-12-31");
        
        $resultArr = $em->getRepository(Reservation::class)
                ->loadOriginStatisticForPeriod($start->format("Y-m-d"), $end->format("Y-m-d"), $objectId);

        $result = Array();
        foreach($resultArr as $single) {
            $origin = $em->getRepository(ReservationOrigin::class)->find($single['id']);
            $result[] = Array("label"=> $origin->getName(), "data"=>$single['origins']);
        }
        
        return new Response(
            json_encode($result),
            200,
            ['Content-Type' => 'application/json']
        );
    }
        
    private function getLocalizedDate($monthNumber, $pattern, $locale) {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::FULL);
        $formatter->setPattern($pattern);
        return $formatter->format(mktime(0, 0, 0, $monthNumber+1, 0, 0));
    }
}
