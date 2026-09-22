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

namespace App\Controller\Api;

use App\Entity\Enum\InvoiceStatus;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\Subsidiary;
use App\Repository\ReservationRepository;
use App\Service\Api\StatisticsQueryService;
use App\Service\HousekeepingViewService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Token-authenticated statistics endpoints. Unlike the session-based /statistics/* routes
 * (which feed the UI charts with locale-dependent labels), these return locale-independent
 * data keyed by ISO months/years and validate their input.
 */
#[Route('/api/v1/statistics')]
#[IsGranted('API_SCOPE_STATISTICS_READ')]
class StatisticsApiController extends AbstractController
{
    private const MAX_MONTHS = StatisticsQueryService::MAX_MONTHS;
    private const MAX_YEARS = StatisticsQueryService::MAX_YEARS;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StatisticsQueryService $statisticsQueryService,
        private readonly HousekeepingViewService $housekeepingViewService,
        private readonly ReservationRepository $reservationRepository,
    ) {
    }

    /**
     * Bed utilization per month in percent.
     */
    #[Route('/utilization', name: 'api.statistics.utilization', methods: ['GET'])]
    public function utilization(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseMonthRange($request);
        $objectId = $this->resolveObjectId($request);
        $statusIds = $this->resolveStatusIds($request);

        $utilization = $this->statisticsQueryService->utilizationByMonth($start, $end, $objectId, $statusIds);
        $data = $utilization['data'];
        $beds = $utilization['beds'];

        return $this->envelope($data, [
            'start' => $start->format('Y-m'),
            'end' => $end->format('Y-m'),
            'objectId' => 'all' === $objectId ? null : (int) $objectId,
            'beds' => $beds,
        ]);
    }

    /**
     * Reservation counts grouped by booking origin/channel.
     */
    #[Route('/origins', name: 'api.statistics.origins', methods: ['GET'])]
    public function origins(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseMonthRange($request);
        $objectId = $this->resolveObjectId($request);
        $statusIds = $this->resolveStatusIds($request);

        $rows = $this->reservationRepository->loadOriginStatisticForPeriod(
            $start->format('Y-m-d'),
            $end->modify('last day of this month')->format('Y-m-d'),
            $objectId,
            $statusIds
        );

        $data = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            $origin = $this->em->getRepository(ReservationOrigin::class)->find($row['id']);
            $data[] = [
                'id' => (int) $row['id'],
                'name' => $origin?->getName(),
                'count' => (int) $row['origins'],
            ];
        }

        return $this->envelope($data, [
            'start' => $start->format('Y-m'),
            'end' => $end->format('Y-m'),
            'objectId' => 'all' === $objectId ? null : (int) $objectId,
        ]);
    }

    /**
     * Turnover (gross, invoice-based) per year or per month.
     */
    #[Route('/turnover', name: 'api.statistics.turnover', methods: ['GET'])]
    public function turnover(Request $request): JsonResponse
    {
        $startYear = $this->parseYear($request->query->get('start'), 'start');
        $endYear = $this->parseYear($request->query->get('end'), 'end') ?? $startYear;
        $startYear ??= (int) date('Y');
        $endYear ??= $startYear;

        if ($endYear < $startYear) {
            throw new BadRequestHttpException("Parameter 'end' must not be before 'start'.");
        }
        if ($endYear - $startYear + 1 > self::MAX_YEARS) {
            throw new BadRequestHttpException(sprintf('Year range must not exceed %d years.', self::MAX_YEARS));
        }

        $granularity = $request->query->get('granularity', 'year');
        if (!\in_array($granularity, ['year', 'month'], true)) {
            throw new BadRequestHttpException("Parameter 'granularity' must be 'year' or 'month'.");
        }

        $invoiceStatus = $this->resolveInvoiceStatus($request);

        $data = $this->statisticsQueryService->turnover($startYear, $endYear, $granularity, $invoiceStatus);

        return $this->envelope($data, [
            'start' => $startYear,
            'end' => $endYear,
            'granularity' => $granularity,
            'invoiceStatus' => $invoiceStatus,
        ]);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} first day of start month / first day of end month
     */
    private function parseMonthRange(Request $request): array
    {
        $start = $this->parseMonth($request->query->get('start'), 'start') ?? new \DateTimeImmutable('first day of this month');
        $end = $this->parseMonth($request->query->get('end'), 'end') ?? $start;

        if ($end < $start) {
            throw new BadRequestHttpException("Parameter 'end' must not be before 'start'.");
        }
        $months = ((int) $end->format('Y') - (int) $start->format('Y')) * 12
            + ((int) $end->format('n') - (int) $start->format('n')) + 1;
        if ($months > self::MAX_MONTHS) {
            throw new BadRequestHttpException(sprintf('Month range must not exceed %d months.', self::MAX_MONTHS));
        }

        return [$start, $end];
    }

    private function parseMonth(?string $value, string $paramName): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value.'-01', new \DateTimeZone('UTC'));
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m') !== $value) {
            throw new BadRequestHttpException(sprintf("Invalid parameter '%s': expected format Y-m.", $paramName));
        }

        return $parsed->setTime(0, 0);
    }

    private function parseYear(?string $value, string $paramName): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!preg_match('/^\d{4}$/', $value)) {
            throw new BadRequestHttpException(sprintf("Invalid parameter '%s': expected a four-digit year.", $paramName));
        }

        return (int) $value;
    }

    /**
     * @return string 'all' or a subsidiary id as string (format expected by the statistics repositories)
     */
    private function resolveObjectId(Request $request): string
    {
        $objectId = $request->query->get('objectId');
        if (null === $objectId || '' === $objectId || 'all' === $objectId) {
            return 'all';
        }
        $subsidiary = $this->em->getRepository(Subsidiary::class)->find((int) $objectId);
        if (!$subsidiary instanceof Subsidiary) {
            throw new BadRequestHttpException("Unknown 'objectId'.");
        }

        return (string) $subsidiary->getId();
    }

    /**
     * @return int[] empty = default blocking-status behaviour of the repositories
     */
    private function resolveStatusIds(Request $request): array
    {
        $param = $request->query->all()['statusId'] ?? null;
        if (null === $param || '' === $param || [] === $param) {
            return [];
        }

        $allStatuses = $this->em->getRepository(ReservationStatus::class)->findAll();
        $ids = $this->housekeepingViewService->normalizeReservationStatusIds($param, $allStatuses);
        if ([] === $ids) {
            throw new BadRequestHttpException("Unknown 'statusId'.");
        }

        return $ids;
    }

    /**
     * @return int[]
     */
    private function resolveInvoiceStatus(Request $request): array
    {
        $param = $request->query->all()['invoiceStatus'] ?? null;
        if (null === $param || '' === $param || [] === $param) {
            return StatisticsQueryService::DEFAULT_INVOICE_STATUS;
        }

        $values = \is_array($param) ? $param : explode(',', (string) $param);
        $result = [];
        foreach ($values as $value) {
            $status = InvoiceStatus::fromStatus((int) $value);
            if (null === $status) {
                throw new BadRequestHttpException("Unknown 'invoiceStatus'.");
            }
            $result[] = $status->value;
        }

        return array_values(array_unique($result));
    }

    private function envelope(array $data, array $meta): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => $meta + ['count' => \count($data)],
        ]);
    }
}
