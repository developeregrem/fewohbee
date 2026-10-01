<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Dto\GuestCheckIn\GuestCheckInApplyRequest;
use App\Entity\Customer;
use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\Enum\IDCardType;
use App\Entity\Reservation;
use App\Repository\GuestCheckInRepository;
use App\Service\PublicUrlService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Data for the "Check-in" tab of the reservation dialog: state, link availability and
 * the submitted details next to what the guest records hold today.
 */
class GuestCheckInReviewService
{
    public function __construct(
        private readonly GuestCheckInRepository $repository,
        private readonly GuestCheckInConfigService $configService,
        private readonly GuestCheckInPolicy $policy,
        private readonly PublicUrlService $publicUrlService,
        private readonly TranslatorInterface $translator,
        private readonly GuestCheckInExtrasService $extrasService,
        private readonly GuestCheckInExistingGuestMatcher $existingGuestMatcher,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $installationLocale,
    ) {
    }

    /**
     * Null when the tab has nothing to show: feature off and nothing was ever submitted.
     *
     * @return array<string, mixed>|null
     */
    public function buildTab(Reservation $reservation, bool $maySeeGlobalCandidates = false): ?array
    {
        $checkIn = $this->repository->findOneByReservation($reservation);
        $enabled = $this->configService->isEnabled();
        if (!$enabled && (null === $checkIn || GuestCheckInStatus::OPEN === $checkIn->getStatus())) {
            return null;
        }

        $state = $this->policy->linkState($reservation, $checkIn, $enabled);
        $payload = $checkIn?->getPayload();
        $mainGuest = \is_array($payload['mainGuest'] ?? null) ? $payload['mainGuest'] : [];
        $mainGlobalCandidates = $maySeeGlobalCandidates && null !== $payload
            ? $this->existingGuestMatcher->matchingCandidates($reservation, $mainGuest)
            : [];
        [$defaultMainTarget, $comparisonGuest] = $this->suggestMainTarget($reservation, $mainGuest, $mainGlobalCandidates);
        $mainNameOnlyMatch = $this->hasNameOnlyCandidate($mainGuest, $mainGlobalCandidates);
        $bookerDiffers = null !== $reservation->getBooker()
            && GuestCheckInPersonMatch::hasFullName($mainGuest)
            && !GuestCheckInPersonMatch::likelySamePerson($mainGuest, $reservation->getBooker());
        $bookerRows = null !== $payload && null !== $reservation->getBooker()
            ? $this->mainGuestRows($mainGuest, $reservation->getBooker())
            : [];
        $bookerNeedsUpdate = false;
        foreach ($bookerRows as $row) {
            if ($row['differs']) {
                $bookerNeedsUpdate = true;
                break;
            }
        }
        $extras = \is_array($payload['extras'] ?? null) ? $payload['extras'] : [];
        $extrasConflict = false;
        if ([] !== $extras) {
            try {
                $this->extrasService->pricesToApply($reservation, $extras);
            } catch (\InvalidArgumentException) {
                $extrasConflict = true;
            }
        }

        return [
            'checkIn' => $checkIn,
            'status' => $checkIn?->getStatus() ?? GuestCheckInStatus::OPEN,
            'linkAvailable' => GuestCheckInLinkState::UNAVAILABLE !== $state && null !== $this->publicUrlService->getBaseUrl(),
            'unavailableReason' => $this->unavailableReason($state, $enabled),
            'arrivalTime' => $payload['arrivalTime'] ?? null,
            'message' => $payload['message'] ?? null,
            'extras' => $extras,
            'extrasConflict' => $extrasConflict,
            'mainGuestRows' => null !== $payload ? $this->mainGuestRows($mainGuest, $comparisonGuest) : [],
            'comparisonGuestName' => null !== $comparisonGuest ? self::name($comparisonGuest) : null,
            'mainGlobalTargets' => $this->globalTargets($mainGlobalCandidates),
            'mainNeedsChoice' => '' === $defaultMainTarget,
            'mainNameOnlyMatch' => $mainNameOnlyMatch,
            'bookerDiffers' => $bookerDiffers,
            'bookerNeedsUpdate' => $bookerNeedsUpdate,
            'bookerIsGuest' => null !== $reservation->getBooker() && $reservation->getCustomers()->contains($reservation->getBooker()),
            'companions' => null !== $payload ? $this->companions($payload['companions'] ?? [], $reservation, $defaultMainTarget, $maySeeGlobalCandidates) : [],
            'mainTargets' => $this->mainTargets($reservation),
            'defaultMainTarget' => $defaultMainTarget,
            'companionTargets' => $this->companionTargets($reservation, $defaultMainTarget),
        ];
    }

    /**
     * Prefer an unambiguous identity match. Multiple matches require an explicit choice;
     * without a match, suggest a new guest instead of overwriting another person.
     *
     * @param array<string, mixed> $submitted
     * @param list<Customer>       $globalCandidates
     *
     * @return array{string, ?Customer}
     */
    private function suggestMainTarget(Reservation $reservation, array $submitted, array $globalCandidates): array
    {
        $booker = $reservation->getBooker();
        if (!GuestCheckInPersonMatch::hasFullName($submitted)) {
            return null !== $booker
                ? [GuestCheckInApplyRequest::TARGET_BOOKER, $booker]
                : [GuestCheckInApplyRequest::TARGET_NEW, null];
        }

        $strong = [];
        $weak = [];
        $nameOnlyGlobal = false;
        if (null !== $booker && GuestCheckInPersonMatch::likelySamePerson($submitted, $booker)) {
            $target = GuestCheckInApplyRequest::TARGET_BOOKER;
            if ($this->existingGuestMatcher->hasStrongMatch($submitted, $booker)) {
                $strong[$target] = $booker;
            } else {
                $weak[$target] = $booker;
            }
        }
        foreach ($reservation->getCustomers() as $customer) {
            if ($customer !== $booker && GuestCheckInPersonMatch::likelySamePerson($submitted, $customer)) {
                $target = GuestCheckInApplyRequest::TARGET_CUSTOMER_PREFIX.$customer->getId();
                if ($this->existingGuestMatcher->hasStrongMatch($submitted, $customer)) {
                    $strong[$target] = $customer;
                } else {
                    $weak[$target] = $customer;
                }
            }
        }
        foreach ($globalCandidates as $candidate) {
            if ($this->existingGuestMatcher->hasStrongMatch($submitted, $candidate)) {
                $strong[GuestCheckInApplyRequest::TARGET_EXISTING_PREFIX.$candidate->getId()] = $candidate;
            } else {
                $nameOnlyGlobal = true;
            }
        }

        if ([] !== $strong) {
            return self::uniqueTarget($strong);
        }

        if ([] !== $weak) {
            return self::uniqueTarget($weak);
        }

        return [$nameOnlyGlobal ? '' : GuestCheckInApplyRequest::TARGET_NEW, null];
    }

    /** @param array<string, Customer> $matches
     * @return array{string, ?Customer}
     */
    private static function uniqueTarget(array $matches): array
    {
        if ([] === $matches) {
            return [GuestCheckInApplyRequest::TARGET_NEW, null];
        }
        if (1 !== \count($matches)) {
            return ['', null];
        }

        $target = array_key_first($matches);

        return [$target, $matches[$target]];
    }

    private function unavailableReason(GuestCheckInLinkState $state, bool $enabled): ?string
    {
        return match (true) {
            !$enabled => 'guest_checkin.tab.unavailable_disabled',
            null === $this->publicUrlService->getBaseUrl() => 'guest_checkin.tab.unavailable_address',
            GuestCheckInLinkState::UNAVAILABLE === $state => 'guest_checkin.tab.unavailable_reservation',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $guest
     *
     * @return list<array{label: string, submitted: ?string, current: ?string, differs: bool}>
     */
    private function mainGuestRows(array $guest, ?Customer $currentGuest): array
    {
        $address = \is_array($guest['address'] ?? null) ? $guest['address'] : [];
        $currentAddress = null !== $currentGuest ? GuestCheckInApplyService::preferredAddress($currentGuest) : null;
        $idType = IDCardType::tryFrom((string) ($guest['idType'] ?? ''));
        // Stored the way GuestCheckInApplyService will write it, so equal values do not look changed.
        $salutation = \is_string($guest['salutation'] ?? null) ? $this->translator->trans($guest['salutation'], [], null, $this->installationLocale) : null;

        $rows = [
            ['customer.salutation', $salutation, $currentGuest?->getSalutation()],
            ['customer.firstname', $guest['firstname'] ?? null, $currentGuest?->getFirstname()],
            ['customer.lastname', $guest['lastname'] ?? null, $currentGuest?->getLastname()],
            ['customer.birthday', self::date($guest['birthday'] ?? null), $currentGuest?->getBirthday()?->format('d.m.Y')],
            ['customer.nationality', $guest['nationality'] ?? null, $currentGuest?->getNationality()],
            ['customer.id.type.name', $idType?->value, $currentGuest?->getIdType()?->value],
            ['customer.id.number', $guest['idNumber'] ?? null, $currentGuest?->getIDNumber()],
            ['customer.address', $address['street'] ?? null, $currentAddress?->getAddress()],
            ['customer.zip', $address['zip'] ?? null, $currentAddress?->getZip()],
            ['customer.city', $address['city'] ?? null, $currentAddress?->getCity()],
            ['customer.country', $address['country'] ?? null, $currentAddress?->getCountry()],
            ['customer.email', $guest['email'] ?? null, $currentAddress?->getEmail()],
            ['customer.phone', $guest['phone'] ?? null, $currentAddress?->getPhone()],
        ];

        $result = [];
        foreach ($rows as [$label, $submitted, $current]) {
            $submitted = self::text($submitted);
            $current = self::text($current);
            if (null === $submitted && null === $current) {
                continue;
            }
            $result[] = [
                'label' => $label,
                'submitted' => $submitted,
                'current' => $current,
                'differs' => null !== $submitted && mb_strtolower($submitted) !== mb_strtolower((string) $current),
            ];
        }

        return $result;
    }

    /**
     * @param array<mixed> $companions
     *
     * @return list<array{name: string, salutation: ?string, birthday: ?string, nationality: ?string, address: ?string, defaultTarget: string, globalTargets: array<string, array{id: int, name: string}>, needsChoice: bool, nameOnlyMatch: bool}>
     */
    private function companions(array $companions, Reservation $reservation, string $defaultMainTarget, bool $maySeeGlobalCandidates): array
    {
        $result = [];
        foreach ($companions as $companion) {
            if (!\is_array($companion)) {
                continue;
            }
            $firstname = self::text($companion['firstname'] ?? null);
            $lastname = self::text($companion['lastname'] ?? null);
            $globalCandidates = $maySeeGlobalCandidates ? $this->existingGuestMatcher->matchingCandidates($reservation, $companion) : [];
            $defaultTarget = $this->matchingGuest($reservation, $companion, $defaultMainTarget, $globalCandidates);
            $result[] = [
                'name' => trim($firstname.' '.$lastname),
                'salutation' => \is_string($companion['salutation'] ?? null)
                    ? $this->translator->trans($companion['salutation'], [], null, $this->installationLocale)
                    : null,
                'birthday' => self::date($companion['birthday'] ?? null),
                'nationality' => self::text($companion['nationality'] ?? null),
                // Null: lives with the main guest.
                'address' => \is_array($companion['address'] ?? null) ? self::addressLine($companion['address']) : null,
                'defaultTarget' => $defaultTarget,
                'globalTargets' => $this->globalTargets($globalCandidates),
                'needsChoice' => '' === $defaultTarget,
                'nameOnlyMatch' => $this->hasNameOnlyCandidate($companion, $globalCandidates),
            ];
        }

        return $result;
    }

    /** @param array<string, mixed> $submitted
     * @param list<Customer> $globalCandidates
     */
    private function matchingGuest(Reservation $reservation, array $submitted, string $defaultMainTarget, array $globalCandidates): string
    {
        $strong = [];
        $weak = [];
        $nameOnlyGlobal = false;
        foreach ($reservation->getCustomers() as $customer) {
            if (!GuestCheckInPersonMatch::likelySamePerson($submitted, $customer)) {
                continue;
            }
            $target = $customer === $reservation->getBooker()
                ? GuestCheckInApplyRequest::TARGET_BOOKER
                : GuestCheckInApplyRequest::TARGET_CUSTOMER_PREFIX.$customer->getId();
            if ($target === $defaultMainTarget) {
                continue;
            }
            if ($this->existingGuestMatcher->hasStrongMatch($submitted, $customer)) {
                $strong[$target] = $customer;
            } else {
                $weak[$target] = $customer;
            }
        }
        foreach ($globalCandidates as $candidate) {
            $target = GuestCheckInApplyRequest::TARGET_EXISTING_PREFIX.$candidate->getId();
            if ($target !== $defaultMainTarget) {
                if ($this->existingGuestMatcher->hasStrongMatch($submitted, $candidate)) {
                    $strong[$target] = $candidate;
                } else {
                    $nameOnlyGlobal = true;
                }
            }
        }

        if ([] !== $strong) {
            return self::uniqueTarget($strong)[0];
        }
        if ([] !== $weak) {
            return self::uniqueTarget($weak)[0];
        }

        return $nameOnlyGlobal ? '' : GuestCheckInApplyRequest::TARGET_NEW;
    }

    /** @param array<string, mixed> $submitted
     * @param list<Customer> $globalCandidates
     */
    private function hasNameOnlyCandidate(array $submitted, array $globalCandidates): bool
    {
        return 1 === \count($globalCandidates)
            && !$this->existingGuestMatcher->hasStrongMatch($submitted, $globalCandidates[0]);
    }

    /**
     * @param list<Customer> $candidates
     *
     * @return array<string, array{id: int, name: string}>
     */
    private function globalTargets(array $candidates): array
    {
        $targets = [];
        foreach ($candidates as $candidate) {
            $targets[GuestCheckInApplyRequest::TARGET_EXISTING_PREFIX.$candidate->getId()] = [
                'id' => (int) $candidate->getId(),
                'name' => self::name($candidate),
            ];
        }

        return $targets;
    }

    /** @return array<string, string> target => label (customer name) */
    private function mainTargets(Reservation $reservation): array
    {
        $targets = [];
        if (null !== $reservation->getBooker()) {
            $targets[GuestCheckInApplyRequest::TARGET_BOOKER] = self::name($reservation->getBooker());
        }

        return $targets + $this->linkedGuests($reservation);
    }

    /** @return array<string, string> */
    private function companionTargets(Reservation $reservation, string $defaultMainTarget): array
    {
        $targets = $this->linkedGuests($reservation);
        $booker = $reservation->getBooker();
        if (GuestCheckInApplyRequest::TARGET_BOOKER !== $defaultMainTarget && null !== $booker && $reservation->getCustomers()->contains($booker)) {
            $targets[GuestCheckInApplyRequest::TARGET_BOOKER] = self::name($booker);
        }

        return $targets;
    }

    /** @return array<string, string> */
    private function linkedGuests(Reservation $reservation): array
    {
        $targets = [];
        foreach ($reservation->getCustomers() as $customer) {
            if ($customer !== $reservation->getBooker()) {
                $targets[GuestCheckInApplyRequest::TARGET_CUSTOMER_PREFIX.$customer->getId()] = self::name($customer);
            }
        }

        return $targets;
    }

    /** @param array<string, mixed> $address */
    private static function addressLine(array $address): string
    {
        $cityLine = trim(self::text($address['zip'] ?? null).' '.self::text($address['city'] ?? null));

        return implode(', ', array_filter([self::text($address['street'] ?? null), $cityLine, self::text($address['country'] ?? null)]));
    }

    private static function name(Customer $customer): string
    {
        return trim($customer->getFirstname().' '.$customer->getLastname());
    }

    private static function date(mixed $value): ?string
    {
        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return false === $date ? null : $date->format('d.m.Y');
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? $value : null;
    }
}
