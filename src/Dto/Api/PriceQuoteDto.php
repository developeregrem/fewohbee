<?php

declare(strict_types=1);

namespace App\Dto\Api;

/**
 * What a stay would cost, computed through the same pipeline that produces the invoice
 * (PriceService → InvoiceService positions → calculateSums), so a quote and the later
 * invoice agree as long as nobody edits the invoice by hand.
 *
 * A quote is never a document: the authoritative amount for a billed stay is the invoice.
 *
 * `priceFound === false` means no price row applies to this apartment/occupancy/period —
 * distinct from a stay that genuinely costs nothing. All totals are 0.0 in that case.
 *
 * `touristTax` and `grandTotal` are null when the token lacks the tourist-tax scope,
 * so "not permitted to see it" stays distinguishable from "no tourist tax applies".
 */
final readonly class PriceQuoteDto
{
    /**
     * @param array{id: int|null, number: string|null, description: string|null} $apartment
     * @param array{id: int|null, name: string|null}                    $object
     * @param array{id: int|null, name: string|null}|null               $roomCategory
     * @param array{id: int, name: string|null}                         $origin
     * @param array<int, int>                                           $guestCounts
     * @param array{gross: float, net: float, vat: float, base: float, modifiers: float} $room
     *        gross/net/vat are VAT-resolved; base/modifiers split the same total into room
     *        rate and guest-category adjustments in the price rows' stored flavour, so they
     *        sum to gross only where those rows are VAT-inclusive
     * @param list<array<string, mixed>>                                $vatRates
     * @param list<array<string, mixed>>                                $nights
     * @param list<array<string, mixed>>                                $modifiers
     * @param list<array<string, mixed>>                                $extras
     * @param array{total: float, items: list<array<string, mixed>>}|null $touristTax
     */
    public function __construct(
        public array $apartment,
        public array $object,
        public ?array $roomCategory,
        public string $startDate,
        public string $endDate,
        public int $nightCount,
        public int $persons,
        public array $guestCounts,
        public array $origin,
        public bool $priceFound,
        public array $room,
        public array $vatRates,
        public array $nights,
        public array $modifiers,
        public array $extras,
        public float $extrasTotal,
        public ?array $touristTax,
        public ?float $grandTotal,
    ) {
    }
}
