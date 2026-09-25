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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Takes a reviewed online check-in over into the guest records.
 *
 * - Only values the guest actually entered overwrite existing ones; nothing is cleared.
 * - Targets are restricted to the reservation's booker and linked guests, so the request can
 *   never reach another customer. Guests are never matched by email address.
 * - An address shared with other customers is left alone; the guest gets an own copy.
 * - Everyone taken over ends up among the reservation's guests, up to the number of guests booked.
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
    ) {
    }

    /**
     * @return list<string> translation keys of warnings (e.g. guests beyond the occupancy)
     *
     * @throws \InvalidArgumentException for targets outside this reservation or without data to apply
     */
    public function apply(GuestCheckIn $checkIn, GuestCheckInApplyRequest $request): array
    {
        $payload = $checkIn->getPayload();
        if (null === $payload || GuestCheckInStatus::SUBMITTED !== $checkIn->getStatus()) {
            throw new \InvalidArgumentException('Nothing to take over.');
        }

        $reservation = $checkIn->getReservation();
        $warnings = [];

        $main = \is_array($payload['mainGuest'] ?? null) ? $payload['mainGuest'] : [];
        $mainCustomer = $this->resolveTarget($reservation, $request->mainTarget, false)
            ?? throw new \InvalidArgumentException('The main guest needs a target.');
        $this->fillPerson($mainCustomer, $main, true);
        $mainAddress = \is_array($main['address'] ?? null) ? $main['address'] : [];
        $this->applyAddress($mainCustomer, $mainAddress, self::nullableString($main['email'] ?? null), self::nullableString($main['phone'] ?? null));
        if ($request->setAsBooker || null === $reservation->getBooker()) {
            $reservation->setBooker($mainCustomer);
        }
        $warnings = [...$warnings, ...$this->addGuest($reservation, $mainCustomer)];

        $companions = \is_array($payload['companions'] ?? null) ? array_values($payload['companions']) : [];
        foreach ($companions as $index => $companion) {
            $customer = $this->resolveTarget($reservation, $request->companionTargets[$index] ?? GuestCheckInApplyRequest::TARGET_SKIP, true);
            if (null === $customer || !\is_array($companion)) {
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

        $checkIn->markApplied($this->clock->now());
        $this->em->flush();

        return array_values(array_unique($warnings));
    }

    /**
     * Null for "skip". New customers are persisted here; existing ones must belong to the
     * reservation (booker or linked guest).
     */
    private function resolveTarget(Reservation $reservation, string $target, bool $allowSkip): ?Customer
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
        if ($isMainGuest && null !== ($salutation = self::nullableString($person['salutation'] ?? null))) {
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
}
