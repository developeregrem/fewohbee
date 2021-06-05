<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Interfaces\ITemplateRenderer;
use App\Entity\Price;
use App\Service\InvoiceService;
use App\Service\PriceService;


class ReservationService implements ITemplateRenderer
{
    private $em;
    private $requestStack;

    public function __construct(EntityManagerInterface $em, RequestStack $requestStack)
    {
        $this->em = $em;
		$this->requestStack = $requestStack;
    }

    public function isAppartmentAlreadyBookedInCreationProcess($reservations, Appartment $appartment, $start, $end)
    {
        foreach ($reservations as $reservation) {
            if ($appartment->getId() == $reservation->getAppartmentId()) {
                $startReservation = strtotime($reservation->getStart());
                $endReservation = strtotime($reservation->getEnd());

                $startDateToBeChecked = strtotime($start);
                $endDateToBeChecked = strtotime($end);

                if (
                    (($startDateToBeChecked <= $startReservation) && ($endDateToBeChecked >= $endReservation)) ||
                    (($startDateToBeChecked <= $startReservation) && ($endDateToBeChecked <= $endReservation) && ($endDateToBeChecked > $startReservation)) ||
                    (($startDateToBeChecked >= $startReservation) && ($startDateToBeChecked < $endReservation) && ($endDateToBeChecked >= $endReservation)) ||
                    (($startDateToBeChecked >= $startReservation) && ($startDateToBeChecked <= $endReservation) && ($endDateToBeChecked > $startReservation) && ($endDateToBeChecked <= $endReservation))
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    public function createReservationsFromReservationInformationArray($newReservationsInformationArray, Customer $customer = null)
    {
        $reservations = Array();

        foreach ($newReservationsInformationArray as $reservationInformation) {
            $reservation = new Reservation();
            $reservation->setAppartment($this->em->getRepository(Appartment::class)->findById($reservationInformation->getAppartmentId())[0]);
            $reservation->setEndDate(new \DateTime($reservationInformation->getEnd()));
            $reservation->setStartDate(new \DateTime($reservationInformation->getStart()));
            $reservation->setStatus($reservationInformation->getStatus());
            $reservation->setPersons($reservationInformation->getPersons());

            if (isset($customer)) {
                $reservation->setBooker($customer);
            }

            $reservations[] = $reservation;
        }

        return $reservations;
    }

    public function setCustomerInReservationInformationArray(&$newReservationsInformationArray, Customer $customer)
    {
        foreach ($newReservationsInformationArray as $reservationInformation) {
            $reservationInformation->setCustomerId($customer->getId());
        }
    }

    public function deleteReservation($id)
    {
        $reservation = $this->em->getRepository(Reservation::class)->find($id);

        if (count($reservation->getInvoices()) == 0) {
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

    public function updateReservation(Request $request)
    {
        $id = $request->get('id');
        $appartmentId = $request->get('aid');
        $persons = $request->get('persons');
        $status = $request->get('status');
        $start = new \DateTime($request->get('from'));
        $end = new \DateTime($request->get('end'));
        $remark = $request->get('remark');
        $origin = $this->em->getRepository(ReservationOrigin::class)->find($request->get('reservation-origin'));
        $dateInterval = date_diff($start, $end);
        // number of days
        $interval = $dateInterval->format('%a');

        $reservation = $this->em->getRepository(Reservation::class)->findById($id)[0];
        $appartment = $this->em->getRepository(Appartment::class)->findById($appartmentId)[0];
        
        if($start > $end) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }

        $reservationsArray = Array(); // holds the reservations that are in conflict with current reservation
        $reservationsForAppartment = $this->em->getRepository(Reservation::class)
            ->loadReservationsForPeriodForSingleAppartmentWithoutStartAndEndDate($start->getTimestamp(), $interval, $appartment);
        if (is_array($reservationsForAppartment)) {
            foreach ($reservationsForAppartment as $reservationForAppartment) {
                // we dont need the reservation that we want to update
                if ($reservationForAppartment->getId() !== $reservation->getId()) {
                    $reservationsArray[] = $reservationForAppartment;
                }
            }
        }
        // update reservation if no other stands in conflict
        if (count($reservationsArray) == 0) {
            $reservation->setStartDate($start);
            $reservation->setEndDate($end);
        }
        $reservation->setAppartment($appartment);
        $reservation->setStatus($status);
        $reservation->setPersons($persons);
        $reservation->setRemark($remark);
        $reservation->setReservationOrigin($origin);

        $this->em->persist($reservation);
        $this->em->flush();

        return $reservationsArray;
    }

    public function updateReservationCustomers($reservation, $customer, $tab)
    {
        if ($tab === 'booker') {
            $reservation->setBooker($customer);
            $this->em->persist($reservation);
            $this->em->flush();
            $this->requestStack->getSession()->getFlashBag()->add('success', 'reservation.flash.update.success');
        } else if ($tab === 'guest' && count($reservation->getCustomers()) < $reservation->getPersons()) {
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
     * Toggles a selected or deselected price for reservations in creation and stores them in the session
     * @param Price $price
     * @param RequestStack $requestStack
     */
    public function toggleInCreationPrice(Price $price, RequestStack $requestStack) {
        $prices = $requestStack->getSession()->get("reservatioInCreationPrices", new ArrayCollection()); 
        
        $exists = $prices->exists(function($key, $value) use ($price) {
                    return $value->getId() === $price->getId();
                });

        if(!$exists) {
            $prices[] = $price;
        } else {
            $toDeleteKeys = $prices->filter(function($element) use ($price) {
                return $element->getId() === $price->getId();
            })
            ->getKeys();
            $prices->remove($toDeleteKeys[0]);
        }
        
        $requestStack->getSession()->set("reservatioInCreationPrices", $prices);
    }
    
    /**
     * Returns an array of prices for reservations in creation
     * @param InvoiceService $is
     * @param array $reservations
     * @param PriceService $ps
     * @param RequestStack $requestStack
     * @return type
     */
    public function getMiscPricesInCreation(InvoiceService $is, array $reservations, PriceService $ps, RequestStack $requestStack) {
        // prices will be filles based on already selected prices
        if($requestStack->getSession()->get("reservatioInCreationPrices", null) !== null) {
            $prices = $requestStack->getSession()->get("reservatioInCreationPrices");
            $requestStack->getSession()->set("invoicePositionsMiscellaneous", []);
            
            // assign selected prices to reservations for getting the invoice price positions based on that prices
            $reservations = $this->setPricesToReservations($prices, $reservations);
            $is->prefillMiscPositionsWithReservations($reservations, $session, true);
            
            return $requestStack->getSession()->get("invoicePositionsMiscellaneous");
        } else { // initial load of preview new reservation, prices will be filled based on price categories     
            $prices = $ps->getUniquePricesForReservations($reservations, 1);
            // prefill reservatioInCreationPrices session
            foreach($prices as $price) {
                $this->toggleInCreationPrice($price, $session);
            }
            $requestStack->getSession()->set("invoicePositionsMiscellaneous", []);
            $is->prefillMiscPositionsWithReservations($reservations, $session);    
            
            return $requestStack->getSession()->get("invoicePositionsMiscellaneous");
        }
    }
    
    /**
     * Adds the given prices to the reservations
     * @param Collection $prices
     * @param array $reservations
     * @return array
     */
    private function setPricesToReservations(Collection $prices, array $reservations) {
        foreach($reservations as $reservation) {
            foreach($prices as $price) {
                $reservation->addPrice($price);
            }
        }
        return $reservations;
    }
    
    /**
     * Based on given Reservation IDs the coresponding Invoices will be returned
     * @return array
     */
    public function getInvoicesForReservationsInProgress() {
        $ids = $this->requestStack->getSession()->get("selectedReservationIds");
        $totalInvoices = [];
        foreach ($ids as $reservationId) {
            $reservation = $this->em->find(Reservation::class, $reservationId);
            if(!$reservation instanceof Reservation) {
                continue;
            }
            $invoices = $reservation->getInvoices();
            if(count($invoices) > 0) {
                $totalInvoices = array_merge($totalInvoices, $invoices->toArray());
            }
        }
        return $totalInvoices;
    }
    
    public function getRenderParams($template, $param) {
        // params need to be an array containing a list of Reservation Objects
        $params = array(
                'reservation1' => $param[0],
                'address' => (count($param[0]->getBooker()->getCustomerAddresses()) == 0 ? null : $param[0]->getBooker()->getCustomerAddresses()[0]),
                'reservations' => $param                 
            );
        return $params;
    }
}
