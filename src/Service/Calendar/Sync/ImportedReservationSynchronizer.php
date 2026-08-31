<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync;

use App\Dto\Ics\IcsOccurrence;
use App\Entity\CalendarSyncImport;
use App\Entity\Reservation;
use App\Event\CalendarImportBookingCreatedEvent;
use App\Repository\GuestCategoryRepository;
use App\Repository\ReservationRepository;
use App\Service\AvailabilityService;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Applies one portal calendar event to reservations and enforces conflict rules.
 */
final class ImportedReservationSynchronizer
{
    private const MAX_UID_LENGTH = 255;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ReservationRepository $reservationRepository,
        private readonly AvailabilityService $availabilityService,
        private readonly GuestCategoryRepository $guestCategoryRepository,
        private readonly ReservationService $reservationService,
    ) {
    }

    /**
     * Create or update the reservation represented by one non-recurring portal event.
     */
    public function synchronize(CalendarSyncImport $import, IcsOccurrence $event): ReservationImportOutcome
    {
        $uid = $this->normalizeUid($event->uid);
        if ('' === $uid) {
            return ReservationImportOutcome::MissingRequiredData;
        }

        $start = $event->start;
        $end = $event->end ?? $start;
        if ($end < new \DateTimeImmutable('today')) {
            return ReservationImportOutcome::Past;
        }

        $existing = $this->reservationRepository->findOneByRefUidAndImport($uid, $import);
        $reservationConflicts = $this->availabilityService->getConflictingReservations(
            $import->getApartment(),
            $start,
            $end,
            $existing,
        );
        $blockConflicts = $this->availabilityService->getConflictingBlocks(
            $import->getApartment(),
            $start,
            $end,
        );

        if ([] === $reservationConflicts && [] === $blockConflicts) {
            return $this->saveReservation($import, $event, $uid, $start, $end, $existing, false);
        }

        return match ($import->getConflictStrategy()) {
            CalendarSyncImport::CONFLICT_OVERWRITE => $this->overwriteConflicts(
                $import,
                $event,
                $uid,
                $start,
                $end,
                $existing,
                $reservationConflicts,
                [] !== $blockConflicts,
            ),
            CalendarSyncImport::CONFLICT_MARK => $this->markConflict(
                $import,
                $event,
                $uid,
                $start,
                $end,
                $existing,
            ),
            default => ReservationImportOutcome::ConflictSkipped,
        };
    }

    /**
     * Clear a conflict only when neither another reservation nor a room block still overlaps it.
     */
    public function resolveConflictReservation(Reservation $reservation): bool
    {
        if (!$reservation->isConflict()) {
            return false;
        }

        if ([] !== $this->availabilityService->getConflictingReservations(
            $reservation->getAppartment(),
            $reservation->getStartDate(),
            $reservation->getEndDate(),
            $reservation,
        )) {
            return false;
        }

        if ([] !== $this->availabilityService->getConflictingBlocks(
            $reservation->getAppartment(),
            $reservation->getStartDate(),
            $reservation->getEndDate(),
        )) {
            return false;
        }

        $reservation->setIsConflict(false);
        $reservation->setIsConflictIgnored(false);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Mark overlapping reservations and retain a conflict only when a room block remains.
     *
     * @param list<Reservation> $conflicts
     */
    private function overwriteConflicts(
        CalendarSyncImport $import,
        IcsOccurrence $event,
        string $uid,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?Reservation $existing,
        array $conflicts,
        bool $hasBlockConflict,
    ): ReservationImportOutcome {
        foreach ($conflicts as $conflict) {
            $conflict->setIsConflict(true);
            $conflict->setIsConflictIgnored(false);
        }

        return $this->saveReservation(
            $import,
            $event,
            $uid,
            $start,
            $end,
            $existing,
            $hasBlockConflict,
        );
    }

    /** Store the imported reservation as a visible conflict unless it was intentionally ignored. */
    private function markConflict(
        CalendarSyncImport $import,
        IcsOccurrence $event,
        string $uid,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?Reservation $existing,
    ): ReservationImportOutcome {
        if ($existing instanceof Reservation && $existing->isConflict() && $existing->isConflictIgnored()) {
            return ReservationImportOutcome::ConflictSkipped;
        }

        return $this->saveReservation($import, $event, $uid, $start, $end, $existing, true);
    }

    /**
     * Persist feed-owned data while preserving manually maintained fields on updates.
     */
    private function saveReservation(
        CalendarSyncImport $import,
        IcsOccurrence $event,
        string $uid,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?Reservation $existing,
        bool $isConflict,
    ): ReservationImportOutcome {
        $isNew = !$existing instanceof Reservation;
        $reservation = $existing ?? $this->buildReservation($import);

        $reservation->setStartDate($this->toDate($start));
        $reservation->setEndDate($this->toDate($end));
        $reservation->setIsConflict($isConflict);
        $reservation->setIsConflictIgnored(false);

        if ($isNew) {
            $reservation->setReservationOrigin($import->getReservationOrigin());
            $reservation->setReservationStatus($import->getReservationStatus());
            $reservation->setRemark('' !== trim($event->description) ? $event->description : null);
            $reservation->setRefUid($uid);
            $reservation->setCalendarSyncImport($import);
            $this->entityManager->persist($reservation);
        }

        $this->entityManager->flush();

        if ($isNew) {
            $this->eventDispatcher->dispatch(new CalendarImportBookingCreatedEvent($reservation));
        }

        return ReservationImportOutcome::Synchronized;
    }

    /**
     * Create an imported reservation with the apartment's bed count as adult occupancy.
     */
    private function buildReservation(CalendarSyncImport $import): Reservation
    {
        $reservation = new Reservation();
        $reservation->setAppartment($import->getApartment());
        $reservation->setUuid(Uuid::v4());

        $beds = max(0, (int) $import->getApartment()->getBedsMax());
        $defaultAdult = $this->guestCategoryRepository->findDefaultAdult();
        if (null !== $defaultAdult?->getId()) {
            $this->reservationService->applyGuestCounts(
                $reservation,
                [(int) $defaultAdult->getId() => $beds],
            );
        } else {
            // Legacy installations without guest categories still need a usable occupancy default.
            $reservation->setPersons($beds);
        }

        return $reservation;
    }

    /** Hash overlong feed UIDs so their identity remains stable without overflowing the column. */
    private function normalizeUid(string $uid): string
    {
        $uid = trim($uid);
        if (mb_strlen($uid) <= self::MAX_UID_LENGTH) {
            return $uid;
        }

        return 'sha256:'.hash('sha256', $uid);
    }

    /** Convert source timestamps to the date-only persistence type used by reservations. */
    private function toDate(\DateTimeImmutable $date): \DateTime
    {
        return new \DateTime($date->format('Y-m-d'));
    }
}
