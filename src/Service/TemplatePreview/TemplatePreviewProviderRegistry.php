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

namespace App\Service\TemplatePreview;

use App\Entity\Template;
use App\Interfaces\ITemplatePreviewProvider;

/**
 * Registry for template preview providers.
 */
class TemplatePreviewProviderRegistry
{
    /**
     * @var array<int, ITemplatePreviewProvider>
     */
    private array $providers = [];

    /**
     * @param iterable<ITemplatePreviewProvider> $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[] = $provider;
        }
    }

    /**
     * Resolve the first provider that supports a given template.
     */
    public function getProvider(Template $template): ?ITemplatePreviewProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supportsPreview($template)) {
                return $provider;
            }
        }

        return null;
    }
}
