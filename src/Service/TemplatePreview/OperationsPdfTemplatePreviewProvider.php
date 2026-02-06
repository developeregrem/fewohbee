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
use App\Service\HousekeepingViewService;
use App\Service\OperationsFilterService;
use App\Service\OperationsReportService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Preview provider for operations report PDF templates.
 */
class OperationsPdfTemplatePreviewProvider implements ITemplatePreviewProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OperationsReportService $operationsReportService,
        private readonly OperationsFilterService $filterService,
        private readonly HousekeepingViewService $housekeepingViewService
    ) {
    }

    public function supportsPreview(Template $template): bool
    {
        return $template->getTemplateType()?->getName() === 'TEMPLATE_OPERATIONS_PDF';
    }

    public function getPreviewContextDefinition(): array
    {
        return [
            [
                'name' => 'dateRangeStart',
                'type' => 'date',
                'label' => 'templates.preview.date_start',
            ],
            [
                'name' => 'dateRangeEnd',
                'type' => 'date',
                'label' => 'templates.preview.date_end',
            ],
        ];
    }

    public function buildSampleContext(): array
    {
        $start = $this->filterService->resolveStartDate(null);
        $end = $this->filterService->resolveEndDate(null, $start);

        return [
            'dateRangeStart' => $start->format('Y-m-d'),
            'dateRangeEnd' => $end->format('Y-m-d'),
        ];
    }

    public function buildPreviewRenderParams(Template $template, array $ctx): array
    {
        $start = $this->filterService->resolveStartDate($ctx['dateRangeStart'] ?? null);
        $end = $this->filterService->resolveEndDate($ctx['dateRangeEnd'] ?? null, $start);
        $subsidiaryId = 'all';
        $subsidiary = null;
        $occupancyTypes = $this->housekeepingViewService->getAllowedOccupancyTypes();

        $reportData = $this->operationsReportService->buildReportData($start, $end, $subsidiary, $occupancyTypes);
        $reportData['filters']['template'] = $template;
        $reportData['filters']['subsidiaryId'] = $subsidiaryId;

        return $this->operationsReportService->getRenderParams($template, $reportData);
    }

    public function getAvailableSnippets(): array
    {
        return [
            [
                'id' => 'operations.period',
                'label' => 'templates.preview.snippet.operations_period',
                'group' => 'Operations',
                'complexity' => 'simple',
                'content' => "[[ filters.start|date('d.m.Y') ]] - [[ filters.end|date('d.m.Y') ]]",
            ],
            [
                'id' => 'operations.dayviews',
                'label' => 'templates.preview.snippet.operations_dayviews',
                'group' => 'Operations',
                'complexity' => 'advanced',
                'content' => "[% for day in dayViews %]\n<p>[[ day.date|date('d.m.Y') ]]</p>\n[% endfor %]",
            ],
            [
                'id' => 'pdf.header',
                'label' => 'templates.preview.snippet.pdf_header',
                'group' => 'PDF',
                'complexity' => 'simple',
                'content' => '<div class="header">\n  <p>Header</p>\n</div>',
            ],
            [
                'id' => 'pdf.footer',
                'label' => 'templates.preview.snippet.pdf_footer',
                'group' => 'PDF',
                'complexity' => 'simple',
                'content' => '<div class="footer">\n  <p>Footer</p>\n</div>',
            ],
        ];
    }
}
