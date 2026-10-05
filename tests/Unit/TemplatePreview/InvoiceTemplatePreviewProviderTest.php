<?php

declare(strict_types=1);

namespace App\Tests\Unit\TemplatePreview;

use App\Service\InvoiceService;
use App\Service\TemplatePreview\InvoiceEmailTemplatePreviewProvider;
use App\Service\TemplatePreview\InvoiceTemplatePreviewProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers which editor snippets the invoice PDF and invoice email templates offer.
 */
final class InvoiceTemplatePreviewProviderTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pdfOnlySnippetIds(): iterable
    {
        yield 'payment QR code' => ['invoice.payment_qr'];
        yield 'PDF header' => ['pdf.header'];
        yield 'PDF footer' => ['pdf.footer'];
    }

    #[DataProvider('pdfOnlySnippetIds')]
    public function testPdfTemplateOffersPdfOnlySnippet(string $snippetId): void
    {
        $provider = new InvoiceTemplatePreviewProvider(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(InvoiceService::class),
        );

        self::assertContains($snippetId, $this->snippetIds($provider));
    }

    #[DataProvider('pdfOnlySnippetIds')]
    public function testEmailTemplateHidesPdfOnlySnippet(string $snippetId): void
    {
        $provider = new InvoiceEmailTemplatePreviewProvider(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(InvoiceService::class),
        );

        self::assertNotContains($snippetId, $this->snippetIds($provider));
    }

    public function testEmailTemplateKeepsTheSharedInvoiceSnippetsAsAList(): void
    {
        $provider = new InvoiceEmailTemplatePreviewProvider(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(InvoiceService::class),
        );

        $snippets = $provider->getAvailableSnippets();

        self::assertTrue(array_is_list($snippets));
        self::assertContains('invoice.number', $this->snippetIds($provider));
        self::assertContains('invoice.payment_due_date', $this->snippetIds($provider));
    }

    /**
     * @return list<string>
     */
    private function snippetIds(InvoiceTemplatePreviewProvider $provider): array
    {
        return array_map(
            static fn (array $snippet): string => (string) $snippet['id'],
            $provider->getAvailableSnippets(),
        );
    }
}
