<?php

declare(strict_types=1);

namespace App\Service\TemplatePreview;

use App\Entity\Template;

/**
 * Resolves runtime render parameters using the template preview provider system.
 */
class TemplateRenderParamsResolver
{
    public function __construct(
        private readonly TemplatePreviewProviderRegistry $providerRegistry
    ) {
    }

    /**
     * Build render params for template output generation.
     *
     * @return array<string, mixed>
     */
    public function resolve(Template $template, mixed $input): array
    {
        $provider = $this->providerRegistry->getProvider($template);
        if (null === $provider) {
            return [];
        }

        return $provider->buildRenderParams($template, $input);
    }
}

