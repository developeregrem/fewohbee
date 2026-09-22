<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Dto\Reservation\BookerData;
use App\Dto\Reservation\ReservationBookingPreview;
use App\Dto\Reservation\ReservationBookingRequest;
use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Exception\InvalidReservationPeriodException;
use App\Exception\ReservationBookingException;
use App\Repository\CustomerRepository;
use App\Repository\GuestCategoryRepository;
use App\Repository\PriceRepository;
use App\Service\AppSettingsService;
use App\Service\AvailabilityService;
use App\Service\OnlineBooking\BookingRestrictionService;
use App\Service\OnlineBooking\PublicPricingService;
use App\Service\ReservationPeriodService;
use App\Service\ReservationService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Books a single room without the web session, strictly: unlike the back-office form, a
 * conflicting reservation or room block refuses the booking instead of only warning.
 *
 * Used by the MCP tools; shaped so channel managers or the web form can use it later. Callers
 * dispatch their own domain event after create(), because the reaction depends on who booked.
 *
 * Rules applied: valid period, active room with enough beds, free for the whole stay (checked again
 * under a row lock on the room when saving), existing status and origin, "at least one adult" when
 * guest categories are used, extras only from the misc prices that apply to the stay. Online booking restrictions (minimum stay, closed arrival, ...) do not
 * block — staff may override them in the back office as well — but are reported as warnings.
 * An existing customer is only linked, never modified.
 */
class ReservationBookingService
{
    private const REMARK_MAX_LENGTH = 1000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationPeriodService $periodService,
        private readonly AvailabilityService $availabilityService,
        private readonly ReservationService $reservationService,
        private readonly BookingRestrictionService $bookingRestrictionService,
        private readonly GuestCategoryRepository $guestCategoryRepository,
        private readonly AppSettingsService $appSettingsService,
        private readonly PriceRepository $priceRepository,
        private readonly PublicPricingService $pricingService,
    ) {
    }

    /**
     * Validates the request without saving anything.
     *
     * @throws ReservationBookingException
     */
    public function preview(ReservationBookingRequest $request): ReservationBookingPreview
    {
        try {
            $period = $this->periodService->validate($request->arrival, $request->departure);
        } catch (InvalidReservationPeriodException) {
            throw new ReservationBookingException('The stay period is invalid: departure must be after arrival.');
        }
        if ($period->start >= $period->end) {
            throw new ReservationBookingException('The stay period is invalid: departure must be after arrival.');
        }

        $apartment = $this->em->getRepository(Appartment::class)->find($request->apartmentId);
        if (!$apartment instanceof Appartment || !$apartment->isActive()) {
            throw new ReservationBookingException('Unknown or inactive apartment.');
        }

        $status = $this->em->getRepository(ReservationStatus::class)->find($request->statusId);
        if (!$status instanceof ReservationStatus) {
            throw new ReservationBookingException('Unknown reservation status.');
        }

        $origin = $this->em->getRepository(ReservationOrigin::class)->find($request->originId);
        if (!$origin instanceof ReservationOrigin) {
            throw new ReservationBookingException('Unknown booking origin.');
        }

        $this->validateBooker($request);

        [$persons, $guestCounts] = $this->resolveOccupancy($request);
        if ($persons < 1) {
            throw new ReservationBookingException('At least one guest who needs a bed is required.');
        }
        if (!$this->availabilityService->hasCapacity($apartment, $persons)) {
            throw new ReservationBookingException(sprintf('The apartment has only %d beds.', $apartment->getBedsMax()));
        }
        if (!$this->availabilityService->isRoomAvailable($apartment, $request->arrival, $request->departure, $persons)) {
            throw new ReservationBookingException('The apartment is not available for this period.');
        }

        return new ReservationBookingPreview(
            $apartment,
            $status,
            $origin,
            $persons,
            $guestCounts,
            $this->collectWarnings($apartment, $request),
            $this->resolveExtras($apartment, $request, $persons, $guestCounts, $origin),
        );
    }

    /**
     * Validates and saves the reservation. Availability is checked again while holding a row lock
     * on the room, so two concurrent requests cannot both book it.
     *
     * @throws ReservationBookingException
     */
    public function create(ReservationBookingRequest $request): Reservation
    {
        $preview = $this->preview($request);

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $this->em->lock($preview->apartment, LockMode::PESSIMISTIC_WRITE);
            if (!$this->availabilityService->isRoomAvailable($preview->apartment, $request->arrival, $request->departure, $preview->persons)) {
                throw new ReservationBookingException('The apartment is not available for this period.');
            }

            $reservation = new Reservation();
            $reservation->setAppartment($preview->apartment);
            $reservation->setStartDate(new \DateTime($request->arrival->format('Y-m-d')));
            $reservation->setEndDate(new \DateTime($request->departure->format('Y-m-d')));
            $reservation->setGuestCounts($preview->guestCounts);
            $reservation->setPersons($preview->persons);
            $reservation->setReservationStatus($preview->status);
            $reservation->setReservationOrigin($preview->origin);
            $reservation->setBooker($this->resolveCustomer($request));
            $reservation->setUuid(Uuid::v4());
            $reservation->setBookingGroupUuid(Uuid::v4());
            foreach ($preview->extras as $extra) {
                $reservation->addPrice($extra);
            }
            $remark = self::sanitize($request->remark ?? '', self::REMARK_MAX_LENGTH);
            if ('' !== $remark) {
                $reservation->setRemark($remark);
            }

            $this->em->persist($reservation);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }

        return $reservation;
    }

    /**
     * The misc prices (breakfast, dog, final cleaning, ...) to attach, chosen from those that apply
     * to this stay like in the back-office form: same origin, period, room category and at least one
     * valid night (flat prices always). Without an explicit choice the prices marked "active by
     * default when creating a reservation" are taken, as staff would get them preselected.
     *
     * @param array<int, int> $guestCounts
     *
     * @return list<Price>
     */
    private function resolveExtras(Appartment $apartment, ReservationBookingRequest $request, int $persons, array $guestCounts, ReservationOrigin $origin): array
    {
        $sample = $this->pricingService->buildSampleReservation($apartment, $persons, $request->arrival, $request->departure, $origin, $guestCounts);
        $nights = (int) $request->arrival->diff($request->departure)->days;

        $applicable = [];
        foreach ($this->priceRepository->findMiscPrices($sample) as $price) {
            if ($price->getIsFlatPrice() || $this->pricingService->countValidDays($sample, $price, $nights) > 0) {
                $applicable[(int) $price->getId()] = $price;
            }
        }

        if (null === $request->extraPriceIds) {
            return array_values(array_filter($applicable, static fn (Price $price): bool => (bool) $price->getIsDefaultActiveInReservationCreation()));
        }

        $extras = [];
        foreach (array_unique($request->extraPriceIds) as $id) {
            if (!isset($applicable[$id])) {
                $offered = array_map(static fn (Price $price): string => sprintf('%d (%s)', $price->getId(), $price->getDescription()), $applicable);
                throw new ReservationBookingException(sprintf(
                    'Extra %d is not available for this stay. Available: %s.',
                    $id,
                    [] === $offered ? 'none' : implode(', ', $offered)
                ));
            }
            $extras[] = $applicable[$id];
        }

        return $extras;
    }

    private function validateBooker(ReservationBookingRequest $request): void
    {
        if (null !== $request->customerId) {
            // Customer 1 is the shared "anonymous" record that anonymized guests are moved to.
            if ($request->customerId <= 1 || !$this->customers()->find($request->customerId) instanceof Customer) {
                throw new ReservationBookingException('Unknown customer.');
            }

            return;
        }

        $booker = $request->booker;
        if (!$booker instanceof BookerData || '' === self::sanitize($booker->lastname, 45)) {
            throw new ReservationBookingException('A booker is required: pass an existing customer id or at least the last name.');
        }
        if (null !== $booker->email && '' !== trim($booker->email) && !filter_var(trim($booker->email), \FILTER_VALIDATE_EMAIL)) {
            throw new ReservationBookingException('The booker email address is invalid.');
        }
        if (null !== $booker->salutation && '' !== trim($booker->salutation)
            && !\in_array(trim($booker->salutation), $this->appSettingsService->getSettings()->getCustomerSalutations(), true)
        ) {
            throw new ReservationBookingException(sprintf(
                'Unknown salutation. Allowed: %s.',
                implode(', ', $this->appSettingsService->getSettings()->getCustomerSalutations())
            ));
        }
    }

    /**
     * Mirrors the back-office form: without guest counts all guests count as the default adult
     * category (when one is configured); with counts, the occupancy follows from them.
     *
     * @return array{0: int, 1: array<int, int>} persons and effective guest counts
     */
    private function resolveOccupancy(ReservationBookingRequest $request): array
    {
        $guestCounts = $request->guestCounts;
        if ([] === $guestCounts) {
            $defaultAdult = $this->guestCategoryRepository->findDefaultAdult();
            if (null === $defaultAdult) {
                return [$request->persons, []];
            }
            $guestCounts = [(int) $defaultAdult->getId() => $request->persons];
        }

        $probe = new Reservation();
        $probe->setGuestCounts($guestCounts);
        if (!$this->reservationService->isAdultRuleSatisfied($probe)) {
            throw new ReservationBookingException('At least one adult is required.');
        }

        $persons = $this->reservationService->computePersonsFromCounts($guestCounts);

        return [$persons > 0 ? $persons : $request->persons, $guestCounts];
    }

    /**
     * @return list<string>
     */
    private function collectWarnings(Appartment $apartment, ReservationBookingRequest $request): array
    {
        $category = $apartment->getRoomCategory();
        if (null === $category) {
            return [];
        }

        $result = $this->bookingRestrictionService->checkStay($category, $request->arrival, $request->departure);
        if ($result->isAllowed()) {
            return [];
        }

        return [sprintf(
            'Online booking restriction "%s" applies%s; staff bookings are still allowed.',
            $result->reason?->value,
            null !== $result->ruleDate ? ' on '.$result->ruleDate->format('Y-m-d') : ''
        )];
    }

    private function resolveCustomer(ReservationBookingRequest $request): Customer
    {
        if (null !== $request->customerId) {
            $customer = $this->customers()->find($request->customerId);
            if (!$customer instanceof Customer) {
                throw new ReservationBookingException('Unknown customer.');
            }

            return $customer;
        }

        /** @var BookerData $booker validated in preview() */
        $booker = $request->booker;
        $email = mb_strtolower(self::sanitize($booker->email ?? '', 180));
        if ('' !== $email) {
            $existing = $this->customers()->findOneByEmailCaseInsensitive($email);
            if ($existing instanceof Customer) {
                // Linked as is: an assistant must not be able to rewrite a guest's contact data.
                return $existing;
            }
        }

        $customer = new Customer();
        $customer->setSalutation(self::sanitize($booker->salutation ?? '', 20));
        $customer->setFirstname(self::sanitize($booker->firstname ?? '', 45) ?: null);
        $customer->setLastname(self::sanitize($booker->lastname, 45));

        $address = new CustomerAddresses();
        $address->setType('CUSTOMER_ADDRESS_TYPE_PRIVATE');
        $address->setEmail('' !== $email ? $email : null);
        $address->setPhone(self::sanitize($booker->phone ?? '', 50) ?: null);
        $customer->addCustomerAddress($address);

        $this->em->persist($address);
        $this->em->persist($customer);

        return $customer;
    }

    private function customers(): CustomerRepository
    {
        /** @var CustomerRepository $repository */
        $repository = $this->em->getRepository(Customer::class);

        return $repository;
    }

    /** Trims, removes control characters (except tab and line breaks) and cuts to length. */
    private static function sanitize(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', trim($value)) ?? '';

        return mb_substr($value, 0, $maxLength);
    }
}
