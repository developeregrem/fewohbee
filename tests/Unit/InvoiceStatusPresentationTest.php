<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\InvoiceStatus;
use App\Entity\Enum\PaymentMeansCode;
use PHPUnit\Framework\TestCase;

/**
 * The payment status is shown as the payment means icon in the status colour with the status as a
 * shape at its corner; every status needs a colour and a shape of its own.
 */
final class InvoiceStatusPresentationTest extends TestCase
{
    public function testEveryStatusHasItsOwnColourAndMark(): void
    {
        $colours = array_map(static fn (InvoiceStatus $status): string => $status->textClass(), InvoiceStatus::cases());
        $marks = array_map(static fn (InvoiceStatus $status): string => $status->markIcon(), InvoiceStatus::cases());

        self::assertCount(4, array_unique($colours));
        self::assertCount(4, array_unique($marks));
    }

    public function testTheMenuOffersEveryStatusFromOpenToCancelled(): void
    {
        self::assertSame(
            [InvoiceStatus::OPEN, InvoiceStatus::PREPAID, InvoiceStatus::PAID, InvoiceStatus::CANCELED],
            InvoiceStatus::menuOrder(),
        );
    }

    public function testAnUnknownStoredStatusShowsAsCancelled(): void
    {
        self::assertSame(InvoiceStatus::CANCELED, InvoiceStatus::forDisplay(9));
        self::assertSame(InvoiceStatus::CANCELED, InvoiceStatus::forDisplay(null));
        self::assertSame(InvoiceStatus::PAID, InvoiceStatus::forDisplay(2));
    }

    public function testEveryPaymentMeansHasAnIcon(): void
    {
        foreach (PaymentMeansCode::cases() as $means) {
            self::assertStringStartsWith('fa-', $means->icon());
        }
        self::assertSame('fa-coins', PaymentMeansCode::CASH->icon());
    }
}
