<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Entity\Customer;
use App\Entity\Reservation;
use App\Repository\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Finds existing guests with strong identity evidence or one unambiguous name candidate. */
final class GuestCheckInExistingGuestMatcher
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Excludes guests already available as reservation targets. A unique name-only match may be
     * offered for manual review, never automatically selected. Conflicting known birth dates
     * rule candidates out.
     *
     * @param array<string, mixed> $submitted
     *
     * @return list<Customer>
     */
    public function matchingCandidates(Reservation $reservation, array $submitted): array
    {
        if (!GuestCheckInPersonMatch::hasFullName($submitted)) {
            return [];
        }

        $repository = $this->em->getRepository(Customer::class);
        if (!$repository instanceof CustomerRepository) {
            throw new \LogicException('Customer repository unavailable.');
        }

        $strongMatches = [];
        $nameOnlyMatches = [];
        $birthday = self::normalized($submitted['birthday'] ?? null);
        foreach ($repository->findCheckInCandidatesByName((string) $submitted['firstname'], (string) $submitted['lastname']) as $candidate) {
            if ($candidate === $reservation->getBooker() || $reservation->getCustomers()->contains($candidate)
                || !GuestCheckInPersonMatch::sameName($submitted, $candidate)) {
                continue;
            }
            $storedBirthday = $candidate->getBirthday()?->format('Y-m-d');
            if (null !== $birthday && null !== $storedBirthday && $birthday !== $storedBirthday) {
                continue;
            }
            if ($this->hasStrongMatch($submitted, $candidate)) {
                $strongMatches[] = $candidate;
            } else {
                $nameOnlyMatches[] = $candidate;
            }
        }

        return [] !== $strongMatches ? $strongMatches : (1 === \count($nameOnlyMatches) ? $nameOnlyMatches : []);
    }

    /**
     * Name plus matching birth date or email; conflicting known birth dates exclude a match.
     *
     * @param array<string, mixed> $submitted
     */
    public function hasStrongMatch(array $submitted, Customer $candidate): bool
    {
        if (!GuestCheckInPersonMatch::sameName($submitted, $candidate)) {
            return false;
        }

        $birthday = self::normalized($submitted['birthday'] ?? null);
        $storedBirthday = $candidate->getBirthday()?->format('Y-m-d');
        if (null !== $birthday && null !== $storedBirthday && $birthday !== $storedBirthday) {
            return false;
        }
        if (null !== $birthday && $birthday === $storedBirthday) {
            return true;
        }

        $email = self::normalized($submitted['email'] ?? null);
        if (null !== $email) {
            foreach ($candidate->getCustomerAddresses() as $address) {
                if ($email === self::normalized($address->getEmail())) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $submitted */
    public function matchingCandidateById(Reservation $reservation, array $submitted, int $id): ?Customer
    {
        foreach ($this->matchingCandidates($reservation, $submitted) as $candidate) {
            if ($candidate->getId() === $id) {
                return $candidate;
            }
        }

        return null;
    }

    private static function normalized(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? mb_strtolower(trim($value)) : null;
    }
}
