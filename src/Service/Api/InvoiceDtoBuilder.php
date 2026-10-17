<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\Dto\Api\InvoiceDto;
use App\Entity\Enum\InvoiceStatus;
use App\Entity\Invoice;
use App\Service\InvoiceService;

/**
 * Builds the InvoiceDto (totals, VAT breakdown, linked reservations) shared by the REST API and
 * the MCP tools.
 */
class InvoiceDtoBuilder
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {
    }

    /**
     * @param list<array{id: int, uuid: mixed}> $reservationRows
     */
    public function build(Invoice $invoice, array $reservationRows): InvoiceDto
    {
        $vats = [];
        $gross = 0.0;
        $vatTotal = 0.0;
        $apartmentTotal = 0.0;
        $miscTotal = 0.0;
        // Note: calculateSums() returns the VAT total in its "netto" out-parameter.
        $this->invoiceService->calculateSums(
            $invoice->getAppartments(),
            $invoice->getPositions(),
            $vats,
            $gross,
            $vatTotal,
            $apartmentTotal,
            $miscTotal
        );

        $vatRates = [];
        foreach ($vats as $rate => $values) {
            $vatRates[] = [
                'rate' => (float) $rate,
                'gross' => round((float) $values['brutto'], 2),
                'vat' => round((float) $values['netto'], 2),
                'net' => round((float) $values['netSum'], 2),
            ];
        }

        $reservations = [];
        foreach ($reservationRows as $row) {
            $uuid = $row['uuid'] ?? null;
            $reservations[] = [
                'id' => (int) $row['id'],
                'uuid' => \is_object($uuid) ? (string) $uuid : $uuid,
            ];
        }

        return InvoiceDto::fromEntity(
            $invoice,
            [
                'gross' => round($gross, 2),
                'vat' => round($vatTotal, 2),
                'net' => round($gross - $vatTotal, 2),
            ],
            $vatRates,
            $reservations
        );
    }

    /**
     * @param list<array{id: int, number: string, date: \DateTimeInterface, status: int}> $rows
     *
     * @return list<array<string, mixed>>
     */
    public static function mapSummaries(array $rows): array
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
}
