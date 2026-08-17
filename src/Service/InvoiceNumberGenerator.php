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
use App\Entity\Subsidiary;
use App\Repository\InvoiceRepository;
use App\Repository\SubsidiaryRepository;

/**
 * Issues the next invoice number of a branch's number range.
 *
 * The sequence is derived rather than stored: the configured pattern is rendered for the
 * invoice date, every number already issued in that same period is read back, and the
 * highest sequence found is incremented. That keeps a year change resetting the counter
 * by itself, survives manual corrections, and leaves no gaps behind abandoned invoice
 * drafts — at the cost of not being collision-free under true concurrency, which the
 * duplicate check at save time absorbs.
 *
 * Deliberately does not depend on InvoiceService: that service injects this one.
 */
final class InvoiceNumberGenerator
{
    /**
     * How many taken numbers to skip before giving up. Guards against a collision with a
     * neighbouring range whose rendered numbers happen to overlap.
     */
    private const MAX_COLLISION_RETRIES = 10;

    public function __construct(
        private readonly AppSettingsService $appSettingsService,
        private readonly SubsidiaryRepository $subsidiaryRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly InvoiceNumberPatternService $patternService,
    ) {
    }

    /**
     * Number range in effect for a branch: its own pattern if set, otherwise the global
     * default. Null when neither is configured, or when the configured pattern is
     * unusable — callers then fall back to the legacy increment.
     */
    public function resolvePattern(?Subsidiary $subsidiary): ?InvoiceNumberPattern
    {
        $branchPattern = $this->patternService->tryCompile($subsidiary?->getInvoiceNumberPattern());
        if (null !== $branchPattern) {
            return $branchPattern;
        }

        return $this->patternService->tryCompile(
            $this->appSettingsService->getSettings()->getInvoiceNumberPattern()
        );
    }

    /**
     * Next free number in the branch's range for the given date, or null when no range
     * is configured.
     *
     * Reads the highest sequence already issued in the same period and adds one, then
     * skips numbers that are already taken (a manual entry can have claimed one).
     */
    public function generateNext(?Subsidiary $subsidiary, \DateTimeInterface $date): ?string
    {
        $pattern = $this->resolvePattern($subsidiary);
        if (null === $pattern) {
            return null;
        }

        $sequence = $this->highestSequence($pattern, $date) + 1;

        for ($attempt = 0; $attempt < self::MAX_COLLISION_RETRIES; ++$attempt) {
            $candidate = $pattern->render($date, $sequence);
            if (0 === $this->invoiceRepository->countByNumber($candidate)) {
                return $candidate;
            }
            ++$sequence;
        }

        // Every candidate was taken — hand back the last one and let the duplicate check
        // at save time surface the problem rather than silently issuing a collision.
        return $pattern->render($date, $sequence);
    }

    /**
     * Every configured pattern — the global default plus each branch override —
     * deduplicated. Used by the bank import to build its matcher, and by the settings
     * screen to show which ranges are recognised.
     *
     * @return list<string>
     */
    public function allConfiguredPatterns(): array
    {
        $patterns = [];

        $global = $this->appSettingsService->getSettings()->getInvoiceNumberPattern();
        if (null !== $global && '' !== trim($global)) {
            $patterns[] = trim($global);
        }

        foreach ($this->subsidiaryRepository->findConfiguredPatterns() as $pattern) {
            $patterns[] = trim($pattern);
        }

        // Keep only the ones that actually compile, so an unusable pattern never turns
        // into a broken regex during import.
        $usable = array_filter(
            array_unique($patterns),
            fn (string $pattern): bool => null !== $this->patternService->tryCompile($pattern),
        );

        return array_values($usable);
    }

    public function hasConfiguredPattern(): bool
    {
        return [] !== $this->allConfiguredPatterns();
    }

    /**
     * Highest sequence already issued in the period $date falls into, or 0 when the
     * range is still empty. Numbers inside the LIKE window that do not parse — hand-edited
     * entries such as '2026-storno' — are ignored.
     */
    private function highestSequence(InvoiceNumberPattern $pattern, \DateTimeInterface $date): int
    {
        $highest = 0;
        foreach ($this->invoiceRepository->findNumbersInRange($pattern->likePattern($date)) as $number) {
            $sequence = $pattern->extractSequence($number, $date);
            if (null !== $sequence && $sequence > $highest) {
                $highest = $sequence;
            }
        }

        return $highest;
    }
}
