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

namespace App\Controller;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\Subsidiary;
use App\Service\InvoiceService;
use App\Service\StatisticsService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_STATISTICS')]
#[Route('/statistics')]
class StatisticsController extends AbstractController
{
    private $perPage = 15;

    /**
     * Index Action start page.
     *
     * @return mixed
     */
    #[Route('/utilization', name: 'statistics.utilization', methods: ['GET'])]
    public function utilizationAction(ManagerRegistry $doctrine, RequestStack $requestStack): Response
    {
        return $this->loadIndex('Statistics/utilization.html.twig', $doctrine, $requestStack);
    }

    #[Route('/utilization/monthtly', name: 'statistics.utilization.monthtly', methods: ['GET'])]
    public function getUtilizationForMonthAction(ManagerRegistry $doctrine, Request $request): JsonResponse
    {
        $em = $doctrine->getManager();

        $objectId = $request->query->get('objectId');
        $monthStart = $request->query->get('monthStart');
        $monthEnd = $request->query->get('monthEnd');
        $yearStart = $request->query->get('yearStart');
        $yearEnd = $request->query->get('yearEnd');
        $beds = $em->getRepository(Appartment::class)->loadSumBedsMinForObject($objectId);
        $beds = (0 == $beds ? 1 : $beds);

        $start = new \DateTime($yearStart.'-'.$monthStart.'-1');
        $end = new \DateTime($yearEnd.'-'.$monthEnd.'-2');
        $diff = date_diff($start, $end);

        $currentDate = $start;
        $interval = new \DateInterval('P1M');
        $result = [];
        for ($j = 0; $j <= $diff->m; ++$j) {
            $days = $currentDate->format('t');

            $tmpResult = ['label' => $this->getLocalizedDate($currentDate->format('n'), 'MMM', $request->getLocale())];
            $timeStartStr = $currentDate->format('Y-m-');
            $data = [];
            for ($i = 1; $i <= $days; ++$i) {
                // var_dump($timeStartStr.$i);
                $utilization = $em->getRepository(Reservation::class)->loadUtilizationForDay($timeStartStr.$i, $objectId);
                $date = new \DateTime($timeStartStr.$i);
                // date in milliseconds, utilization in %
                $data[] = [$i, $utilization * 100 / $beds]; // x,y values
                // echo $i.':'.$utilization.'<br>';
            }
            $tmpResult['data'] = $data;
            $result[] = $tmpResult;
            $currentDate->add($interval);
        }

        return new JsonResponse(
            $result
        );
    }

    #[Route('/utilization/yearly', name: 'statistics.utilization.yearly', methods: ['GET'])]
    public function getUtilizationForYearAction(ManagerRegistry $doctrine, StatisticsService $ss, Request $request): JsonResponse
    {
        $em = $doctrine->getManager();
        $objectId = $request->query->get('objectId', 'all');
        $yearStart = $request->query->get('yearStart');
        $yearEnd = $request->query->get('yearEnd');

        $beds = $em->getRepository(Appartment::class)->loadSumBedsMinForObject($objectId);
        $beds = (0 == $beds ? 1 : $beds);

        $result = [];
        for ($y = $yearStart; $y <= $yearEnd; ++$y) {
            $tmpResult = ['label' => $y];
            $tmpResult['data'] = $ss->loadUtilizationForYear($objectId, $y, $beds);
            $result[] = $tmpResult;
        }

        return new JsonResponse(
            $result
        );
    }

    /**
     * Index Action reservationorigin page.
     */
    #[Route('/origin', name: 'statistics.origin', methods: ['GET'])]
    public function originAction(ManagerRegistry $doctrine, RequestStack $requestStack): Response
    {
        return $this->loadIndex('Statistics/reservationorigin.html.twig', $doctrine, $requestStack);
    }

    /**
     * Load Statistics for origins for a given period.
     */
    #[Route('/origin/monthtly', name: 'statistics.origin.monthtly', methods: ['GET'])]
    public function getOriginForMonthAction(ManagerRegistry $doctrine, Request $request): JsonResponse
    {
        $em = $doctrine->getManager();

        $objectId = $request->query->get('objectId');
        $monthStart = $request->query->get('monthStart');
        $monthEnd = $request->query->get('monthEnd');
        $yearStart = $request->query->get('yearStart');
        $yearEnd = $request->query->get('yearEnd');

        $start = new \DateTime($yearStart.'-'.$monthStart.'-1');
        $tmpEnd = new \DateTime($yearEnd.'-'.$monthEnd.'-1'); // set to first day, we need to figure out the number of days in this month
        $days = $tmpEnd->format('t');
        $end = new \DateTime($yearEnd.'-'.$monthEnd.'-'.$days); // now the correct end date with last day in this month

        $resultArr = $em->getRepository(Reservation::class)
                ->loadOriginStatisticForPeriod($start->format('Y-m-d'), $end->format('Y-m-d'), $objectId);

        $result = [];
        foreach ($resultArr as $single) {
            $origin = $em->getRepository(ReservationOrigin::class)->find($single['id']);
            $result[] = ['label' => $origin->getName(), 'data' => $single['origins']];
        }

        return new JsonResponse(
            $result
        );
    }

    /**
     * Load Statistics for origins per year.
     */
    #[Route('/origin/yearly', name: 'statistics.origin.yearly', methods: ['GET'])]
    public function getOriginForYearAction(ManagerRegistry $doctrine, Request $request): JsonResponse
    {
        $em = $doctrine->getManager();
        $objectId = $request->query->get('objectId');
        $yearStart = $request->query->get('yearStart');
        $yearEnd = $request->query->get('yearEnd');

        $start = new \DateTime($yearStart.'-01-1');
        $end = new \DateTime($yearEnd.'-12-31');

        $resultArr = $em->getRepository(Reservation::class)
                ->loadOriginStatisticForPeriod($start->format('Y-m-d'), $end->format('Y-m-d'), $objectId);

        $result = [];
        foreach ($resultArr as $single) {
            $origin = $em->getRepository(ReservationOrigin::class)->find($single['id']);
            $result[] = ['label' => $origin->getName(), 'data' => $single['origins']];
        }

        return new JsonResponse(
            $result
        );
    }

    #[Route('/turnover', name: 'statistics.turnover', methods: ['GET'])]
    public function turnoverAction(ManagerRegistry $doctrine, RequestStack $requestStack): Response
    {
        return $this->loadIndex('Statistics/turnover.html.twig', $doctrine, $requestStack);
    }

    /**
     * @return Response
     */
    #[Route('/turnover/yearly', name: 'statistics.turnover.yearly', methods: ['GET'])]
    public function getTurnoverForYearAction(ManagerRegistry $doctrine, InvoiceService $is, StatisticsService $ss, Request $request): JsonResponse
    {
        $yearStart = (int) $request->query->get('yearStart');
        $yearEnd = (int) $request->query->get('yearEnd');
        $invoiceStatus = $request->query->all('invoice-status');

        $result = [
            'labels' => [],
            'datasets' => [],
        ];
        for ($y = $yearStart; $y <= $yearEnd; ++$y) {
            $result['labels'][] = $y;
            $result['datasets'][0]['data'][] = $ss->loadTurnoverForYear($is, $y, $invoiceStatus);
        }

        return new JsonResponse(
            $result,
        );
    }

    #[Route('/turnover/monthly', name: 'statistics.turnover.monthly', methods: ['GET'])]
    public function getTurnoverForMonthAction(ManagerRegistry $doctrine, InvoiceService $is, StatisticsService $ss, Request $request): JsonResponse
    {
        $yearStart = (int) $request->query->get('yearStart');
        $yearEnd = (int) $request->query->get('yearEnd');
        $invoiceStatus = $request->query->all('invoice-status');

        $result = [
            'labels' => [],
            'datasets' => [],
        ];

        for ($i = 1; $i <= 12; ++$i) {
            $result['labels'][] = $this->getLocalizedDate($i, 'MMM', $request->getLocale());
        }

        for ($y = $yearStart; $y <= $yearEnd; ++$y) {
            $tmpResult = [
                'label' => $y,
                'data' => $ss->loadTurnoverForMonth($is, $y, $invoiceStatus),
            ];
            $result['datasets'][] = $tmpResult;
        }

        return new JsonResponse(
            $result,
        );
    }

    /**
     * General index page wich is the same for all statistics sites.
     *
     * @throws \Exception
     */
    private function loadIndex(string $template, ManagerRegistry $doctrine, RequestStack $requestStack): Response
    {
        $em = $doctrine->getManager();
        $objects = $em->getRepository(Subsidiary::class)->findAll();
        $objectId = $requestStack->getSession()->get('reservation-overview-objectid', 'all');

        $minStr = $em->getRepository(Reservation::class)->getMinEndDate();
        $maxStr = $em->getRepository(Reservation::class)->getMaxStartDate();
        $minDate = new \DateTime($minStr);
        $maxDate = new \DateTime($maxStr);

        return $this->render($template, [
            'objects' => $objects,
            'objectId' => $objectId,
            'minYear' => $minDate->format('Y'),
            'maxYear' => $maxDate->format('Y'),
        ]);
    }

    private function getLocalizedDate($monthNumber, $pattern, $locale)
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::FULL);
        $formatter->setPattern($pattern);

        return $formatter->format(mktime(0, 0, 0, $monthNumber + 1, 0, 0));
    }
}
