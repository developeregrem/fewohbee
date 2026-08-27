<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\InvoiceSettingsData;
use PHPUnit\Framework\TestCase;

final class InvoiceSettingsDueDateTest extends TestCase
{
    public function testTheDueDateIsTheInvoiceDatePlusThePaymentPeriod(): void
    {
        $settings = (new InvoiceSettingsData())->setPaymentDueDays(10);

        $due = $settings->dueDateFor(new \DateTime('2026-08-27'));

        self::assertSame('2026-09-06', $due?->format('Y-m-d'));
    }

    /**
     * The settings accept free-text terms instead of a period, and validation only
     * insists that one of the two is filled. Callers print nothing rather than a
     * date they made up.
     */
    public function testNoDueDateWithoutAPaymentPeriod(): void
    {
        $settings = new InvoiceSettingsData();

        self::assertNull($settings->dueDateFor(new \DateTime('2026-08-27')));
    }

    public function testAPeriodOfZeroDaysMeansTheInvoiceDateItself(): void
    {
        $settings = (new InvoiceSettingsData())->setPaymentDueDays(0);

        self::assertSame('2026-08-27', $settings->dueDateFor(new \DateTime('2026-08-27'))?->format('Y-m-d'));
    }

    /** Crossing a month and a leap day is date arithmetic, not addition. */
    public function testThePeriodCrossesMonthBoundaries(): void
    {
        $settings = (new InvoiceSettingsData())->setPaymentDueDays(14);

        self::assertSame('2028-03-06', $settings->dueDateFor(new \DateTime('2028-02-21'))?->format('Y-m-d'));
    }

    /** The invoice keeps its own date; the caller may hold a mutable one. */
    public function testTheInvoiceDateIsNotModified(): void
    {
        $invoiceDate = new \DateTime('2026-08-27');
        (new InvoiceSettingsData())->setPaymentDueDays(10)->dueDateFor($invoiceDate);

        self::assertSame('2026-08-27', $invoiceDate->format('Y-m-d'));
    }
}
