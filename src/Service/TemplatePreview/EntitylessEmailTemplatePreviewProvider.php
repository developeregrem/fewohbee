<?php

declare(strict_types=1);

namespace App\Service\TemplatePreview;

use App\Entity\Template;
use App\Interfaces\ITemplatePreviewProvider;

/**
 * Preview provider for email templates that have no entity render context.
 */
final class EntitylessEmailTemplatePreviewProvider implements ITemplatePreviewProvider
{
    private const SUPPORTED_TEMPLATE_TYPES = [
        'TEMPLATE_GENERAL_EMAIL',
        'TEMPLATE_NEWSLETTER_EMAIL',
    ];

    public function supportsPreview(Template $template): bool
    {
        return in_array(
            $template->getTemplateType()?->getName(),
            self::SUPPORTED_TEMPLATE_TYPES,
            true
        );
    }

    public function buildRenderParams(Template $template, mixed $input): array
    {
        return [];
    }

    public function getPreviewContextDefinition(): array
    {
        return [];
    }

    public function buildPreviewRenderParams(Template $template, array $ctx): array
    {
        return [];
    }

    public function buildSampleContext(): array
    {
        return [];
    }

    public function getAvailableSnippets(): array
    {
        return [];
    }

    public function getRenderParamsSchema(): array
    {
        return [];
    }
}
