<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Entity\AppSettings;
use App\Service\AppSettingsService;
use App\Service\InvoiceService;
use App\Service\PriceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InvoiceServiceFilenameTest extends TestCase
{
    public function testBuildFilenameUsesPatternAndSanitizes(): void
    {
        $invoice = $this->buildInvoice([
            'company' => 'Müller & Söhne',
            'number' => '100',
            'date' => '2026-01-05',
        ]);

        $service = $this->buildService('Rechnung-<company>-<number>-<date>');

        $filename = $service->buildInvoiceExportFilename($invoice);

        self::assertSame('Rechnung-Mueller_Soehne-100-2026-01-05', $filename);
    }

    public function testFallbackPlaceholderUsesLastnameWhenCompanyMissing(): void
    {
        $invoice = $this->buildInvoice([
            'company' => '',
            'lastname' => 'Müller',
            'number' => '100',
        ]);

        $service = $this->buildService('<company|lastname>-<number>');

        $filename = $service->buildInvoiceExportFilename($invoice);

        self::assertSame('Mueller-100', $filename);
    }

    public function testStatusAndPaymentTranslatedAndSanitized(): void
    {
        $invoice = $this->buildInvoice([
            'status' => 2,
            'paymentMeans' => PaymentMeansCode::SEPA_CREDIT_TRANSFER,
        ]);

        $service = $this->buildService('<status>-<payment>');

        $filename = $service->buildInvoiceExportFilename($invoice);

        self::assertSame('Bezahlt-SEPA-Ueberweisung', $filename);
    }

    public function testUnknownPlaceholderIsRemovedAndSeparatorsCollapsed(): void
    {
        $invoice = $this->buildInvoice([
            'number' => '100',
        ]);

        $service = $this->buildService('INV-<evil>-<number>');

        $filename = $service->buildInvoiceExportFilename($invoice);

        self::assertSame('INV-100', $filename);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function buildInvoice(array $values): Invoice
    {
        $invoice = new Invoice();

        if (array_key_exists('company', $values)) {
            $invoice->setCompany($values['company']);
        }
        if (array_key_exists('lastname', $values)) {
            $invoice->setLastname($values['lastname']);
        }
        if (array_key_exists('firstname', $values)) {
            $invoice->setFirstname($values['firstname']);
        }
        if (array_key_exists('number', $values)) {
            $invoice->setNumber($values['number']);
        }
        if (array_key_exists('date', $values)) {
            $invoice->setDate(new \DateTime((string) $values['date']));
        } else {
            $invoice->setDate(new \DateTime('2026-01-01'));
        }
        if (array_key_exists('status', $values)) {
            $invoice->setStatus($values['status']);
        }
        if (array_key_exists('paymentMeans', $values)) {
            $invoice->setPaymentMeans($values['paymentMeans']);
        }

        return $invoice;
    }

    private function buildService(string $pattern): InvoiceService
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $priceService = $this->createStub(PriceService::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static function (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string {
                if ('invoice.status.paid' === $id) {
                    return 'Bezahlt!';
                }
                if ('SEPA_CREDIT_TRANSFER' === $id) {
                    return 'SEPA-Überweisung';
                }

                return $id;
            });

        $appSettings = new AppSettings();
        $appSettings->setInvoiceFilenamePattern($pattern);
        $appSettingsService = $this->createStub(AppSettingsService::class);
        $appSettingsService->method('getSettings')->willReturn($appSettings);

        return new InvoiceService($em, $priceService, $translator, $appSettingsService);
    }
}
