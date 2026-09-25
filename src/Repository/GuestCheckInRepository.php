<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestCheckIn>
 */
class GuestCheckInRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestCheckIn::class);
    }

    public function findOneBySelector(string $selector): ?GuestCheckIn
    {
        return $this->findOneBy(['selector' => $selector]);
    }

    public function findOneByReservation(Reservation $reservation): ?GuestCheckIn
    {
        return $this->findOneBy(['reservation' => $reservation]);
    }

    /**
     * Returns the selector of the reservation's check-in and creates the row on first use.
     *
     * Links are built while templates render — inside a workflow run, or for a preview whose
     * caller never flushes — so this works on the connection and never flushes the caller's
     * pending changes. It looks before it inserts: an insert that finds the row already there
     * would still use up an auto-increment value, and links are requested on every mail and
     * every click. $candidate is only used when no row exists yet.
     */
    public function ensureSelector(int $reservationId, string $candidate, \DateTimeImmutable $now): string
    {
        $existing = $this->selectorOf($reservationId);
        if (null !== $existing) {
            return $existing;
        }

        try {
            $this->getEntityManager()->getConnection()->insert('guest_check_in', [
                'reservation_id' => $reservationId,
                'selector' => $candidate,
                'status' => GuestCheckInStatus::OPEN->value,
                'created_at' => $now,
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            // A parallel request created the row a moment earlier (the unique reservation
            // index keeps it single) — use that one.
        }

        // Null only if the random candidate collided with another reservation's selector.
        return $this->selectorOf($reservationId) ?? throw new \RuntimeException('Could not create the online check-in link.');
    }

    private function selectorOf(int $reservationId): ?string
    {
        $selector = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT selector FROM guest_check_in WHERE reservation_id = :reservation',
            ['reservation' => $reservationId],
        );

        return \is_string($selector) ? $selector : null;
    }

    /**
     * Drops the guest data of check-ins whose stay ended before $departedBefore; state and
     * timestamps stay. A bulk update on purpose: the audit log must not copy the data it removes.
     *
     * @return int number of check-ins purged
     */
    public function purgePayloadsDepartedBefore(\DateTimeInterface $departedBefore): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'UPDATE '.GuestCheckIn::class.' g SET g.payload = NULL
             WHERE g.payload IS NOT NULL
               AND g.reservation IN (SELECT r.id FROM '.Reservation::class.' r WHERE r.endDate < :date)'
        )->setParameter('date', \DateTimeImmutable::createFromInterface($departedBefore), Types::DATE_IMMUTABLE)->execute();
    }

    /**
     * Drops the guest data of the given reservations' check-ins, e.g. when their guest is deleted.
     *
     * @param list<int> $reservationIds
     */
    public function purgePayloadsForReservations(array $reservationIds): int
    {
        if ([] === $reservationIds) {
            return 0;
        }

        return (int) $this->getEntityManager()->createQuery(
            'UPDATE '.GuestCheckIn::class.' g SET g.payload = NULL WHERE g.payload IS NOT NULL AND g.reservation IN (:ids)'
        )->setParameter('ids', $reservationIds)->execute();
    }

    /**
     * Check-in state per reservation for list views, in one query.
     *
     * @param list<int> $reservationIds
     *
     * @return array<int, GuestCheckInStatus> keyed by reservation id; reservations without a
     *                                         check-in row are missing
     */
    public function findStatusesForReservations(array $reservationIds): array
    {
        if ([] === $reservationIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('g')
            ->select('IDENTITY(g.reservation) AS reservationId', 'g.status AS status')
            ->where('g.reservation IN (:ids)')
            ->setParameter('ids', $reservationIds)
            ->getQuery()
            ->getArrayResult();

        $statuses = [];
        foreach ($rows as $row) {
            $status = $row['status'];
            $statuses[(int) $row['reservationId']] = $status instanceof GuestCheckInStatus
                ? $status
                : GuestCheckInStatus::from((string) $status);
        }

        return $statuses;
    }
}
