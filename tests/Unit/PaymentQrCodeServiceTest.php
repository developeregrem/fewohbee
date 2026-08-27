<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AppSettings;
use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Service\AppSettingsService;
use App\Service\EInvoice\EInvoiceReadinessService;
use App\Service\PaymentQrCodeService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PaymentQrCodeServiceTest extends TestCase
{
    public function testPayloadFollowsTheEpcLineOrder(): void
    {
        $service = $this->createService($this->createSettings());

        $payload = $service->buildPayload($this->createInvoice('2026-0007'), 1342.5);

        $this->assertSame([
            'BCD',
            '002',
            '1',
            'SCT',
            'LUHSDE6AXXX',
            'Thomas Lisowski',
            'DE64545500100240006924',
            'EUR1342.50',
            '',
            '',
            'Rechnung 2026-0007',
            '',
        ], explode("\n", (string) $payload));
    }

    /** The IBAN is stored the way it was typed, the payload wants it without spaces. */
    public function testIbanAndBicAreCompacted(): void
    {
        $settings = $this->createSettings();
        $settings->setAccountIBAN('DE64 5455 0010 0240 0069 24');
        $settings->setAccountBIC('luhsde6axxx');

        $payload = (string) $this->createService($settings)->buildPayload($this->createInvoice(), 10.0);
        $lines = explode("\n", $payload);

        $this->assertSame('LUHSDE6AXXX', $lines[4]);
        $this->assertSame('DE64545500100240006924', $lines[6]);
    }

    public function testAmountIsFormattedWithADecimalPoint(): void
    {
        $payload = (string) $this->createService($this->createSettings())->buildPayload($this->createInvoice(), 89.5);

        $this->assertSame('EUR89.50', explode("\n", $payload)[7]);
    }

    public function testNoPayloadWithoutBankDetails(): void
    {
        $settings = $this->createSettings();
        $settings->setAccountIBAN('');

        $this->assertNull($this->createService($settings)->buildPayload($this->createInvoice(), 10.0));
    }

    public function testNoPayloadWithoutConfiguredInvoiceSettings(): void
    {
        $this->assertNull($this->createService(null)->buildPayload($this->createInvoice(), 10.0));
    }

    /** EPC069-12 carries euro amounts only, so another currency must not produce a code. */
    public function testNoPayloadOutsideTheEuroZone(): void
    {
        $service = $this->createService($this->createSettings(), 'CHF');

        $this->assertNull($service->buildPayload($this->createInvoice(), 10.0));
    }

    public function testNoPayloadForAnAmountOutsideTheAllowedRange(): void
    {
        $service = $this->createService($this->createSettings());

        $this->assertNull($service->buildPayload($this->createInvoice(), 0.0));
        $this->assertNull($service->buildPayload($this->createInvoice(), 1_000_000_000.0));
    }

    /** A beneficiary name longer than the 70 characters EPC069-12 allows is cut, not rejected. */
    public function testLongBeneficiaryNameIsTruncated(): void
    {
        $settings = $this->createSettings();
        $settings->setAccountName(str_repeat('a', 90));

        $payload = (string) $this->createService($settings)->buildPayload($this->createInvoice(), 10.0);

        $this->assertSame(70, mb_strlen(explode("\n", $payload)[5]));
    }

    public function testRemittanceStaysEmptyWithoutAnInvoiceNumber(): void
    {
        $payload = (string) $this->createService($this->createSettings())->buildPayload(new Invoice(), 10.0);

        $this->assertSame('', explode("\n", $payload)[10]);
    }

    /** The issuer follows the invoice's branch, so each company's own account is used. */
    public function testSettingsAreResolvedForTheInvoicesSubsidiary(): void
    {
        $branchSettings = $this->createSettings();
        $branchSettings->setAccountIBAN('DE02120300000000202051');
        $branchSettings->setAccountName('Zweites Haus');

        $payload = (string) $this->createService($branchSettings)->buildPayload($this->createInvoice(), 10.0);
        $lines = explode("\n", $payload);

        $this->assertSame('Zweites Haus', $lines[5]);
        $this->assertSame('DE02120300000000202051', $lines[6]);
    }

    /**
     * The per-line caps count characters while the scheme counts bytes, so an umlaut
     * holder name plus a long remittance can still overrun it. Banking apps reject an
     * oversized code, and no code is better than a broken one.
     */
    public function testNoPayloadWhenTheLinesTogetherExceedTheSchemeLimit(): void
    {
        $settings = $this->createSettings();
        $settings->setAccountName(str_repeat('ä', 70));

        $service = $this->createService($settings);
        $this->assertNull($service->buildPayload($this->createInvoice(str_repeat('9', 140)), 10.0));
    }

    /** The same long name stays fine as long as it is plain ASCII. */
    public function testAsciiNameOfTheSameLengthStillFits(): void
    {
        $settings = $this->createSettings();
        $settings->setAccountName(str_repeat('a', 70));

        $this->assertNotNull($this->createService($settings)->buildPayload($this->createInvoice(), 10.0));
    }

    public function testDataUriIsAPngOfTheRequestedSize(): void
    {
        $uri = $this->createService($this->createSettings())->buildDataUri($this->createInvoice(), 10.0, 300);

        self::assertIsString($uri);
        self::assertStringStartsWith('data:image/png;base64,', $uri);
        self::assertSame([300, 300], $this->pixelSize($uri));
    }

    /**
     * The size comes from a template, so it has to survive a typo. Memory and time
     * grow with the square of the edge; an unbounded value takes the worker down.
     */
    public function testAnOversizedRequestIsCapped(): void
    {
        $uri = $this->createService($this->createSettings())->buildDataUri($this->createInvoice(), 10.0, 8000);

        self::assertSame([1000, 1000], $this->pixelSize((string) $uri));
    }

    /** Below the floor the encoder refuses a full-length payload outright. */
    public function testAnUndersizedRequestIsRaisedToTheFloor(): void
    {
        $uri = $this->createService($this->createSettings())->buildDataUri($this->createInvoice(), 10.0, 10);

        self::assertSame([100, 100], $this->pixelSize((string) $uri));
    }

    /** Even at the floor, the longest payload the scheme carries still encodes. */
    public function testTheFloorStillEncodesAMaximalPayload(): void
    {
        $settings = $this->createSettings();
        $settings->setAccountName(str_repeat('a', 70));

        $uri = $this->createService($settings)->buildDataUri($this->createInvoice(str_repeat('9', 60)), 999999999.99, 1);

        self::assertSame([100, 100], $this->pixelSize((string) $uri));
    }

    public function testNoDataUriWhenNoPayloadIsPossible(): void
    {
        self::assertNull($this->createService(null)->buildDataUri($this->createInvoice(), 10.0));
    }

    /** @return array{int, int} */
    private function pixelSize(string $dataUri): array
    {
        $png = base64_decode(substr($dataUri, \strlen('data:image/png;base64,')), true);
        $info = getimagesizefromstring((string) $png);

        return [$info[0] ?? 0, $info[1] ?? 0];
    }

    private function createService(?InvoiceSettingsData $settings, string $currency = 'EUR'): PaymentQrCodeService
    {
        $readiness = $this->createStub(EInvoiceReadinessService::class);
        $readiness->method('resolveSettingsFor')->willReturn($settings);

        $appSettings = new AppSettings();
        $appSettings->setCurrency($currency);

        $appSettingsService = $this->createStub(AppSettingsService::class);
        $appSettingsService->method('getSettings')->willReturn($appSettings);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []) => 'Rechnung '.($params['%number%'] ?? '')
        );

        return new PaymentQrCodeService($readiness, $appSettingsService, $translator);
    }

    private function createSettings(): InvoiceSettingsData
    {
        $settings = new InvoiceSettingsData();
        $settings->setAccountName('Thomas Lisowski');
        $settings->setAccountIBAN('DE64545500100240006924');
        $settings->setAccountBIC('LUHSDE6AXXX');

        return $settings;
    }

    private function createInvoice(string $number = '2026-0001'): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber($number);

        return $invoice;
    }
}
