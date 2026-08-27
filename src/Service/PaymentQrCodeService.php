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

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Service\EInvoice\EInvoiceReadinessService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Builds an EPC069-12 payment QR code ("GiroCode") for an invoice.
 *
 * Scanning the code fills a SEPA credit transfer in the payer's banking app, so
 * nobody has to retype IBAN, amount and invoice number. The payload is the plain
 * text defined by EPC069-12: twelve newline separated lines, at most 331 bytes.
 *
 * The code is offered to templates as a `data:` URI placeholder. Whether it ends
 * up on the invoice — and whether that makes sense for the payment method at hand —
 * is left to the template, which can check `invoice.paymentMeans` and `invoice.status`.
 */
final class PaymentQrCodeService
{
    private const SERVICE_TAG = 'BCD';
    /** Version 002 makes the BIC optional, which SEPA no longer requires. */
    private const VERSION = '002';
    /** Character set 1 = UTF-8. */
    private const CHARACTER_SET = '1';
    private const IDENTIFICATION = 'SCT';

    /** EPC069-12 is a euro-only scheme; there is no way to express another currency. */
    private const CURRENCY = 'EUR';
    private const MAX_NAME_LENGTH = 70;
    private const MAX_REMITTANCE_LENGTH = 140;
    private const MIN_AMOUNT = 0.01;
    private const MAX_AMOUNT = 999999999.99;
    /** The scheme caps the whole payload, not just the individual lines. */
    private const MAX_PAYLOAD_BYTES = 331;

    /**
     * Bounds for the requested edge length, because the size comes from a template
     * and templates are written by hand.
     *
     * Below the lower bound the encoder refuses a full-length payload outright
     * ("Too much data"), which would surface as an exception in the middle of an
     * invoice. Above the upper bound nothing is gained and a lot is paid: memory and
     * time grow with the square of the edge, and an 8000 px code costs half a
     * gigabyte and five seconds — enough to take the worker down with it. 1000 px is
     * around 85 mm at 300 dpi, past anything a printed invoice asks for.
     */
    private const MIN_SIZE = 100;
    private const MAX_SIZE = 1000;

    /**
     * Rendered codes per invoice and pixel size, for the lifetime of the request:
     * a template that guards with an if and then prints the code asks twice.
     *
     * @var array<string, string|null>
     */
    private array $rendered = [];

    public function __construct(
        private readonly EInvoiceReadinessService $readinessService,
        private readonly AppSettingsService $appSettingsService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Returns the QR code as a base64 encoded PNG `data:` URI, or null when the
     * invoice cannot be paid by credit transfer as it stands — missing bank
     * details, a non-euro installation, or an amount outside the range
     * EPC069-12 allows.
     *
     * @param int $size edge length of the generated image in pixels, clamped to
     *                  MIN_SIZE..MAX_SIZE; how large the code appears on the page is
     *                  a matter for the template, not for this number
     */
    public function buildDataUri(Invoice $invoice, float $amount, int $size = 300): ?string
    {
        // Clamped before the cache key so two requests that end up at the same edge
        // length share the one rendered code.
        $size = max(self::MIN_SIZE, min(self::MAX_SIZE, $size));

        $cacheKey = ((string) $invoice->getId()).'|'.$size.'|'.$amount;
        if (\array_key_exists($cacheKey, $this->rendered)) {
            return $this->rendered[$cacheKey];
        }

        $payload = $this->buildPayload($invoice, $amount);
        if (null === $payload) {
            return $this->rendered[$cacheKey] = null;
        }

        return $this->rendered[$cacheKey] = (new Builder(
            writer: new PngWriter(),
            data: $payload,
            // Medium leaves the code readable on a printed invoice that got folded.
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 0,
        ))->build()->getDataUri();
    }

    /**
     * Assembles the twelve EPC069-12 lines. Returns null when a required field is
     * missing, so callers can leave the code off instead of printing a broken one.
     */
    public function buildPayload(Invoice $invoice, float $amount): ?string
    {
        // The scheme only carries euro amounts, so a shop running another currency
        // would get a code demanding the wrong money.
        if (self::CURRENCY !== strtoupper($this->appSettingsService->getSettings()->getCurrency())) {
            return null;
        }

        // Issuer data follows the invoice's branch, so a two-company setup puts the
        // right account on each code instead of whichever row is globally active.
        $settings = $this->readinessService->resolveSettingsFor($invoice);
        if (!$settings instanceof InvoiceSettingsData) {
            return null;
        }

        $iban = $this->compact((string) $settings->getAccountIBAN());
        $name = trim((string) ($settings->getAccountName() ?: $settings->getCompanyName()));

        if ('' === $iban || '' === $name) {
            return null;
        }

        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            return null;
        }

        $lines = [
            self::SERVICE_TAG,
            self::VERSION,
            self::CHARACTER_SET,
            self::IDENTIFICATION,
            $this->compact((string) $settings->getAccountBIC()),
            $this->cut($name, self::MAX_NAME_LENGTH),
            $iban,
            self::CURRENCY.number_format($amount, 2, '.', ''),
            '',  // purpose code
            '',  // structured creditor reference, mutually exclusive with the line below
            $this->cut($this->buildRemittanceInfo($invoice), self::MAX_REMITTANCE_LENGTH),
            '',  // beneficiary to originator information
        ];

        $payload = implode("\n", $lines);

        // Truncating the individual lines is not enough: those caps count characters
        // while the scheme counts bytes, so an umlaut holder name can weigh twice its
        // length. Banking apps reject an oversized code outright rather than showing a
        // partial transfer.
        return \strlen($payload) > self::MAX_PAYLOAD_BYTES ? null : $payload;
    }

    /**
     * The remittance text shows up in the payer's banking app, so it is translated
     * rather than hardcoded German.
     */
    private function buildRemittanceInfo(Invoice $invoice): string
    {
        $number = trim((string) $invoice->getNumber());
        if ('' === $number) {
            return '';
        }

        return $this->translator->trans('invoice.payment_qr.remittance', ['%number%' => $number]);
    }

    /**
     * IBAN and BIC are stored the way they were typed; the payload wants them
     * without spaces and in upper case.
     */
    private function compact(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
    }

    private function cut(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }
}
