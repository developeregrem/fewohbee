<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarSyncImport;
use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Synchronize remote iCal feeds into reservations. */
class CalendarImportService
{
    public const SYNC_THROTTLE_SECONDS = 3600;

    private const SYNC_OK = 'ok';
    private const SYNC_SKIP_MISSING = 'skip_missing';
    private const SYNC_SKIP_PAST = 'skip_past';
    private const SYNC_SKIP_CONFLICT = 'skip_conflict';

    /** Initialize calendar import dependencies. */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly TranslatorInterface $translator
    ) {
    }

    /** Run synchronization for a single import configuration. */
    public function syncImport(CalendarSyncImport $import): void
    {
        if (!$import->isActive()) {
            return;
        }

        try {
            $response = $this->httpClient->request('GET', $import->getUrl(), [
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                $this->updateSyncError($import, 'calendar.sync.import.error.http_status');

                return;
            }
            $content = $response->getContent();
        } catch (ExceptionInterface $exception) {
            $this->updateSyncError($import, 'calendar.sync.import.error.unreachable');

            return;
        }

        if (!$this->isValidCalendar($content)) {
            $this->updateSyncError($import, 'calendar.sync.import.error.invalid_ical');

            return;
        }

        $events = $this->parseEvents($content);
        if (count($events) === 0) {
            $this->updateSyncError($import, 'calendar.sync.import.error.no_events');

            return;
        }

        $missingCount = 0;
        $conflictCount = 0;
        foreach ($events as $event) {
            $result = $this->syncEvent($import, $event);
            if (self::SYNC_SKIP_MISSING === $result) {
                $missingCount++;
            } elseif (self::SYNC_SKIP_CONFLICT === $result) {
                $conflictCount++;
            }
        }

        $import->setLastSyncAt(new \DateTime());
        $import->setLastSyncError(
            ($missingCount + $conflictCount) > 0
                ? $this->buildSkipSummaryMessage($missingCount, $conflictCount)
                : null
        );
        $this->em->flush();
    }

    /** Run synchronization for all active imports. */
    /** Run synchronization for all active imports with optional throttling. */
    public function syncActiveImports(bool $force = false): void
    {
        if (!$force && !$this->shouldRunSync()) {
            return;
        }
        $imports = $this->em->getRepository(CalendarSyncImport::class)->findBy(['isActive' => true]);
        foreach ($imports as $import) {
            $this->syncImport($import);
        }
    }

    /** Update sync error information on an import. */
    private function updateSyncError(CalendarSyncImport $import, string $errorKey): void
    {
        $import->setLastSyncAt(new \DateTime());
        $import->setLastSyncError($errorKey);
        $this->em->flush();
    }

    /** Build a summary message for skipped events. */
    private function buildSkipSummaryMessage(int $missingCount, int $conflictCount): string
    {
        return $this->translator->trans('calendar.sync.import.error.skipped.summary', [
            '%missing%' => $missingCount,
            '%conflict%' => $conflictCount,
        ]);
    }

    /** Ensure full import sync runs at most once per hour. */
    private function shouldRunSync(): bool
    {
        $key = self::buildThrottleCacheKey(time());
        $executed = false;
        $this->cache->get($key, function (ItemInterface $item) use (&$executed) {
            $item->expiresAfter(self::SYNC_THROTTLE_SECONDS);
            $executed = true;

            return true;
        });

        return $executed;
    }

    /** Build a cache key for throttled sync execution. */
    public static function buildThrottleCacheKey(int $timestamp): string
    {
        $bucket = (int) floor($timestamp / self::SYNC_THROTTLE_SECONDS);

        return sprintf('calendar_import_sync_all_%d', $bucket);
    }

    /** Validate that the content is a basic iCal container. */
    private function isValidCalendar(string $content): bool
    {
        return str_contains($content, 'BEGIN:VCALENDAR') && str_contains($content, 'END:VCALENDAR');
    }

    /** Parse VEVENT blocks into structured event data. */
    private function parseEvents(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $unfolded = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (!empty($unfolded) && (str_starts_with($line, ' ') || str_starts_with($line, "\t"))) {
                $unfolded[count($unfolded) - 1] .= ltrim($line);
            } else {
                $unfolded[] = $line;
            }
        }

        $events = [];
        $current = null;
        foreach ($unfolded as $line) {
            if ('BEGIN:VEVENT' === $line) {
                $current = [];
                continue;
            }
            if ('END:VEVENT' === $line) {
                if (is_array($current)) {
                    $events[] = $current;
                }
                $current = null;
                continue;
            }
            if (!is_array($current)) {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$name, $value] = $parts;
            $name = strtoupper(explode(';', $name, 2)[0]);
            $current[$name] = $value;
        }

        return $events;
    }

    /** Persist a single event, respecting conflict strategy and updates. */
    private function syncEvent(CalendarSyncImport $import, array $event): string
    {
        if (!$this->isEventValid($event)) {
            return self::SYNC_SKIP_MISSING;
        }

        $uid = $event['UID'];
        $start = $this->parseIcalDate($event['DTSTART']);
        if (null === $start) {
            return self::SYNC_SKIP_MISSING;
        }

        $end = isset($event['DTEND']) ? $this->parseIcalDate($event['DTEND']) : null;
        $end = $end ?? $start;

        if ($this->isEventInPast($end)) {
            return self::SYNC_SKIP_PAST;
        }

        $reservation = $this->getReservationRepository()->findOneByRefUidAndImport($uid, $import);
        if ($reservation instanceof Reservation) {
            return $this->updateExistingReservation($import, $reservation, $start, $end, $event);
        }

        return $this->createNewReservation($import, $start, $end, $event);
    }

    /** Validate required VEVENT fields for import. */
    private function isEventValid(array $event): bool
    {
        return isset($event['UID'], $event['DTSTAMP'], $event['DTSTART']);
    }

    /** Parse an iCal date or date-time string into a DateTimeImmutable. */
    private function parseIcalDate(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{8}$/', $value) === 1) {
            $date = \DateTimeImmutable::createFromFormat('Ymd', $value);

            return $date ?: null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            return null;
        }
    }

    /** Check whether an event ended before today. */
    private function isEventInPast(\DateTimeImmutable $end): bool
    {
        $today = new \DateTimeImmutable('today');

        return $end < $today;
    }

    /** Update an existing reservation for a matching UID. */
    private function updateExistingReservation(
        CalendarSyncImport $import,
        Reservation $reservation,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $event
    ): string {
        $conflicts = $this->findConflicts($import, $start, $end, $reservation);
        if (count($conflicts) > 0) {
            return $this->handleConflict($import, $reservation, $start, $end, $event, $conflicts, true);
        }

        $reservation->setStartDate($this->toDate($start));
        $reservation->setEndDate($this->toDate($end));
        $reservation->setReservationOrigin($import->getReservationOrigin());
        $reservation->setReservationStatus($import->getReservationStatus());
        $reservation->setRemark($event['DESCRIPTION'] ?? null);
        $reservation->setRefUid($event['UID']);
        $reservation->setIsConflict(false);
        $reservation->setIsConflictIgnored(false);
        $reservation->setCalendarSyncImport($import);
        $this->em->flush();

        return self::SYNC_OK;
    }

    /** Create a new reservation from a VEVENT. */
    private function createNewReservation(
        CalendarSyncImport $import,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $event
    ): string {
        $conflicts = $this->findConflicts($import, $start, $end, null);
        if (count($conflicts) > 0) {
            return $this->handleConflict($import, null, $start, $end, $event, $conflicts, false);
        }

        $reservation = $this->buildReservation($import, $start, $end, $event, false);
        $this->em->persist($reservation);
        $this->em->flush();

        return self::SYNC_OK;
    }

    /** Resolve conflicts based on the configured strategy. */
    private function handleConflict(
        CalendarSyncImport $import,
        ?Reservation $existing,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $event,
        array $conflicts,
        bool $isUpdate
    ): string {
        $strategy = $import->getConflictStrategy();
        // just ignore the event
        if (CalendarSyncImport::CONFLICT_SKIP === $strategy) {
            return self::SYNC_SKIP_CONFLICT;
        }

        // store the current event and mark conflicitng reservations as conflicted
        if (CalendarSyncImport::CONFLICT_OVERWRITE === $strategy) {
            foreach ($conflicts as $conflict) {
                $conflict->setIsConflict(true);
                $conflict->setIsConflictIgnored(false);
            }
            $reservation = $existing ?? $this->buildReservation($import, $start, $end, $event, false);
            $reservation->setStartDate($this->toDate($start));
            $reservation->setEndDate($this->toDate($end));
            $reservation->setReservationOrigin($import->getReservationOrigin());
            $reservation->setReservationStatus($import->getReservationStatus());
            $reservation->setRemark($event['DESCRIPTION'] ?? null);
            $reservation->setRefUid($event['UID']);
            $reservation->setIsConflict(false);
            $reservation->setIsConflictIgnored(false);
            $reservation->setCalendarSyncImport($import);
            if (!$isUpdate) {
                $this->em->persist($reservation);
            }
            $this->em->flush();

            return self::SYNC_OK;
        }

        // create the event as a reservation but mark it as conflicted
        if (CalendarSyncImport::CONFLICT_MARK === $strategy) {
            if ($this->hasIgnoredConflict($import, $event['UID'])) {
                return self::SYNC_SKIP_CONFLICT;
            }
            $conflictReservation = $existing ?? $this->buildReservation($import, $start, $end, $event, true);
            $conflictReservation->setStartDate($this->toDate($start));
            $conflictReservation->setEndDate($this->toDate($end));
            $conflictReservation->setReservationOrigin($import->getReservationOrigin());
            $conflictReservation->setReservationStatus($import->getReservationStatus());
            $conflictReservation->setRemark($event['DESCRIPTION'] ?? null);
            $conflictReservation->setRefUid($event['UID']);
            $conflictReservation->setIsConflict(true);
            $conflictReservation->setIsConflictIgnored(false);
            $conflictReservation->setCalendarSyncImport($import);
            if (!$isUpdate) {
                $this->em->persist($conflictReservation);
            }
            $this->em->flush();

            return self::SYNC_OK;
        }

        return self::SYNC_SKIP_CONFLICT;
    }

    /** Build a reservation entity from import data. */
    private function buildReservation(
        CalendarSyncImport $import,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $event,
        bool $isConflict
    ): Reservation {
        $reservation = new Reservation();
        $reservation->setAppartment($import->getApartment());
        $reservation->setStartDate($this->toDate($start));
        $reservation->setEndDate($this->toDate($end));
        $reservation->setPersons(1);
        $reservation->setReservationOrigin($import->getReservationOrigin());
        $reservation->setReservationStatus($import->getReservationStatus());
        $reservation->setRemark($event['DESCRIPTION'] ?? null);
        $reservation->setRefUid($event['UID']);
        $reservation->setIsConflict($isConflict);
        $reservation->setIsConflictIgnored(false);
        $reservation->setCalendarSyncImport($import);
        $reservation->setUuid(Uuid::v4());

        return $reservation;
    }

    /** Find conflicting reservations for a date range. */
    private function findConflicts(
        CalendarSyncImport $import,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?Reservation $current
    ): array {
        $reservations = $this->getReservationRepository()->loadReservationsForApartmentWithoutStartEnd(
            $this->toDate($start),
            $this->toDate($end),
            $import->getApartment()
        );

        return array_values(array_filter($reservations, function (Reservation $reservation) use ($current) {
            if ($reservation->isConflict()) {
                return false;
            }
            if (null !== $current && $reservation->getId() === $current->getId()) {
                return false;
            }

            return true;
        }));
    }

    /** Check whether a conflict for the given UID was intentionally ignored. */
    private function hasIgnoredConflict(CalendarSyncImport $import, string $refUid): bool
    {
        $reservation = $this->getReservationRepository()->findOneByRefUidAndImport($refUid, $import);
        if (!$reservation instanceof Reservation) {
            return false;
        }

        return $reservation->isConflict() && $reservation->isConflictIgnored();
    }

    /** Resolve a conflict reservation if no blocking reservation exists. */
    public function resolveConflictReservation(Reservation $reservation): bool
    {
        if (!$reservation->isConflict()) {
            return false;
        }

        $blocking = $this->getReservationRepository()->loadReservationsForApartmentWithoutStartEnd(
            $reservation->getStartDate(),
            $reservation->getEndDate(),
            $reservation->getAppartment()
        );

        if (count($blocking) > 0) {
            return false;
        }

        $reservation->setIsConflict(false);
        $reservation->setIsConflictIgnored(false);
        $this->em->flush();

        return true;
    }

    /** Convert a DateTimeImmutable to a date-only DateTime. */
    private function toDate(\DateTimeImmutable $date): \DateTime
    {
        return new \DateTime($date->format('Y-m-d'));
    }

    /** Resolve the reservation repository from the entity manager. */
    private function getReservationRepository(): ReservationRepository
    {
        /** @var ReservationRepository $repository */
        $repository = $this->em->getRepository(Reservation::class);

        return $repository;
    }
}
