<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Repository\ReservationRepository;
use App\Service\HousekeepingViewService;
use App\Service\OperationsFilterService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reservation search shared by the REST API and the MCP tools: reservations touching a date
 * range, filtered by property, room, status and type (arrival/departure/inhouse).
 */
class ReservationQueryService
{
    public const MAX_RANGE_DAYS = 180;

    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly OperationsFilterService $operationsFilterService,
        private readonly HousekeepingViewService $housekeepingViewService,
        private readonly ReservationTypeClassifier $typeClassifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param \DateTimeImmutable $start       first day, inclusive
     * @param \DateTimeImmutable $end         last day, inclusive
     * @param string|null        $objectId    subsidiary id; null, '' or 'all' for every property
     * @param mixed              $statusParam status ids as list or comma separated string; null keeps
     *                                        the default of blocking statuses
     *
     * @return list<array{0: Reservation, 1: list<string>}> reservations with their types in the range
     *
     * @throws \InvalidArgumentException with a message that is safe to show to API clients
     */
    public function find(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?string $objectId = null,
        ?int $apartmentId = null,
        mixed $statusParam = null,
        ?string $type = null,
    ): array {
        if ($end < $start) {
            throw new \InvalidArgumentException("Parameter 'end' must not be before 'start'.");
        }
        if ((int) $start->diff($end)->format('%a') > self::MAX_RANGE_DAYS) {
            throw new \InvalidArgumentException(sprintf('Date range must not exceed %d days.', self::MAX_RANGE_DAYS));
        }

        $subsidiary = null;
        if (null !== $objectId && '' !== $objectId && 'all' !== $objectId) {
            $subsidiary = $this->operationsFilterService->resolveSubsidiary($this->em, $objectId);
            if (null === $subsidiary) {
                throw new \InvalidArgumentException("Unknown 'objectId'.");
            }
        }

        $statusIds = $this->resolveStatusIds($statusParam);

        $apartment = null;
        if (null !== $apartmentId) {
            $apartment = $this->em->getRepository(Appartment::class)->find($apartmentId);
            if (!$apartment instanceof Appartment) {
                throw new \InvalidArgumentException("Unknown 'apartmentId'.");
            }
            if (null !== $subsidiary && $apartment->getObject()?->getId() !== $subsidiary->getId()) {
                throw new \InvalidArgumentException("Apartment does not belong to the given 'objectId'.");
            }
        }

        if (null !== $type && !\in_array($type, ReservationTypeClassifier::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf("Parameter 'type' must be one of: %s.", implode(', ', ReservationTypeClassifier::TYPES)));
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
            $matched[] = [$reservation, array_values($types)];
        }

        return $matched;
    }

    /**
     * @return int[]|null null = default blocking-status behaviour
     */
    private function resolveStatusIds(mixed $param): ?array
    {
        if (null === $param || '' === $param || [] === $param) {
            return null;
        }

        $allStatuses = $this->em->getRepository(ReservationStatus::class)->findAll();
        $ids = $this->housekeepingViewService->normalizeReservationStatusIds($param, $allStatuses);
        if ([] === $ids) {
            throw new \InvalidArgumentException("Unknown 'statusId'.");
        }

        return $ids;
    }
}
