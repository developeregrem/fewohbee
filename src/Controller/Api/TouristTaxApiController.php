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

use App\Dto\Api\TouristTaxDto;
use App\Entity\ReservationStatus;
use App\Entity\Subsidiary;
use App\Repository\TouristTaxRepository;
use App\Security\Voter\ApiScopeVoter;
use App\Service\HousekeepingViewService;
use App\Service\TouristTaxReportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Token-authenticated tourist-tax endpoints (Kurtaxe / city tax).
 *
 * The report is the same monthly aggregation the operations report template renders,
 * built by the shared TouristTaxReportService — amounts are live-calculated per
 * reservation rather than read off invoices, so the figures cannot drift apart.
 */
#[Route('/api/v1/tourist-taxes')]
#[IsGranted(ApiScopeVoter::TOURIST_TAX_READ)]
class TouristTaxApiController extends AbstractController
{
    private const MAX_MONTHS = 36;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TouristTaxRepository $touristTaxRepository,
        private readonly TouristTaxReportService $touristTaxReportService,
        private readonly HousekeepingViewService $housekeepingViewService,
    ) {
    }

    /**
     * The configured tourist taxes with their tariffs per guest category.
     */
    #[Route('', name: 'api.tourist_taxes.list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $taxes = $this->touristTaxRepository->findAllOrdered();
        $data = array_map(static fn ($tax): TouristTaxDto => TouristTaxDto::fromEntity($tax), $taxes);

        return new JsonResponse([
            'data' => $data,
            'meta' => ['count' => \count($data)],
        ]);
    }

    /**
     * Monthly tourist-tax report — the figures a municipality filing is built from.
     *
     * Rows are grouped per tax and per report group (falling back to the guest category
     * when a rate defines no group), because that is how the tariffs are declared.
     */
    #[Route('/report', name: 'api.tourist_taxes.report', methods: ['GET'])]
    public function report(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseMonthRange($request);
        $subsidiary = $this->resolveSubsidiary($request);
        $statusIds = $this->resolveStatusIds($request);

        $payload = $this->touristTaxReportService->buildPayload(
            $start,
            $end->modify('last day of this month')->setTime(23, 59, 59),
            $subsidiary,
            $statusIds,
        );

        $months = [];
        foreach ($payload['months'] as $month) {
            $taxes = array_values($month['taxes']);
            $months[] = [
                'month' => sprintf('%d-%02d', $month['year'], $month['month']),
                'taxes' => $taxes,
                'total' => round(array_sum(array_map(
                    static fn (array $tax): float => (float) $tax['totalAmount'],
                    $taxes
                )), 2),
            ];
        }

        return new JsonResponse([
            'data' => $months,
            'meta' => [
                'start' => $start->format('Y-m'),
                'end' => $end->format('Y-m'),
                'objectId' => $subsidiary?->getId(),
                'guestCategories' => $payload['guestCategories'],
                'count' => \count($months),
            ],
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

        return [$start->setTime(0, 0), $end->setTime(0, 0)];
    }

    private function parseMonth(?string $value, string $paramName): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value.'-01');
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m') !== $value) {
            throw new BadRequestHttpException(sprintf("Invalid parameter '%s': expected format Y-m.", $paramName));
        }

        return $parsed->setTime(0, 0);
    }

    private function resolveSubsidiary(Request $request): ?Subsidiary
    {
        $objectId = $request->query->get('objectId');
        if (null === $objectId || '' === $objectId || 'all' === $objectId) {
            return null;
        }
        $subsidiary = $this->em->getRepository(Subsidiary::class)->find((int) $objectId);
        if (!$subsidiary instanceof Subsidiary) {
            throw new BadRequestHttpException("Unknown 'objectId'.");
        }

        return $subsidiary;
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
}
