<?php

declare(strict_types=1);

namespace App\Mcp\Prompt;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\Schema;

/**
 * Ready-made instructions for recurring evaluations, offered by MCP clients as prompt templates.
 */
final class ReportPrompts
{
    /**
     * @return list<array{role: string, content: string}>
     */
    #[McpPrompt(
        name: 'monthly_operations_report',
        title: 'Monthly operations report',
        description: 'Guides the assistant through a monthly operations report for the guesthouse.',
    )]
    public function monthlyOperationsReport(
        #[Schema(description: 'Month of the report, YYYY-MM.')]
        string $month,
    ): array {
        // Only a YYYY-MM value is interpolated; anything else falls back to a neutral phrase.
        $period = 1 === preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : 'the last complete month';

        return [[
            'role' => 'user',
            'content' => <<<TEXT
                Create an operations report for {$period} for our guesthouse.

                1. Call get_property_overview to learn the properties and rooms.
                2. Call get_monthly_metrics for {$period} (all properties, then per property if there are several).
                3. Call get_occupancy_statistics for {$period} and the same month of the previous year for comparison.
                4. Call get_turnover with granularity "month" for the year of {$period} and the previous year, if available.
                5. Call get_tourist_tax_report for {$period}, if available.

                Structure the report as: summary with the three most important findings; occupancy and
                overnight stays (with previous-year comparison); arrivals and length of stay; booking origins;
                guests by country; turnover; tourist tax; data quality warnings from the metrics.
                Use tables where helpful, state figures with units and do not invent numbers that no tool returned.
                If a tool reports a missing permission, mention it and continue with the rest.
                TEXT,
        ]];
    }
}
