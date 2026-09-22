<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Enum\ApiScope;
use App\Entity\Enum\InvoiceStatus;
use App\Entity\Subsidiary;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Security\McpToolException;
use App\Mcp\Support\McpInput;
use App\Service\Api\StatisticsQueryService;
use App\Service\MonthlyStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * Key figures for operations reports: utilization, turnover and the monthly statistics.
 */
final class StatisticsTools
{
    public function __construct(
        private readonly StatisticsQueryService $statisticsQueryService,
        private readonly MonthlyStatsService $monthlyStatsService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_occupancy_statistics',
        title: 'Occupancy statistics',
        description: 'Bed utilization in percent per month (at most 60 months), for all properties or one.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::STATISTICS_READ)]
    public function occupancy(
        #[Schema(description: 'First month, YYYY-MM.')]
        string $startMonth,
        #[Schema(type: 'string', description: 'Last month (inclusive), YYYY-MM. Defaults to startMonth.')]
        ?string $endMonth = null,
        #[Schema(type: 'integer', description: 'Property (object) id; all properties when omitted.')]
        ?int $objectId = null,
    ): array {
        $start = McpInput::month($startMonth, 'startMonth');
        $end = null !== $endMonth ? McpInput::month($endMonth, 'endMonth') : $start;
        if ($end < $start) {
            throw McpToolException::invalid("'endMonth' must not be before 'startMonth'.");
        }
        $months = ((int) $end->format('Y') - (int) $start->format('Y')) * 12 + ((int) $end->format('n') - (int) $start->format('n')) + 1;
        if ($months > StatisticsQueryService::MAX_MONTHS) {
            throw McpToolException::invalid(\sprintf('The range must not exceed %d months.', StatisticsQueryService::MAX_MONTHS));
        }

        $result = $this->statisticsQueryService->utilizationByMonth($start, $end, $this->resolveObjectId($objectId), []);

        return [
            'months' => $result['data'],
            'beds' => $result['beds'],
            'objectId' => $objectId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_turnover',
        title: 'Turnover',
        description: 'Invoice-based gross turnover per year or month (at most 5 years). Canceled invoices are excluded.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::STATISTICS_READ)]
    public function turnover(
        #[Schema(description: 'First year.', minimum: 1970, maximum: 2999)]
        int $startYear,
        #[Schema(type: 'integer', description: 'Last year (inclusive). Defaults to startYear.', minimum: 1970, maximum: 2999)]
        ?int $endYear = null,
        #[Schema(description: 'One value per year or per month.', enum: ['year', 'month'])]
        string $granularity = 'year',
    ): array {
        $endYear ??= $startYear;
        if ($endYear < $startYear) {
            throw McpToolException::invalid("'endYear' must not be before 'startYear'.");
        }
        if ($endYear - $startYear + 1 > StatisticsQueryService::MAX_YEARS) {
            throw McpToolException::invalid(\sprintf('The range must not exceed %d years.', StatisticsQueryService::MAX_YEARS));
        }
        if (!\in_array($granularity, ['year', 'month'], true)) {
            throw McpToolException::invalid("'granularity' must be 'year' or 'month'.");
        }

        return [
            'turnover' => $this->statisticsQueryService->turnover($startYear, $endYear, $granularity, StatisticsQueryService::DEFAULT_INVOICE_STATUS),
            'invoiceStatus' => array_map(static fn (int $status): ?string => InvoiceStatus::fromStatus($status)?->name, StatisticsQueryService::DEFAULT_INVOICE_STATUS),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_monthly_metrics',
        title: 'Monthly key figures',
        description: 'Key figures of one month as shown in the monthly statistics: stays and overnight stays, arrivals, utilization per day, guests by country, booking origins, blocked rooms, turnover (all properties only) and data quality warnings.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::STATISTICS_READ)]
    public function monthlyMetrics(
        #[Schema(description: 'Month, YYYY-MM.')]
        string $month,
        #[Schema(type: 'integer', description: 'Property (object) id; all properties when omitted.')]
        ?int $objectId = null,
    ): array {
        $monthStart = McpInput::month($month, 'month');
        $subsidiary = null;
        if (null !== $objectId) {
            $subsidiary = $this->em->getRepository(Subsidiary::class)->find($objectId);
            if (!$subsidiary instanceof Subsidiary) {
                throw McpToolException::invalid('Unknown property (object) id.');
            }
        }

        // Computed live; unlike the statistics page this does not store a snapshot.
        $result = $this->monthlyStatsService->buildMetrics((int) $monthStart->format('n'), (int) $monthStart->format('Y'), $subsidiary);

        return $result['metrics'];
    }

    private function resolveObjectId(?int $objectId): string
    {
        if (null === $objectId) {
            return 'all';
        }
        if (!$this->em->getRepository(Subsidiary::class)->find($objectId) instanceof Subsidiary) {
            throw McpToolException::invalid('Unknown property (object) id.');
        }

        return (string) $objectId;
    }
}
