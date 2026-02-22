<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\MonthlyStatsSnapshot;
use App\Entity\Reservation;
use App\Entity\Subsidiary;
use App\Entity\Enum\InvoiceStatus;
use App\Entity\ReservationStatus;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

class MonthlyStatsService
{
    public function __construct(
        private EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
        private readonly StatisticsService $statisticsService,
        private readonly InvoiceService $invoiceService,
        private readonly TranslatorInterface $translator
    ) {
    }

    /**
     * Load or compute and persist a snapshot for the given month/year and subsidiary.
     */
    public function getOrCreateSnapshot(int $month, int $year, ?Subsidiary $subsidiary, bool $force = false): MonthlyStatsSnapshot
    {
        $payload = $this->getOrCreateSnapshotWithWarnings($month, $year, $subsidiary, $force);

        return $payload['snapshot'];
    }

    /**
     * Build the metrics payload and warnings for a snapshot without persisting it.
     */
    public function buildMetrics(
        int $month,
        int $year,
        ?Subsidiary $subsidiary,
        array $ignoredWarnings = [],
        array $reservationStatus = []
    ): array
    {
        $this->ensureEntityManager();
        $defaultStatusIds = $reservationStatus ?: $this->getDefaultReservationStatusIds();
        $objectId = $subsidiary?->getId() ?? 'all';
        $appartmentRepo = $this->em->getRepository(Appartment::class);
        $reservationRepo = $this->em->getRepository(Reservation::class);

        /*
        * Calculate inventory totals (beds, rooms) for the given subsidiary (or all).
        */
        $bedsTotal = (int) $appartmentRepo->loadSumBedsMinForObject($objectId);
        $roomsTotal = (int) $appartmentRepo->loadRoomCountForObject($objectId);

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $monthEndExclusive = $monthStart->modify('first day of next month');

        $reservations = $reservationRepo->loadReservationsForMonth($month, $year, $objectId, null);

        /*
        * Calculate utilization and tourism stats (arrivals, overnights) for the month.
        */
        $defaultStatusLookup = array_flip(array_map('intval', $defaultStatusIds));
        $summary = [
            'reservations_total' => 0,
            'guests_total' => 0,
            'nights_total' => 0,
            'arrivals_total' => 0,
            'departures_total' => 0,
            'turnovers_count' => 0,
        ];
        $tourism = [
            'arrivals_total' => 0,
            'overnights_total' => 0,
            'arrivals_by_country' => [],
            'overnights_by_country' => [],
        ];
        $originStats = [];
        $stays = 0;
        $warningsByReservation = [];
        $arrivalDatesByApartment = [];
        $departureDatesByApartment = [];
        $byStatus = [];

        foreach ($reservations as $reservation) {
            $statusId = $reservation->getReservationStatus()?->getId();
            if (null === $statusId) {
                continue;
            }
            $statusKey = (string) $statusId;
            $isDefaultStatus = isset($defaultStatusLookup[$statusId]);
            if (!isset($byStatus[$statusKey])) {
                $byStatus[$statusKey] = [
                    'tourism' => [
                        'arrivals_total' => 0,
                        'overnights_total' => 0,
                        'arrivals_by_country' => [],
                        'overnights_by_country' => [],
                    ],
                    'utilization' => [
                        'stays' => 0,
                    ],
                ];
            }

            $customers = $reservation->getCustomers();
            $customerCount = $customers->count();
            $persons = $reservation->getPersons();
            $useBookerFallback = $persons !== $customerCount;
            if ($useBookerFallback) {
                $reservationId = $reservation->getId();
                if (!isset($warningsByReservation[$reservationId])) {
                    $appartment = $reservation->getAppartment();
                    $appartmentNumber = $appartment ? $appartment->getNumber() : null;
                    $warningsByReservation[$reservationId] = [
                        'reservation_id' => $reservationId,
                        'status_id' => $statusId,
                        'start_date' => $reservation->getStartDate()->format('Y-m-d'),
                        'end_date' => $reservation->getEndDate()->format('Y-m-d'),
                        'appartment_number' => $appartmentNumber,
                        'message' => $this->translator->trans('statistics.snapshot.warning.persons_mismatch'),
                    ];
                }
            }
            if (0 === $customerCount && (!$useBookerFallback || null === $reservation->getBooker())) {
                continue;
            }

            if ($isDefaultStatus) {
                $summary['reservations_total'] += 1;
            }

            $startDate = $this->toImmutable($reservation->getStartDate());
            $endDate = $this->toImmutable($reservation->getEndDate());

            $resStart = $startDate < $monthStart ? $monthStart : $startDate;
            $resEnd = $endDate > $monthEndExclusive ? $monthEndExclusive : $endDate;

            // Only count overnights that fall within the report month.
            if ($resStart < $resEnd) {
                $nights = $resStart->diff($resEnd)->days;
                $byStatus[$statusKey]['utilization']['stays'] += $nights * $persons;
                if ($isDefaultStatus) {
                    $stays += $nights * $persons;
                }
                if ($useBookerFallback) {
                    $country = $this->resolveCountryForCustomer($reservation->getBooker());
                    $byStatus[$statusKey]['tourism']['overnights_by_country'][$country] =
                        ($byStatus[$statusKey]['tourism']['overnights_by_country'][$country] ?? 0) + ($nights * $persons);
                    $byStatus[$statusKey]['tourism']['overnights_total'] += $nights * $persons;
                    if ($isDefaultStatus) {
                        $tourism['overnights_by_country'][$country] =
                            ($tourism['overnights_by_country'][$country] ?? 0) + ($nights * $persons);
                        $tourism['overnights_total'] += $nights * $persons;
                        $summary['nights_total'] += $nights * $persons;
                    }
                } else {
                    foreach ($customers as $customer) {
                        $country = $this->resolveCountryForCustomer($customer);
                        $byStatus[$statusKey]['tourism']['overnights_by_country'][$country] =
                            ($byStatus[$statusKey]['tourism']['overnights_by_country'][$country] ?? 0) + $nights;
                        $byStatus[$statusKey]['tourism']['overnights_total'] += $nights;
                        if ($isDefaultStatus) {
                            $tourism['overnights_by_country'][$country] =
                                ($tourism['overnights_by_country'][$country] ?? 0) + $nights;
                            $tourism['overnights_total'] += $nights;
                            $summary['nights_total'] += $nights;
                        }
                    }
                }
            }

            // Arrivals are counted only in the start month of the reservation.
            if ($startDate >= $monthStart && $startDate < $monthEndExclusive) {
                if ($useBookerFallback) {
                    $country = $this->resolveCountryForCustomer($reservation->getBooker());
                    $byStatus[$statusKey]['tourism']['arrivals_by_country'][$country] =
                        ($byStatus[$statusKey]['tourism']['arrivals_by_country'][$country] ?? 0) + $persons;
                    $byStatus[$statusKey]['tourism']['arrivals_total'] += $persons;
                    if ($isDefaultStatus) {
                        $tourism['arrivals_by_country'][$country] =
                            ($tourism['arrivals_by_country'][$country] ?? 0) + $persons;
                        $tourism['arrivals_total'] += $persons;
                        $summary['arrivals_total'] += $persons;
                        $summary['guests_total'] += $persons;
                    }
                } else {
                    foreach ($customers as $customer) {
                        $country = $this->resolveCountryForCustomer($customer);
                        $byStatus[$statusKey]['tourism']['arrivals_by_country'][$country] =
                            ($byStatus[$statusKey]['tourism']['arrivals_by_country'][$country] ?? 0) + 1;
                        $byStatus[$statusKey]['tourism']['arrivals_total'] += 1;
                        if ($isDefaultStatus) {
                            $tourism['arrivals_by_country'][$country] =
                                ($tourism['arrivals_by_country'][$country] ?? 0) + 1;
                            $tourism['arrivals_total'] += 1;
                            $summary['arrivals_total'] += 1;
                            $summary['guests_total'] += 1;
                        }
                    }
                }

                $appartment = $reservation->getAppartment();
                if ($isDefaultStatus && $appartment instanceof Appartment) {
                    $arrivalDatesByApartment[$appartment->getId()][$startDate->format('Y-m-d')] = true;
                }
            }

            // Departures are counted only in the end month of the reservation.
            if ($endDate > $monthStart && $endDate <= $monthEndExclusive) {
                if ($isDefaultStatus) {
                    if ($useBookerFallback) {
                        $summary['departures_total'] += $persons;
                    } else {
                        $summary['departures_total'] += max(1, $customerCount);
                    }
                }

                $appartment = $reservation->getAppartment();
                if ($isDefaultStatus && $appartment instanceof Appartment) {
                    $departureDatesByApartment[$appartment->getId()][$endDate->format('Y-m-d')] = true;
                }
            }

            $origin = $reservation->getReservationOrigin();
            if ($isDefaultStatus && null !== $origin) {
                $originName = $origin->getName();
                $originStats[$originName] = ($originStats[$originName] ?? 0) + 1;
            }
        }

        $daysInMonth = (int) $monthStart->format('t');
        foreach ($byStatus as $statusKey => &$bucket) {
            ksort($bucket['tourism']['arrivals_by_country']);
            ksort($bucket['tourism']['overnights_by_country']);
        }
        unset($bucket);

        $turnoversTotal = 0;
        foreach ($arrivalDatesByApartment as $apartmentId => $arrivalDatesForApartment) {
            $departureDatesForApartment = $departureDatesByApartment[$apartmentId] ?? [];
            foreach ($arrivalDatesForApartment as $dateKey => $present) {
                if (isset($departureDatesForApartment[$dateKey])) {
                    ++$turnoversTotal;
                }
            }
        }
        $summary['turnovers_count'] = $turnoversTotal;
        ksort($tourism['arrivals_by_country']);
        ksort($tourism['overnights_by_country']);
        ksort($originStats);

        $dailyUtilization = $this->buildDailyUtilization(
            $monthStart,
            $daysInMonth,
            $objectId,
            $bedsTotal,
            $reservationRepo,
            $defaultStatusIds
        );

        // Attach ignored flags to warnings.
        foreach ($warningsByReservation as $reservationId => &$warning) {
            $warning['ignored'] = $ignoredWarnings[$reservationId] ?? false;
        }
        unset($warning);
        $warnings = array_values($warningsByReservation);
        $metrics = [
            'period' => [
                'year' => $year,
                'month' => $month,
            ],
            'subsidiary' => $subsidiary?->getId(),
            'summary' => $summary,
            'inventory' => [
                'rooms_total' => $roomsTotal,
                'beds_total' => $bedsTotal,
            ],
            'utilization' => [
                'month_percent' => ($bedsTotal > 0 && $daysInMonth > 0)
                    ? ($stays * 100.0 / ($bedsTotal * $daysInMonth))
                    : 0.0,
                'daily_percent' => $dailyUtilization,
            ],
            'tourism' => $tourism,
            'reservation_origin' => $originStats,
            'warnings' => $warnings,
            'by_status' => $byStatus,
        ];
        if (null === $subsidiary) {
            /*
            * Calculate turnover only for all subsidiaries.
            */
            $turnoverByStatus = [];
            $turnoverTotal = 0.0;
            foreach (InvoiceStatus::cases() as $status) {
                $statusTotal = $this->statisticsService->loadTurnoverForSingleMonth(
                    $this->invoiceService,
                    $year,
                    $month,
                    [$status->value]
                );
                $turnoverByStatus[$status->value] = $statusTotal;
                $turnoverTotal += $statusTotal;
            }
            $metrics['turnover'] = [
                'total' => $turnoverTotal,
                'by_status' => $turnoverByStatus,
            ];
        }

        return [
            'metrics' => $metrics,
            'warnings' => $warnings,
        ];
    }

    /**
     * Resolve the preferred country for a customer using address priority rules.
     */
    private function resolveCountryForCustomer(?Customer $customer): string
    {
        if (null === $customer) {
            return 'unknown';
        }
        $country = '';
        $addresses = $customer->getCustomerAddresses();
        $preferred = null;
        foreach ($addresses as $address) {
            if ('CUSTOMER_ADDRESS_TYPE_PRIVATE' === $address->getType()) {
                $preferred = $address;
                break;
            }
        }
        if (null === $preferred) {
            $preferred = $addresses->first() ?: null;
        }
        if (null !== $preferred && $preferred->getCountry()) {
            $country = (string) $preferred->getCountry();
        }

        $country = trim($country);

        return '' === $country ? 'unknown' : $country;
    }

    /**
     * Normalize DateTime instances to DateTimeImmutable.
     */
    private function toImmutable(\DateTimeInterface $date): \DateTimeImmutable
    {
        if ($date instanceof \DateTimeImmutable) {
            return $date;
        }

        return \DateTimeImmutable::createFromMutable($date);
    }

    /**
     * Build daily utilization percentages for a month without persisting them.
     */
    public function getDailyUtilizationForMonth(
        int $month,
        int $year,
        ?Subsidiary $subsidiary,
        array $reservationStatus = []
    ): array
    {
        $objectId = $subsidiary?->getId() ?? 'all';
        $appartmentRepo = $this->em->getRepository(Appartment::class);
        $reservationRepo = $this->em->getRepository(Reservation::class);
        $bedsTotal = (int) $appartmentRepo->loadSumBedsMinForObject($objectId);
        if (!$reservationStatus) {
            $reservationStatus = $this->getDefaultReservationStatusIds();
        }

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $daysInMonth = (int) $monthStart->format('t');

        return $this->buildDailyUtilization(
            $monthStart,
            $daysInMonth,
            $objectId,
            $bedsTotal,
            $reservationRepo,
            $reservationStatus
        );
    }

    /**
     * Filter existing metrics to only include data for the given reservation status IDs.
     */
    public function filterMetricsByStatus(array $metrics, array $statusIds): array
    {
        $statusIds = array_values(array_filter(array_map('intval', $statusIds), static fn (int $id): bool => $id > 0));
        if (!$statusIds || empty($metrics['by_status']) || !is_array($metrics['by_status'])) {
            return $metrics;
        }

        $period = $metrics['period'] ?? [];
        $year = (int) ($period['year'] ?? 0);
        $month = (int) ($period['month'] ?? 0);
        $daysInMonth = 0;
        if ($year > 0 && $month > 0) {
            $daysInMonth = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        }

        $bedsTotal = (int) ($metrics['inventory']['beds_total'] ?? 0);
        $aggregate = $this->aggregateByStatus($metrics['by_status'], $statusIds, $bedsTotal, $daysInMonth);
        $metrics['tourism'] = $aggregate['tourism'];
        $metrics['utilization']['month_percent'] = $aggregate['utilization']['month_percent'];
        $metrics['utilization']['daily_percent'] = [];
        $metrics['warnings'] = array_values(array_filter(
            $metrics['warnings'] ?? [],
            static fn (array $warning): bool => in_array((int) ($warning['status_id'] ?? 0), $statusIds, true)
        ));

        return $metrics;
    }

    /**
     * Create or update a single snapshot for a specific subsidiary scope.
     */
    private function upsertSnapshot(int $month, int $year, ?Subsidiary $subsidiary, bool $force): array
    {
        $this->ensureEntityManager();
        $repo = $this->em->getRepository(MonthlyStatsSnapshot::class);
        $snapshot = $repo->findOneByMonthYearSubsidiary($month, $year, $subsidiary);

        if (null === $snapshot) {
            $snapshot = new MonthlyStatsSnapshot();
            $snapshot->setMonth($month);
            $snapshot->setYear($year);
            $snapshot->setIsAll(null === $subsidiary);
            $snapshot->setSubsidiary($subsidiary);
            $this->em->persist($snapshot);
        } elseif (!$force) {
            $metrics = $snapshot->getMetrics();

            return [
                'snapshot' => $snapshot,
                'warnings' => $metrics['warnings'] ?? [],
            ];
        }

        $snapshot->setIsAll(null === $subsidiary);
        $ignoredWarnings = [];
        if (null !== $snapshot) {
            $existingMetrics = $snapshot->getMetrics();
            foreach (($existingMetrics['warnings'] ?? []) as $warning) {
                if (!empty($warning['ignored']) && isset($warning['reservation_id'])) {
                    $ignoredWarnings[(int) $warning['reservation_id']] = true;
                }
            }
        }
        $payload = $this->buildMetrics($month, $year, $subsidiary, $ignoredWarnings);
        $snapshot->setMetrics($payload['metrics']);
        $snapshot->touchUpdatedAt();
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $exception) {
            $this->registry->resetManager();
            $this->em = $this->registry->getManager();
            $repo = $this->em->getRepository(MonthlyStatsSnapshot::class);
            $existing = $repo->findOneByMonthYearSubsidiary($month, $year, $subsidiary);
            if (null === $existing) {
                throw $exception;
            }
            if ($force) {
                $existing->setMetrics($payload['metrics']);
                $existing->touchUpdatedAt();
                $this->em->flush();
            }
            $metrics = $existing->getMetrics();

            return [
                'snapshot' => $existing,
                'warnings' => $metrics['warnings'] ?? $payload['warnings'],
            ];
        }

        return [
            'snapshot' => $snapshot,
            'warnings' => $payload['warnings'],
        ];
    }

    /**
     * Build daily utilization data using the reservation repository.
     */
    private function buildDailyUtilization(
        \DateTimeImmutable $monthStart,
        int $daysInMonth,
        $objectId,
        int $bedsTotal,
        $reservationRepo,
        array $reservationStatus = []
    ): array {
        $beds = 0 === $bedsTotal ? 1 : $bedsTotal;
        $data = [];
        $timeStartStr = $monthStart->format('Y-m-');
        for ($i = 1; $i <= $daysInMonth; ++$i) {
            $utilization = $reservationRepo->loadUtilizationForDay($timeStartStr.$i, $objectId, $reservationStatus);
            $data[] = $utilization * 100 / $beds;
        }

        return $data;
    }

    private function getDefaultReservationStatusIds(): array
    {
        return $this->em->getRepository(ReservationStatus::class)->findDefaultIds();
    }

     /**
      * Loop over reservation status IDs in the 'by_status' field and calculate the summed metrics.
      */
    private function aggregateByStatus(array $byStatus, array $statusIds, int $bedsTotal, int $daysInMonth): array
    {
        $tourism = [
            'arrivals_total' => 0,
            'overnights_total' => 0,
            'arrivals_by_country' => [],
            'overnights_by_country' => [],
        ];
        $stays = 0;

        foreach ($statusIds as $statusId) {
            $key = (string) $statusId;
            if (!isset($byStatus[$key]) || !is_array($byStatus[$key])) {
                continue;
            }
            $bucket = $byStatus[$key];
            $tourism['arrivals_total'] += (int) ($bucket['tourism']['arrivals_total'] ?? 0);
            $tourism['overnights_total'] += (int) ($bucket['tourism']['overnights_total'] ?? 0);
            foreach (($bucket['tourism']['arrivals_by_country'] ?? []) as $country => $count) {
                $tourism['arrivals_by_country'][$country] = ($tourism['arrivals_by_country'][$country] ?? 0) + (int) $count;
            }
            foreach (($bucket['tourism']['overnights_by_country'] ?? []) as $country => $count) {
                $tourism['overnights_by_country'][$country] = ($tourism['overnights_by_country'][$country] ?? 0) + (int) $count;
            }
            $stays += (int) ($bucket['utilization']['stays'] ?? 0);
        }

        ksort($tourism['arrivals_by_country']);
        ksort($tourism['overnights_by_country']);

        $utilization = [
            'stays' => $stays,
            'month_percent' => ($bedsTotal > 0 && $daysInMonth > 0)
                ? ($stays * 100.0 / ($bedsTotal * $daysInMonth))
                : 0.0,
        ];

        return [
            'tourism' => $tourism,
            'utilization' => $utilization,
        ];
    }

    /**
     * Build or update a snapshot and return it with runtime warnings.
     */
    public function getOrCreateSnapshotWithWarnings(int $month, int $year, ?Subsidiary $subsidiary, bool $force = false): array
    {
        $this->ensureEntityManager();
        if (null === $subsidiary) {
            $allPayload = $this->upsertSnapshot($month, $year, null, $force);
            $subsidiaries = $this->em->getRepository(Subsidiary::class)->findAll();
            foreach ($subsidiaries as $singleSubsidiary) {
                $this->upsertSnapshot($month, $year, $singleSubsidiary, $force);
            }

            return [
                'snapshot' => $allPayload['snapshot'],
                'warnings' => $allPayload['warnings'],
            ];
        }

        return $this->upsertSnapshot($month, $year, $subsidiary, $force);
    }

    /**
     * Ensure the previous month snapshot is current and prefill the current one.
     */
    public function runSnapshotMaintenance(): void
    {
        $this->ensureEntityManager();
        $now = new \DateTimeImmutable('now');
        $prevMonthDate = $now->modify('first day of last month');
        $prevMonth = (int) $prevMonthDate->format('n');
        $prevYear = (int) $prevMonthDate->format('Y');

        $repo = $this->em->getRepository(MonthlyStatsSnapshot::class);
        $existing = $repo->findOneByMonthYearSubsidiary($prevMonth, $prevYear, null);
        $force = false;
        if (null !== $existing) {
            $force = $existing->getUpdatedAt()->format('Y-m') !== $now->format('Y-m');
        }

        $this->getOrCreateSnapshotWithWarnings($prevMonth, $prevYear, null, $force);
        $this->getOrCreateSnapshotWithWarnings((int) $now->format('n'), (int) $now->format('Y'), null, false);
    }

    /**
     * Ensure the entity manager is open and reset it if needed.
     */
    private function ensureEntityManager(): void
    {
        if ($this->em->isOpen()) {
            return;
        }

        $this->registry->resetManager();
        $this->em = $this->registry->getManager();
    }
}
