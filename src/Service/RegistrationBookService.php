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

use App\Entity\RegistrationBookEntry;
use App\Entity\CustomerAddresses;
use App\Entity\Reservation;
use App\Controller\CustomerServiceController;

class RegistrationBookService
{

    private $em = null;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function addBookEntriesFromReservation($reservationId)
    {
        $reservation = $this->em->getRepository(Reservation::class)->find($reservationId);
        $customers = $reservation->getCustomers();
        /* @var $customer \App\Entity\Customer */
        foreach ($customers as $customer) {
            $entry = new RegistrationBookEntry();
            $entry->setNumber("1");
            $entry->setSalutation($customer->getSalutation());
            $entry->setFirstname($customer->getFirstname());
            $entry->setLastname($customer->getLastname());
            $entry->setBirthday($customer->getBirthday());
            $addresses = $customer->getCustomerAddresses();
            $cAddress = new CustomerAddresses();
            /* @var $address \App\Entity\CustomerAddresses */
            foreach($addresses as $address) {
                if($address->getType() == CustomerServiceController::$addessTypes[1]) {
                    $cAddress = $address;
                    break;
                } else {
                    $cAddress = $address;
                }
            }
            $entry->setCompany($cAddress->getCompany());
            $entry->setAddress($cAddress->getAddress());
            $entry->setCity($cAddress->getCity());
            $entry->setZip($cAddress->getZip());
            $entry->setCountry($cAddress->getCountry());
            $entry->setReservation($reservation);
            $entry->setCustomer($customer);
            $entry->setYear($reservation->getStartDate()->format("Y"));
            $this->em->persist($entry);
        }
        $this->em->flush();

        return true;
    }
    
    /**
     * Delete registration book entry
     * @param int $id
     * @return bool
     */
    public function deleteEntry($id)
    {
        /* @var $entry \Pensionsverwaltung\Database\Entity\RegistrationBookEntry */
        $entry = $this->em->getRepository(RegistrationBookEntry::class)->find($id);
        
        if($entry instanceof RegistrationBookEntry) {
            $this->em->remove($entry);
            $this->em->flush();
            
            return true;
        }
        return false;        
    }
}

?>