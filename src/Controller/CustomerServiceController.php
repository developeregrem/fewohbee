<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\Persistence\ManagerRegistry;

use App\Service\CSRFProtectionService;
use App\Service\CustomerService;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use Symfony\Component\Intl\Countries;
use App\Service\TemplatesService;
use App\Entity\Template;

/**
 * @Route("/customers")
 */
class CustomerServiceController extends AbstractController
{
    private $perPage = 20;
    public static $addessTypes = Array('CUSTOMER_ADDRESS_TYPE_PRIVATE', 'CUSTOMER_ADDRESS_TYPE_BUSINESS', 'CUSTOMER_ADDRESS_TYPE_ADDITIONAL');

    public function indexAction(ManagerRegistry $doctrine, Request $request)
    {
        $em = $doctrine->getManager();

        $search = $request->query->get('search', '');
        $page = $request->query->get('page', 1);
        $customers = $em->getRepository(Customer::class)->findByFilter($search, $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($customers->count() / $this->perPage);
        
//        $customers2 = $em->getRepository(Customer::class)->findAll();
//        /* @var $customer \Pensionsverwaltung\Database\Entity\Customer */
//        foreach($customers2 as $customer) {
//            if(empty($customer->getAddress()) && empty($customer->getCity()) && empty($customer->getEmail()) && empty($customer->getFax())
//                    && empty($customer->getCompany()) && empty($customer->getMobilePhone()) && empty($customer->getPhone()) && empty($customer->getZip()))
//                continue;
//            $address = new CustomerAddresses();
//            $address->setType((empty($customer->getCompany()) ? self::$addessTypes[0] : self::$addessTypes[1]));
//            $address->setAddress($customer->getAddress());
//            $address->setCity($customer->getCity());
//            $address->setCompany($customer->getCompany());
//            $address->setCountry($customer->getCountry());
//            $address->setEmail($customer->getEmail());
//            $address->setFax($customer->getFax());
//            $address->setMobilePhone($customer->getMobilePhone());
//            $address->setPhone($customer->getPhone());
//            $address->setZip($customer->getZip());
//            
//            $em->persist($address);
//            $customer->addCustomerAddress($address);
//            $em->persist($customer);
//        }
//        $em->flush();

        return $this->render('Customers/index.html.twig', array(
            "customers" => $customers,
            'page' => $page,
            'pages' => $pages,
            'search' => $search
        ));
    }

    public function searchCustomersAction(ManagerRegistry $doctrine, Request $request)
    {
        $em = $doctrine->getManager();
        $search = $request->request->get('search', '');
        $page = $request->request->get('page', 1);
        $customers = $em->getRepository(Customer::class)->findByFilter($search, $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($customers->count() / $this->perPage);

        return $this->render('Customers/customer_table.html.twig', array(
            "customers" => $customers,
            'page' => $page,
            'pages' => $pages,
            'search' => $search
        ));
    }

    public function getCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, $id)
    {
        $em = $doctrine->getManager();
        $customer = $em->getRepository(Customer::class)->find($id);

        return $this->render('Customers/customer_form_show.html.twig', array(
            'customer' => $customer,
            'token' => $csrf->getCSRFTokenForForm(),
        ));
    }

    public function newCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request)
    {
        $em = $doctrine->getManager();

        // Get the country names for a locale
        $countries = Countries::getNames($request->getLocale());

        $customer = new Customer();
        $customer->setId('new');

        $customersForTemplate = $em->getRepository(Customer::class)->getLastCustomers(5);

        return $this->render('Customers/customer_form_create.html.twig', array(
            'customer' => $customer,
            'token' => $csrf->getCSRFTokenForForm(),
            'countries' => $countries,
            'customersForTemplate' => $customersForTemplate,
            'addresstypes' => self::$addessTypes
        ));
    }

    public function createCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, CustomerService $cs, Request $request)
    {
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            /* @var $customer Customer */
            $customer = $cs->getCustomerFromForm($request, "new");

            // check for mandatory fields
            if (strlen($customer->getSalutation()) == 0 || strlen($customer->getLastname()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em = $doctrine->getManager();
                $em->persist($customer);
                $em->flush();

                // add succes message
                $this->addFlash('success', 'customer.flash.create.success');
            }
        }

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }

    public function showEditCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request, $id) {
        $em = $doctrine->getManager();
        $customer = $em->getRepository(Customer::class)->find($id);

        // Get the country names for a locale
        $countries = Countries::getNames($request->getLocale());

        return $this->render('Customers/customer_form_edit.html.twig', array(
            'customer' => $customer,
            'token' => $csrf->getCSRFTokenForForm(),
            'countries' => $countries,
            'addresstypes' => self::$addessTypes
        ));
    }

    public function editCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, CustomerService $cs, Request $request, $id)
    {
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            /* @var $customer Customer */
            $customer = $cs->getCustomerFromForm($request, $id);

            // check for mandatory fields
            if (strlen($customer->getSalutation()) == 0 || strlen($customer->getLastname()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em = $doctrine->getManager();
                $em->persist($customer);
                $em->flush();

                // add succes message           
                $this->addFlash('success', 'customer.flash.edit.success');
            }
        }

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }

    public function deleteCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, CustomerService $cs, Request $request, $id)
    {
        if ($request->getMethod() == 'POST') {
            if (($csrf->validateCSRFToken($request, true))) {
                $customer = $cs->deleteCustomer($id);

                if ($customer) {
                    $this->addFlash('success', 'customer.flash.delete.success');
                } else {
                    $this->addFlash('warning', 'customer.flash.delete.error.still.in.use');
                }
            }
            return new Response("ok");
        } else {
            // initial get load (ask for deleting)           
            return $this->render('Customers/customer_form_delete.html.twig', array(
                "id" => $id,
                'token' => $csrf->getCSRFTokenForForm()
            ));
        }
    }
    
    /**
     * OnkeyUp Request for plz field in the create or edit customer process to find the city for the given plz
     * @param Request $request
     * @return string
     * 
     * @Route("/citylookup/{countryCode}/{postalCode}", name="customers.citylookup", methods={"GET"})
     */
    public function cityLookUpAction(string $countryCode, string $postalCode, Request $request, CustomerService $cs)
    {
        $cities = $cs->getCitiesByZIP($countryCode, $postalCode);
        
        return new JsonResponse($cities);
    }
    
    /**
     * Search for a given address (autocomplete)
     * @param Request $request
     * @param string $address
     * 
     * @Route("/search/address/{address}", name="customers.search.address", methods={"GET"})
     */
    public function searchAddressAction(ManagerRegistry $doctrine, Request $request, $address)
    {   
        $em = $doctrine->getManager();

        $customers = $em->getRepository(Customer::class)->findByFilterToArray($address, 1, 5);
        $addresses = array();

        foreach($customers as $customer) {
            foreach($customer['customerAddresses'] as $address) {
                $addresses[] = $address;
            }
                       
        }
        return $this->json($addresses);
    }
    
    public function exportGDPRToPdfAction(ManagerRegistry $doctrine, TemplatesService $ts, CustomerService $cs, $id)
    {
        $em = $doctrine->getManager();

        $customer = $em->getRepository(Customer::class)->find($id);
        if($customer === null) {
            return $this->json(['error' => 'no user']);
        }
        
        $templates = $em->getRepository(Template::class)->loadByTypeName(array('TEMPLATE_GDPR_PDF'));
        $defaultTemplate = $ts->getDefaultTemplate($templates);

        if($defaultTemplate === null) {
            return $this->json(['error' => 'no template']);
            
        }
        $templateId = $defaultTemplate->getId();
        
        $templateOutput = $ts->renderTemplate($templateId, $customer, $cs);
        
        $template = $em->getRepository(Template::class)->find($templateId);

        $pdfOutput = $ts->getPDFOutput($templateOutput, "GDPR-Export", $template);
        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }
}