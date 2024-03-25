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

use App\Controller\CustomerServiceController;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\RegistrationBookEntry;
use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;

class RegistrationBookService
{
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function addBookEntriesFromReservation($reservationId)
    {
        $reservation = $this->em->getRepository(Reservation::class)->find($reservationId);
        $customers = $reservation->getCustomers();
        /* @var $customer Customer */
        foreach ($customers as $customer) {
            $entry = new RegistrationBookEntry();
            $entry->setNumber('1');
            $entry->setSalutation($customer->getSalutation());
            $entry->setFirstname($customer->getFirstname());
            $entry->setLastname($customer->getLastname());
            $entry->setBirthday($customer->getBirthday());
            $addresses = $customer->getCustomerAddresses();
            $cAddress = new CustomerAddresses();
            /* @var $address CustomerAddresses */
            foreach ($addresses as $address) {
                $cAddress = $address;
                if ($address->getType() == CustomerServiceController::$addessTypes[1]) {
                    break;
                }
            }
            $entry->setCompany($cAddress->getCompany());
            $entry->setAddress($cAddress->getAddress());
            $entry->setCity($cAddress->getCity());
            $entry->setZip($cAddress->getZip());
            $entry->setCountry($cAddress->getCountry());
            $entry->setReservation($reservation);
            $entry->setIdType($customer->getIdType());
            $entry->setIDNumber($customer->getIDNumber());
            $entry->setCustomer($customer);
            $entry->setYear($reservation->getStartDate()->format('Y'));
            $this->em->persist($entry);
        }
        $this->em->flush();

        return true;
    }

    /**
     * Delete registration book entry.
     *
     * @param int $id
     *
     * @return bool
     */
    public function deleteEntry($id)
    {
        /* @var $entry \Pensionsverwaltung\Database\Entity\RegistrationBookEntry */
        $entry = $this->em->getRepository(RegistrationBookEntry::class)->find($id);

        if ($entry instanceof RegistrationBookEntry) {
            $this->em->remove($entry);
            $this->em->flush();

            return true;
        }

        return false;
    }
}
