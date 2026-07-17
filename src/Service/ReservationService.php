<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Template;
use App\Event\ReservationStatusChangedEvent;
use App\Repository\GuestCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ReservationService
{
    public const SESSION_SELECTED_RESERVATIONS = 'selectedReservationIds';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly InvoiceService $is,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly GuestCategoryRepository $guestCategoryRepository,
        private readonly AvailabilityService $availabilityService,
    ) {
    }

    /**
     * Apply a guest-counts map to the reservation and recompute persons
     * as the sum of categories flagged isCountedInOccupancy.
     *
     * @param array<int, int> $counts
     */
    public function applyGuestCounts(Reservation $reservation, array $counts): void
    {
        $reservation->setGuestCounts($counts);
        $reservation->setPersons($this->computePersonsFromCounts($counts));
    }

    /**
     * Sum of counts for categories with isCountedInOccupancy=true.
     *
     * @param array<int, int> $counts
     */
    public function computePersonsFromCounts(array $counts): int
    {
        if ([] === $counts) {
            return 0;
        }
        $categories = $this->guestCategoryRepository->findBy(['id' => array_keys($counts)]);
        $sum = 0;
        foreach ($categories as $category) {
            if ($category->isCountedInOccupancy()) {
                $sum += (int) ($counts[(int) $category->getId()] ?? 0);
            }
        }

        return $sum;
    }

    /**
     * Returns the total number of guests in the reservation that match
     * the given boolean flag accessor on the GuestCategory entity.
     *
     * @param 'isAdult'|'isCountedInOccupancy' $flag
     */
    public function getCountByFlag(Reservation $reservation, string $flag): int
    {
        $counts = $reservation->getGuestCounts();
        if ([] === $counts) {
            return 0;
        }
        $categories = $this->guestCategoryRepository->findBy(['id' => array_keys($counts)]);
        $sum = 0;
        foreach ($categories as $category) {
            $matches = match ($flag) {
                'isAdult' => $category->isAdult(),
                'isCountedInOccupancy' => $category->isCountedInOccupancy(),
                default => false,
            };
            if ($matches) {
                $sum += (int) ($counts[(int) $category->getId()] ?? 0);
            }
        }

        return $sum;
    }

    /**
     * Validates the "at least one adult" rule. Returns true if the booking
     * is allowed (has at least one adult OR adultRuleOverride is set).
     */
    public function isAdultRuleSatisfied(Reservation $reservation): bool
    {
        if ($reservation->isAdultRuleOverride()) {
            return true;
        }

        return $this->getCountByFlag($reservation, 'isAdult') >= 1;
    }

    public function changeStatus(Reservation $reservation, ?ReservationStatus $newStatus, bool $flush = true): void
    {
        $previous = $reservation->getReservationStatus();
        if ($previous === $newStatus) {
            return;
        }
        $reservation->setReservationStatus($newStatus);
        if ($flush) {
            $this->em->flush();
        }
        $this->eventDispatcher->dispatch(new ReservationStatusChangedEvent($reservation, $previous));
    }

    public function resetSelectedReservations(): void
    {
        $this->requestStack->getSession()->set(self::SESSION_SELECTED_RESERVATIONS, []);
    }

    public function addReservationToSelection(int $reservationId): void
    {
        $selectedReservationIds = $this->getSelectedReservationIds();

        if (!in_array($reservationId, $selectedReservationIds, true)) {
            $selectedReservationIds[] = $reservationId;
            $this->requestStack->getSession()->set(self::SESSION_SELECTED_RESERVATIONS, $selectedReservationIds);
        }
    }

    /**
     * Adds the reservation with the given ID and all other reservations of the same booking group to the selection.
     */
    public function addReservationGroupToSelection(int $reservationId): void
    {
        $reservation = $this->em->getRepository(Reservation::class)->find($reservationId);
        if (!$reservation instanceof Reservation) {
            return;
        }

        $this->addReservationToSelection($reservationId);

        $bookingGroupUuid = $reservation->getBookingGroupUuid();
        if (null === $bookingGroupUuid) {
            return;
        }

        $groupReservations = $this->em->getRepository(Reservation::class)->findByBookingGroupUuid($bookingGroupUuid);
        foreach ($groupReservations as $groupReservation) {
            $this->addReservationToSelection((int) $groupReservation->getId());
        }
    }

    public function removeReservationFromSelection(int|string $reservationKey): void
    {
        $selectedReservationIds = $this->getSelectedReservationIds();

        if (array_key_exists($reservationKey, $selectedReservationIds)) {
            unset($selectedReservationIds[$reservationKey]);
            $this->requestStack->getSession()->set(self::SESSION_SELECTED_RESERVATIONS, $selectedReservationIds);
        }
    }

    public function getSelectedReservationIds(): array
    {
        return $this->requestStack->getSession()->get(self::SESSION_SELECTED_RESERVATIONS, []);
    }

    public function hasSelectedReservations(): bool
    {
        return count($this->getSelectedReservationIds()) > 0;
    }

    /**
     * Returns the selected Reservation Objects.
     *
     * @return Reservation[]
     */
    public function getSelectedReservations(): array
    {
        $reservations = [];
        foreach ($this->getSelectedReservationIds() as $reservationId) {
            $reservation = $this->em->getRepository(Reservation::class)->find($reservationId);
            if ($reservation instanceof Reservation) {
                $reservations[] = $reservation;
            }
        }

        return $reservations;
    }

    public function isReservationAlreadySelected(int $reservationId): bool
    {
        return in_array($reservationId, $this->getSelectedReservationIds(), true);
    }

    public function isAppartmentAlreadyBookedInCreationProcess($reservations, Appartment $apartment, \DateTimeInterface $start, \DateTimeInterface $end)
    {
        foreach ($reservations as $reservation) {
            if ($apartment->getId() == $reservation->getAppartmentId()) {
                $startReservation = strtotime($reservation->getStart());
                $endReservation = strtotime($reservation->getEnd());
                $persons = $reservation->getPersons();
                $bedsMax = $apartment->getBedsMax();

                $startDateToBeChecked = $start->getTimestamp();
                $endDateToBeChecked = $end->getTimestamp();

                if (
                    (($startDateToBeChecked <= $startReservation) && ($endDateToBeChecked >= $endReservation))
                    || (($startDateToBeChecked <= $startReservation) && ($endDateToBeChecked <= $endReservation) && ($endDateToBeChecked > $startReservation))
                    || (($startDateToBeChecked >= $startReservation) && ($startDateToBeChecked < $endReservation) && ($endDateToBeChecked >= $endReservation))
                    || (
                        ($startDateToBeChecked >= $startReservation) && ($startDateToBeChecked <= $endReservation) && ($endDateToBeChecked > $startReservation) && ($endDateToBeChecked <= $endReservation)
                    )
                ) {
                    // still some space free in room
                    if ($apartment->isMultipleOccupancy() && ($persons < $bedsMax)) {
                        return false;
                    }

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns a list of apartments that can be selected (free) for the given period.
     */
    public function getAvailableApartments(\DateTimeInterface $start, \DateTimeInterface $end, ?Appartment $apartment = null, string $propertyId = 'all'): array
    {
        if ($apartment instanceof Appartment) {
            $propertyId = $apartment->getObject()->getId();
        }
        $result = [];
        if ('all' == $propertyId) {
            $appartments = $this->em->getRepository(Appartment::class)->findAll();
        } else {
            $appartments = $this->em->getRepository(Appartment::class)->findBy(['object' => $propertyId, 'active' => true]);
        }
        $availableApartments = [];
        foreach ($appartments as $ap) {
            $available = $this->isApartmentAvailable($start, $end, $ap, 0);
            if ($available) {
                $availableApartments[] = $ap;
            }
        }

        $newReservationsInformationArray = $this->requestStack->getSession()->get('reservationInCreation', []);

        // during creation process remove reservation that is already in session
        if (0 != count($newReservationsInformationArray)) {
            foreach ($availableApartments as $apartment) {
                if (!$this->isAppartmentAlreadyBookedInCreationProcess($newReservationsInformationArray, $apartment, $start, $end)) {
                    $result[] = $apartment;
                }
            }
        } else {
            $result = $availableApartments;
        }

        return $result;
    }

    /**
     * Checks whether the provided apartment can be selected for the given period.
     */
    public function isApartmentSelectable(\DateTimeInterface $start, \DateTimeInterface $end, Appartment $apartment): bool
    {
        $apartments = $this->getAvailableApartments($start, $end, $apartment);
        $result = false;
        foreach ($apartments as $a) {
            if ($a->getId() === $apartment->getId()) {
                return true;
            }
        }

        return $result;
    }

    public function createReservationsFromReservationInformationArray($newReservationsInformationArray, ?Customer $customer = null)
    {
        $reservations = [];

        foreach ($newReservationsInformationArray as $reservationInformation) {
            $reservation = new Reservation();
            $reservation->setAppartment($this->em->getRepository(Appartment::class)->findById($reservationInformation->getAppartmentId())[0]);
            $reservation->setEndDate(new \DateTime($reservationInformation->getEnd()));
            $reservation->setStartDate(new \DateTime($reservationInformation->getStart()));
            $reservation->setReservationStatus($this->em->getRepository(ReservationStatus::class)->find($reservationInformation->getReservationStatus()));
            $reservation->setAdultRuleOverride($reservationInformation->isAdultRuleOverride());
            $reservation->setKurtaxeWaived($reservationInformation->isKurtaxeWaived());

            $counts = $reservationInformation->getGuestCounts();
            if ([] !== $counts) {
                $this->applyGuestCounts($reservation, $counts);
            } else {
                // Fallback: legacy persons-only path → bucket into the default adult category
                $persons = (int) $reservationInformation->getPersons();
                $defaultAdult = $this->guestCategoryRepository->findDefaultAdult();
                if ($persons > 0 && null !== $defaultAdult) {
                    $this->applyGuestCounts($reservation, [(int) $defaultAdult->getId() => $persons]);
                } else {
                    $reservation->setPersons($persons);
                }
            }

            if (isset($customer)) {
                $reservation->setBooker($customer);
            }

            $reservations[] = $reservation;
        }

        return $reservations;
    }

    /**
     * Returns the default reservation status ID that should be preselected in the reservation creation process.
     */
    public function getDefaultSelectableReservationStatusId(): int
    {
        $repository = $this->em->getRepository(ReservationStatus::class);
        $status = $repository->findOneBy(['code' => null], ['id' => 'ASC']);

        if (!$status instanceof ReservationStatus) {
            $status = $repository->findOneBy([], ['id' => 'ASC']);
        }

        return $status instanceof ReservationStatus ? (int) $status->getId() : 1;
    }

    public function setCustomerInReservationInformationArray(&$newReservationsInformationArray, Customer $customer): void
    {
        foreach ($newReservationsInformationArray as $reservationInformation) {
            $reservationInformation->setCustomerId($customer->getId());
        }
    }

    public function deleteReservation($id)
    {
        $reservation = $this->em->getRepository(Reservation::class)->find($id);

        if (0 == count($reservation->getInvoices())) {
            $customers = $reservation->getCustomers();

            foreach ($customers as $customer) {
                $reservation->removeCustomer($customer);
            }
            $this->em->persist($reservation);

            $this->em->remove($reservation);
            $this->em->flush();

            return true;
        } else {
            return false;
        }
    }

    public function updateReservation(Request $request, Reservation $reservation): bool
    {
        $apartmentId = $request->request->get('aid');
        $status = $request->request->get('status');
        $start = new \DateTime($request->request->get('from'));
        $end = new \DateTime($request->request->get('end'));

        $guestCountsRaw = $request->request->get('guestCounts', '{}');
        $guestCounts = is_string($guestCountsRaw) ? (json_decode($guestCountsRaw, true) ?: []) : [];
        $adultRuleOverride = (bool) $request->request->get('adultRuleOverride', false);
        $kurtaxeWaived = (bool) $request->request->get('kurtaxeWaived', $reservation->isKurtaxeWaived());

        $apartment = $this->em->getRepository(Appartment::class)->find($apartmentId);
        $reservationStatus = $this->em->getRepository(ReservationStatus::class)->find($status);
        if (!$reservationStatus instanceof ReservationStatus) {
            // Moving a room must not clear the required status when a dynamic
            // options form does not submit a status value.
            $reservationStatus = $reservation->getReservationStatus();
        }

        // Never apply a partial update when a submitted relation cannot be resolved.
        if (!$apartment instanceof Appartment || !$reservationStatus instanceof ReservationStatus) {
            return false;
        }

        if ($start > $end) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }

        // Occupancy data is needed for the availability check below. Keep its
        // previous state so a rejected move leaves the managed entity untouched.
        $previousGuestCounts = $reservation->getGuestCounts();
        $previousPersons = $reservation->getPersons();
        $previousAdultRuleOverride = $reservation->isAdultRuleOverride();
        $previousKurtaxeWaived = $reservation->isKurtaxeWaived();
        $restoreOccupancy = static function () use (
            $reservation,
            $previousGuestCounts,
            $previousPersons,
            $previousAdultRuleOverride,
            $previousKurtaxeWaived,
        ): void {
            $reservation->setGuestCounts($previousGuestCounts);
            $reservation->setPersons($previousPersons);
            $reservation->setAdultRuleOverride($previousAdultRuleOverride);
            $reservation->setKurtaxeWaived($previousKurtaxeWaived);
        };

        // Apply guest counts (or fall back to legacy persons-only path)
        $reservation->setAdultRuleOverride($adultRuleOverride);
        $reservation->setKurtaxeWaived($kurtaxeWaived);
        if ([] !== $guestCounts) {
            $this->applyGuestCounts($reservation, $guestCounts);
        } else {
            $persons = (int) $request->request->get('persons', 0);
            $defaultAdult = $this->guestCategoryRepository->findDefaultAdult();
            if ($persons > 0 && null !== $defaultAdult) {
                $this->applyGuestCounts($reservation, [(int) $defaultAdult->getId() => $persons]);
            } else {
                // Keep both occupancy representations consistent. Previously
                // persons=0 left stale guestCounts on the reservation.
                $reservation->setGuestCounts([]);
                $reservation->setPersons($persons);
            }
        }

        // Hard "at least one adult" validation
        if (!$this->isAdultRuleSatisfied($reservation)) {
            $restoreOccupancy();

            return false;
        }

        if (!$this->moveReservation($reservation, $apartment, $start, $end, flush: false)) {
            $restoreOccupancy();

            return false;
        }

        $this->changeStatus($reservation, $reservationStatus, flush: false);

        $this->em->persist($reservation);
        $this->em->flush();

        return true;
    }

    /**
     * Move one reservation to another period and/or room after a central
     * availability check. No reservation data is changed on conflict.
     */
    public function moveReservation(
        Reservation $reservation,
        Appartment $apartment,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        bool $flush = true,
    ): bool {
        if (!$this->availabilityService->isRoomAvailable(
            $apartment,
            $start,
            $end,
            $reservation->getPersons(),
            $reservation,
        )) {
            return false;
        }

        $reservation->setStartDate(\DateTime::createFromInterface($start));
        $reservation->setEndDate(\DateTime::createFromInterface($end));
        $reservation->setAppartment($apartment);

        if ($flush) {
            $this->em->persist($reservation);
            $this->em->flush();
        }

        return true;
    }

    /**
     * Check if an apartment is available for the given time.
     */
    public function isApartmentAvailable(\DateTimeInterface $start, \DateTimeInterface $end, Appartment $apartment, int $numberOfPersons, ?Reservation $reservation = null): bool
    {
        return $this->availabilityService->isRoomAvailable($apartment, $start, $end, $numberOfPersons, $reservation);
    }

    public function updateReservationCustomers($reservation, $customer, $tab): void
    {
        if ('booker' === $tab) {
            $reservation->setBooker($customer);
            $this->em->persist($reservation);
            $this->em->flush();
            $this->requestStack->getSession()->getFlashBag()->add('success', 'reservation.flash.update.success');
        } elseif ('guest' === $tab && count($reservation->getCustomers()) < $reservation->getPersons()) {
            // check if customer is already in list
            $isAlreadyInList = false;
            $customers = $reservation->getCustomers();
            foreach ($customers as $customerItem) {
                if ($customerItem->getId() == $customer->getId()) {
                    $isAlreadyInList = true;
                    break;
                }
            }

            if (!$isAlreadyInList) {
                // if we add a customer and room is not overbooked (more customers in it than allowed)
                $reservation->addCustomer($customer);
                $this->em->persist($reservation);
                $this->em->flush();
                $this->requestStack->getSession()->getFlashBag()->add('success', 'reservation.flash.update.success');
            } else {
                $this->requestStack->getSession()->getFlashBag()->add('warning', 'reservation.flash.update.customeralreadyinlist');
            }
        } else {
            $this->requestStack->getSession()->getFlashBag()->add('warning', 'reservation.flash.update.toomuchcustomers');
        }
    }

    /**
     * Toggles a selected or deselected price for reservations in creation and stores them in the session.
     */
    public function toggleInCreationPrice(Price $price, RequestStack $requestStack): void
    {
        if (!$price->getActive()) {
            return;
        }

        $prices = $requestStack->getSession()->get('reservatioInCreationPrices', new ArrayCollection());

        $exists = $prices->exists(fn ($key, $value) => $value->getId() === $price->getId());

        if (!$exists) {
            $prices[] = $price;
        } else {
            $toDeleteKeys = $prices->filter(fn ($element) => $element->getId() === $price->getId())
            ->getKeys();
            $prices->remove($toDeleteKeys[0]);
        }

        $requestStack->getSession()->set('reservatioInCreationPrices', $prices);
    }

    /**
     * Returns an array of prices for reservations in creation.
     *
     * @return type
     */
    public function getMiscPricesInCreation(InvoiceService $is, array $reservations, PriceService $ps, RequestStack $requestStack): array
    {
        // prices will be filles based on already selected prices
        if (null !== $requestStack->getSession()->get('reservatioInCreationPrices', null)) {
            $prices = $requestStack->getSession()->get('reservatioInCreationPrices');
            $prices = $prices->filter(static fn (Price $price) => $price->getActive());
            $requestStack->getSession()->set('reservatioInCreationPrices', $prices);
            $requestStack->getSession()->set('invoicePositionsMiscellaneous', []);

            // assign selected prices to reservations for getting the invoice price positions based on that prices
            $reservations = $this->setPricesToReservations($prices, $reservations);
            $is->prefillMiscPositionsWithReservations($reservations, $requestStack, true);

            return $requestStack->getSession()->get('invoicePositionsMiscellaneous');
        } else { // initial load of preview new reservation, prices will be filled based on price categories
            $prices = $ps->getUniquePricesForReservations($reservations, 1);
            // prefill reservatioInCreationPrices session
            foreach ($prices as $price) {
                if ($price->getIsDefaultActiveInReservationCreation()) {
                    $this->toggleInCreationPrice($price, $requestStack);
                }
            }
            $selectedPrices = $requestStack->getSession()->get('reservatioInCreationPrices', new ArrayCollection());
            $requestStack->getSession()->set('invoicePositionsMiscellaneous', []);
            $reservations = $this->setPricesToReservations($selectedPrices, $reservations);
            $is->prefillMiscPositionsWithReservations($reservations, $requestStack, true);

            return $requestStack->getSession()->get('invoicePositionsMiscellaneous');
        }
    }

    /**
     * Adds the given prices to the reservations.
     *
     * @return array
     */
    private function setPricesToReservations(Collection $prices, array $reservations)
    {
        foreach ($reservations as $reservation) {
            foreach ($prices as $price) {
                $reservation->addPrice($price);
            }
        }

        return $reservations;
    }

    /**
     * Based on given Reservation IDs the coresponding Invoices will be returned.
     *
     * @return array
     */
    public function getInvoicesForReservationsInProgress()
    {
        $ids = $this->getSelectedReservationIds();
        $totalInvoices = [];
        foreach ($ids as $reservationId) {
            $reservation = $this->em->find(Reservation::class, $reservationId);
            if (!$reservation instanceof Reservation) {
                continue;
            }
            $invoices = $reservation->getInvoices();
            if (count($invoices) > 0) {
                $totalInvoices = array_merge($totalInvoices, $invoices->toArray());
            }
        }

        return $totalInvoices;
    }

    /**
     * Collects the sum of all prices for the given reservations, e.g. to be used in templates.
     */
    private function getTotalPricesForTemplate(array $reservations): array
    {
        $invoicePositionsMiscellaneousArray = $this->is->buildMiscPositions($reservations, true);

        $invoicePositionsAppartmentsArray = [];
        foreach ($reservations as $reservation) {
            array_push($invoicePositionsAppartmentsArray, ...$this->is->buildAppartmentPositions($reservation));
        }

        // collect sums
        $sumApartment = 0;
        $sumMisc = 0;
        /* @var $position \App\Entity\InvoicePosition */
        foreach ($invoicePositionsMiscellaneousArray as $position) {
            $sumMisc += $position->getTotalPriceRaw();
        }

        /* @var $position \App\Entity\InvoiceAppartment */
        foreach ($invoicePositionsAppartmentsArray as $position) {
            $sumApartment += $position->getTotalPriceRaw();
        }

        return [
            'sumApartmentRaw' => $sumApartment,
            'sumMiscRaw' => $sumMisc,
            'totalPriceRaw' => $sumApartment + $sumMisc,
            'sumApartment' => number_format($sumApartment, 2, ',', '.'),
            'sumMisc' => number_format($sumMisc, 2, ',', '.'),
            'totalPrice' => number_format($sumApartment + $sumMisc, 2, ',', '.'),
            'apartmentPositions' => $invoicePositionsAppartmentsArray,
            'miscPositions' => $invoicePositionsMiscellaneousArray,
        ];
    }

    /**
     * Build all reservation variables required by template rendering.
     */
    public function buildTemplateRenderParams(Template $template, mixed $param): array
    {
        // params need to be an array containing a list of Reservation Objects
        $params = [
            'reservation1' => $param[0],
            'address' => (0 == count($param[0]->getBooker()->getCustomerAddresses()) ? new CustomerAddresses() : $param[0]->getBooker()->getCustomerAddresses()[0]),
            'reservations' => $param,
        ];
        $prices = $this->getTotalPricesForTemplate($param);

        return array_merge($params, $prices);
    }
}
