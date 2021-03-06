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

use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\OpengeodbDePlz;

use Symfony\Component\HttpFoundation\Request;
use Doctrine\Common\Collections\ArrayCollection;
use App\Interfaces\ITemplateRenderer;

class CustomerService implements ITemplateRenderer
{

    private $em = null;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function getCustomerFromForm(Request $request, $id = 'new')
    {
        $customer = null;

        if ($id === 'new') {
            $customer = new Customer();
        } else {
            $customer = $this->em->getRepository(Customer::class)->find($id);
        }
        
        $customer->setSalutation($request->get("salutation-" . $id));
        $customer->setFirstname($request->get("firstname-" . $id));
        $customer->setLastname($request->get("lastname-" . $id));
        if (strlen($request->get("birthday-" . $id)) > 0) {
            $customer->setBirthday(new \DateTime($request->get("birthday-" . $id)));
        } else {
            $customer->setBirthday(null);
        }
        
        $addressCount = count($request->get("addresstype-" . $id, Array()));
        $newAddresses = Array();
        for($i = 0; $i < $addressCount; $i++) {
            if ($id === 'new') {
                $address = new CustomerAddresses();
            } else {
                // if exist use it or create one
                $atid = $request->get("addresstypeid-" . $id)[$i];
                $address = $this->em->getRepository(CustomerAddresses::class)->find($atid);
                if(!$address instanceof CustomerAddresses) {
                    $address = new CustomerAddresses();
                }
            }          
            
            $address->setType($request->get("addresstype-" . $id)[$i]);
            $address->setCompany($request->get("company-" . $id)[$i]);
            $address->setAddress($request->get("address-" . $id)[$i]);
            $address->setZip($request->get("zip-" . $id)[$i]);
            $address->setCity($request->get("city-" . $id)[$i]);
            $address->setCountry($request->get("country-" . $id)[$i]);
            $address->setPhone($request->get("phone-" . $id)[$i]);
            $address->setFax($request->get("fax-" . $id)[$i]);
            $address->setMobilePhone($request->get("mobilephone-" . $id)[$i]);
            $address->setEmail($request->get("email-" . $id)[$i]);
            
            $newAddresses[] = $address;
        }
        $customer->setRemark($request->get("remark-" . $id));
        
        // first remove all old addresses
        $oldAddresses = clone $customer->getCustomerAddresses();        
        
        foreach($oldAddresses as $oldAddress) {
            $customer->removeCustomerAddress($oldAddress);
        }
        //
        foreach($newAddresses as $newAddress) {
            $customer->addCustomerAddress($newAddress);
            $this->em->persist($newAddress);
        }
        // todo delete address whics is not used by other customers
        // at the moment it does not work since old address is still assigned to customer
        foreach($oldAddresses as $oldAddress) {
            //var_dump($oldAddress->getId());
            //var_dump($oldAddress->getCustomers()->count());
            if($oldAddress->getCustomers()->count() == 0) {
                $this->em->remove($oldAddress);
            }
        }

        return $customer;
    }

    public function deleteCustomer($id)
    {
        $customer = $this->em->getRepository(Customer::class)->find($id);

        $reservations = $customer->getReservations();
        $bookedReservations = $customer->getBookedReservations();
        $reservationsArray = new ArrayCollection(
            array_merge($reservations->toArray(), $bookedReservations->toArray())); // combine both arrays
        $today = new \DateTime();
        $canBeDeleted = true;

        // check if user has an active reservation (future or today)
        foreach ($reservationsArray as $reservation) {
            $reservationEndDate = $reservation->getEndDate();

            if ($reservationEndDate >= $today) {
                $canBeDeleted = false;
                break;
            }
        }

        if ($canBeDeleted) {
            $criteria = [
                'firstname' => 'Anonym',
                'lastname' => 'Anonym'
            ];
            $deletedCustomer = $this->em->getRepository(Customer::class)->findOneBy($criteria);

            // assign all reservations of the user to our anonymous user
            foreach ($reservations as $reservation) {
                $reservation->removeCustomer($customer); // first delete old customer
                $reservation->removeCustomer($deletedCustomer); // if anonymous customer is already present -> delete him first
                $reservation->addCustomer($deletedCustomer);
                $this->em->persist($reservation);
            }
            // assign all booked reservations of the user to our anonymous user
            foreach ($bookedReservations as $reservation) {
                $reservation->setBooker($deletedCustomer);
                $this->em->persist($reservation);
            }

            $this->em->remove($customer);
            $this->em->flush();

            return true;
        } else {
            return false;
        }
    }
    
    /**
     * Returns the city connected to the given plz
     * @param string $plz
     * @return string
     */
    public function getCityByPlz($plz)
    {
        $city = $this->em->getRepository(OpengeodbDePlz::class)->find($plz);

        return $city;
    }

    public function getRenderParams($template, $param) {
        $params = array(
                'customer' => $param,                  
            );
        return $params;
    }

}
