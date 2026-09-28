<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Dto\GuestCheckIn\GuestCheckInCompanion;
use App\Dto\GuestCheckIn\GuestCheckInGuest;
use App\Dto\GuestCheckIn\GuestCheckInSubmission;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Enum\IDCardType;
use App\Entity\Enum\GuestCheckInFieldMode;
use App\Entity\GuestCheckIn;
use App\Entity\GuestCheckInConfig;
use App\Entity\Reservation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the public check-in form starts with: check, complete and correct rather than type
 * everything again — returning guests already have most details on file.
 *
 * The guest's own earlier submission wins. Otherwise the reservation's guest records are used:
 * the booker when linked as a guest, otherwise the first linked guest (or the booker when no
 * guests are linked) as main guest, and the other linked guests as fellow travellers. The form
 * is only shown after the booking-details check.
 * ID numbers are never put into the page; storedIdNumberHint() shows their last characters and
 * an empty field keeps the number on file.
 */
class GuestCheckInFormDataFactory
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $installationLocale,
    ) {
    }

    /**
     * @param int          $companionCount number of fellow-traveller blocks on the form
     * @param list<string> $salutations    the configured salutations the form offers
     */
    public function create(GuestCheckIn $checkIn, int $companionCount, array $salutations, ?GuestCheckInConfig $config = null): GuestCheckInSubmission
    {
        $payload = $checkIn->getPayload();
        $submission = null !== $payload
            ? $this->fromPayload($payload)
            : $this->fromGuestRecords($checkIn->getReservation(), $salutations);

        // Exactly one block per fellow traveller: surplus records are left out, missing ones empty.
        $submission->companions = \array_slice($submission->companions, 0, $companionCount);
        while (\count($submission->companions) < $companionCount) {
            $submission->companions[] = new GuestCheckInCompanion();
        }

        if (null !== $config) {
            $this->clearHiddenFields($submission, $config);
        }

        return $submission;
    }

    /** Hidden fields must never create invisible validation errors or be submitted from prefill. */
    private function clearHiddenFields(GuestCheckInSubmission $submission, GuestCheckInConfig $config): void
    {
        $guest = $submission->mainGuest;
        if (GuestCheckInFieldMode::HIDDEN === $config->getBirthdayMode()) {
            $guest->birthday = null;
        }
        if (GuestCheckInFieldMode::HIDDEN === $config->getNationalityMode()) {
            $guest->nationality = null;
        }
        if (GuestCheckInFieldMode::HIDDEN === $config->getIdDocumentMode()) {
            $guest->idType = null;
            $guest->idNumber = null;
        }
        if (GuestCheckInFieldMode::HIDDEN === $config->getAddressMode()) {
            $guest->street = $guest->zip = $guest->city = $guest->country = null;
        }
        if (GuestCheckInFieldMode::HIDDEN === $config->getContactMode()) {
            $guest->email = $guest->phone = null;
        }
        if (GuestCheckInFieldMode::HIDDEN === $config->getCompanionsMode()) {
            $submission->companions = [];
        }
        foreach ($submission->companions as $companion) {
            if (GuestCheckInFieldMode::HIDDEN === $config->getBirthdayMode()) {
                $companion->birthday = null;
            }
            if (GuestCheckInFieldMode::HIDDEN === $config->getNationalityMode()) {
                $companion->nationality = null;
            }
            if (GuestCheckInFieldMode::HIDDEN === $config->getAddressMode()) {
                $companion->street = $companion->zip = $companion->city = $companion->country = null;
            }
        }
    }

    /** "•••567" when an ID number is on file (submitted before or in the guest records), else null. */
    public function storedIdNumberHint(GuestCheckIn $checkIn): ?string
    {
        $number = $checkIn->getPayload()['mainGuest']['idNumber'] ?? $this->mainGuestRecord($checkIn->getReservation())?->getIDNumber();
        if (!\is_string($number) || '' === trim($number)) {
            return null;
        }

        $number = trim($number);

        // Short numbers would be revealed almost completely by their ending.
        return mb_strlen($number) >= 6 ? '•••'.mb_substr($number, -3) : '•••';
    }

    /** @param array<string, mixed> $payload */
    private function fromPayload(array $payload): GuestCheckInSubmission
    {
        $submission = new GuestCheckInSubmission();
        $submission->arrivalTime = self::stringOrNull($payload['arrivalTime'] ?? null);
        $submission->message = self::stringOrNull($payload['message'] ?? null);
        foreach (\is_array($payload['extras'] ?? null) ? $payload['extras'] : [] as $extra) {
            if (\is_array($extra) && \is_int($extra['id'] ?? null)) {
                $submission->extras[] = $extra['id'];
            }
        }

        $main = \is_array($payload['mainGuest'] ?? null) ? $payload['mainGuest'] : [];
        $guest = $submission->mainGuest;
        $guest->salutation = self::stringOrNull($main['salutation'] ?? null);
        $guest->firstname = self::stringOrNull($main['firstname'] ?? null);
        $guest->lastname = self::stringOrNull($main['lastname'] ?? null);
        $guest->birthday = self::dateOrNull($main['birthday'] ?? null);
        $guest->nationality = self::stringOrNull($main['nationality'] ?? null);
        $guest->idType = IDCardType::tryFrom((string) ($main['idType'] ?? ''));
        $address = \is_array($main['address'] ?? null) ? $main['address'] : [];
        $guest->street = self::stringOrNull($address['street'] ?? null);
        $guest->zip = self::stringOrNull($address['zip'] ?? null);
        $guest->city = self::stringOrNull($address['city'] ?? null);
        $guest->country = self::stringOrNull($address['country'] ?? null);
        $guest->email = self::stringOrNull($main['email'] ?? null);
        $guest->phone = self::stringOrNull($main['phone'] ?? null);

        foreach (\is_array($payload['companions'] ?? null) ? $payload['companions'] : [] as $data) {
            if (!\is_array($data)) {
                continue;
            }
            $companion = new GuestCheckInCompanion();
            $companion->salutation = self::stringOrNull($data['salutation'] ?? null);
            $companion->firstname = self::stringOrNull($data['firstname'] ?? null);
            $companion->lastname = self::stringOrNull($data['lastname'] ?? null);
            $companion->birthday = self::dateOrNull($data['birthday'] ?? null);
            $companion->nationality = self::stringOrNull($data['nationality'] ?? null);
            $address = \is_array($data['address'] ?? null) ? $data['address'] : [];
            $companion->street = self::stringOrNull($address['street'] ?? null);
            $companion->zip = self::stringOrNull($address['zip'] ?? null);
            $companion->city = self::stringOrNull($address['city'] ?? null);
            $companion->country = self::stringOrNull($address['country'] ?? null);
            $submission->companions[] = $companion;
        }

        return $submission;
    }

    /** @param list<string> $salutations */
    private function fromGuestRecords(Reservation $reservation, array $salutations): GuestCheckInSubmission
    {
        $submission = new GuestCheckInSubmission();

        $submission->arrivalTime = $reservation->getArrivalTime()?->format('H:i');

        $main = $this->mainGuestRecord($reservation);
        if (null !== $main) {
            $this->fillGuest($submission->mainGuest, $main, $salutations);
        }
        $mainAddress = null !== $main ? GuestCheckInApplyService::preferredAddress($main) : null;

        foreach ($reservation->getCustomers() as $customer) {
            if ($customer === $main) {
                continue;
            }
            $companion = new GuestCheckInCompanion();
            $companion->salutation = $this->salutationChoice((string) $customer->getSalutation(), $salutations);
            $companion->firstname = self::stringOrNull($customer->getFirstname());
            $companion->lastname = self::stringOrNull($customer->getLastname());
            $companion->birthday = self::immutable($customer->getBirthday());
            $companion->nationality = self::stringOrNull($customer->getNationality());

            // Only an address that differs from the main guest's counts as "own address".
            $address = GuestCheckInApplyService::preferredAddress($customer);
            if (null !== $address && !self::sameAddress($address, $mainAddress)) {
                $companion->street = self::stringOrNull($address->getAddress());
                $companion->zip = self::matching($address->getZip(), '/^[A-Za-z0-9 \-]{2,10}$/');
                $companion->city = self::stringOrNull($address->getCity());
                $companion->country = self::stringOrNull($address->getCountry());
            }
            $submission->companions[] = $companion;
        }

        return $submission;
    }

    /** @param list<string> $salutations */
    private function fillGuest(GuestCheckInGuest $guest, Customer $customer, array $salutations): void
    {
        $guest->salutation = $this->salutationChoice((string) $customer->getSalutation(), $salutations);
        $guest->firstname = self::stringOrNull($customer->getFirstname());
        $guest->lastname = self::stringOrNull($customer->getLastname());
        $guest->birthday = self::immutable($customer->getBirthday());
        $guest->nationality = self::stringOrNull($customer->getNationality());
        $guest->idType = $customer->getIdType();

        $address = GuestCheckInApplyService::preferredAddress($customer);
        if (null === $address) {
            return;
        }

        $guest->street = self::stringOrNull($address->getAddress());
        $guest->zip = self::matching($address->getZip(), '/^[A-Za-z0-9 \-]{2,10}$/');
        $guest->city = self::stringOrNull($address->getCity());
        $guest->country = self::stringOrNull($address->getCountry());
        // Values the form would reject are left out rather than greeting a returning guest with
        // an error on a field they did not touch.
        $email = self::stringOrNull($address->getEmail());
        $guest->email = null !== $email && false !== filter_var($email, \FILTER_VALIDATE_EMAIL) ? $email : null;
        $guest->phone = self::matching($address->getPhone(), '/^[0-9+()\/ .\-]{5,30}$/')
            ?? self::matching($address->getMobilePhone(), '/^[0-9+()\/ .\-]{5,30}$/');
    }

    private static function sameAddress(CustomerAddresses $address, ?CustomerAddresses $other): bool
    {
        if (null === $other) {
            return false;
        }

        $key = static fn (CustomerAddresses $a): string => mb_strtolower(trim((string) $a->getAddress()).'|'.trim((string) $a->getZip()).'|'.trim((string) $a->getCity()));

        return $address === $other || $key($address) === $key($other);
    }

    private function mainGuestRecord(Reservation $reservation): ?Customer
    {
        $first = $reservation->getCustomers()->first();
        $booker = $reservation->getBooker();

        return null !== $booker && $reservation->getCustomers()->contains($booker)
            ? $booker
            : ($first instanceof Customer ? $first : $booker);
    }

    /**
     * Records keep the salutation in the installation's wording ("Frau"), the form offers the
     * configured entries ("Ms"); map back, or leave it for the guest to choose.
     *
     * @param list<string> $salutations
     */
    private function salutationChoice(string $stored, array $salutations): ?string
    {
        foreach ($salutations as $choice) {
            if ($choice === $stored || $this->translator->trans($choice, [], null, $this->installationLocale) === $stored) {
                return $choice;
            }
        }

        return null;
    }

    private static function matching(?string $value, string $pattern): ?string
    {
        $value = self::stringOrNull($value);

        return null !== $value && 1 === preg_match($pattern, $value) ? $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    private static function immutable(?\DateTimeInterface $date): ?\DateTimeImmutable
    {
        return null === $date ? null : \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
    }

    private static function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }
}
