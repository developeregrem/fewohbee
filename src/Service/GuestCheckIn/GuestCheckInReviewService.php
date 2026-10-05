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
 * Data for the "Check-in" tab of the reservation dialog.
 *
 * While a submission waits for review, every submitted person comes with each record it could be
 * stored as and, per record, what confirming would write there — the tab shows the changes for
 * whichever record the hotelier picks. A record is preselected only on an unambiguous identity
 * match, never one whose name differs from the submission.
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
        private readonly GuestCheckInInvitationService $invitationService,
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
        $status = $checkIn?->getStatus() ?? GuestCheckInStatus::OPEN;
        $payload = $checkIn?->getPayload();
        $unavailableReason = $this->unavailableReason($state, $enabled);

        return [
            'checkIn' => $checkIn,
            'status' => $status,
            'unavailableReason' => $unavailableReason,
            // Only worth telling while the guest still has to act.
            'invitation' => GuestCheckInStatus::OPEN === $status && null === $unavailableReason ? [
                'workflows' => $this->invitationService->findInvitationWorkflows(),
                'sentAt' => $this->invitationService->lastSentAt($reservation),
            ] : null,
            'review' => GuestCheckInStatus::SUBMITTED === $status && null !== $payload
                ? $this->review($reservation, $payload, $maySeeGlobalCandidates)
                : null,
        ];
    }

    /**
     * "simple" means nothing needs a decision: every person has a unique suggestion, nobody
     * linked to the room is left unaccounted for, everyone fits the booked number of guests and
     * the requested services can be booked as shown.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function review(Reservation $reservation, array $payload, bool $maySeeGlobalCandidates): array
    {
        $booker = $reservation->getBooker();
        $main = \is_array($payload['mainGuest'] ?? null) ? $payload['mainGuest'] : [];
        $mainAddress = \is_array($main['address'] ?? null) ? $main['address'] : [];
        $mainCandidates = $maySeeGlobalCandidates ? $this->existingGuestMatcher->matchingCandidates($reservation, $main) : [];
        $mainDefault = $this->suggestMainTarget($reservation, $main, $mainCandidates);

        $persons = [$this->person($reservation, $main, true, $mainDefault, $this->mainTargets($reservation, $mainCandidates), $mainCandidates, $mainAddress)];
        foreach (\is_array($payload['companions'] ?? null) ? $payload['companions'] : [] as $companion) {
            if (!\is_array($companion)) {
                continue;
            }
            $candidates = $maySeeGlobalCandidates ? $this->existingGuestMatcher->matchingCandidates($reservation, $companion) : [];
            $default = $this->matchingGuest($reservation, $companion, $mainDefault, $candidates);
            $persons[] = $this->person($reservation, $companion, false, $default, $this->companionTargets($reservation, $candidates), $candidates, $mainAddress);
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

        $roomGuests = [];
        foreach ($reservation->getCustomers() as $customer) {
            $roomGuests[] = ['id' => (int) $customer->getId(), 'name' => self::name($customer), 'isBooker' => $customer === $booker];
        }

        return [
            'arrivalTime' => $payload['arrivalTime'] ?? null,
            'message' => $payload['message'] ?? null,
            'extras' => $extras,
            'extrasConflict' => $extrasConflict,
            'persons' => $persons,
            'roomGuests' => $roomGuests,
            'capacity' => $reservation->getTotalGuests(),
            'simple' => !$extrasConflict && $this->fitsWithSuggestions($reservation, $persons),
        ];
    }

    /**
     * @param array<string, mixed>                                          $submitted
     * @param list<array{value: string, kind: string, customer: ?Customer}> $targets
     * @param list<Customer>                                                $globalCandidates
     * @param array<string, mixed>                                          $mainAddress
     *
     * @return array<string, mixed>
     */
    private function person(Reservation $reservation, array $submitted, bool $isMain, string $defaultTarget, array $targets, array $globalCandidates, array $mainAddress): array
    {
        $options = [];
        foreach ($targets as ['value' => $value, 'kind' => $kind, 'customer' => $customer]) {
            $options[] = [
                'value' => $value,
                'kind' => $kind,
                'name' => null !== $customer ? self::name($customer) : null,
                'customerId' => $customer?->getId(),
                'inRoom' => null !== $customer && $reservation->getCustomers()->contains($customer),
                // Picking a record of somebody else replaces that person's details.
                'overwrites' => null !== $customer && !GuestCheckInPersonMatch::likelySamePerson($submitted, $customer),
                'changes' => GuestCheckInApplyRequest::TARGET_SKIP === $value ? null : $this->changes($submitted, $customer, $isMain, $mainAddress),
            ];
        }

        $booker = $reservation->getBooker();

        return [
            'main' => $isMain,
            'name' => trim(self::text($submitted['firstname'] ?? null).' '.self::text($submitted['lastname'] ?? null)),
            'livesWithMain' => !$isMain && !\is_array($submitted['address'] ?? null),
            'differsFromBooker' => $isMain && null !== $booker && GuestCheckInPersonMatch::hasFullName($submitted)
                && !GuestCheckInPersonMatch::likelySamePerson($submitted, $booker),
            'options' => $options,
            'defaultTarget' => $defaultTarget,
            'needsChoice' => '' === $defaultTarget,
            'nameOnlyMatch' => $this->hasNameOnlyCandidate($submitted, $globalCandidates),
        ];
    }

    /**
     * What confirming would write into $target (null: a new record), mirroring
     * GuestCheckInApplyService: only entered values count, and a fellow traveller without an own
     * address gets the main guest's when the record has none yet. Values are compared ignoring case.
     *
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $mainAddress
     *
     * @return array{new: list<array{label: string, value: string, old: ?string, translatable: bool}>, changed: list<array{label: string, value: string, old: ?string, translatable: bool}>, same: list<array{label: string, value: string, old: ?string, translatable: bool}>}
     */
    private function changes(array $submitted, ?Customer $target, bool $isMain, array $mainAddress): array
    {
        if ($isMain || \is_array($submitted['address'] ?? null)) {
            $address = \is_array($submitted['address'] ?? null) ? $submitted['address'] : [];
        } else {
            $address = null === $target || $target->getCustomerAddresses()->isEmpty() ? $mainAddress : [];
        }
        $currentAddress = null !== $target ? GuestCheckInApplyService::preferredAddress($target) : null;
        // Stored the way GuestCheckInApplyService will write it, so equal values do not look changed.
        $salutation = \is_string($submitted['salutation'] ?? null) ? $this->translator->trans($submitted['salutation'], [], null, $this->installationLocale) : null;

        // Salutation and ID type are stored as translation keys or configured salutations.
        $fields = [
            ['customer.salutation', $salutation, $target?->getSalutation(), true],
            ['customer.firstname', $submitted['firstname'] ?? null, $target?->getFirstname(), false],
            ['customer.lastname', $submitted['lastname'] ?? null, $target?->getLastname(), false],
            ['customer.birthday', self::date($submitted['birthday'] ?? null), $target?->getBirthday()?->format('d.m.Y'), false],
            ['customer.nationality', $submitted['nationality'] ?? null, $target?->getNationality(), false],
        ];
        if ($isMain) {
            $fields[] = ['customer.id.type.name', IDCardType::tryFrom((string) ($submitted['idType'] ?? ''))?->value, $target?->getIdType()?->value, true];
            $fields[] = ['customer.id.number', $submitted['idNumber'] ?? null, $target?->getIDNumber(), false];
        }
        $fields[] = ['customer.address', $address['street'] ?? null, $currentAddress?->getAddress(), false];
        $fields[] = ['customer.zip', $address['zip'] ?? null, $currentAddress?->getZip(), false];
        $fields[] = ['customer.city', $address['city'] ?? null, $currentAddress?->getCity(), false];
        $fields[] = ['customer.country', $address['country'] ?? null, $currentAddress?->getCountry(), false];
        if ($isMain) {
            $fields[] = ['customer.email', $submitted['email'] ?? null, $currentAddress?->getEmail(), false];
            $fields[] = ['customer.phone', $submitted['phone'] ?? null, $currentAddress?->getPhone(), false];
        }

        $changes = ['new' => [], 'changed' => [], 'same' => []];
        foreach ($fields as [$label, $value, $current, $translatable]) {
            $value = self::text($value);
            if (null === $value) {
                // Nothing entered, nothing written.
                continue;
            }
            $current = self::text($current);
            $kind = match (true) {
                null === $current => 'new',
                mb_strtolower($value) !== mb_strtolower($current) => 'changed',
                default => 'same',
            };
            $changes[$kind][] = ['label' => $label, 'value' => $value, 'old' => $current, 'translatable' => $translatable];
        }

        return $changes;
    }

    /**
     * Whether the suggestions alone can be confirmed without a second look.
     *
     * @param list<array<string, mixed>> $persons
     */
    private function fitsWithSuggestions(Reservation $reservation, array $persons): bool
    {
        $matchedRoomGuests = [];
        $added = 0;
        foreach ($persons as $person) {
            if ($person['needsChoice']) {
                return false;
            }
            foreach ($person['options'] as $option) {
                if ($option['value'] === $person['defaultTarget']) {
                    if ($option['inRoom']) {
                        $matchedRoomGuests[$option['customerId']] = true;
                    } else {
                        ++$added;
                    }
                }
            }
        }

        $roomGuests = $reservation->getCustomers()->count();

        // A linked guest nobody matched may not travel: that is for the hotelier to say.
        return \count($matchedRoomGuests) === $roomGuests && $roomGuests + $added <= $reservation->getTotalGuests();
    }

    /**
     * Prefer an unambiguous identity match. Multiple matches require an explicit choice ('');
     * without a match, suggest a new guest instead of overwriting another person.
     *
     * @param array<string, mixed> $submitted
     * @param list<Customer>       $globalCandidates
     */
    private function suggestMainTarget(Reservation $reservation, array $submitted, array $globalCandidates): string
    {
        $booker = $reservation->getBooker();
        if (!GuestCheckInPersonMatch::hasFullName($submitted)) {
            return null !== $booker ? GuestCheckInApplyRequest::TARGET_BOOKER : GuestCheckInApplyRequest::TARGET_NEW;
        }

        $strong = [];
        $weak = [];
        $nameOnlyGlobal = false;
        if (null !== $booker && GuestCheckInPersonMatch::likelySamePerson($submitted, $booker)) {
            $target = GuestCheckInApplyRequest::TARGET_BOOKER;
            if ($this->existingGuestMatcher->hasStrongMatch($submitted, $booker)) {
                $strong[$target] = true;
            } else {
                $weak[$target] = true;
            }
        }
        foreach ($reservation->getCustomers() as $customer) {
            if ($customer !== $booker && GuestCheckInPersonMatch::likelySamePerson($submitted, $customer)) {
                $target = GuestCheckInApplyRequest::TARGET_CUSTOMER_PREFIX.$customer->getId();
                if ($this->existingGuestMatcher->hasStrongMatch($submitted, $customer)) {
                    $strong[$target] = true;
                } else {
                    $weak[$target] = true;
                }
            }
        }
        foreach ($globalCandidates as $candidate) {
            if ($this->existingGuestMatcher->hasStrongMatch($submitted, $candidate)) {
                $strong[GuestCheckInApplyRequest::TARGET_EXISTING_PREFIX.$candidate->getId()] = true;
            } else {
                $nameOnlyGlobal = true;
            }
        }

        return self::uniqueTarget($strong) ?? self::uniqueTarget($weak) ?? ($nameOnlyGlobal ? '' : GuestCheckInApplyRequest::TARGET_NEW);
    }

    /**
     * Same rules for a fellow traveller; the main guest's suggestion is not offered twice.
     *
     * @param array<string, mixed> $submitted
     * @param list<Customer>       $globalCandidates
     */
    private function matchingGuest(Reservation $reservation, array $submitted, string $defaultMainTarget, array $globalCandidates): string
    {
        $strong = [];
        $weak = [];
        $nameOnlyGlobal = false;
        $booker = $reservation->getBooker();
        $known = $reservation->getCustomers()->toArray();
        if (null !== $booker && !\in_array($booker, $known, true)) {
            $known[] = $booker;
        }
        foreach ($known as $customer) {
            if (!GuestCheckInPersonMatch::likelySamePerson($submitted, $customer)) {
                continue;
            }
            $target = $customer === $booker
                ? GuestCheckInApplyRequest::TARGET_BOOKER
                : GuestCheckInApplyRequest::TARGET_CUSTOMER_PREFIX.$customer->getId();
            if ($target === $defaultMainTarget) {
                continue;
            }
            if ($this->existingGuestMatcher->hasStrongMatch($submitted, $customer)) {
                $strong[$target] = true;
            } else {
                $weak[$target] = true;
            }
        }
        foreach ($globalCandidates as $candidate) {
            $target = GuestCheckInApplyRequest::TARGET_EXISTING_PREFIX.$candidate->getId();
            if ($target === $defaultMainTarget) {
                continue;
            }
            if ($this->existingGuestMatcher->hasStrongMatch($submitted, $candidate)) {
                $strong[$target] = true;
            } else {
                $nameOnlyGlobal = true;
            }
        }

        return self::uniqueTarget($strong) ?? self::uniqueTarget($weak) ?? ($nameOnlyGlobal ? '' : GuestCheckInApplyRequest::TARGET_NEW);
    }

    /**
     * The one target of a set of matches; '' when several compete, null when there are none.
     *
     * @param array<string, true> $matches
     */
    private static function uniqueTarget(array $matches): ?string
    {
        return match (\count($matches)) {
            0 => null,
            1 => (string) array_key_first($matches),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $submitted
     * @param list<Customer>       $globalCandidates
     */
    private function hasNameOnlyCandidate(array $submitted, array $globalCandidates): bool
    {
        return 1 === \count($globalCandidates)
            && !$this->existingGuestMatcher->hasStrongMatch($submitted, $globalCandidates[0]);
    }

    /**
     * Booker, linked guests, verified global candidates, new — in this order.
     *
     * @param list<Customer> $globalCandidates
     *
     * @return list<array{value: string, kind: string, customer: ?Customer}>
     */
    private function mainTargets(Reservation $reservation, array $globalCandidates): array
    {
        $targets = [];
        if (null !== $reservation->getBooker()) {
            $targets[] = ['value' => GuestCheckInApplyRequest::TARGET_BOOKER, 'kind' => 'booker', 'customer' => $reservation->getBooker()];
        }

        return [
            ...$targets,
            ...$this->linkedGuests($reservation),
            ...self::globalTargets($globalCandidates),
            ['value' => GuestCheckInApplyRequest::TARGET_NEW, 'kind' => 'new', 'customer' => null],
        ];
    }

    /**
     * New first (fellow travellers are mostly not on file), then the records, then "skip".
     *
     * @param list<Customer> $globalCandidates
     *
     * @return list<array{value: string, kind: string, customer: ?Customer}>
     */
    private function companionTargets(Reservation $reservation, array $globalCandidates): array
    {
        $targets = [['value' => GuestCheckInApplyRequest::TARGET_NEW, 'kind' => 'new', 'customer' => null], ...$this->linkedGuests($reservation)];
        if (null !== $reservation->getBooker()) {
            $targets[] = ['value' => GuestCheckInApplyRequest::TARGET_BOOKER, 'kind' => 'booker', 'customer' => $reservation->getBooker()];
        }

        return [
            ...$targets,
            ...self::globalTargets($globalCandidates),
            ['value' => GuestCheckInApplyRequest::TARGET_SKIP, 'kind' => 'skip', 'customer' => null],
        ];
    }

    /** @return list<array{value: string, kind: string, customer: ?Customer}> */
    private function linkedGuests(Reservation $reservation): array
    {
        $targets = [];
        foreach ($reservation->getCustomers() as $customer) {
            if ($customer !== $reservation->getBooker()) {
                $targets[] = ['value' => GuestCheckInApplyRequest::TARGET_CUSTOMER_PREFIX.$customer->getId(), 'kind' => 'guest', 'customer' => $customer];
            }
        }

        return $targets;
    }

    /**
     * @param list<Customer> $candidates
     *
     * @return list<array{value: string, kind: string, customer: ?Customer}>
     */
    private static function globalTargets(array $candidates): array
    {
        return array_map(static fn (Customer $candidate): array => [
            'value' => GuestCheckInApplyRequest::TARGET_EXISTING_PREFIX.$candidate->getId(),
            'kind' => 'existing',
            'customer' => $candidate,
        ], $candidates);
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
