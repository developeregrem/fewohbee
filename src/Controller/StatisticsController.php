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

use App\Entity\Reservation;
use App\Entity\Subsidiary;
use App\Service\MonthlyStatsService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Routing\Attribute\Route;
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
    public function getUtilizationForMonthAction(ManagerRegistry $doctrine, MonthlyStatsService $monthlyStatsService, Request $request): JsonResponse
    {
        $em = $doctrine->getManager();

        $objectId = $request->query->get('objectId', 'all');
        $monthStart = (int) $request->query->get('monthStart');
        $monthEnd = (int) $request->query->get('monthEnd');
        $yearStart = (int) $request->query->get('yearStart');
        $yearEnd = (int) $request->query->get('yearEnd');

        $subsidiary = null;
        if ('all' !== $objectId) {
            $subsidiary = $em->getRepository(Subsidiary::class)->find($objectId);
        }

        $start = new \DateTimeImmutable($yearStart.'-'.$monthStart.'-01');
        // DatePeriod excludes the end date, so we move it to the first day of the month after the end
        $periodEnd = new \DateTimeImmutable($yearEnd.'-'.$monthEnd.'-01')->modify('first day of next month');
        $period = new \DatePeriod($start, new \DateInterval('P1M'), $periodEnd);

        $result = [
            'labels' => [],
            'datasets' => [],
        ];
        $maxDays = 0;

        foreach ($period as $currentDate) {
            $daysInMonth = (int) $currentDate->format('t');
            $maxDays = max($maxDays, $daysInMonth);
            $snapshot = $monthlyStatsService->getOrCreateSnapshot(
                (int) $currentDate->format('n'),
                (int) $currentDate->format('Y'),
                $subsidiary
            );
            $metrics = $snapshot->getMetrics();
            $data = $metrics['utilization']['daily_percent'] ?? null;
            if (!is_array($data)) {
                $data = $monthlyStatsService->getDailyUtilizationForMonth(
                    (int) $currentDate->format('n'),
                    (int) $currentDate->format('Y'),
                    $subsidiary
                );
            }
            $result['datasets'][] = [
                'label' => sprintf(
                    '%s %s',
                    $this->getLocalizedDate($currentDate->format('n'), 'MMM', $request->getLocale()),
                    $currentDate->format('Y')
                ),
                'data' => $data,
            ];
        }

        $result['labels'] = range(1, $maxDays ?: 1);

        return new JsonResponse($result);
    }

    #[Route('/utilization/yearly', name: 'statistics.utilization.yearly', methods: ['GET'])]
    public function getUtilizationForYearAction(ManagerRegistry $doctrine, MonthlyStatsService $monthlyStatsService, Request $request): JsonResponse
    {
        $em = $doctrine->getManager();
        $objectId = $request->query->get('objectId', 'all');
        $yearStart = (int) $request->query->get('yearStart');
        $yearEnd = (int) $request->query->get('yearEnd');

        $subsidiary = null;
        if ('all' !== $objectId) {
            $subsidiary = $em->getRepository(Subsidiary::class)->find($objectId);
        }

        $result = [
            'labels' => [],
            'datasets' => [],
        ];

        for ($i = 1; $i <= 12; ++$i) {
            $result['labels'][] = $this->getLocalizedDate($i, 'MMM', $request->getLocale());
        }

        for ($y = $yearStart; $y <= $yearEnd; ++$y) {
            $data = [];
            for ($m = 1; $m <= 12; ++$m) {
                $snapshot = $monthlyStatsService->getOrCreateSnapshot($m, $y, $subsidiary);
                $metrics = $snapshot->getMetrics();
                $data[] = $metrics['utilization']['month_percent'] ?? 0;
            }
            $result['datasets'][] = [
                'label' => (string) $y,
                'data' => $data,
            ];
        }

        return new JsonResponse($result);
    }

    #[Route('/snapshot/monthly', name: 'statistics.snapshot.monthly', methods: ['GET'])]
    /**
     * Return or create a monthly snapshot for the requested period.
     */
    public function getMonthlySnapshotAction(ManagerRegistry $doctrine, MonthlyStatsService $monthlyStatsService, Request $request): JsonResponse
    {
        $em = $doctrine->getManager();
        $month = (int) $request->query->get('month');
        $year = (int) $request->query->get('year');
        $objectId = $request->query->get('objectId', 'all');
        $force = (bool) $request->query->get('force', false);

        if ($month < 1 || $month > 12 || $year < 1) {
            return new JsonResponse(['error' => 'month/year required'], 400);
        }

        $subsidiary = null;
        if ('all' !== $objectId) {
            $subsidiary = $em->getRepository(Subsidiary::class)->find($objectId);
            if (null === $subsidiary) {
                return new JsonResponse(['error' => 'subsidiary not found'], 404);
            }
        }

        $payload = $monthlyStatsService->getOrCreateSnapshotWithWarnings($month, $year, $subsidiary, $force);
        $snapshot = $payload['snapshot'];
        $warnings = $payload['warnings'];

        return new JsonResponse([
            'id' => $snapshot->getId(),
            'month' => $snapshot->getMonth(),
            'year' => $snapshot->getYear(),
            'subsidiary' => $subsidiary?->getId(),
            'metrics' => $snapshot->getMetrics(),
            'warnings' => $warnings,
            'countryNames' => Countries::getNames($request->getLocale()),
        ]);
    }

    /**
     * Index Action reservationorigin page.
     */
    #[Route('/origin', name: 'statistics.origin', methods: ['GET'])]
    public function originAction(ManagerRegistry $doctrine, RequestStack $requestStack): Response
    {
        return $this->loadIndex('Statistics/reservationorigin.html.twig', $doctrine, $requestStack);
    }

    #[Route('/tourism', name: 'statistics.tourism', methods: ['GET'])]
    public function tourismAction(ManagerRegistry $doctrine, RequestStack $requestStack): Response
    {
        return $this->loadIndex('Statistics/tourism.html.twig', $doctrine, $requestStack);
    }

    /**
     * Load Statistics for origins for a given period.
     */
    #[Route('/origin/monthtly', name: 'statistics.origin.monthtly', methods: ['GET'])]
    public function getOriginForMonthAction(ManagerRegistry $doctrine, MonthlyStatsService $monthlyStatsService, Request $request): JsonResponse
    {
        $em = $doctrine->getManager();

        $objectId = $request->query->get('objectId', 'all');
        $monthStart = (int) $request->query->get('monthStart');
        $monthEnd = (int) $request->query->get('monthEnd');
        $yearStart = (int) $request->query->get('yearStart');
        $yearEnd = (int) $request->query->get('yearEnd');

        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $yearStart, $monthStart));
        $end = new \DateTimeImmutable(sprintf('%04d-%02d-01', $yearEnd, $monthEnd))->modify('first day of next month');
        $period = new \DatePeriod($start, new \DateInterval('P1M'), $end);

        $originStats = $this->loadOriginSnapshotStats($monthlyStatsService, $em, $period, $objectId);

        return $this->formatOriginResponse($originStats);
    }

    /**
     * Load Statistics for origins per year.
     */
    #[Route('/origin/yearly', name: 'statistics.origin.yearly', methods: ['GET'])]
    public function getOriginForYearAction(ManagerRegistry $doctrine, MonthlyStatsService $monthlyStatsService, Request $request): JsonResponse
    {
        $em = $doctrine->getManager();
        $objectId = $request->query->get('objectId', 'all');
        $yearStart = (int) $request->query->get('yearStart');
        $yearEnd = (int) $request->query->get('yearEnd');

        $start = new \DateTimeImmutable(sprintf('%04d-01-01', $yearStart));
        $end = new \DateTimeImmutable(sprintf('%04d-01-01', $yearEnd))->modify('+1 year');
        $period = new \DatePeriod($start, new \DateInterval('P1M'), $end);

        $originStats = $this->loadOriginSnapshotStats($monthlyStatsService, $em, $period, $objectId);

        return $this->formatOriginResponse($originStats);
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
    public function getTurnoverForYearAction(ManagerRegistry $doctrine, MonthlyStatsService $monthlyStatsService, Request $request): JsonResponse
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
            $result['datasets'][0]['data'][] = $this->loadTurnoverSnapshotForYear(
                $monthlyStatsService,
                $y,
                $invoiceStatus
            );
        }

        return new JsonResponse(
            $result,
        );
    }

    #[Route('/turnover/monthly', name: 'statistics.turnover.monthly', methods: ['GET'])]
    public function getTurnoverForMonthAction(ManagerRegistry $doctrine, MonthlyStatsService $monthlyStatsService, Request $request): JsonResponse
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
                'data' => $this->loadTurnoverSnapshotForMonths(
                    $monthlyStatsService,
                    $y,
                    $invoiceStatus
                ),
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

    /**
     * Aggregate reservation origin stats from monthly snapshots for a given period.
     */
    private function loadOriginSnapshotStats(
        MonthlyStatsService $monthlyStatsService,
        $em,
        \DatePeriod $period,
        $objectId
    ): array {
        $subsidiary = null;
        if ('all' !== $objectId) {
            $subsidiary = $em->getRepository(Subsidiary::class)->find($objectId);
        }

        $originStats = [];
        foreach ($period as $currentDate) {
            $snapshot = $monthlyStatsService->getOrCreateSnapshot(
                (int) $currentDate->format('n'),
                (int) $currentDate->format('Y'),
                $subsidiary
            );
            $metrics = $snapshot->getMetrics();
            $originData = $metrics['reservation_origin'] ?? [];
            foreach ($originData as $origin => $count) {
                $originStats[$origin] = ($originStats[$origin] ?? 0) + (int) $count;
            }
        }

        ksort($originStats);

        return $originStats;
    }

    /**
     * Format origin stats into a standard chart response structure.
     */
    private function formatOriginResponse(array $originStats): JsonResponse
    {
        $labels = array_keys($originStats);
        $data = array_values($originStats);

        return new JsonResponse([
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Origin',
                    'data' => $data,
                ],
            ],
        ]);
    }

    /**
     * Aggregate yearly turnover from monthly snapshots (all subsidiaries only).
     */
    private function loadTurnoverSnapshotForYear(MonthlyStatsService $monthlyStatsService, int $year, array $statusFilter): float
    {
        $total = 0.0;
        for ($m = 1; $m <= 12; ++$m) {
            $snapshot = $monthlyStatsService->getOrCreateSnapshot($m, $year, null);
            $metrics = $snapshot->getMetrics();
            $total += $this->getTurnoverFromSnapshot($metrics, $statusFilter);
        }

        return $total;
    }

    /**
     * Build monthly turnover values from snapshots (all subsidiaries only).
     */
    private function loadTurnoverSnapshotForMonths(MonthlyStatsService $monthlyStatsService, int $year, array $statusFilter): array
    {
        $data = [];
        for ($m = 1; $m <= 12; ++$m) {
            $snapshot = $monthlyStatsService->getOrCreateSnapshot($m, $year, null);
            $metrics = $snapshot->getMetrics();
            $data[] = $this->getTurnoverFromSnapshot($metrics, $statusFilter);
        }

        return $data;
    }

    /**
     * Resolve turnover totals from snapshot metrics with an optional status filter.
     */
    private function getTurnoverFromSnapshot(array $metrics, array $statusFilter): float
    {
        if (empty($statusFilter)) {
            return 0.0;
        }

        $turnover = $metrics['turnover'] ?? [];
        $byStatus = $turnover['by_status'] ?? [];
        if (empty($byStatus)) {
            return (float) ($turnover['total'] ?? 0);
        }

        $total = 0.0;
        foreach ($statusFilter as $status) {
            if (isset($byStatus[$status])) {
                $total += (float) $byStatus[$status];
            }
        }

        return $total;
    }
}
