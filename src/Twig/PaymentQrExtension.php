<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Twig;

use App\Entity\Invoice;
use App\Service\InvoiceService;
use App\Service\PaymentQrCodeService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Makes the EPC069-12 payment QR code available to invoice templates.
 *
 * Offered as a function rather than a render parameter for two reasons: the pixel
 * size belongs in the hands of whoever lays out the template, and a function is only
 * evaluated where it is used — building a code costs about 20 ms, which no template
 * should pay for a placeholder it never prints.
 */
final class PaymentQrExtension extends AbstractExtension
{
    public function __construct(
        private readonly PaymentQrCodeService $paymentQrCodeService,
        private readonly InvoiceService $invoiceService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('payment_qr', $this->paymentQr(...)),
        ];
    }

    /**
     * Returns the QR code as a `data:` URI ready for an `<img src>`, or an empty
     * string when the invoice cannot be paid by credit transfer as it stands —
     * no bank details, a non-euro installation, an amount the scheme cannot carry.
     * The empty string keeps `data-if="payment_qr(invoice)"` working as a guard.
     *
     * @param int $size edge length of the generated image in pixels; the size on
     *                  the page is a matter for the template's own CSS
     */
    public function paymentQr(Invoice $invoice, int $size = 300): string
    {
        return $this->paymentQrCodeService->buildDataUri($invoice, $this->grossTotal($invoice), $size) ?? '';
    }

    /**
     * The amount the guest is asked to transfer: the invoice total including VAT,
     * rounded per VAT rate the same way the printed total is.
     */
    private function grossTotal(Invoice $invoice): float
    {
        $vatSums = [];
        $brutto = 0;
        $netto = 0;
        $appartmentTotal = 0;
        $miscTotal = 0;

        $this->invoiceService->calculateSums(
            $invoice->getAppartments(),
            $invoice->getPositions(),
            $vatSums,
            $brutto,
            $netto,
            $appartmentTotal,
            $miscTotal
        );

        return (float) $brutto;
    }
}
