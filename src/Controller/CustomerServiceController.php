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

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Enum\IDCardType;
use App\Entity\Template;
use App\Service\CSRFProtectionService;
use App\Service\CustomerService;
use App\Service\TemplatesService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/customers')]
class CustomerServiceController extends AbstractController
{
    private $perPage = 20;
    public static $addessTypes = ['CUSTOMER_ADDRESS_TYPE_PRIVATE', 'CUSTOMER_ADDRESS_TYPE_BUSINESS', 'CUSTOMER_ADDRESS_TYPE_ADDITIONAL'];

    #[Route('/', name: 'customers.overview', methods: ['GET'])]
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

        return $this->render('Customers/index.html.twig', [
            'customers' => $customers,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
        ]);
    }

    #[Route('/search', name: 'customers.search', methods: ['POST'])]
    public function searchCustomersAction(ManagerRegistry $doctrine, Request $request)
    {
        $em = $doctrine->getManager();
        $search = $request->request->get('search', '');
        $page = $request->request->get('page', 1);
        $customers = $em->getRepository(Customer::class)->findByFilter($search, $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($customers->count() / $this->perPage);

        return $this->render('Customers/customer_table.html.twig', [
            'customers' => $customers,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
        ]);
    }

    #[Route('/{id}/get', name: 'customers.get.customer', methods: ['GET'], defaults: ['id' => '0'])]
    public function getCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, $id)
    {
        $em = $doctrine->getManager();
        $customer = $em->getRepository(Customer::class)->find($id);

        return $this->render('Customers/customer_form_show.html.twig', [
            'customer' => $customer,
            'token' => $csrf->getCSRFTokenForForm()
        ]);
    }

    #[Route('/new', name: 'customers.new.customer', methods: ['GET'])]
    public function newCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request)
    {
        $em = $doctrine->getManager();

        // Get the country names for a locale
        $countries = Countries::getNames($request->getLocale());

        $customer = new Customer();
        $customer->setId('new');

        $customersForTemplate = $em->getRepository(Customer::class)->getLastCustomers(5);

        return $this->render('Customers/customer_form_create.html.twig', [
            'customer' => $customer,
            'token' => $csrf->getCSRFTokenForForm(),
            'countries' => $countries,
            'customersForTemplate' => $customersForTemplate,
            'addresstypes' => self::$addessTypes,
            'cardTypes' => IDCardType::cases(),
        ]);
    }

    #[Route('/create', name: 'customers.create.customer', methods: ['POST'])]
    public function createCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, CustomerService $cs, Request $request)
    {
        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            /* @var $customer Customer */
            $customer = $cs->getCustomerFromForm($request, 'new');

            // check for mandatory fields
            if (0 == strlen($customer->getSalutation()) || 0 == strlen($customer->getLastname())) {
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

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/{id}/edit/show', name: 'customers.edit.customer.show', methods: ['GET'], defaults: ['id' => '0'])]
    public function showEditCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request, $id)
    {
        $em = $doctrine->getManager();
        $customer = $em->getRepository(Customer::class)->find($id);

        // Get the country names for a locale
        $countries = Countries::getNames($request->getLocale());

        return $this->render('Customers/customer_form_edit.html.twig', [
            'customer' => $customer,
            'token' => $csrf->getCSRFTokenForForm(),
            'countries' => $countries,
            'addresstypes' => self::$addessTypes,
            'cardTypes' => IDCardType::cases(),
        ]);
    }

    #[Route('/{id}/edit', name: 'customers.edit.customer', methods: ['POST'], defaults: ['id' => '0'])]
    public function editCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, CustomerService $cs, Request $request, $id)
    {
        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            /* @var $customer Customer */
            $customer = $cs->getCustomerFromForm($request, $id);

            // check for mandatory fields
            if (0 == strlen($customer->getSalutation()) || 0 == strlen($customer->getLastname())) {
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

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/{id}/delete', name: 'customers.delete.customer', methods: ['GET', 'POST'])]
    public function deleteCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, CustomerService $cs, Request $request, $id)
    {
        if ('POST' == $request->getMethod()) {
            if ($csrf->validateCSRFToken($request, true)) {
                $customer = $cs->deleteCustomer($id);

                if ($customer) {
                    $this->addFlash('success', 'customer.flash.delete.success');
                } else {
                    $this->addFlash('warning', 'customer.flash.delete.error.still.in.use');
                }
            }

            return new Response('ok');
        } else {
            // initial get load (ask for deleting)
            return $this->render('Customers/customer_form_delete.html.twig', [
                'id' => $id,
                'token' => $csrf->getCSRFTokenForForm(),
            ]);
        }
    }

    /**
     * OnkeyUp Request for plz field in the create or edit customer process to find the city for the given plz.
     */
    #[Route(path: '/citylookup/{countryCode}/{postalCode}', name: 'customers.citylookup', methods: ['GET'])]
    public function cityLookUpAction(string $countryCode, string $postalCode, Request $request, CustomerService $cs): JsonResponse
    {
        $cities = $cs->getCitiesByZIP($countryCode, $postalCode);

        return new JsonResponse($cities);
    }

    /**
     * Search for a given address (autocomplete).
     *
     * @param string $address
     */
    #[Route(path: '/search/address/{address}', name: 'customers.search.address', methods: ['GET'])]
    public function searchAddressAction(ManagerRegistry $doctrine, Request $request, $address): JsonResponse
    {
        $em = $doctrine->getManager();
        $customers = $em->getRepository(Customer::class)->findByFilterToArray($address, 1, 5);
        $addresses = [];
        foreach ($customers as $customer) {
            foreach ($customer['customerAddresses'] as $address) {
                $addresses[] = $address;
            }
        }

        return $this->json($addresses);
    }

    #[Route('/{id}/gdpr', name: 'customers.gdpr.customer', methods: ['GET'])]
    public function exportGDPRToPdfAction(ManagerRegistry $doctrine, TemplatesService $ts, CustomerService $cs, $id)
    {
        $em = $doctrine->getManager();

        $customer = $em->getRepository(Customer::class)->find($id);
        if (null === $customer) {
            return $this->json(['error' => 'no user']);
        }

        $templates = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_GDPR_PDF']);
        $defaultTemplate = $ts->getDefaultTemplate($templates);

        if (null === $defaultTemplate) {
            return $this->json(['error' => 'no template']);
        }
        $templateId = $defaultTemplate->getId();

        $templateOutput = $ts->renderTemplate($templateId, $customer, $cs);

        $template = $em->getRepository(Template::class)->find($templateId);

        $pdfOutput = $ts->getPDFOutput($templateOutput, 'GDPR-Export', $template);
        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }
}
