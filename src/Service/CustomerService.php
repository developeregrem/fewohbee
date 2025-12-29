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

use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Enum\IDCardType;
use App\Entity\PostalCodeData;
use App\Entity\Template;
use App\Interfaces\ITemplateRenderer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class CustomerService implements ITemplateRenderer
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function getCustomerFromForm(Request $request, $id = 'new')
    {
        $customer = null;

        if ('new' === $id) {
            $customer = new Customer();
        } else {
            $customer = $this->em->getRepository(Customer::class)->find($id);
        }

        $customer->setSalutation($request->request->get('salutation-'.$id, ''));
        $customer->setFirstname($request->request->get('firstname-'.$id));
        $customer->setLastname($request->request->get('lastname-'.$id));
        if (strlen($request->request->get('birthday-'.$id)) > 0) {
            $customer->setBirthday(new \DateTime($request->request->get('birthday-'.$id)));
        } else {
            $customer->setBirthday(null);
        }

        $addressTypes = $request->request->all('addresstype-'.$id) ?? [];
        $addressCount = count($addressTypes);
        $newAddresses = [];
        for ($i = 0; $i < $addressCount; ++$i) {
            if ('new' === $id) {
                $address = new CustomerAddresses();
            } else {
                // if exist use it or create one
                $atid = $request->request->all('addresstypeid-'.$id)[$i];
                $address = $this->em->getRepository(CustomerAddresses::class)->find($atid);
                if (!$address instanceof CustomerAddresses) {
                    $address = new CustomerAddresses();
                }
            }

            $address->setType($request->request->all('addresstype-'.$id)[$i]);
            $address->setCompany($request->request->all('company-'.$id)[$i]);
            $address->setAddress($request->request->all('address-'.$id)[$i]);
            $address->setZip($request->request->all('zip-'.$id)[$i]);
            $address->setCity($request->request->all('city-'.$id)[$i]);
            $address->setCountry($request->request->all('country-'.$id)[$i]);
            $address->setPhone($request->request->all('phone-'.$id)[$i]);
            $address->setFax($request->request->all('fax-'.$id)[$i]);
            $address->setMobilePhone($request->request->all('mobilephone-'.$id)[$i]);
            $address->setEmail($request->request->all('email-'.$id)[$i]);

            $newAddresses[] = $address;
        }
        $customer->setIdType(IDCardType::tryFrom($request->request->get('id-type-'.$id)));
        $customer->setIDNumber($request->request->get('id-'.$id));
        $customer->setIDNumber($request->request->get('id-'.$id));
        $customer->setRemark($request->request->get('remark-'.$id));

        // first remove all old addresses
        $oldAddresses = clone $customer->getCustomerAddresses();

        foreach ($oldAddresses as $oldAddress) {
            $customer->removeCustomerAddress($oldAddress);
        }

        foreach ($newAddresses as $newAddress) {
            $customer->addCustomerAddress($newAddress);
            $this->em->persist($newAddress);
        }
        // todo delete address whics is not used by other customers
        // at the moment it does not work since old address is still assigned to customer
        foreach ($oldAddresses as $oldAddress) {
            // var_dump($oldAddress->getId());
            // var_dump($oldAddress->getCustomers()->count());
            if (0 == $oldAddress->getCustomers()->count()) {
                $this->em->remove($oldAddress);
            }
        }

        return $customer;
    }

    public function deleteCustomer($id)
    {
        /* @var $customer Customer */
        $customer = $this->em->getRepository(Customer::class)->find($id);

        $reservations = $customer->getReservations();
        $registrationBookEntries = $customer->getRegistrationBookEntries();
        $bookedReservations = $customer->getBookedReservations();
        $reservationsArray = new ArrayCollection(
            array_merge($reservations->toArray(), $bookedReservations->toArray())
        ); // combine both arrays
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
                'lastname' => 'Anonym',
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
            // assign all registration book entries to our anonym user
            foreach ($registrationBookEntries as $entry) {
                $entry->setCustomer($deletedCustomer);
                $this->em->persist($entry);
            }

            $this->em->remove($customer);
            $this->em->flush();

            return true;
        } else {
            return false;
        }
    }

    /**
     * Returns the city connected to the given plz.
     *
     * @param string $country
     * @param string $zip
     *
     * @return array
     */
    public function getCitiesByZIP($country, $zip)
    {
        $cities = $this->em->getRepository(PostalCodeData::class)->findPlacesByCode($country, $zip);
        $result = [];
        /* @var $city PostalCodeData */
        foreach ($cities as $city) {
            $result[] = [
                'postalCode' => $city->getPostalCode(),
                'placeName' => $city->getPlaceName(),
                'search' => $city->getPostalCode().' - '.$city->getPlaceName(),
            ];
        }

        return $result;
    }

    public function getRenderParams(Template $template, mixed $param)
    {
        $params = [
            'customer' => $param,
        ];

        return $params;
    }
}
