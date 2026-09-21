<?php

declare(strict_types=1);

namespace App\Service\TemplatePreview;

use App\Entity\Template;

/**
 * Preview provider for invoice email templates (TEMPLATE_INVOICE_EMAIL).
 *
 * Uses the same render params as invoice PDF templates since both operate
 * on Invoice entities.
 */
class InvoiceEmailTemplatePreviewProvider extends InvoiceTemplatePreviewProvider
{
    /**
     * Snippets that only work in the PDF pipeline. The payment QR code is an embedded
     * image, which mail clients do not show reliably (Gmail and Outlook drop `data:`
     * URIs outright), so it belongs on the invoice PDF; header and footer are mPDF
     * page regions that have no meaning in a mail body.
     */
    private const PDF_ONLY_SNIPPETS = ['invoice.payment_qr', 'pdf.header', 'pdf.footer'];

    public function supportsPreview(Template $template): bool
    {
        return $template->getTemplateType()?->getName() === 'TEMPLATE_INVOICE_EMAIL';
    }

    public function getAvailableSnippets(): array
    {
        return array_values(array_filter(
            parent::getAvailableSnippets(),
            static fn (array $snippet): bool => !\in_array($snippet['id'] ?? null, self::PDF_ONLY_SNIPPETS, true),
        ));
    }
}
