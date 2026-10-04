<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoiceSettingsData;
use PHPUnit\Framework\TestCase;

final class InvoicePaymentDueDateTest extends TestCase
{
    public function testTheDefaultIsTheInvoiceDatePlusTheSettingsPeriod(): void
    {
        self::assertSame('2026-09-06', $this->due($this->invoice('2026-08-27'), 10));
    }

    /**
     * The settings accept free-text terms instead of a period, and validation only
     * insists that one of the two is filled. Callers print nothing rather than a
     * date they made up.
     */
    public function testNoDefaultWithoutAPaymentPeriod(): void
    {
        self::assertNull($this->invoice('2026-08-27')->defaultPaymentDueDate(new InvoiceSettingsData()));
        self::assertNull($this->invoice('2026-08-27')->defaultPaymentDueDate(null));
    }

    public function testAPeriodOfZeroDaysMeansTheInvoiceDateItself(): void
    {
        self::assertSame('2026-08-27', $this->due($this->invoice('2026-08-27'), 0));
    }

    /** Crossing a month and a leap day is date arithmetic, not addition. */
    public function testThePeriodCrossesMonthBoundaries(): void
    {
        self::assertSame('2028-03-06', $this->due($this->invoice('2028-02-21'), 14));
    }

    /** The invoice keeps its own date; the entity holds a mutable one. */
    public function testTheInvoiceDateIsNotModified(): void
    {
        $invoice = $this->invoice('2026-08-27');
        $this->due($invoice, 10);

        self::assertSame('2026-08-27', $invoice->getDate()->format('Y-m-d'));
    }

    /** The stored date is a day, whatever time of day the input carried. */
    public function testTheStoredDueDateHasNoTimeOfDay(): void
    {
        $invoice = $this->invoice('2026-08-27')->setPaymentDueDate(new \DateTime('2026-10-01 15:30'));

        self::assertSame('2026-10-01 00:00', $invoice->getPaymentDueDate()?->format('Y-m-d H:i'));
    }

    public function testTheLastDepartureIsTheLatestEndDate(): void
    {
        $apartments = array_map(function (string $end): InvoiceAppartment {
            $apartment = new InvoiceAppartment();
            $apartment->setStartDate(new \DateTime('2026-08-01'));
            $apartment->setEndDate(new \DateTime($end.' 10:00'));

            return $apartment;
        }, ['2026-08-10', '2026-08-14', '2026-08-12']);

        self::assertSame('2026-08-14 00:00', Invoice::lastDepartureOf($apartments)?->format('Y-m-d H:i'));
        self::assertNull(Invoice::lastDepartureOf([]));
    }

    public function testTheFirstArrivalIsTheEarliestStartDate(): void
    {
        $apartments = array_map(function (string $start): InvoiceAppartment {
            $apartment = new InvoiceAppartment();
            $apartment->setStartDate(new \DateTime($start.' 15:00'));
            $apartment->setEndDate(new \DateTime('2026-08-20'));

            return $apartment;
        }, ['2026-08-10', '2026-08-04', '2026-08-12']);

        self::assertSame('2026-08-04 00:00', Invoice::firstArrivalOf($apartments)?->format('Y-m-d H:i'));
        self::assertNull(Invoice::firstArrivalOf([]));
    }

    private function invoice(string $date): Invoice
    {
        $invoice = new Invoice();
        $invoice->setDate(new \DateTime($date));

        return $invoice;
    }

    private function due(Invoice $invoice, int $settingsDays): ?string
    {
        return $invoice->defaultPaymentDueDate((new InvoiceSettingsData())->setPaymentDueDays($settingsDays))?->format('Y-m-d');
    }
}
