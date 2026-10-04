<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Dto\GuestCheckIn\GuestCheckInApplyRequest;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\Enum\IDCardType;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Event\GuestCheckInConfirmedEvent;
use App\Repository\AppSettingsRepository;
use App\Service\Pricing\PricePromiseService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Confirms a reviewed online check-in by taking it over into the guest records.
 *
 * - Only values the guest actually entered overwrite existing ones; nothing is cleared.
 * - Linked guests are always available; a customer outside the reservation can only be selected
 *   with customer access and a fresh identity suggestion that the hotelier confirms.
 * - An address shared with other customers is left alone; the guest gets an own copy.
 * - Everyone taken over ends up among the reservation's guests, up to the number of guests booked;
 *   linked guests the hotelier marks as not travelling make room first.
 * - Afterwards the submission itself is dropped; the audit log records who took it over.
 */
class GuestCheckInApplyService
{
    public const ADDRESS_TYPE_PRIVATE = 'CUSTOMER_ADDRESS_TYPE_PRIVATE';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $installationLocale,
        private readonly GuestCheckInExtrasService $extrasService,
        private readonly AppSettingsRepository $settingsRepository,
        private readonly GuestCheckInExistingGuestMatcher $existingGuestMatcher,
        private readonly PricePromiseService $pricePromises,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @return list<string> translation keys of warnings (e.g. guests beyond the occupancy)
     *
     * @throws \InvalidArgumentException for invalid or unverified targets, or without data to apply
     */
    public function apply(GuestCheckIn $checkIn, GuestCheckInApplyRequest $request): array
    {
        $payload = $checkIn->getPayload();
        if (null === $payload || GuestCheckInStatus::SUBMITTED !== $checkIn->getStatus()) {
            throw new \InvalidArgumentException('Nothing to take over.');
        }

        if (null !== $request->expectedSubmissionVersion && !hash_equals($request->expectedSubmissionVersion, (string) $checkIn->getSubmissionVersion())) {
            throw new \InvalidArgumentException('The guest changed the submission after it was opened for review.');
        }

        $reservation = $checkIn->getReservation();
        $main = \is_array($payload['mainGuest'] ?? null) ? $payload['mainGuest'] : [];
        // "booker" and "customer:<booker id>" name the same person. Canonicalise them before
        // changing any customer, or the second traveller can silently overwrite the first.
        $usedTargets = [];
        foreach ([$request->mainTarget, ...$request->companionTargets] as $target) {
            if (\in_array($target, [GuestCheckInApplyRequest::TARGET_NEW, GuestCheckInApplyRequest::TARGET_SKIP], true)) {
                continue;
            }
            if (GuestCheckInApplyRequest::TARGET_BOOKER === $target && null !== $reservation->getBooker()) {
                $target = GuestCheckInApplyRequest::TARGET_CUSTOMER_PREFIX.$reservation->getBooker()->getId();
            } elseif (str_starts_with($target, GuestCheckInApplyRequest::TARGET_EXISTING_PREFIX)) {
                $target = GuestCheckInApplyRequest::TARGET_CUSTOMER_PREFIX.substr($target, \strlen(GuestCheckInApplyRequest::TARGET_EXISTING_PREFIX));
            }
            if (isset($usedTargets[$target])) {
                throw new \InvalidArgumentException('One customer was selected for multiple guests.');
            }
            $usedTargets[$target] = true;
        }

        $guestsToRemove = [];
        foreach (array_unique($request->removeGuestIds) as $id) {
            $guest = $reservation->getCustomers()->findFirst(static fn (int $key, Customer $customer): bool => $customer->getId() === $id);
            // Somebody taken over from the check-in obviously travels.
            if (null === $guest || isset($usedTargets[GuestCheckInApplyRequest::TARGET_CUSTOMER_PREFIX.$id])) {
                throw new \InvalidArgumentException('Only linked guests not taken over can be removed.');
            }
            $guestsToRemove[] = $guest;
        }

        $existingMainCustomer = GuestCheckInApplyRequest::TARGET_NEW === $request->mainTarget
            ? null
            : $this->resolveTarget($reservation, $request->mainTarget, false, $main, $request->allowGlobalMatch);
        $companions = \is_array($payload['companions'] ?? null) ? array_values($payload['companions']) : [];
        foreach ($companions as $index => $companion) {
            $target = $request->companionTargets[$index] ?? GuestCheckInApplyRequest::TARGET_SKIP;
            if (\is_array($companion) && GuestCheckInApplyRequest::TARGET_NEW !== $target) {
                $this->resolveTarget($reservation, $target, true, $companion, $request->allowGlobalMatch);
            }
        }
        $mainAlreadyInRoom = null !== $existingMainCustomer && $reservation->getCustomers()->contains($existingMainCustomer);
        if (!$mainAlreadyInRoom && \count($reservation->getCustomers()) - \count($guestsToRemove) >= $reservation->getTotalGuests()) {
            throw new GuestCheckInNoGuestSlotException('No room for the arriving main guest.');
        }

        $requestedExtras = \is_array($payload['extras'] ?? null) ? $payload['extras'] : [];
        $extrasToApply = [];
        if ([] !== $requestedExtras && $request->applyExtras) {
            $extrasToApply = $this->extrasService->pricesToApply($reservation, $requestedExtras);
        }
        $deferredExtrasEntry = [] !== $requestedExtras && !$request->applyExtras
            ? $this->deferredExtrasEntry($checkIn, $requestedExtras)
            : null;
        $warnings = [];

        foreach ($guestsToRemove as $guest) {
            $reservation->removeCustomer($guest);
        }

        $mainCustomer = $existingMainCustomer ?? $this->resolveTarget($reservation, $request->mainTarget, false, $main, $request->allowGlobalMatch)
            ?? throw new \InvalidArgumentException('The main guest needs a target.');
        $this->fillPerson($mainCustomer, $main, true);
        $mainAddress = \is_array($main['address'] ?? null) ? $main['address'] : [];
        $this->applyAddress($mainCustomer, $mainAddress, self::nullableString($main['email'] ?? null), self::nullableString($main['phone'] ?? null));
        if ($request->setAsBooker || null === $reservation->getBooker()) {
            $reservation->setBooker($mainCustomer);
        }
        $warnings = [...$warnings, ...$this->addGuest($reservation, $mainCustomer)];

        foreach ($companions as $index => $companion) {
            if (!\is_array($companion)) {
                continue;
            }
            $customer = $this->resolveTarget($reservation, $request->companionTargets[$index] ?? GuestCheckInApplyRequest::TARGET_SKIP, true, $companion, $request->allowGlobalMatch);
            if (null === $customer) {
                continue;
            }

            $this->fillPerson($customer, $companion, false);
            $ownAddress = \is_array($companion['address'] ?? null) ? $companion['address'] : null;
            if (null !== $ownAddress) {
                $this->applyAddress($customer, $ownAddress, null, null);
            } elseif ($customer->getCustomerAddresses()->isEmpty()) {
                // The guest said this person lives with the main guest; someone who already has an
                // address on file keeps it.
                $this->applyAddress($customer, $mainAddress, null, null);
            }
            $warnings = [...$warnings, ...$this->addGuest($reservation, $customer)];
        }

        $message = self::nullableString($payload['message'] ?? null);
        if (null !== $message) {
            $entry = $this->translator->trans('guest_checkin.remark.guest_comment', [
                '%date%' => $checkIn->getLastSubmittedAt()?->format('d.m.Y H:i') ?? $this->clock->now()->format('d.m.Y H:i'),
                '%message%' => $message,
            ], null, $this->installationLocale);
            $this->appendRemark($reservation, $entry);
        }

        if (null !== $deferredExtrasEntry) {
            $this->appendRemark($reservation, $deferredExtrasEntry);
            $warnings[] = 'guest_checkin.apply.warning_extras_deferred';
        }

        foreach ($extrasToApply as $price) {
            $reservation->addPrice($price);
        }
        // Extras chosen by the guest are promised at today's price, the room keeps its own.
        $this->pricePromises->reconcile($reservation);

        $checkIn->markApplied($this->clock->now());
        $this->em->flush();
        $this->dispatcher->dispatch(new GuestCheckInConfirmedEvent($reservation, $checkIn));

        return array_values(array_unique($warnings));
    }

    /**
     * Null for "skip". New customers are persisted here. Global targets must still match the
     * submitted person and require customer access, even if an ID was forged in the POST.
     *
     * @param array<string, mixed> $person
     */
    private function resolveTarget(Reservation $reservation, string $target, bool $allowSkip, array $person, bool $allowGlobalMatch): ?Customer
    {
        if ($allowSkip && GuestCheckInApplyRequest::TARGET_SKIP === $target) {
            return null;
        }

        if (GuestCheckInApplyRequest::TARGET_NEW === $target) {
            $customer = new Customer();
            $customer->setSalutation('');
            $this->em->persist($customer);

            return $customer;
        }

        if (GuestCheckInApplyRequest::TARGET_BOOKER === $target) {
            return $reservation->getBooker() ?? throw new \InvalidArgumentException('The reservation has no booker.');
        }

        if ($allowGlobalMatch && preg_match('/^existing:([1-9][0-9]*)$/D', $target, $matches)) {
            return $this->existingGuestMatcher->matchingCandidateById($reservation, $person, (int) $matches[1])
                ?? throw new \InvalidArgumentException('The existing guest no longer matches the submission.');
        }

        if (str_starts_with($target, GuestCheckInApplyRequest::TARGET_CUSTOMER_PREFIX)) {
            $id = (int) substr($target, \strlen(GuestCheckInApplyRequest::TARGET_CUSTOMER_PREFIX));
            foreach ([$reservation->getBooker(), ...$reservation->getCustomers()] as $customer) {
                if ($customer instanceof Customer && $customer->getId() === $id) {
                    return $customer;
                }
            }
        }

        throw new \InvalidArgumentException('Unknown target.');
    }

    /** @param array<string, mixed> $person */
    private function fillPerson(Customer $customer, array $person, bool $isMainGuest): void
    {
        if (null !== ($salutation = self::nullableString($person['salutation'] ?? null))) {
            // The form offers the configured salutations untranslated; the record keeps the
            // wording of the installation, whatever language the guest used.
            $customer->setSalutation(mb_substr($this->translator->trans($salutation, [], null, $this->installationLocale), 0, 20));
        }
        if (null !== ($value = self::nullableString($person['firstname'] ?? null))) {
            $customer->setFirstname($value);
        }
        if (null !== ($value = self::nullableString($person['lastname'] ?? null))) {
            $customer->setLastname($value);
        }
        if (null !== ($value = self::nullableString($person['birthday'] ?? null))) {
            $birthday = \DateTime::createFromFormat('!Y-m-d', $value);
            if (false !== $birthday) {
                $customer->setBirthday($birthday);
            }
        }
        if (null !== ($value = self::nullableString($person['nationality'] ?? null))) {
            $customer->setNationality($value);
        }
        if ($isMainGuest && null !== ($value = self::nullableString($person['idNumber'] ?? null))) {
            $customer->setIDNumber($value);
        }
        if ($isMainGuest && null !== ($idType = IDCardType::tryFrom((string) ($person['idType'] ?? '')))) {
            $customer->setIdType($idType);
        }
    }

    /**
     * Writes into the customer's own private address, or into a new one when the existing
     * address is shared with other customers (changing it would move them as well).
     *
     * @param array<string, mixed> $address
     */
    private function applyAddress(Customer $customer, array $address, ?string $email, ?string $phone): void
    {
        $values = [
            'address' => self::nullableString($address['street'] ?? null),
            'zip' => self::nullableString($address['zip'] ?? null),
            'city' => self::nullableString($address['city'] ?? null),
            'country' => self::nullableString($address['country'] ?? null),
            'email' => $email,
            'phone' => $phone,
        ];
        if ([] === array_filter($values)) {
            return;
        }

        $target = null;
        foreach ($customer->getCustomerAddresses() as $existing) {
            if (self::ADDRESS_TYPE_PRIVATE === $existing->getType() && \count($existing->getCustomers()) <= 1) {
                $target = $existing;
                break;
            }
        }

        if (null === $target) {
            $target = new CustomerAddresses();
            $target->setType(self::ADDRESS_TYPE_PRIVATE);
            $this->em->persist($target);
            $customer->addCustomerAddress($target);
        }

        foreach ($values as $field => $value) {
            if (null !== $value) {
                $target->{'set'.ucfirst($field)}($value);
            }
        }
    }

    /** The customer's private address, or their first one if none is marked private. */
    public static function preferredAddress(Customer $customer): ?CustomerAddresses
    {
        $first = null;
        foreach ($customer->getCustomerAddresses() as $address) {
            if (self::ADDRESS_TYPE_PRIVATE === $address->getType()) {
                return $address;
            }
            $first ??= $address;
        }

        return $first;
    }

    /** @return list<string> */
    private function addGuest(Reservation $reservation, Customer $customer): array
    {
        if ($reservation->getCustomers()->contains($customer)) {
            return [];
        }

        if (\count($reservation->getCustomers()) >= $reservation->getTotalGuests()) {
            return ['guest_checkin.apply.warning_guest_count'];
        }

        $reservation->addCustomer($customer);

        return [];
    }

    private static function nullableString(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    private function appendRemark(Reservation $reservation, string $entry): void
    {
        $previous = trim((string) $reservation->getRemark());
        $reservation->setRemark('' === $previous ? $entry : $previous."\n\n".$entry);
    }

    /** @param array<mixed> $requestedExtras */
    private function deferredExtrasEntry(GuestCheckIn $checkIn, array $requestedExtras): string
    {
        $currency = $this->settingsRepository->findSingleton()?->getCurrencySymbol() ?? '';
        $germanFormat = str_starts_with($this->installationLocale, 'de');
        $services = [];
        foreach ($requestedExtras as $extra) {
            if (!\is_array($extra) || !\is_string($extra['description'] ?? null) || !\is_string($extra['total'] ?? null)) {
                throw new \InvalidArgumentException('Invalid service request.');
            }
            $total = \is_numeric($extra['total'])
                ? number_format((float) $extra['total'], 2, $germanFormat ? ',' : '.', $germanFormat ? '.' : ',')
                : $extra['total'];
            $services[] = trim($extra['description']).' · '.$total.' '.$currency;
        }

        return $this->translator->trans('guest_checkin.remark.deferred_extras', [
            '%date%' => $checkIn->getLastSubmittedAt()?->format('d.m.Y H:i') ?? $this->clock->now()->format('d.m.Y H:i'),
            '%services%' => implode("\n", $services),
        ], null, $this->installationLocale);
    }
}
