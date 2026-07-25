<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Invoice;
use App\Service\InvoiceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceServiceNextNumberTest extends TestCase
{
    private InvoiceService $invoiceService;

    protected function setUp(): void
    {
        // determineNextInvoiceNumber is a pure function; bypass the constructor
        $reflection = new \ReflectionClass(InvoiceService::class);
        $this->invoiceService = $reflection->newInstanceWithoutConstructor();
    }

    #[DataProvider('numberProvider')]
    public function testDetermineNextInvoiceNumber(?string $last, ?string $expected): void
    {
        self::assertSame($expected, $this->invoiceService->determineNextInvoiceNumber($last));
    }

    public function testEnsureInvoiceCreationMetaDefaultsDateAndNumber(): void
    {
        $invoice = new Invoice(); // fresh: no date, no number
        $this->invoiceService->ensureInvoiceCreationMeta($invoice, 'R-2026-041');

        self::assertInstanceOf(\DateTimeInterface::class, $invoice->getDate());
        self::assertSame('R-2026-042', $invoice->getNumber());
    }

    public function testEnsureInvoiceCreationMetaKeepsExistingValues(): void
    {
        $invoice = new Invoice();
        $invoice->setNumber('CUSTOM-1');
        $date = new \DateTime('2026-05-01');
        $invoice->setDate($date);

        $this->invoiceService->ensureInvoiceCreationMeta($invoice, 'R-2026-041');

        self::assertSame('CUSTOM-1', $invoice->getNumber());
        self::assertSame($date, $invoice->getDate());
    }

    public function testEnsureInvoiceCreationMetaLeavesNumberEmptyWhenNoSuggestion(): void
    {
        $invoice = new Invoice();
        $this->invoiceService->ensureInvoiceCreationMeta($invoice, '');

        self::assertInstanceOf(\DateTimeInterface::class, $invoice->getDate());
        self::assertEmpty($invoice->getNumber());
    }

    public static function numberProvider(): array
    {
        return [
            'simple increment' => ['R-2026-041', 'R-2026-042'],
            'month/year prefix stays' => ['RE-0726-0004', 'RE-0726-0005'],
            'zero padded' => ['0099', '0100'],
            'plain number' => ['12', '13'],
            'digit overflow keeps length growth' => ['R-999', 'R-1000'],
            'no trailing digits' => ['ABC', null],
            'empty string' => ['', null],
            'null' => [null, null],
            'whitespace' => ['   ', null],
        ];
    }
}
