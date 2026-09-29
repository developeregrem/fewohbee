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

    /**
     * @return list<array{role: string, content: string}>
     */
    #[McpPrompt(
        name: 'daily_operations_briefing',
        title: 'Daily operations briefing',
        description: 'Guides the assistant through the daily briefing for front desk, housekeeping and breakfast.',
    )]
    public function dailyOperationsBriefing(
        #[Schema(description: 'Day of the briefing, YYYY-MM-DD.')]
        string $date,
    ): array {
        // Only a valid YYYY-MM-DD value is interpolated; anything else falls back to a neutral phrase.
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $day = false !== $parsed && $parsed->format('Y-m-d') === $date ? $date : 'today';

        return [[
            'role' => 'user',
            'content' => <<<TEXT
                Create the daily operations briefing for {$day} for our guesthouse.

                1. Call get_property_overview to learn the properties and rooms.
                2. Call get_operations_report for {$day}.

                Structure the briefing as: arrivals (room, guests, arrival time, extras); departures; rooms to
                clean, turnovers first because the next guests arrive the same day; stayovers; blocked rooms;
                breakfast and other booked extras with the number of persons; open housekeeping tasks and notes.
                Keep it short enough to print on one page. Do not invent names or numbers that no tool returned.
                If guest names are missing, the access token may not share guest data: use room numbers instead.
                TEXT,
        ]];
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    #[McpPrompt(
        name: 'price_review',
        title: 'Price review',
        description: 'Guides the assistant through a review of the room prices for an upcoming period, with suggestions for special-period prices.',
    )]
    public function priceReview(
        #[Schema(description: 'First month to review, YYYY-MM.')]
        string $month,
        #[Schema(description: 'Number of months to review, 1 to 12.', minimum: 1, maximum: 12)]
        int $months = 3,
    ): array {
        // Only a YYYY-MM value and a bounded number are interpolated.
        $period = 1 === preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : 'the next month';
        $months = max(1, min(12, $months));

        return [[
            'role' => 'user',
            'content' => <<<TEXT
                Review our room prices for {$months} month(s) starting with {$period}.

                1. Call get_property_overview to learn the properties, rooms and room categories.
                2. Call get_occupancy_forecast for the period to see booked and free rooms per night.
                3. Call get_booking_pace for each month of the period to compare with last year.
                4. Call get_rate_calendar per room category for the period, and get_price_rules to see which
                   price rows (seasons, weekdays, special periods, minimum stays) produce these rates.

                Then add what you know about the region: public and school holidays, trade fairs, festivals and
                other events in the period. Name your source or say that it is general knowledge.

                Report: occupancy and pace per month; nights or periods with unusually high or low demand;
                concrete suggestions (period, room category, current rate, suggested rate, minimum stay) with a
                short reason each. Suggest special-period prices rather than changes to the base price.
                Do not change anything: the prices are adjusted by a person in FewohBee. Do not invent numbers
                that no tool returned.
                TEXT,
        ]];
    }
}
