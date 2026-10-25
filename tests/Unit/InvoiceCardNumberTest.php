<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Invoice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceCardNumberTest extends TestCase
{
    #[DataProvider('cardNumbers')]
    public function testShortNumberPreservesMaskingWithoutChangingTheStoredValue(
        ?string $input,
        ?string $stored,
        ?string $short,
    ): void {
        $invoice = new Invoice();
        $invoice->setCardNumber($input);

        self::assertSame($stored, $invoice->getCardNumber());
        self::assertSame($short, $invoice->getCardNumberShort());
        self::assertSame($stored, $invoice->getCardNumber());
    }

    public function testAClearedCardNumberHasNoShortNumber(): void
    {
        $invoice = new Invoice();
        $invoice->setCardNumber('123456789012');
        $invoice->setCardNumber(null);

        self::assertNull($invoice->getCardNumberShort());
    }

    /** @return iterable<string, array{string|null, string|null, string|null}> */
    public static function cardNumbers(): iterable
    {
        yield 'not supplied' => [null, null, null];
        yield 'empty' => ['', '', ''];
        yield 'short' => ['12345', '12345', '12345'];
        yield 'six characters' => ['123456', '123456', '123456'];
        yield 'nine characters' => ['123456789', '12XXX6789', '12XXX6789'];
        yield 'ten characters' => ['1234567890', '12XXXX7890', '12XXXX7890'];
        yield 'long' => ['123456789012', '12XXXXXX9012', 'XXXXXX9012'];
    }
}
