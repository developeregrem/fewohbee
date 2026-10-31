<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subsidiary;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Shared helpers for operations filters (date, subsidiary, categories).
 */
class OperationsFilterService
{
    /**
     * Resolve the selected date from query input, defaulting to today (UTC).
     */
    public function resolveDate(?string $dateParam, ?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        $tz = $timezone ?? new \DateTimeZone('UTC');
        if ($dateParam) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $dateParam, $tz);
            if ($parsed instanceof \DateTimeImmutable) {
                return $parsed->setTime(0, 0, 0);
            }
        }

        return new \DateTimeImmutable('today', $tz)->setTime(0, 0, 0);
    }

    /**
     * Resolve the requested subsidiary entity, if any.
     * Only a repository lookup is required, using the manager supplied by ManagerRegistry.
     */
    public function resolveSubsidiary(ObjectManager $em, string $subsidiaryId): ?Subsidiary
    {
        if ('all' === $subsidiaryId || '' === $subsidiaryId) {
            return null;
        }

        $subsidiary = $em->getRepository(Subsidiary::class)->find($subsidiaryId);

        return $subsidiary instanceof Subsidiary ? $subsidiary : null;
    }

    /**
     * Normalize a date into its Monday week start.
     */
    public function resolveWeekStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify('monday this week')->setTime(0, 0, 0);
    }

    /**
     * Resolve the start date, defaulting to Monday of the current week.
     */
    public function resolveStartDate(?string $dateParam, ?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        $tz = $timezone ?? new \DateTimeZone('UTC');
        if ($dateParam) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $dateParam, $tz);
            if ($parsed instanceof \DateTimeImmutable) {
                return $parsed->setTime(0, 0, 0);
            }
        }

        return new \DateTimeImmutable('today', $tz)->modify('monday this week')->setTime(0, 0, 0);
    }

    /**
     * Resolve the end date, defaulting to Sunday of the start week.
     */
    public function resolveEndDate(?string $dateParam, \DateTimeImmutable $start, ?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        $tz = $timezone ?? new \DateTimeZone('UTC');
        if ($dateParam) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $dateParam, $tz);
            if ($parsed instanceof \DateTimeImmutable) {
                $end = $parsed->setTime(0, 0, 0);

                return $end < $start ? $start : $end;
            }
        }

        return $start->modify('+6 days')->setTime(0, 0, 0);
    }

    /**
     * Normalize selected categories (arrival, departure, inhouse).
     *
     * @param array<array-key, mixed> $selected Raw request or session entries
     *
     * @return list<string>
     */
    public function normalizeCategories(array $selected): array
    {
        $allowed = ['arrival', 'departure', 'inhouse'];
        $values = array_values(array_intersect($allowed, array_map('strval', $selected)));

        return [] === $values ? $allowed : $values;
    }

    /**
     * Resolve a string filter value with session fallback.
     */
    public function resolveFilterValue(
        Request $request,
        SessionInterface $session,
        string $sessionKey,
        string $queryKey,
        string $default = ''
    ): string {
        if ($request->query->has($queryKey)) {
            $value = (string) $request->query->get($queryKey, $default);
            $session->set($sessionKey, $value);

            return $value;
        }

        return (string) $session->get($sessionKey, $default);
    }

    /**
     * Resolve an array filter value with session fallback.
     * Raw keys and values are preserved; callers normalize their filter-specific contents.
     *
     * @return array<array-key, mixed>
     */
    public function resolveFilterArray(
        Request $request,
        SessionInterface $session,
        string $sessionKey,
        string $queryKey
    ): array {
        if ($request->query->has($queryKey)) {
            $value = $request->query->all($queryKey);
            $session->set($sessionKey, $value);

            return $value;
        }

        $stored = $session->get($sessionKey, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Resolve a boolean filter value with session fallback.
     */
    public function resolveFilterBool(
        Request $request,
        SessionInterface $session,
        string $sessionKey,
        string $queryKey,
        bool $default
    ): bool {
        if ($request->query->has($queryKey)) {
            $value = $request->query->getBoolean($queryKey, $default);
            $session->set($sessionKey, $value);

            return $value;
        }

        return (bool) $session->get($sessionKey, $default);
    }
}
