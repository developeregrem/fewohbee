<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\OnlineBookingConfig;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Template;
use App\Repository\AppartmentRepository;
use App\Repository\CustomerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class PublicBookingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppartmentRepository $appartmentRepository,
        private readonly OnlineBookingConfigService $configService,
        private readonly PublicAvailabilityService $availabilityService,
        private readonly InvoiceService $invoiceService,
        private readonly RequestStack $requestStack,
        private readonly TemplatesService $templatesService,
        private readonly MailService $mailService,
        private readonly TranslatorInterface $translator
    ) {
    }

    /**
     * Validate public input and return preview data (availability + room total).
     *
     * @param array<string, int> $qtyByType
     * @return array{availability: array, selected: array<string,int>, roomTotal: float, roomTotalFormatted: string, roomPriceBreakdown: array<int, array{label: string, quantity: int, total: float, totalFormatted: string}>, roomReservations: Reservation[]}
     */
    public function buildSelectionPreview(
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $persons,
        int $roomsCount,
        array $qtyByType,
        Request $request
    ): array {
        $config = $this->configService->getConfig();
        $availability = $this->availabilityService->getAvailability($dateFrom, $dateTo, $persons, $roomsCount, $config);

        $selection = $this->normalizeSelection($qtyByType);
        if ([] === $selection && [] !== $qtyByType) {
            throw new \RuntimeException('online_booking.error.select_at_least_one_room');
        }

        if ([] !== $selection) {
            $this->validateSelectionAgainstAvailability($selection, $availability, $persons, $roomsCount);
        }
        $assignedRooms = $this->assignRoomsFromAvailability($availability, $selection);
        $roomReservations = $this->buildTransientReservationsForRooms($assignedRooms, $dateFrom, $dateTo, $persons);
        $pricing = $this->calculateRoomTotal($roomReservations);

        return [
            'availability' => $availability,
            'selected' => $selection,
            'roomTotal' => $pricing['roomTotal'],
            'roomTotalFormatted' => $pricing['roomTotalFormatted'],
            'roomPriceBreakdown' => $pricing['roomPriceBreakdown'],
            'roomReservations' => $roomReservations,
        ];
    }

    /**
     * Create reservations for a public booking request.
     *
     * @param array<string, int> $qtyByType
     * @param array<string, string> $booker
     * @return array{reservations: Reservation[], bookingGroupUuid: Uuid, roomTotal: float, roomTotalFormatted: string, roomPriceBreakdown: array<int, array{label: string, quantity: int, total: float, totalFormatted: string}>}
     */
    public function createBooking(
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $persons,
        int $roomsCount,
        array $qtyByType,
        array $booker,
        Request $request
    ): array {
        $config = $this->configService->getConfig();
        $this->assertConfigReady($config);

        $selection = $this->normalizeSelection($qtyByType);
        $availability = $this->availabilityService->getAvailability($dateFrom, $dateTo, $persons, $roomsCount, $config);
        $this->validateSelectionAgainstAvailability($selection, $availability, $persons, $roomsCount);

        $assignedRooms = $this->assignRoomsFromAvailability($availability, $selection);
        $reservations = $this->buildTransientReservationsForRooms($assignedRooms, $dateFrom, $dateTo, $persons);

        $status = OnlineBookingConfig::BOOKING_MODE_BOOKING === $config->getBookingMode()
            ? $this->configService->getBookingStatus($config)
            : $this->configService->getInquiryStatus($config);
        if (!$status instanceof ReservationStatus) {
            throw new \RuntimeException('online_booking.error.invalid_status_config');
        }

        $origin = $this->configService->getReservationOrigin($config);
        if (null === $origin) {
            throw new \RuntimeException('online_booking.error.reservation_origin_missing');
        }

        $customer = $this->findOrCreateBookerCustomer(
            $booker
        );
        $publicComment = trim((string) ($booker['comment'] ?? ''));

        $bookingGroupUuid = Uuid::v4();
        foreach ($reservations as $reservation) {
            $reservation->setReservationOrigin($origin);
            $reservation->setReservationStatus($status);
            $reservation->setBooker($customer);
            $reservation->setUuid(Uuid::v4());
            $reservation->setBookingGroupUuid($bookingGroupUuid);
            if ('' !== $publicComment) {
                $reservation->setRemark($publicComment);
            }
            $this->em->persist($reservation);
        }
        $this->em->flush();

        $pricing = $this->calculateRoomTotal($reservations);
        $this->sendConfirmationMailIfPossible($config, $customer, $reservations);

        return [
            'reservations' => $reservations,
            'bookingGroupUuid' => $bookingGroupUuid,
            'roomTotal' => $pricing['roomTotal'],
            'roomTotalFormatted' => $pricing['roomTotalFormatted'],
            'roomPriceBreakdown' => $pricing['roomPriceBreakdown'],
        ];
    }

    /** Check whether the current online booking config is enabled and structurally valid for runtime use. */
    public function validateEnabledConfig(): ?string
    {
        $config = $this->configService->getConfig();

        if (!$config->isEnabled()) {
            return 'online_booking.error.disabled';
        }

        try {
            $this->assertConfigReady($config);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** Guard booking execution against incomplete or invalid online booking configuration. */
    private function assertConfigReady(OnlineBookingConfig $config): void
    {
        if (!$config->isEnabled()) {
            throw new \RuntimeException('online_booking.error.disabled');
        }

        if (null === $this->configService->getReservationOrigin($config)) {
            throw new \RuntimeException('online_booking.error.reservation_origin_missing');
        }

        // Template validity is checked in settings validation. Runtime can fall back to the default template.

        if (!$this->configService->getInquiryStatus($config) instanceof ReservationStatus) {
            throw new \RuntimeException('online_booking.error.invalid_status_config');
        }
        if (!$this->configService->getBookingStatus($config) instanceof ReservationStatus) {
            throw new \RuntimeException('online_booking.error.invalid_status_config');
        }
    }

    /**
     * Normalize posted quantity values and keep only positive selections.
     *
     * @param array<string, int|string|null> $qtyByType
     * @return array<string, int>
     */
    private function normalizeSelection(array $qtyByType): array
    {
        $normalized = [];
        foreach ($qtyByType as $typeKey => $qty) {
            $qtyInt = max(0, (int) $qty);
            if ($qtyInt > 0) {
                $normalized[(string) $typeKey] = $qtyInt;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * Deterministically map selected room-type quantities to concrete rooms using repository-loaded room entities.
     *
     * @param array<int, array{typeKey: string, typeLabel: string, maxGuests: int, availableCount: int, roomIds: int[]}> $availability
     * @param array<string, int> $selection
     * @return Appartment[]
     */
    private function assignRoomsFromAvailability(array $availability, array $selection): array
    {
        $assignedRoomIds = [];
        $roomOrder = [];

        foreach ($availability as $row) {
            $typeKey = $row['typeKey'];
            $qty = $selection[$typeKey] ?? 0;
            if ($qty < 1) {
                continue;
            }

            if ($qty > (int) $row['availableCount']) {
                throw new \RuntimeException('online_booking.error.qty_exceeds_availability');
            }

            $picked = array_slice($row['roomIds'], 0, $qty);
            foreach ($picked as $roomId) {
                $assignedRoomIds[] = (int) $roomId;
                $roomOrder[] = (int) $roomId;
            }
        }

        if ([] === $assignedRoomIds) {
            return [];
        }

        $rooms = $this->appartmentRepository->findByIdsWithRelations($assignedRoomIds);

        $byId = [];
        foreach ($rooms as $room) {
            $byId[(int) $room->getId()] = $room;
        }

        $ordered = [];
        foreach ($roomOrder as $roomId) {
            if (isset($byId[$roomId])) {
                $ordered[] = $byId[$roomId];
            }
        }

        return $ordered;
    }

    /**
     * Validate room quantities and capacity against freshly computed availability.
     *
     * @param array<string, int> $selection
     * @param array<int, array{typeKey: string, maxGuests: int, availableCount: int}> $availability
     */
    private function validateSelectionAgainstAvailability(array $selection, array $availability, int $persons, int $roomsCount): void
    {
        $sumQty = array_sum($selection);
        if ($sumQty !== $roomsCount) {
            throw new \RuntimeException('online_booking.error.qty_sum_mismatch');
        }

        if ($sumQty < 1) {
            throw new \RuntimeException('online_booking.error.select_at_least_one_room');
        }

        $availabilityMap = [];
        $capacity = 0;
        foreach ($availability as $row) {
            $availabilityMap[$row['typeKey']] = $row;
        }

        foreach ($selection as $typeKey => $qty) {
            if (!isset($availabilityMap[$typeKey])) {
                throw new \RuntimeException('online_booking.error.room_type_no_longer_available');
            }
            $row = $availabilityMap[$typeKey];
            if ($qty > (int) $row['availableCount']) {
                throw new \RuntimeException('online_booking.error.qty_exceeds_availability');
            }
            $capacity += $qty * (int) $row['maxGuests'];
        }

        if ($capacity < $persons) {
            throw new \RuntimeException('online_booking.error.insufficient_capacity');
        }
    }

    /**
     * Build transient reservations for pricing and final persistence using a deterministic person distribution.
     *
     * @param Appartment[] $rooms
     * @return Reservation[]
     */
    private function buildTransientReservationsForRooms(array $rooms, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo, int $persons): array
    {
        if ([] === $rooms) {
            return [];
        }

        $totalCapacity = array_sum(array_map(static fn (Appartment $room): int => (int) $room->getBedsMax(), $rooms));
        if ($persons < count($rooms) || $persons > $totalCapacity) {
            throw new \RuntimeException('online_booking.error.guest_distribution_invalid');
        }

        $origin = $this->configService->getReservationOrigin();
        $remainingPersons = $persons;
        $remainingRooms = count($rooms);
        $reservations = [];

        foreach ($rooms as $room) {
            $remainingRooms--;
            $minReserveForOthers = $remainingRooms; // at least one person per remaining room
            $maxForRoom = (int) $room->getBedsMax();
            $assignable = $remainingPersons - $minReserveForOthers;
            $assigned = max(1, min($maxForRoom, $assignable));

            $reservation = new Reservation();
            $reservation->setAppartment($room);
            $reservation->setStartDate(new \DateTime($dateFrom->format('Y-m-d')));
            $reservation->setEndDate(new \DateTime($dateTo->format('Y-m-d')));
            $reservation->setPersons($assigned);
            if (null !== $origin) {
                $reservation->setReservationOrigin($origin);
            }

            $reservations[] = $reservation;
            $remainingPersons -= $assigned;
        }

        if (0 !== $remainingPersons) {
            throw new \RuntimeException('online_booking.error.guest_distribution_failed');
        }

        return $reservations;
    }

    /**
     * Calculate the room-only total using the existing reservation preview pricing logic.
     *
     * @param Reservation[] $reservations
     * @return array{roomTotal: float, roomTotalFormatted: string, roomPriceBreakdown: array<int, array{label: string, quantity: int, total: float, totalFormatted: string}>}
     */
    private function calculateRoomTotal(array $reservations): array
    {
        $session = $this->requestStack->getSession();
        if (null === $session) {
            return ['roomTotal' => 0.0, 'roomTotalFormatted' => number_format(0, 2, ',', '.'), 'roomPriceBreakdown' => []];
        }

        $apartmentTotal = 0.0;
        $breakdown = [];

        foreach ($reservations as $reservation) {
            $singleTotal = $this->calculateSingleReservationRoomTotal($reservation);
            $label = $this->buildReservationTypeLabel($reservation);

            if (!isset($breakdown[$label])) {
                $breakdown[$label] = [
                    'label' => $label,
                    'quantity' => 0,
                    'total' => 0.0,
                ];
            }

            $breakdown[$label]['quantity']++;
            $breakdown[$label]['total'] += $singleTotal;
            $apartmentTotal += $singleTotal;
        }

        $formattedBreakdown = array_map(static function (array $row): array {
            $row['totalFormatted'] = number_format((float) $row['total'], 2, ',', '.');

            return $row;
        }, array_values($breakdown));

        return [
            'roomTotal' => $apartmentTotal,
            'roomTotalFormatted' => number_format($apartmentTotal, 2, ',', '.'),
            'roomPriceBreakdown' => $formattedBreakdown,
        ];
    }

    /** Calculate the room-only total for a single transient reservation. */
    private function calculateSingleReservationRoomTotal(Reservation $reservation): float
    {
        $session = $this->requestStack->getSession();
        if (null === $session) {
            return 0.0;
        }

        $session->set('invoicePositionsAppartments', new ArrayCollection());
        $this->invoiceService->prefillAppartmentPositions($reservation, $this->requestStack);

        $apartmentPositions = $session->get('invoicePositionsAppartments', new ArrayCollection());
        $vatSums = [];
        $brutto = 0.0;
        $netto = 0.0;
        $apartmentTotal = 0.0;
        $miscTotal = 0.0;
        $this->invoiceService->calculateSums(
            $apartmentPositions instanceof ArrayCollection ? $apartmentPositions : new ArrayCollection((array) $apartmentPositions),
            new ArrayCollection(),
            $vatSums,
            $brutto,
            $netto,
            $apartmentTotal,
            $miscTotal
        );

        return $apartmentTotal;
    }

    /** Build a readable room type label for price breakdown rows. */
    private function buildReservationTypeLabel(Reservation $reservation): string
    {
        $room = $reservation->getAppartment();
        $category = $room->getRoomCategory();

        if (null !== $category && null !== $category->getName() && '' !== trim($category->getName())) {
            return trim($category->getName());
        }

        return trim(sprintf('%s - %s', (string) $room->getNumber(), (string) $room->getDescription()));
    }

    /**
     * Reuse a customer by email when possible or create a public-booking customer with contact details.
     *
     * @param array<string, string> $booker
     */
    private function findOrCreateBookerCustomer(array $booker): Customer
    {
        $salutation = trim((string) ($booker['salutation'] ?? ''));
        $firstname = trim((string) ($booker['firstname'] ?? ''));
        $lastname = trim((string) ($booker['lastname'] ?? ''));
        $email = trim((string) ($booker['email'] ?? ''));
        $phone = trim((string) ($booker['phone'] ?? ''));
        $normalizedEmail = mb_strtolower(trim($email));
        if ('' === $salutation || '' === $firstname || '' === $lastname || '' === $normalizedEmail) {
            throw new \RuntimeException('online_booking.error.booker_required');
        }

        if (
            '' === trim((string) ($booker['address'] ?? ''))
            || '' === trim((string) ($booker['zip'] ?? ''))
            || '' === trim((string) ($booker['city'] ?? ''))
            || '' === trim((string) ($booker['country'] ?? ''))
        ) {
            throw new \RuntimeException('online_booking.error.booker_required');
        }

        /** @var CustomerRepository $customerRepository */
        $customerRepository = $this->em->getRepository(Customer::class);
        $customer = $customerRepository->findOneByEmailCaseInsensitive($normalizedEmail);

        if ($customer instanceof Customer) {
            $this->updateExistingCustomerContact($customer, $booker, $normalizedEmail);

            return $customer;
        }

        $customer = new Customer();
        $customer->setSalutation($salutation);
        $customer->setFirstname($firstname);
        $customer->setLastname($lastname);

        $address = new CustomerAddresses();
        $this->applyBookerDataToAddress($address, $booker, $normalizedEmail);
        $customer->addCustomerAddress($address);

        $this->em->persist($address);
        $this->em->persist($customer);
        $this->em->flush();

        return $customer;
    }

    /**
     * Update only missing customer contact fields so public bookings do not overwrite curated CRM data.
     *
     * @param array<string, string> $booker
     */
    private function updateExistingCustomerContact(Customer $customer, array $booker, string $email): void
    {
        $updated = false;
        $firstAddress = null;

        if ((null === $customer->getSalutation() || '' === trim((string) $customer->getSalutation())) && '' !== trim((string) ($booker['salutation'] ?? ''))) {
            $customer->setSalutation(trim((string) $booker['salutation']));
            $updated = true;
        }
        if ((null === $customer->getFirstname() || '' === trim((string) $customer->getFirstname())) && '' !== trim((string) ($booker['firstname'] ?? ''))) {
            $customer->setFirstname(trim((string) $booker['firstname']));
            $updated = true;
        }
        if ('' === trim((string) $customer->getLastname()) && '' !== trim((string) ($booker['lastname'] ?? ''))) {
            $customer->setLastname(trim((string) $booker['lastname']));
            $updated = true;
        }
        foreach ($customer->getCustomerAddresses() as $address) {
            if (!$address instanceof CustomerAddresses) {
                continue;
            }
            $firstAddress ??= $address;

            if (null !== $address->getEmail() && mb_strtolower((string) $address->getEmail()) === $email) {
                $updated = $this->mergeMissingBookerDataIntoAddress($address, $booker, $email) || $updated;
                if ($updated) {
                    $this->em->persist($customer);
                    $this->em->persist($address);
                    $this->em->flush();
                }

                return;
            }
        }

        if ($firstAddress instanceof CustomerAddresses) {
            $updated = $this->mergeMissingBookerDataIntoAddress($firstAddress, $booker, $email) || $updated;
            if ($updated) {
                $this->em->persist($customer);
                $this->em->persist($firstAddress);
                $this->em->flush();
            }

            return;
        }

        $address = new CustomerAddresses();
        $this->applyBookerDataToAddress($address, $booker, $email);
        $customer->addCustomerAddress($address);

        $this->em->persist($address);
        $this->em->persist($customer);
        $this->em->flush();
    }

    /**
     * Apply the submitted public-booking address payload to a customer address entity.
     *
     * @param array<string, string> $booker
     */
    private function applyBookerDataToAddress(CustomerAddresses $address, array $booker, string $email): void
    {
        $company = trim((string) ($booker['company'] ?? ''));

        $address->setType('' !== $company ? 'CUSTOMER_ADDRESS_TYPE_BUSINESS' : 'CUSTOMER_ADDRESS_TYPE_PRIVATE');
        $address->setCompany('' !== $company ? $company : null);
        $address->setAddress(trim((string) ($booker['address'] ?? '')) ?: null);
        $address->setZip(trim((string) ($booker['zip'] ?? '')) ?: null);
        $address->setCity(trim((string) ($booker['city'] ?? '')) ?: null);
        $address->setCountry(trim((string) ($booker['country'] ?? '')) ?: null);
        $address->setEmail($email);
        $address->setPhone(trim((string) ($booker['phone'] ?? '')) ?: null);
    }

    /**
     * Merge only missing address fields from the public-booking payload into an existing address.
     *
     * @param array<string, string> $booker
     */
    private function mergeMissingBookerDataIntoAddress(CustomerAddresses $address, array $booker, string $email): bool
    {
        $updated = false;
        $company = trim((string) ($booker['company'] ?? ''));

        if ((null === $address->getEmail() || '' === trim((string) $address->getEmail())) && '' !== $email) {
            $address->setEmail($email);
            $updated = true;
        }
        if ((null === $address->getPhone() || '' === trim((string) $address->getPhone())) && '' !== trim((string) ($booker['phone'] ?? ''))) {
            $address->setPhone(trim((string) $booker['phone']));
            $updated = true;
        }
        if ((null === $address->getAddress() || '' === trim((string) $address->getAddress())) && '' !== trim((string) ($booker['address'] ?? ''))) {
            $address->setAddress(trim((string) $booker['address']));
            $updated = true;
        }
        if ((null === $address->getZip() || '' === trim((string) $address->getZip())) && '' !== trim((string) ($booker['zip'] ?? ''))) {
            $address->setZip(trim((string) $booker['zip']));
            $updated = true;
        }
        if ((null === $address->getCity() || '' === trim((string) $address->getCity())) && '' !== trim((string) ($booker['city'] ?? ''))) {
            $address->setCity(trim((string) $booker['city']));
            $updated = true;
        }
        if ((null === $address->getCountry() || '' === trim((string) $address->getCountry())) && '' !== trim((string) ($booker['country'] ?? ''))) {
            $address->setCountry(trim((string) $booker['country']));
            $updated = true;
        }
        if ((null === $address->getCompany() || '' === trim((string) $address->getCompany())) && '' !== $company) {
            $address->setCompany($company);
            $address->setType('CUSTOMER_ADDRESS_TYPE_BUSINESS');
            $updated = true;
        }

        return $updated;
    }

    /**
     * Send a confirmation mail with the configured template and fallback to the default reservation email template.
     *
     * @param Reservation[] $reservations
     */
    private function sendConfirmationMailIfPossible(OnlineBookingConfig $config, Customer $customer, array $reservations): void
    {
        $email = null;
        foreach ($customer->getCustomerAddresses() as $address) {
            if ($address instanceof CustomerAddresses && null !== $address->getEmail() && '' !== trim($address->getEmail())) {
                $email = trim($address->getEmail());
                break;
            }
        }

        if (null === $email) {
            return;
        }

        $template = $this->configService->getConfirmationEmailTemplate($config);
        if (!$template instanceof Template) {
            $templates = $this->em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_RESERVATION_EMAIL']);
            $template = $this->templatesService->getDefaultTemplate($templates ?? []);
        }

        if (!$template instanceof Template) {
            return;
        }

        $subject = OnlineBookingConfig::BOOKING_MODE_BOOKING === $config->getBookingMode()
            ? $this->translator->trans('online_booking.email.subject.booking')
            : $this->translator->trans('online_booking.email.subject.inquiry');

        $body = $this->templatesService->renderTemplate((int) $template->getId(), $reservations);
        $this->mailService->sendHTMLMail($email, $subject, $body);
    }
}
