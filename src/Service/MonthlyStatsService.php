<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\MonthlyStatsSnapshot;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\Subsidiary;
use App\Entity\Enum\InvoiceStatus;
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
    public function buildMetrics(int $month, int $year, ?Subsidiary $subsidiary): array
    {
        $this->ensureEntityManager();
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
        $monthEndInclusive = $monthEndExclusive->modify('-1 day');

        $reservations = $reservationRepo->loadReservationsForMonth($month, $year, $objectId);

        /*
        * Calculate utilization and tourism stats (arrivals, overnights) for the month.
        */
        $stays = 0;
        $arrivalsTotal = 0;
        $overnightsTotal = 0;
        $arrivalsByCountry = [];
        $overnightsByCountry = [];
        $warningsByReservation = [];

        foreach ($reservations as $reservation) {
            $customers = $reservation->getCustomers();
            $customerCount = $customers->count();
            $persons = $reservation->getPersons();
            if ($persons !== $customerCount) {
                $reservationId = $reservation->getId();
                if (!isset($warningsByReservation[$reservationId])) {
                    $appartment = $reservation->getAppartment();
                    $appartmentNumber = $appartment ? $appartment->getNumber() : null;
                    $warningsByReservation[$reservationId] = [
                        'reservation_id' => $reservationId,
                        'start_date' => $reservation->getStartDate()->format('Y-m-d'),
                        'end_date' => $reservation->getEndDate()->format('Y-m-d'),
                        'appartment_number' => $appartmentNumber,
                        'message' => $this->translator->trans('statistics.snapshot.warning.persons_mismatch'),
                    ];
                }
            }
            if (0 === $customerCount) {
                continue;
            }

            $startDate = $this->toImmutable($reservation->getStartDate());
            $endDate = $this->toImmutable($reservation->getEndDate());

            $resStart = $startDate < $monthStart ? $monthStart : $startDate;
            $resEnd = $endDate > $monthEndExclusive ? $monthEndExclusive : $endDate;

            // Only count overnights that fall within the report month.
            if ($resStart < $resEnd) {
                $nights = $resStart->diff($resEnd)->days;
                foreach ($customers as $customer) {
                    $country = $this->resolveCountryForCustomer($customer);
                    $overnightsByCountry[$country] = ($overnightsByCountry[$country] ?? 0) + $nights;
                    $overnightsTotal += $nights;
                    $stays += $nights;
                }
            }

            // Arrivals are counted only in the start month of the reservation.
            if ($startDate >= $monthStart && $startDate < $monthEndExclusive) {
                foreach ($customers as $customer) {
                    $country = $this->resolveCountryForCustomer($customer);
                    $arrivalsByCountry[$country] = ($arrivalsByCountry[$country] ?? 0) + 1;
                    $arrivalsTotal += 1;
                }
            }
        }

        ksort($arrivalsByCountry);
        ksort($overnightsByCountry);

        /*
        * Calculate overall utilization percentage for the month.
        */
        $daysInMonth = (int) $monthStart->format('t');
        $utilization = 0.0;
        if ($bedsTotal > 0 && $daysInMonth > 0) {
            $utilization = $stays * 100.0 / ($bedsTotal * $daysInMonth);
        }
        $dailyUtilization = $this->buildDailyUtilization($monthStart, $daysInMonth, $objectId, $bedsTotal, $reservationRepo);

        /*
        * Calculate reservation origins for the month.
        */
        $originRows = $reservationRepo->loadOriginStatisticForPeriod(
            $monthStart->format('Y-m-d'),
            $monthEndInclusive->format('Y-m-d'),
            $objectId
        );
        $originStats = [];
        foreach ($originRows as $row) {
            $origin = $this->em->getRepository(ReservationOrigin::class)->find($row['id']);
            if (null !== $origin) {
                $originStats[$origin->getName()] = (int) $row['origins'];
            }
        }
        ksort($originStats);

        $warnings = array_values($warningsByReservation);
        $metrics = [
            'period' => [
                'year' => $year,
                'month' => $month,
            ],
            'subsidiary' => $subsidiary?->getId(),
            'inventory' => [
                'rooms_total' => $roomsTotal,
                'beds_total' => $bedsTotal,
            ],
            'utilization' => [
                'month_percent' => $utilization,
                'daily_percent' => $dailyUtilization,
            ],
            'tourism' => [
                'arrivals_total' => $arrivalsTotal,
                'overnights_total' => $overnightsTotal,
                'arrivals_by_country' => $arrivalsByCountry,
                'overnights_by_country' => $overnightsByCountry,
            ],
            'reservation_origin' => $originStats,
            'warnings' => $warnings,
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
    private function resolveCountryForCustomer(Customer $customer): string
    {
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
    public function getDailyUtilizationForMonth(int $month, int $year, ?Subsidiary $subsidiary): array
    {
        $objectId = $subsidiary?->getId() ?? 'all';
        $appartmentRepo = $this->em->getRepository(Appartment::class);
        $reservationRepo = $this->em->getRepository(Reservation::class);
        $bedsTotal = (int) $appartmentRepo->loadSumBedsMinForObject($objectId);

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $daysInMonth = (int) $monthStart->format('t');

        return $this->buildDailyUtilization($monthStart, $daysInMonth, $objectId, $bedsTotal, $reservationRepo);
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
        $payload = $this->buildMetrics($month, $year, $subsidiary);
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
        $reservationRepo
    ): array {
        $beds = 0 === $bedsTotal ? 1 : $bedsTotal;
        $data = [];
        $timeStartStr = $monthStart->format('Y-m-');
        for ($i = 1; $i <= $daysInMonth; ++$i) {
            $utilization = $reservationRepo->loadUtilizationForDay($timeStartStr.$i, $objectId);
            $data[] = $utilization * 100 / $beds;
        }

        return $data;
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
