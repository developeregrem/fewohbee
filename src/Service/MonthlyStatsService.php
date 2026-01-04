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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class MonthlyStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
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

        /*
        * Calculate turnover for the month.
        */
        $invoiceStatuses = array_map(
            static fn (InvoiceStatus $status) => $status->value,
            InvoiceStatus::cases()
        );
        $turnover = $this->statisticsService->loadTurnoverForSingleMonth(
            $this->invoiceService,
            $year,
            $month,
            $invoiceStatuses
        );

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
            ],
            'tourism' => [
                'arrivals_total' => $arrivalsTotal,
                'overnights_total' => $overnightsTotal,
                'arrivals_by_country' => $arrivalsByCountry,
                'overnights_by_country' => $overnightsByCountry,
            ],
            'reservation_origin' => $originStats,
            'turnover' => [
                'total' => $turnover,
            ],
            'warnings' => $warnings,
        ];

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
     * Create or update a single snapshot for a specific subsidiary scope.
     */
    private function upsertSnapshot(int $month, int $year, ?Subsidiary $subsidiary, bool $force): array
    {
        $repo = $this->em->getRepository(MonthlyStatsSnapshot::class);
        $snapshot = $repo->findOneByMonthYearSubsidiary($month, $year, $subsidiary);

        if (null === $snapshot) {
            $snapshot = new MonthlyStatsSnapshot();
            $snapshot->setMonth($month);
            $snapshot->setYear($year);
            $snapshot->setSubsidiary($subsidiary);
            $this->em->persist($snapshot);
        } elseif (!$force) {
            $metrics = $snapshot->getMetrics();

            return [
                'snapshot' => $snapshot,
                'warnings' => $metrics['warnings'] ?? [],
            ];
        }

        $payload = $this->buildMetrics($month, $year, $subsidiary);
        $snapshot->setMetrics($payload['metrics']);
        $snapshot->touchUpdatedAt();
        $this->em->flush();

        return [
            'snapshot' => $snapshot,
            'warnings' => $payload['warnings'],
        ];
    }

    /**
     * Build or update a snapshot and return it with runtime warnings.
     */
    public function getOrCreateSnapshotWithWarnings(int $month, int $year, ?Subsidiary $subsidiary, bool $force = false): array
    {
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
}
