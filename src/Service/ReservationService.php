<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <alex.pensionsverwaltung@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Service\ReservationObject;
use App\Interfaces\ITemplateRenderer;


class ReservationService implements ITemplateRenderer
{
    private $em;
	private $session;

    public function __construct(EntityManagerInterface $em, SessionInterface $session)
    {
        $this->em = $em;
		$this->session = $session;
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
            $this->session->getFlashBag()->add('success', 'reservation.flash.update.success');
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
                $this->session->getFlashBag()->add('success', 'reservation.flash.update.success');
            } else {
                $this->session->getFlashBag()->add('warning', 'reservation.flash.update.customeralreadyinlist');
            }
        } else {
            $this->session->getFlashBag()->add('warning', 'reservation.flash.update.toomuchcustomers');
        }
    }
    
    public function getRenderParams($template, $param) {
        // params need to be an array containing a list of Reservation Objects
        $params = array(
                'reservations' => $param                 
            );
        return $params;
    }
}
