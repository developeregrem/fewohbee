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

    public function getRenderParamsSchema(): array
    {
        // Operations templates use dynamically built arrays rather than Doctrine entities.
        // 'scalar' = flat value, 'array' = iterable list (data-repeat capable, no sub-introspection).
        return [
            'simple' => ['type' => 'scalar'],
            'occupancyLabels' => ['type' => 'array'],
            'statusLabels' => ['type' => 'array'],
            'invoiceStatusLabels' => ['type' => 'array'],
            'reservations' => ['type' => 'array'],
            'dayViews' => ['type' => 'array'],
        ];
    }

    public function getAvailableSnippets(): array
    {
        return [
            [
                'id' => 'operations.header_footer',
                'label' => 'templates.editor.operations.header_footer',
                'group' => 'Operations',
                'complexity' => 'easy',
                'content' => "<div class=\"header\"><div style=\"text-align: center; color: #666; font-size: 11px;\">[[ simple.meta.periodLabel ]]<span data-repeat=\"simple.meta.occupancyTypeLabelKeys\" data-repeat-as=\"type\"> | [[ occupancyLabels[type]|trans({}, 'Housekeeping') ]]</span> | [[ simple.meta.subsidiaryLabel|trans({}, 'Housekeeping') ]] | Erzeugt [[ simple.meta.generatedAt ]]</div></div><div class=\"footer\"><div style=\"text-align: right; font-size: 11px; color: #666;\">Seite {PAGENO} von {nbpg}</div></div>",
            ],
            [
                'id' => 'operations.reservations.table',
                'label' => 'templates.editor.operations.reservations_table',
                'group' => 'Operations',
                'complexity' => 'easy',
                'content' => "<h3>Alle Reservierungen im Zeitraum</h3><table style=\"width:100%; border-collapse: collapse;\"><tr><th>Zimmer</th><th>Gast</th><th>Personen</th><th>Zeitraum</th></tr><tr data-repeat=\"simple.reservations\" data-repeat-as=\"row\"><td>[[ row.roomLabel ]]</td><td>[[ row.guest ]]</td><td>[[ row.persons ]]</td><td>[[ row.start|date('d.m.Y') ]] - [[ row.end|date('d.m.Y') ]]</td></tr></table>",
            ],
            [
                'id' => 'operations.housekeeping.day',
                'label' => 'templates.editor.operations.housekeeping_day',
                'group' => 'Operations',
                'complexity' => 'easy',
                'content' => "<h3>Tagesbelegung</h3><table style=\"width:100%; border-collapse: collapse;\"><tr><th>{{ 'housekeeping.date'|trans({}, 'Housekeeping') }}</th><th>{{ 'housekeeping.room'|trans({}, 'Housekeeping') }}</th><th>{{ 'housekeeping.occupancy'|trans({}, 'Housekeeping') }}</th><th>{{ 'housekeeping.guests'|trans({}, 'Housekeeping') }}</th><th>{{ 'housekeeping.reservation'|trans({}, 'Housekeeping') }}</th><th>{{ 'housekeeping.status'|trans({}, 'Housekeeping') }}</th><th>{{ 'housekeeping.assigned_to'|trans({}, 'Housekeeping') }}</th><th>{{ 'housekeeping.note'|trans({}, 'Housekeeping') }}</th></tr><tr data-repeat=\"simple.views.housekeepingDay.rows\" data-repeat-as=\"row\"><td>[[ row.date ]]</td><td>[[ row.room ]]</td><td>[[ row.occupancyLabelKey|trans({}, 'Housekeeping') ]]</td><td>[[ row.guests ]]</td><td>[[ row.reservationSummary ]]</td><td>[[ row.statusLabelKey ? (row.statusLabelKey|trans({}, 'Housekeeping')) : '' ]]</td><td>[[ row.assignedTo ]]</td><td>[[ row.note ]]</td></tr></table>",
            ],
            [
                'id' => 'operations.housekeeping.week',
                'label' => 'templates.editor.operations.housekeeping_week',
                'group' => 'Operations',
                'complexity' => 'easy',
                'content' => "<h3>Wochenbelegung</h3><table style=\"width:100%; border-collapse: collapse;\"><tr><th>{{ 'housekeeping.room'|trans({}, 'Housekeeping') }}</th><th data-repeat=\"simple.days\" data-repeat-as=\"day\">[[ day.label ]]</th></tr><tr data-repeat=\"simple.views.housekeepingWeek.rows\" data-repeat-as=\"row\"><td>[[ row.apartmentLabel ]]</td><td data-repeat=\"row.cells\" data-repeat-as=\"cell\">[[ cell.occupancyLabelKey ? (cell.occupancyLabelKey|trans({}, 'Housekeeping')) : '' ]][[ cell.statusLabelKey ? ' / ' ~ (cell.statusLabelKey|trans({}, 'Housekeeping')) : '' ]][[ cell.guests ? ' / ' ~ cell.guests : '' ]]</td></tr></table>",
            ],
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
