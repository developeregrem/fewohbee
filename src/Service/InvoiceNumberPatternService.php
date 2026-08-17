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

namespace App\Service;

use App\Dto\InvoiceNumberPattern;
use App\Service\Exception\InvalidInvoiceNumberPatternException;

/**
 * Parses and validates user-written invoice number patterns such as `RE-<year>-<number:4>`.
 *
 * The placeholder syntax deliberately mirrors the invoice filename pattern
 * ({@see InvoiceService::buildInvoiceExportFilename()}) so operators only have to learn
 * one convention. Unlike the filename pattern there are no `|` fallback chains: a
 * fallback would make the rendered prefix non-deterministic, and the running sequence is
 * derived from exactly that prefix.
 *
 * Intentionally free of constructor dependencies so it can be instantiated directly in
 * tests and injected anywhere without risking a service cycle.
 */
final class InvoiceNumberPatternService
{
    public const DEFAULT_PATTERN = '<year>-<number:4>';

    /**
     * Placeholder name => segment kind. `number` is handled separately because it
     * carries an optional width.
     */
    private const DATE_PLACEHOLDERS = [
        'year' => InvoiceNumberPattern::KIND_YEAR,
        'year2' => InvoiceNumberPattern::KIND_YEAR2,
        'month' => InvoiceNumberPattern::KIND_MONTH,
        'day' => InvoiceNumberPattern::KIND_DAY,
    ];

    /**
     * Matches a single placeholder including an optional `:width` suffix.
     */
    private const PLACEHOLDER_REGEX = '/<([a-zA-Z][a-zA-Z0-9]*)(?::(\d+))?>/';

    /**
     * Compiles a pattern into its segment list.
     *
     * @throws InvalidInvoiceNumberPatternException when the pattern is empty, lacks a
     *                                              `<number>` placeholder, carries more
     *                                              than one, uses an unknown placeholder
     *                                              or an out-of-range width
     */
    public function compile(string $pattern): InvoiceNumberPattern
    {
        $pattern = trim($pattern);
        if ('' === $pattern) {
            throw new InvalidInvoiceNumberPatternException('invoice_number_pattern.empty');
        }

        $segments = [];
        $numberCount = 0;
        $offset = 0;

        while (preg_match(self::PLACEHOLDER_REGEX, $pattern, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = (int) $match[0][1];
            $token = strtolower($match[1][0]);
            // With PREG_OFFSET_CAPTURE a group that did not participate carries offset -1.
            $width = isset($match[2]) && -1 !== $match[2][1] ? (int) $match[2][0] : null;

            if ($start > $offset) {
                $segments[] = $this->literal(substr($pattern, $offset, $start - $offset));
            }

            if ('number' === $token) {
                ++$numberCount;
                $segments[] = $this->numberSegment($width);
            } elseif (isset(self::DATE_PLACEHOLDERS[$token])) {
                if (null !== $width) {
                    throw new InvalidInvoiceNumberPatternException(
                        'invoice_number_pattern.width_not_allowed',
                        ['%placeholder%' => $token],
                    );
                }
                $segments[] = ['kind' => self::DATE_PLACEHOLDERS[$token], 'value' => '', 'width' => 0];
            } else {
                throw new InvalidInvoiceNumberPatternException(
                    'invoice_number_pattern.unknown_placeholder',
                    ['%placeholder%' => $match[0][0]],
                );
            }

            $offset = $start + strlen($match[0][0]);
        }

        if ($offset < strlen($pattern)) {
            $segments[] = $this->literal(substr($pattern, $offset));
        }

        if (0 === $numberCount) {
            throw new InvalidInvoiceNumberPatternException('invoice_number_pattern.missing_number');
        }
        if ($numberCount > 1) {
            throw new InvalidInvoiceNumberPatternException('invoice_number_pattern.duplicate_number');
        }

        return new InvoiceNumberPattern($pattern, $segments);
    }

    /**
     * Compiles without throwing — for the many callers that must treat an unusable
     * pattern as "not configured" rather than as an error. Returns null for null, an
     * empty string and anything compile() would reject.
     */
    public function tryCompile(?string $pattern): ?InvoiceNumberPattern
    {
        if (null === $pattern || '' === trim($pattern)) {
            return null;
        }

        try {
            return $this->compile($pattern);
        } catch (InvalidInvoiceNumberPatternException) {
            return null;
        }
    }

    /**
     * Collects everything wrong with a pattern for display in a settings form.
     *
     * Errors block saving; the single warning does not — a pattern with `<month>` but no
     * year is legal, it just makes the sequence restart every month and collide across
     * years, which the operator may well intend.
     *
     * An empty pattern yields no findings at all: it means "not configured" and falls
     * back to the global default or the legacy increment.
     *
     * @return list<array{key: string, params: array<string, string>, severity: 'error'|'warning'}>
     */
    public function validate(string $pattern): array
    {
        if ('' === trim($pattern)) {
            return [];
        }

        try {
            $compiled = $this->compile($pattern);
        } catch (InvalidInvoiceNumberPatternException $e) {
            return [[
                'key' => $e->getTranslationKey(),
                'params' => $e->getTranslationParameters(),
                'severity' => 'error',
            ]];
        }

        $findings = [];

        $kinds = array_column($compiled->segments, 'kind');
        $hasYear = \in_array(InvoiceNumberPattern::KIND_YEAR, $kinds, true)
            || \in_array(InvoiceNumberPattern::KIND_YEAR2, $kinds, true);
        $hasSubYear = \in_array(InvoiceNumberPattern::KIND_MONTH, $kinds, true)
            || \in_array(InvoiceNumberPattern::KIND_DAY, $kinds, true);

        if ($hasSubYear && !$hasYear) {
            $findings[] = [
                'key' => 'invoice_number_pattern.month_without_year',
                'params' => [],
                'severity' => 'warning',
            ];
        }

        // The rendered number has to survive Invoice::$number, which is VARCHAR(45).
        $rendered = $compiled->render(new \DateTime(), 10 ** $compiled->numberWidth() - 1);
        if (strlen($rendered) > 45) {
            $findings[] = [
                'key' => 'invoice_number_pattern.too_long',
                'params' => ['%max%' => '45'],
                'severity' => 'error',
            ];
        }

        return $findings;
    }

    /**
     * True when the pattern has no error-severity finding, i.e. it can be saved.
     */
    public function isValid(string $pattern): bool
    {
        foreach ($this->validate($pattern) as $finding) {
            if ('error' === $finding['severity']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{kind: string, value: string, width: int}
     */
    private function literal(string $value): array
    {
        return ['kind' => InvoiceNumberPattern::KIND_LITERAL, 'value' => $value, 'width' => 0];
    }

    /**
     * @return array{kind: string, value: string, width: int}
     *
     * @throws InvalidInvoiceNumberPatternException
     */
    private function numberSegment(?int $width): array
    {
        $width ??= InvoiceNumberPattern::DEFAULT_NUMBER_WIDTH;

        if ($width < 1 || $width > InvoiceNumberPattern::MAX_NUMBER_WIDTH) {
            throw new InvalidInvoiceNumberPatternException(
                'invoice_number_pattern.invalid_width',
                ['%max%' => (string) InvoiceNumberPattern::MAX_NUMBER_WIDTH],
            );
        }

        return ['kind' => InvoiceNumberPattern::KIND_NUMBER, 'value' => '', 'width' => $width];
    }
}
