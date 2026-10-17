<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Entity\Customer;

/** Conservative matching for review suggestions; a name is never used to load foreign guests. */
final class GuestCheckInPersonMatch
{
    /** @param array<string, mixed> $submitted */
    public static function hasFullName(array $submitted): bool
    {
        return '' !== self::normalize($submitted['firstname'] ?? null)
            && '' !== self::normalize($submitted['lastname'] ?? null);
    }

    /** @param array<string, mixed> $submitted */
    public static function sameName(array $submitted, Customer $customer): bool
    {
        return self::hasFullName($submitted)
            && self::normalize($submitted['firstname']) === self::normalize($customer->getFirstname())
            && self::normalize($submitted['lastname']) === self::normalize($customer->getLastname());
    }

    /**
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    public static function sameSubmittedName(array $first, array $second): bool
    {
        return self::hasFullName($first)
            && self::normalize($first['firstname']) === self::normalize($second['firstname'] ?? null)
            && self::normalize($first['lastname']) === self::normalize($second['lastname'] ?? null);
    }

    /** @param array<string, mixed> $submitted */
    public static function likelySamePerson(array $submitted, Customer $customer): bool
    {
        if (!self::sameName($submitted, $customer)) {
            return false;
        }

        $birthday = $submitted['birthday'] ?? null;

        return !\is_string($birthday) || '' === $birthday || null === $customer->getBirthday()
            || $birthday === $customer->getBirthday()->format('Y-m-d');
    }

    private static function normalize(mixed $value): string
    {
        if (!\is_string($value)) {
            return '';
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
