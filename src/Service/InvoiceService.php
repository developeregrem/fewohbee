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
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use App\Entity\Invoice;
use App\Entity\Customer;
use App\Interfaces\ITemplateRenderer;
use App\Entity\CustomerAddresses;
use App\Entity\InvoiceAppartment;
use App\Service\PriceService;
use App\Entity\Reservation;
use App\Entity\Price;

class InvoiceService implements ITemplateRenderer
{

    private $em = null;
    private $ps = null;

    public function __construct(EntityManagerInterface $em, PriceService $ps)
    {
        $this->em = $em;
        $this->ps = $ps;
    }

    public function calculateSums(Invoice $invoice, $apps, $poss, &$vats, &$brutto, &$netto, &$appartmentTotal, &$miscTotal)
    {
        $vats = Array();
        $brutto = 0;
        $netto = 0;
        $appartmentTotal = 0;
        $miscTotal = 0;

        /* @var $apps \Pensionsverwaltung\Database\Entity\InvoiceAppartment */
        //$apps = $invoice->getAppartments();
        //$poss = $invoice->getPositions();
        foreach ($apps as $appartment) {
            if (array_key_exists($appartment->getVat(), $vats)) {
                $vats[$appartment->getVat()]['sum'] += $appartment->getAmount() * $appartment->getPrice();
                $vats[$appartment->getVat()]['netto'] += ((($appartment->getAmount() * $appartment->getPrice()) * $appartment->getVat()) / (100 + $appartment->getVat()));
            } else {
                $vats[$appartment->getVat()]['sum'] = $appartment->getAmount() * $appartment->getPrice();
                $vats[$appartment->getVat()]['netto'] = ((($appartment->getAmount() * $appartment->getPrice()) * $appartment->getVat()) / (100 + $appartment->getVat()));
            }
            $appartmentTotal += $appartment->getAmount() * $appartment->getPrice();
        }

        foreach ($poss as $pos) {
            if (array_key_exists($pos->getVat(), $vats)) {
                $vats[$pos->getVat()]['sum'] += $pos->getAmount() * $pos->getPrice();
                $vats[$pos->getVat()]['netto'] += ((($pos->getAmount() * $pos->getPrice()) * $pos->getVat()) / (100 + $pos->getVat()));
            } else {
                $vats[$pos->getVat()]['sum'] = $pos->getAmount() * $pos->getPrice();
                $vats[$pos->getVat()]['netto'] = ((($pos->getAmount() * $pos->getPrice()) * $pos->getVat()) / (100 + $pos->getVat()));
            }
            $miscTotal += $pos->getAmount() * $pos->getPrice();
        }
        
        foreach($vats as $key=>$vat) {
            $brutto += round($vat['sum'], 2);
            $netto += round($vat['netto'], 2);
            $vats[$key]['nettoFormated'] = number_format(round($vat['netto'], 2), 2, ',', '.');
        }
        ksort($vats);
    }

    public function getNewInvoiceForCustomer(Customer $customer, $number)
    {
        $invoice = new Invoice();
        $address = $customer->getCustomerAddresses()[0];
        $invoice->setNumber($number);
        $invoice->setDate(new \DateTime());
        $invoice->setSalutation($customer->getSalutation());
        $invoice->setFirstname($customer->getFirstname());
        $invoice->setLastname($customer->getLastname());
        $invoice->setCompany($address->getCompany());
        $invoice->setAddress($address->getAddress());
        $invoice->setZip($address->getZip());
        $invoice->setCity($address->getCity());
        $invoice->setRemark("");
        $invoice->setStatus(1);

        return $invoice;
    }

    public function makeInvoiceCustomerArray(Customer $customer) {
        $arr = Array();
        $addresses = $customer->getCustomerAddresses();
        // set first address as default address for invoice
        if(count($addresses) > 0) {
            $arr['company'] = $addresses[0]->getCompany();
            $arr['address'] = $addresses[0]->getAddress();
            $arr['zip'] = $addresses[0]->getZip();
            $arr['city'] = $addresses[0]->getCity();
        }
        else {
            $arr['company'] = "";
            $arr['address'] = "";
            $arr['zip'] = "";
            $arr['city'] = "";
        }
        $arr['salutation'] = $customer->getSalutation();
        $arr['firstname'] = $customer->getFirstname();
        $arr['lastname'] = $customer->getLastname();

        return $arr;
    }

    public function makeInvoiceCustomerArrayFromRequest($request) {
        $arr = Array();
        $arr['salutation'] = $request->get('salutation');
        $arr['firstname'] = $request->get('firstname');
        $arr['lastname'] = $request->get('lastname');
        $arr['company'] = $request->get('company');
        $arr['address'] = $request->get('address');
        $arr['zip'] = $request->get('zip');
        $arr['city'] = $request->get('city');

        return $arr;
    }

    public function makeInvoiceCustomerFromArray($arr) {
        $customer = new Customer();
        $address = new CustomerAddresses();
        $address->setCompany($arr['company']);
        $address->setAddress($arr['address']);
        $address->setZip($arr['zip']);
        $address->setCity($arr['city']);
        $customer->setSalutation($arr['salutation']);
        $customer->setFirstname($arr['firstname']);
        $customer->setLastname($arr['lastname']);
        $customer->addCustomerAddress($address);

        return $customer;
    }
    
    public function makeInvoiceCustomerFromInvoice(Invoice $invoice) {
        $customer = new Customer();
        $address = new CustomerAddresses();
        $address->setCompany($invoice->getCompany());
        $address->setAddress($invoice->getAddress());
        $address->setZip($invoice->getZip());
        $address->setCity($invoice->getCity());
        $customer->setSalutation($invoice->getSalutation());
        $customer->setFirstname($invoice->getFirstname());
        $customer->setLastname($invoice->getLastname());
        $customer->addCustomerAddress($address);

        return $customer;
    }

    /**
     * Loops through all Periods and removes dublicate (same) entries
     * @param Invoice $invoice
     * @return array
     */
    public function getUniqueReservationPeriods($invoice) {
        $arr = Array();
        $i = 0;
        foreach($invoice->getAppartments() as $appartment) {
           $found = false;
            foreach($arr as $periods) {
               if($periods['startDate'] == $appartment->getStartDate() && $periods['endDate'] == $appartment->getEndDate()) {
                   $found = true;
                   break;
               }
           }
            if(!$found) {
                $arr[$i]['startDate'] = $appartment->getStartDate();
                $arr[$i]['endDate'] = $appartment->getEndDate();
                $i++;
            }

        }
        return $arr;
    }
    
    /**
     * Loops through all Appartments and removes dublicate (same) entries (by number)
     * @param Invoice $invoice
     * @return array
     */
    public function getUniqueAppartmentsNumber($invoice) {
        $arr = Array();
        foreach($invoice->getAppartments() as $appartment) {
           $found = false;
            foreach($arr as $numbers) {
               if($numbers['number'] == $appartment->getNumber()) {
                   $found = true;
                   break;
               }
           }
            if(!$found) {
                $arr[]['number'] = $appartment->getNumber();
            }
        }
        return $arr;
    }

    /**
     * Delets Invoice and all dependencies
     * @param type $id The ID of the invoice
     * @return boolean
     */
    public function deleteInvoice($id)
    {        
        $invoice = $this->em->getRepository(Invoice::class)->find($id);

        if ($invoice instanceof Invoice) {
            $reservations = $invoice->getReservations();
            foreach ($reservations as $reservation) {
                $reservation->removeInvoice($invoice);
                $this->em->persist($reservation);
            }
            $positions = $invoice->getPositions();
            foreach ($positions as $position) {
                $this->em->remove($position);
            }
            $appartments = $invoice->getAppartments();
            foreach ($appartments as $appartment) {
                $this->em->remove($appartment);
            }
            $this->em->persist($invoice);

            $this->em->remove($invoice);
            $this->em->flush();

            return true;
        } else {
            return false;
        }
    }

    public function getRenderParams($template, $param) {
        $invoice = $this->em->getRepository(Invoice::class)->find($param);

        $vatSums = Array();
        $brutto = 0;
        $netto = 0;
        $appartmantTotal = 0;
        $miscTotal = 0;
        // calculate needed values for template
        $this->calculateSums(
            $invoice,
            $invoice->getAppartments(),
            $invoice->getPositions(),
            $vatSums,
            $brutto,
            $netto,
            $appartmantTotal,
            $miscTotal
        );

        $periods = $this->getUniqueReservationPeriods($invoice);
        $appartmentNumbers = $this->getUniqueAppartmentsNumber($invoice);

        $params = array(
            'invoice' => $invoice,
            'vats' => $vatSums,
            'brutto' => $brutto,
            'netto' => $netto,
            'bruttoFormated' => number_format($brutto, 2, ',', '.'),
            'nettoFormated' => number_format($brutto-$netto, 2, ',', '.'),
            'periods' => $periods,
            'numbers' => $appartmentNumbers,
            'appartmentTotal' => number_format($appartmantTotal, 2, ',', '.'),
            'miscTotal' => number_format($miscTotal, 2, ',', '.')
            );
        return $params;
    }
    
    /**
     * Retrieves valid prices for each day of stay and prefills the appartment position for the reservation
     * @param Reservation $reservation
     * @param SessionInterface $session
     */
    public function prefillAppartmentPositions(Reservation $reservation, SessionInterface $session) {
        $days = $this->getDateDiff($reservation->getStartDate(), $reservation->getEndDate());
        $prices = $this->ps->getPrices($reservation, 2);
        
        $curDate = clone $reservation->getStartDate();
        $lastPrice = $prices[0];
        $start = clone $reservation->getStartDate();        
        $i = 0;
        // loop through each day and create the position based on the retrieved price for this day
        do {      
            if($i < $days) {
                $price = $prices[$i];
            } else {
                $price = $lastPrice;
            }
            
            $curDate = (clone $curDate)->add(new \DateInterval("P".($i === 0 ? 0 : 1)."D"));
            if($price !== null && $lastPrice !== null && ($lastPrice->getId() !== $price->getId() || $i == $days)) {
                $position = $this->makePosition($start, $curDate, $reservation, $lastPrice);
                $this->saveNewAppartmentPosition($position, $session);
                $start = clone $curDate;
            }
            
            $lastPrice = $price;
            $i++;
        } while($i <= $days);   // loop must run one more time to add the position for the last day of stay
    }
    
    /**
     * Stores a new appartment position in the session
     * @param InvoiceAppartment $position
     * @param SessionInterface $session
     */
    public function saveNewAppartmentPosition(InvoiceAppartment $position, SessionInterface $session) {
        $newInvoicePositionsAppartmentsArray = $session->get("invoicePositionsAppartments");                
        $newInvoicePositionsAppartmentsArray[] = $position;

        $session->set("invoicePositionsAppartments", $newInvoicePositionsAppartmentsArray);
    }
    
    /**
     * Creates a new InvoicePosition object based on the input
     * @param \DateTime $start
     * @param \DateTime $end
     * @param Reservation $reservation
     * @param Price $price
     * @return InvoiceAppartment
     */
    private function makePosition(\DateTime $start, \DateTime $end, Reservation $reservation, Price $price) : InvoiceAppartment {
        $positionAppartment = new InvoiceAppartment();
        $positionAppartment->setDescription($reservation->getAppartment()->getDescription());
        $positionAppartment->setNumber($reservation->getAppartment()->getNumber());
        $positionAppartment->setStartDate($start);
        $positionAppartment->setEndDate($end);
        $positionAppartment->setVat($price->getVat());
        $positionAppartment->setPrice($price->getPrice());
        $positionAppartment->setPersons($reservation->getPersons());
        $positionAppartment->setBeds($reservation->getAppartment()->getBedsMin());
        
        return $positionAppartment;
    }
    
    private function getDateDiff(\DateTime $start, \DateTime $end) : int {
        $interval = date_diff($start, $end);
		
        // return number of days
        return $interval->format('%a');
    }
}
