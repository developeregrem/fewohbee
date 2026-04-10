<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\Enum\InvoiceStatus;

/**
 * Builds frontdesk list items with arrival/departure/inhouse categories.
 */
class FrontdeskViewService
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $selectedCategories
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildItems(array $rows, \DateTimeImmutable $date, array $selectedCategories): array
    {
        $dateKey = $date->format('Y-m-d');
        $items = [];
        $seen = [];

        foreach ($rows as $row) {
            $reservations = $row['apartmentReservations'] ?? [];
            foreach ($reservations as $reservation) {
                if (!$reservation instanceof Reservation) {
                    continue;
                }
                $reservationId = $reservation->getId();
                if (isset($seen[$reservationId])) {
                    continue;
                }

                $startKey = $reservation->getStartDate()->format('Y-m-d');
                $endKey = $reservation->getEndDate()->format('Y-m-d');
                $categories = [];
                if ($startKey === $dateKey) {
                    $categories[] = 'arrival';
                }
                if ($endKey === $dateKey) {
                    $categories[] = 'departure';
                }
                if ($startKey < $dateKey && $endKey > $dateKey) {
                    $categories[] = 'inhouse';
                }

                if (empty($categories)) {
                    continue;
                }

                if (count(array_intersect($categories, $selectedCategories)) === 0) {
                    continue;
                }

                $invoiceStatusLabel = null;
                $firstInvoice = $reservation->getInvoices()->first();
                if ($firstInvoice) {
                    $statusEnum = InvoiceStatus::fromStatus($firstInvoice->getStatus());
                    $invoiceStatusLabel = $statusEnum?->labelKey();
                }

                $items[] = [
                    'reservation' => $reservation,
                    'apartment' => $row['apartment'],
                    'categories' => $categories,
                    'invoiceStatusLabel' => $invoiceStatusLabel,
                ];
                $seen[$reservationId] = true;
            }
        }

        return $items;
    }
}
