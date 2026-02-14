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
 * Preview provider for generic PDF templates without entity context.
 */
class FilePdfTemplatePreviewProvider implements ITemplatePreviewProvider
{
    public function supportsPreview(Template $template): bool
    {
        return $template->getTemplateType()?->getName() === 'TEMPLATE_FILE_PDF';
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

    public function getRenderParamsSchema(): array
    {
        return [];
    }

    public function getAvailableSnippets(): array
    {
        return [
            [
                'id' => 'pdf.header',
                'label' => 'templates.preview.snippet.pdf_header',
                'group' => 'PDF',
                'complexity' => 'simple',
                'content' => '<div class="header"><p>Header</p></div>',
            ],
            [
                'id' => 'pdf.footer',
                'label' => 'templates.preview.snippet.pdf_footer',
                'group' => 'PDF',
                'complexity' => 'simple',
                'content' => '<div class="footer"><p>Footer</p></div>',
            ],
        ];
    }
}

