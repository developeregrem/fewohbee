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
    public function supportsPreview(Template $template): bool
    {
        return $template->getTemplateType()?->getName() === 'TEMPLATE_INVOICE_EMAIL';
    }
}
