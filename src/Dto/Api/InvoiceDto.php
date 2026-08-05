<?php

declare(strict_types=1);

namespace App\Dto\Api;

use App\Entity\Enum\InvoiceStatus;
use App\Entity\Invoice;

/**
 * API representation of an invoice including its line items and VAT breakdown.
 * Payment credentials (IBAN, card number, card holder, mandate reference) are
 * deliberately never exposed.
 */
final readonly class InvoiceDto
{
    /**
     * @param array{id: int, code: string|null}                    $status
     * @param array{code: int, name: string}|null                  $paymentMeans
     * @param array<string, string|null>                           $recipient
     * @param array{gross: float, net: float, vat: float}          $totals
     * @param list<array<string, mixed>>                           $vatRates
     * @param list<array<string, mixed>>                           $apartments
     * @param list<array<string, mixed>>                           $positions
     * @param list<array{id: int, uuid: string|null}>              $reservations
     */
    public function __construct(
        public ?int $id,
        public ?string $number,
        public string $date,
        public array $status,
        public ?array $paymentMeans,
        public ?string $payment,
        public array $recipient,
        public ?string $remark,
        public array $totals,
        public array $vatRates,
        public array $apartments,
        public array $positions,
        public array $reservations,
    ) {
    }

    /**
     * @param array{gross: float, net: float, vat: float} $totals
     * @param list<array<string, mixed>>                  $vatRates
     * @param list<array{id: int, uuid: string|null}>     $reservations
     */
    public static function fromEntity(Invoice $invoice, array $totals, array $vatRates, array $reservations): self
    {
        $statusId = (int) $invoice->getStatus();
        $paymentMeans = $invoice->getPaymentMeans();

        $apartments = [];
        foreach ($invoice->getAppartments() as $apartment) {
            $apartments[] = [
                'id' => (int) $apartment->getId(),
                'number' => $apartment->getNumber(),
                'description' => $apartment->getDescription(),
                'startDate' => $apartment->getStartDate()->format('Y-m-d'),
                'endDate' => $apartment->getEndDate()->format('Y-m-d'),
                'beds' => (int) $apartment->getBeds(),
                'persons' => (int) $apartment->getPersons(),
                'amount' => $apartment->getAmount(),
                'price' => (float) $apartment->getPrice(),
                'vat' => $apartment->getVat(),
                'includesVat' => (bool) $apartment->getIncludesVat(),
                'isFlatPrice' => (bool) $apartment->getIsFlatPrice(),
                'isPerRoom' => $apartment->getIsPerRoom(),
                'total' => round($apartment->getTotalPriceRaw(), 2),
            ];
        }

        $positions = [];
        foreach ($invoice->getPositions() as $position) {
            $positions[] = [
                'id' => (int) $position->getId(),
                'description' => $position->getDescription(),
                'amount' => $position->getAmount(),
                'price' => (float) $position->getPrice(),
                'vat' => $position->getVat(),
                'includesVat' => (bool) $position->getIncludesVat(),
                'isFlatPrice' => (bool) $position->getIsFlatPrice(),
                'positionGroup' => $position->getPositionGroup(),
                'total' => round($position->getTotalPriceRaw(), 2),
            ];
        }

        return new self(
            id: null === $invoice->getId() ? null : (int) $invoice->getId(),
            number: $invoice->getNumber(),
            date: $invoice->getDate()->format('Y-m-d'),
            status: ['id' => $statusId, 'code' => InvoiceStatus::fromStatus($statusId)?->name],
            paymentMeans: null === $paymentMeans ? null : ['code' => $paymentMeans->value, 'name' => $paymentMeans->name],
            payment: $invoice->getPayment(),
            recipient: [
                'salutation' => $invoice->getSalutation(),
                'firstname' => $invoice->getFirstname(),
                'lastname' => $invoice->getLastname(),
                'company' => $invoice->getCompany(),
                'address' => $invoice->getAddress(),
                'zip' => $invoice->getZip(),
                'city' => $invoice->getCity(),
                'country' => $invoice->getCountry(),
                'email' => $invoice->getEmail(),
                'phone' => $invoice->getPhone(),
                'buyerReference' => $invoice->getBuyerReference(),
                'buyerVatId' => $invoice->getBuyerVatId(),
            ],
            remark: $invoice->getRemark(),
            totals: $totals,
            vatRates: $vatRates,
            apartments: $apartments,
            positions: $positions,
            reservations: $reservations,
        );
    }
}
