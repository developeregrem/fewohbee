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

use App\Dto\Api\ReservationDto;
use App\Entity\Appartment;
use App\Entity\Enum\InvoiceStatus;
use App\Entity\ReservationStatus;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;
use App\Security\Voter\ApiScopeVoter;
use App\Service\Api\ReservationTypeClassifier;
use App\Service\HousekeepingViewService;
use App\Service\OperationsFilterService;
use App\Service\ReservationNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/v1')]
class ReservationApiController extends AbstractController
{
    private const MAX_RANGE_DAYS = 180;

    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly OperationsFilterService $operationsFilterService,
        private readonly HousekeepingViewService $housekeepingViewService,
        private readonly ReservationTypeClassifier $typeClassifier,
        private readonly ReservationNameResolver $nameResolver,
        private readonly SerializerInterface $serializer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/reservations', name: 'api.reservations.list', methods: ['GET'])]
    #[IsGranted('API_SCOPE_RESERVATIONS_READ')]
    public function list(Request $request): JsonResponse
    {
        $start = $this->parseDate($request->query->get('start'), 'start') ?? new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $end = $this->parseDate($request->query->get('end'), 'end') ?? $start;

        if ($end < $start) {
            throw new BadRequestHttpException("Parameter 'end' must not be before 'start'.");
        }
        if ((int) $start->diff($end)->format('%a') > self::MAX_RANGE_DAYS) {
            throw new BadRequestHttpException(sprintf('Date range must not exceed %d days.', self::MAX_RANGE_DAYS));
        }

        $subsidiary = null;
        $objectId = $request->query->get('objectId');
        if (null !== $objectId && '' !== $objectId && 'all' !== $objectId) {
            $subsidiary = $this->operationsFilterService->resolveSubsidiary($this->em, (string) $objectId);
            if (null === $subsidiary) {
                throw new BadRequestHttpException("Unknown 'objectId'.");
            }
        }

        $statusIds = $this->resolveStatusIds($request);

        $apartment = null;
        $apartmentId = $request->query->get('apartmentId');
        if (null !== $apartmentId && '' !== $apartmentId) {
            $apartment = $this->em->getRepository(Appartment::class)->find((int) $apartmentId);
            if (!$apartment instanceof Appartment) {
                throw new BadRequestHttpException("Unknown 'apartmentId'.");
            }
            if (null !== $subsidiary && $apartment->getObject()?->getId() !== $subsidiary->getId()) {
                throw new BadRequestHttpException("Apartment does not belong to the given 'objectId'.");
            }
        }

        $type = $request->query->get('type');
        if (null !== $type && !\in_array($type, ReservationTypeClassifier::TYPES, true)) {
            throw new BadRequestHttpException(sprintf("Parameter 'type' must be one of: %s.", implode(', ', ReservationTypeClassifier::TYPES)));
        }

        // Repository expects an exclusive end date (same convention as HousekeepingViewService::buildRangeView()).
        $reservations = $this->reservationRepository->findForHousekeepingRange(
            $start,
            $end->modify('+1 day'),
            $subsidiary,
            'blocking',
            $statusIds
        );

        $matched = [];
        foreach ($reservations as $reservation) {
            if (null !== $apartment && $reservation->getAppartment()?->getId() !== $apartment->getId()) {
                continue;
            }
            $types = $this->typeClassifier->classify($reservation, $start, $end);
            if (null !== $type && !\in_array($type, $types, true)) {
                continue;
            }
            $matched[] = [$reservation, $types];
        }

        // Linked invoices are only disclosed to tokens that may read invoices;
        // null (instead of an empty list) tells the caller the scope is missing.
        $invoicesByReservation = null;
        if ($this->isGranted(ApiScopeVoter::INVOICES_READ)) {
            $invoicesByReservation = $this->invoiceRepository->findSummariesByReservationIds(
                array_map(static fn (array $row): int => (int) $row[0]->getId(), $matched)
            );
        }

        $dtos = [];
        foreach ($matched as [$reservation, $types]) {
            $dtos[] = ReservationDto::fromEntity(
                $reservation,
                $types,
                $this->nameResolver->resolve($reservation),
                null === $invoicesByReservation
                    ? null
                    : $this->mapInvoiceSummaries($invoicesByReservation[(int) $reservation->getId()] ?? [])
            );
        }

        return JsonResponse::fromJsonString($this->serializer->serialize([
            'data' => $dtos,
            'meta' => [
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
                'count' => \count($dtos),
            ],
        ], 'json'));
    }

    /**
     * @param list<array{id: int, number: string, date: \DateTimeInterface, status: int}> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function mapInvoiceSummaries(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $statusId = (int) $row['status'];
            $result[] = [
                'id' => (int) $row['id'],
                'number' => $row['number'],
                'date' => $row['date']->format('Y-m-d'),
                'status' => ['id' => $statusId, 'code' => InvoiceStatus::fromStatus($statusId)?->name],
            ];
        }

        return $result;
    }

    private function parseDate(?string $value, string $paramName): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException(sprintf("Invalid parameter '%s': expected format Y-m-d.", $paramName));
        }

        return $parsed->setTime(0, 0);
    }

    /**
     * @return int[]|null null = default blocking-status behaviour
     */
    private function resolveStatusIds(Request $request): ?array
    {
        // Accept both statusId=1,2 and statusId[]=1&statusId[]=2.
        $param = $request->query->all()['statusId'] ?? null;
        if (null === $param || '' === $param || [] === $param) {
            return null;
        }

        $allStatuses = $this->em->getRepository(ReservationStatus::class)->findAll();
        $ids = $this->housekeepingViewService->normalizeReservationStatusIds($param, $allStatuses);
        if ([] === $ids) {
            throw new BadRequestHttpException("Unknown 'statusId'.");
        }

        return $ids;
    }
}
