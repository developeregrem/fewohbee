<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Dto\GuestCheckIn\GuestCheckInApplyRequest;
use App\Entity\Customer;
use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\Enum\IDCardType;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Repository\GuestCheckInRepository;
use App\Service\PublicUrlService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Data for the "Online check-in" tab of the reservation dialog: state, link availability and
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
        #[Autowire('%kernel.default_locale%')]
        private readonly string $installationLocale,
    ) {
    }

    /**
     * Null when the tab has nothing to show: feature off and nothing was ever submitted.
     *
     * @return array<string, mixed>|null
     */
    public function buildTab(Reservation $reservation): ?array
    {
        $checkIn = $this->repository->findOneByReservation($reservation);
        $enabled = $this->configService->isEnabled();
        if (!$enabled && (null === $checkIn || GuestCheckInStatus::OPEN === $checkIn->getStatus())) {
            return null;
        }

        $state = $this->policy->linkState($reservation, $checkIn, $enabled);
        $payload = $checkIn?->getPayload();

        return [
            'checkIn' => $checkIn,
            'status' => $checkIn?->getStatus() ?? GuestCheckInStatus::OPEN,
            'linkAvailable' => GuestCheckInLinkState::UNAVAILABLE !== $state && null !== $this->publicUrlService->getBaseUrl(),
            'unavailableReason' => $this->unavailableReason($state, $enabled),
            'arrivalTime' => $payload['arrivalTime'] ?? null,
            'message' => $payload['message'] ?? null,
            'mainGuestRows' => null !== $payload ? $this->mainGuestRows($payload['mainGuest'] ?? [], $reservation->getBooker()) : [],
            'companions' => null !== $payload ? $this->companions($payload['companions'] ?? [], $reservation) : [],
            'mainTargets' => $this->mainTargets($reservation),
            'defaultMainTarget' => null !== $reservation->getBooker() ? GuestCheckInApplyRequest::TARGET_BOOKER : GuestCheckInApplyRequest::TARGET_NEW,
            'companionTargets' => $this->companionTargets($reservation),
        ];
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
    private function mainGuestRows(array $guest, ?Customer $booker): array
    {
        $address = \is_array($guest['address'] ?? null) ? $guest['address'] : [];
        $bookerAddress = null !== $booker ? GuestCheckInApplyService::preferredAddress($booker) : null;
        $idType = IDCardType::tryFrom((string) ($guest['idType'] ?? ''));
        // Stored the way GuestCheckInApplyService will write it, so equal values do not look changed.
        $salutation = \is_string($guest['salutation'] ?? null) ? $this->translator->trans($guest['salutation'], [], null, $this->installationLocale) : null;

        $rows = [
            ['customer.salutation', $salutation, $booker?->getSalutation()],
            ['customer.firstname', $guest['firstname'] ?? null, $booker?->getFirstname()],
            ['customer.lastname', $guest['lastname'] ?? null, $booker?->getLastname()],
            ['customer.birthday', self::date($guest['birthday'] ?? null), $booker?->getBirthday()?->format('d.m.Y')],
            ['customer.nationality', $guest['nationality'] ?? null, $booker?->getNationality()],
            ['customer.id.type.name', $idType?->value, $booker?->getIdType()?->value],
            ['customer.id.number', $guest['idNumber'] ?? null, $booker?->getIDNumber()],
            ['customer.address', $address['street'] ?? null, $bookerAddress?->getAddress()],
            ['customer.zip', $address['zip'] ?? null, $bookerAddress?->getZip()],
            ['customer.city', $address['city'] ?? null, $bookerAddress?->getCity()],
            ['customer.country', $address['country'] ?? null, $bookerAddress?->getCountry()],
            ['customer.email', $guest['email'] ?? null, $bookerAddress?->getEmail()],
            ['customer.phone', $guest['phone'] ?? null, $bookerAddress?->getPhone()],
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
     * @return list<array{name: string, birthday: ?string, nationality: ?string, address: ?string, defaultTarget: string}>
     */
    private function companions(array $companions, Reservation $reservation): array
    {
        $result = [];
        foreach ($companions as $companion) {
            if (!\is_array($companion)) {
                continue;
            }
            $firstname = self::text($companion['firstname'] ?? null);
            $lastname = self::text($companion['lastname'] ?? null);
            $result[] = [
                'name' => trim($firstname.' '.$lastname),
                'birthday' => self::date($companion['birthday'] ?? null),
                'nationality' => self::text($companion['nationality'] ?? null),
                // Null: lives with the main guest.
                'address' => \is_array($companion['address'] ?? null) ? self::addressLine($companion['address']) : null,
                'defaultTarget' => $this->matchingGuest($reservation, $firstname, $lastname) ?? GuestCheckInApplyRequest::TARGET_NEW,
            ];
        }

        return $result;
    }

    /** A linked guest with the same name is most likely the same person. */
    private function matchingGuest(Reservation $reservation, ?string $firstname, ?string $lastname): ?string
    {
        foreach ($reservation->getCustomers() as $customer) {
            if (mb_strtolower((string) $customer->getFirstname()) === mb_strtolower((string) $firstname)
                && mb_strtolower((string) $customer->getLastname()) === mb_strtolower((string) $lastname)
                && $customer !== $reservation->getBooker()
            ) {
                return GuestCheckInApplyRequest::TARGET_CUSTOMER_PREFIX.$customer->getId();
            }
        }

        return null;
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
    private function companionTargets(Reservation $reservation): array
    {
        return $this->linkedGuests($reservation);
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
