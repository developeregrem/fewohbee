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
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use App\Service\CSRFProtectionService;
use App\Service\TemplatesService;
use App\Service\InvoiceService;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\Customer;
use App\Entity\Template;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\Price;
use App\Form\InvoiceMiscPositionType;
use App\Form\InvoiceApartmentPositionType;

/**
 * @Route("/invoices")
 */
class InvoiceServiceController extends AbstractController
{
    private $perPage = 20;

    public function __construct()
    {
    }

    public function indexAction(SessionInterface $session, TemplatesService $ts, Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $templates = $em->getRepository(Template::class)->loadByTypeName(array('TEMPLATE_INVOICE_PDF'));
        $defaultTemplate = $ts->getDefaultTemplate($templates);

        $templateId = 1;
        if($defaultTemplate != null) {
            $templateId = $defaultTemplate->getId();
        }

        $templateId = $session->get("invoice-template-id", $templateId); // get previously selected id

        $search = $request->get('search', '');
        $page = $request->get('page', 1);

        $invoices = $em->getRepository(Invoice::class)->findByFilter($search, $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($invoices->count() / $this->perPage);

        return $this->render(
            'Invoices/index.html.twig',
            array(
                "invoices" => $invoices,
                'templates' => $templates,
                'templateId' => $templateId,
                'page' => $page,
                'pages' => $pages,
                'search' => $search
            )
        );
    }

    public function searchInvoicesAction(SessionInterface $session, TemplatesService $ts, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $search = $request->get('search', '');
        $page = $request->get('page', 1);
        $invoices = $em->getRepository(Invoice::class)->findByFilter($search, $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($invoices->count() / $this->perPage);

        $templates = $em->getRepository(Template::class)->loadByTypeName(array('TEMPLATE_INVOICE_PDF'));
        $defaultTemplate = $ts->getDefaultTemplate($templates);

        $templateId = 1;
        if($defaultTemplate != null) {
            $templateId = $defaultTemplate->getId();
        }

        $templateId = $session->get("invoice-template-id", $templateId); // get previously selected id

        return $this->render('Invoices/invoice_table.html.twig', array(
            'invoices' => $invoices,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
            'templateId' => $templateId
        ));
    }

    public function getInvoiceAction(CSRFProtectionService $csrf, SessionInterface $session, TemplatesService $ts, InvoiceService $is, $id)
    {
        $em = $this->getDoctrine()->getManager();
        $invoice = $em->getRepository(Invoice::class)->find($id);
        $vatSums = Array();
        $brutto = 0;
        $netto = 0;
        $apartmentTotal = 0;
        $miscTotal = 0;
        $is->calculateSums(
            $invoice,
            $invoice->getAppartments(),
            $invoice->getPositions(),
            $vatSums,
            $brutto,
            $netto,
            $apartmentTotal,
            $miscTotal
        );

        $templates = $em->getRepository(Template::class)->loadByTypeName(array('TEMPLATE_INVOICE_PDF'));
        $defaultTemplate = $ts->getDefaultTemplate($templates);

        $templateId = 1;
        if($defaultTemplate != null) {
            $templateId = $defaultTemplate->getId();
        }

        $templateId = $session->get("invoice-template-id", $templateId); // get previously selected id

        return $this->render(
            'Invoices/invoice_form_show.html.twig',
            array(
                'invoice' => $invoice,
                'vats' => $vatSums,
                'brutto' => $brutto,
                'netto' => $netto,
                'token' => $csrf->getCSRFTokenForForm(),
                'templateId' => $templateId,
                'apartmentTotal' => $apartmentTotal,
                'miscTotal' => $miscTotal,
                'error' => true
            )
        );
    }

    public function newInvoiceAction(CSRFProtectionService $csrf, SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        if ($request->get('createNewInvoice') == "true") {
            $newInvoiceInformationArray = array();
            $session->set("invoiceInCreation", $newInvoiceInformationArray);
            // reset session variables
            $session->remove("invoicePositionsMiscellaneous");
            $session->remove("invoicePositionsAppartments");
            $session->remove("new-invoice-id");
            $session->remove("invoiceDate");
            $session->remove("invoiceCustomer");
        } else {
            $newInvoiceInformationArray = $session->get("invoiceInCreation");
        }

        if (count($newInvoiceInformationArray) == 0) {
            $objectContainsReservations = "false";
        } else {
            $objectContainsReservations = "true";
        }

        return $this->render(
            'Invoices/invoice_form_select_reservation.html.twig',
            array(
                'objectContainsReservations' => $objectContainsReservations
            )
        );
    }

    public function getReservationsInPeriodAction(SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $reservations = Array();
        $newInvoiceInformationArray = $session->get("invoiceInCreation");

        $potentialReservations = $em->getRepository(
            Reservation::class
        )->loadReservationsForPeriod($request->get('from'), $request->get('end'));

        foreach ($potentialReservations as $reservation) {
            // make sure that already selected reservation can not be choosen twice
            if(!in_array($reservation->getId(), $newInvoiceInformationArray)) {
                $reservations[] = $reservation;
            }
        }

        return $this->render(
            'Reservations/reservation_matching_reservations.html.twig',
            array(
                'reservations' => $reservations
            )
        );
    }

    public function getReservationsForCustomerAction(SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $reservations = Array();
        $newInvoiceInformationArray = $session->get("invoiceInCreation");

        $customer = $em->getRepository(Customer::class)->findOneByLastname(
            $request->get("lastname")
        );

        if ($customer instanceof Customer) {
            $potentialReservations = $em->getRepository(
                Reservation::class
            )->loadReservationsWithoutInvoiceForCustomer($customer);

            $newInvoiceInformationArray = $session->get("invoiceInCreation");

            foreach ($potentialReservations as $reservation) {
                if (!in_array($reservation->getId(), $newInvoiceInformationArray)) {                
                    $reservations[] = $reservation;
                }
            }
        }

        return $this->render(
            'Reservations/reservation_matching_reservations.html.twig',
            array(
                'reservations' => $reservations
            )
        );
    }

    public function selectReservationAction(SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        if ($request->get('createNewInvoice') == "true") {
            $newInvoiceInformationArray = array();
            $session->set("invoiceInCreation", $newInvoiceInformationArray);
            // reset session variables
            $session->remove("invoicePositionsMiscellaneous");
            $session->remove("invoicePositionsAppartments");
            $session->remove("new-invoice-id");
            $session->remove("invoiceDate");
            $session->remove("invoiceCustomer");
        } else {
            $newInvoiceInformationArray = $session->get("invoiceInCreation");
        }

        if ($request->get("reservationid") != null) {
            $newInvoiceInformationArray[] = $request->get("reservationid");
            $session->set("invoiceInCreation", $newInvoiceInformationArray);
        }

        $reservations = Array();
        foreach ($newInvoiceInformationArray as $reservationid) {
            $reservations[] = $em->getRepository(Reservation::class)->find($reservationid);
        }

        if (count($newInvoiceInformationArray) > 0) {
            $arrayContainsReservations = true;
        }
        
        return $this->render(
            'Invoices/invoice_form_show_selected_reservations.html.twig',
            array(
                'reservations' => $reservations,
                'arrayContainsReservations' => $arrayContainsReservations
            )
        );
    }

    public function removeReservationFromSelectionAction(SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $newInvoiceInformationArray = $session->get("invoiceInCreation");

        if ($request->get("reservationkey") != null) {
            unset($newInvoiceInformationArray[$request->get("reservationkey")]);
            $session->set("invoiceInCreation", $newInvoiceInformationArray);
        }
        $reservations = Array();
        foreach ($newInvoiceInformationArray as $reservationid) {
            $reservations[] = $em->getRepository(Reservation::class)->findById(
                $reservationid
            )[0];
        }

        if (count($newInvoiceInformationArray) > 0) {
            $arrayContainsReservations = true;
        } else {
            $arrayContainsReservations = false;
        }


        return $this->render(
            'Invoices/invoice_form_show_selected_reservations.html.twig',
            array(
                'reservations' => $reservations,
                'arrayContainsReservations' => $arrayContainsReservations
            )
        );
    }

    /**
     * @Route("/create/positions/create", name="invoices.create.invoice.positions", methods={"GET","POST"})
     */
    public function showCreateInvoicePositionsFormAction(SessionInterface $session, InvoiceService $is, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $newInvoiceReservationsArray = $session->get("invoiceInCreation");
        
        if(!$session->has("invoicePositionsMiscellaneous")) {            
            $newInvoicePositionsMiscellaneousArray = array();
            $session->set("invoicePositionsMiscellaneous", $newInvoicePositionsMiscellaneousArray);
            
            // prefill positions for all selected reservations
            $is->prefillMiscPositionsWithReservationIds($newInvoiceReservationsArray, $session, true);           
        }
        $newInvoicePositionsMiscellaneousArray = $session->get("invoicePositionsMiscellaneous");
        
        if(!$session->has("invoicePositionsAppartments")) {
            $newInvoicePositionsAppartmentsArray = array();
            $session->set("invoicePositionsAppartments", $newInvoicePositionsAppartmentsArray);
            // prefill positions for all selected reservations
            foreach($newInvoiceReservationsArray as $resId) {
                $reservation = $em->getRepository(Reservation::class)->find($resId);
                $is->prefillAppartmentPositions($reservation, $session);
            }            
        }
        $newInvoicePositionsAppartmentsArray = $session->get("invoicePositionsAppartments");

        if(!$session->has("invoiceCustomer")) {
            $customer = $em->getRepository(Reservation::class)->find(
                $newInvoiceReservationsArray[0]
            )->getBooker();
            $invoiceCustomerArray = $is->makeInvoiceCustomerArray($customer);
            $session->set("invoiceCustomer", $invoiceCustomerArray);
        } else {
            $invoiceCustomerArray = $session->get("invoiceCustomer");
        }
        
        if(!$session->has("invoiceDate")) {
            $invoiceDate = new \DateTime();
            $session->set("invoiceDate", $invoiceDate);
        } else {
            $invoiceDate = $session->get("invoiceDate");
        }

        if (!$session->has("new-invoice-id")) {
            $invoiceid = null;
            $lastInvoiceId = $em->getRepository(Invoice::class)->getLastInvoiceId();
        } else {
            $invoiceid = $session->get("new-invoice-id");
            $lastInvoiceId = '';
        }

        if ($request->get("invoiceid") != null) {
            $invoiceid = $request->get("invoiceid");
            $session->set("new-invoice-id", $invoiceid);
        }
        if ($request->get("invoiceDate") != null && strlen($request->get("invoiceDate")) > 0) {
            $invoiceDate = new \DateTime($request->get("invoiceDate"));
            $session->set("invoiceDate", $invoiceDate);
        }
        
        if (count($newInvoicePositionsAppartmentsArray) != 0 && $invoiceid != null) {
            $appartmentPositionExists = true;
        } else {
            $appartmentPositionExists = false;
        }

        return $this->render(
            'Invoices/invoice_form_show_create_invoice_positions.html.twig',
            array(
                'positionsMiscellaneous' => $newInvoicePositionsMiscellaneousArray,
                'positionsAppartment' => $newInvoicePositionsAppartmentsArray,
                'appartmentPositionExists' => $appartmentPositionExists,
                'lastinvoiceid' => $lastInvoiceId,
                'invoiceid' => $invoiceid,
                'customer' => $invoiceCustomerArray,
                'invoiceDate' => $invoiceDate
            )
        );
    }

    public function showChangeCustomerInvoiceFormAction(CSRFProtectionService $csrf, SessionInterface $session)
    {
        $em = $this->getDoctrine()->getManager();
        $newInvoiceReservationsArray = $session->get("invoiceInCreation");
        $customersArray = Array();

        $invoiceCustomerArray = $session->get("invoiceCustomer");

        // collect all unique bookers and customers for recommendation list
        foreach ($newInvoiceReservationsArray as $reservationId) {
            $reservation = $em->getRepository(Reservation::class)->find($reservationId);
            $booker = $reservation->getBooker();
            $customers = $reservation->getCustomers();
            foreach($customers as $customer) {
                if(!array_key_exists($customer->getId(), $customersArray)) {
                    $customersArray[$customer->getId()] = $customer;
                }
            }
            if(!array_key_exists($booker->getId(), $customersArray)) {
                $customersArray[$booker->getId()] = $booker;
            }
        }

        foreach ($newInvoiceReservationsArray as $reservationId) {
            $reservations[] = $em->getRepository(Reservation::class)->find($reservationId);
        }

        return $this->render(
            'Invoices/invoice_form_show_change_customer.html.twig',
            array(
                'reservations' => $reservations,
                'customers' => $customersArray,
                'invoiceCustomer' => $invoiceCustomerArray,
                'invoiceId' => 'new',
                'token' => $csrf->getCSRFTokenForForm()
            )
        );
    }

    public function saveChangeCustomerInvoiceFormAction(CSRFProtectionService $csrf, SessionInterface $session, InvoiceService $is, Request $request)
    {
        $id = $request->get('invoice-id');
        if ($csrf->validateCSRFToken($request, true)) {
            if($id === 'new') {
                $invoiceCustomerArray = $is->makeInvoiceCustomerArrayFromRequest($request);
                $session->set("invoiceCustomer", $invoiceCustomerArray);
                
                return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction', array());
            } else {
                $em = $this->getDoctrine()->getManager();
                $invoice = $em->getRepository(Invoice::class)->find($id);

                $invoice->setSalutation($request->get('salutation'));
                $invoice->setFirstname($request->get('firstname'));
                $invoice->setLastname($request->get('lastname'));
                $invoice->setCompany($request->get('company'));
                $invoice->setAddress($request->get('address'));
                $invoice->setZip($request->get('zip'));
                $invoice->setCity($request->get('city'));
                
                $em->persist($invoice);
                $em->flush();
                
                $this->addFlash('success', 'invoice.flash.edit.success');
                
                return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', array(
                    'id'  => $id,
                ));
            }            
        }
        // if invalid token
        if($id === 'new') {
            return $this->forward('App\Controller\InvoiceServiceController::showChangeCustomerInvoiceFormAction', array());
        } else {
            return $this->forward('App\Controller\InvoiceServiceController::showChangeCustomerInvoiceEditAction', array(
                    'id'  => $id,
            ));
        }
        
    }
    
    /**
     * @Route("/{invoiceId}/position/apartment/new/", name="invoices.new.apartment.position", methods={"GET","POST"})
     */
    public function newApartmentPosition($invoiceId, SessionInterface $session, InvoiceService $is, Request $request): Response
    {
        $invoicePosition = new InvoiceAppartment();
        $em = $this->getDoctrine()->getManager();
        
        // during invoice create process
        if($invoiceId === 'new') {
            $newInvoiceReservationsArray = $session->get("invoiceInCreation");

            foreach ($newInvoiceReservationsArray as $reservationid) {
                $reservations[] = $em->getRepository(Reservation::class)->findById(
                    $reservationid
                )[0];
            }
        } else { // during invoice edit process
            $invoice = $em->getRepository(Invoice::class)->find($invoiceId);
            $reservations = $invoice->getReservations();
        }
        
        $form = $this->createForm(InvoiceApartmentPositionType::class, $invoicePosition, [
            'action' => $this->generateUrl('invoices.new.apartment.position', ['invoiceId' => $invoiceId]),
            'reservations' => $reservations
        ]);
        $form->handleRequest($request);
        

        if ($form->isSubmitted() && $form->isValid()) {
            
            // float values must be converted to string for later calculation steps in calculateSums()
            $invoicePosition->setPrice(number_format($invoicePosition->getPrice(), 2, '.', ''));
            $invoicePosition->setVat(number_format($invoicePosition->getVat(), 2, '.', ''));
            
            // during invoice create process
            if($invoiceId === 'new') {
                $is->saveNewAppartmentPosition($invoicePosition, $session);

                return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction');
            } else { // during edit process
                $em = $this->getDoctrine()->getManager();
                $invoice = $em->getRepository(Invoice::class)->find($invoiceId);
                $invoicePosition->setInvoice($invoice);
                
                $em->persist($invoicePosition);
                $em->flush();
                
                $this->addFlash('success', 'invoice.flash.edit.success');
                
                return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', array(
                    'id'  => $invoiceId,
                ));
            }
        }
        
        $prices = $em->getRepository(Price::class)->getActiveAppartmentPrices();
        
        return $this->render(
            'Invoices/invoice_form_show_create_appartment_position.html.twig',
            [
                'reservations' => $reservations,
                'prices' => $prices,
                'invoiceId' => $invoiceId,
                'form' => $form->createView()
            ]
        );
    }
    
    /**
     * @Route("/{invoiceId}/edit/position/apartment/{id}/edit", name="invoices.edit.apartment.position", methods={"GET","POST"})
     */
    public function editApartmentPosition($invoiceId, $id, Request $request, SessionInterface $session): Response
    {
        $em = $this->getDoctrine()->getManager();
        
        // during invoice create process
        if($invoiceId === 'new') {
            $newInvoicePositionsAppartmentArray = $session->get("invoicePositionsAppartments");
            $invoicePosition = $newInvoicePositionsAppartmentArray[$id];
            
            $newInvoiceReservationsArray = $session->get("invoiceInCreation");

            foreach ($newInvoiceReservationsArray as $reservationid) {
                $reservations[] = $em->getRepository(Reservation::class)->find($reservationid);
            }
        } else { // during edit process     
            
            $invoicePosition = $em->getRepository(InvoiceAppartment::class)->find($id);
            
            $reservations = $invoicePosition->getInvoice()->getReservations();
        }
        
        $form = $this->createForm(InvoiceApartmentPositionType::class, $invoicePosition, [
            'action' => $this->generateUrl('invoices.edit.apartment.position', ['invoiceId' => $invoiceId, 'id' => $id]),
            'reservations' => $reservations
        ]);
        
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) { 
            // float values must be converted to string for later calculation steps in calculateSums()
            $invoicePosition->setPrice(number_format($invoicePosition->getPrice(), 2, '.', ''));
            $invoicePosition->setVat(number_format($invoicePosition->getVat(), 2, '.', ''));
            
            // during invoice create process
            if($invoiceId === 'new') {
                $newInvoicePositionsAppartmentArray[$id] = $invoicePosition;
                $session->set("invoicePositionsAppartments", $newInvoicePositionsAppartmentArray);

                return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction');
            } else { // during edit process                
                $em->persist($invoicePosition);
                $em->flush();
                
                $this->addFlash('success', 'invoice.flash.edit.success');
                
                return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', array(
                    'id'  => $invoicePosition->getInvoice()->getId(),
                ));
            }
        }
        
        $prices = $em->getRepository(Price::class)->getActiveAppartmentPrices();

        return $this->render('Invoices/invoice_form_show_edit_appartment_position.html.twig', [
            'reservations' => $reservations,
            'prices' => $prices,
            'invoiceId' => $invoiceId,
            'form' => $form->createView(),
        ]);
    }

    public function deleteAppartmentInvoicePositionAction(SessionInterface $session, Request $request)
    {        
        $index = $request->get("appartmentInvoicePositionIndex", -1);       // during create process
        $positionId = $request->get("appartmentInvoicePositionEditId", -1); // during edit process
        
        if($index != -1) {
            $newInvoicePositionsAppartmentsArray = $session->get("invoicePositionsAppartments");
            unset($newInvoicePositionsAppartmentsArray[$index]);
            $session->set("invoicePositionsAppartments", $newInvoicePositionsAppartmentsArray);

            return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction');
        } else {
            $em = $this->getDoctrine()->getManager();
            $invoiceAppartment = $em->getRepository(InvoiceAppartment::class)->find($positionId);
            $invoiceId = $invoiceAppartment->getInvoice()->getId();
            $em->remove($invoiceAppartment);
            $em->flush();
            
            $this->addFlash('success', 'invoice.flash.edit.success');
            
            return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', array(
                    'id'  => $invoiceId,
            ));
        }        
    }

    /**
     * @Route("/{invoiceId}/new/miscellaneous", name="invoices.new.miscellaneous.position", methods={"GET","POST"})
     */
    public function newMiscellaneousPosition($invoiceId, SessionInterface $session, InvoiceService $is, Request $request): Response
    {
        $invoicePosition = new InvoicePosition();
        $form = $this->createForm(InvoiceMiscPositionType::class, $invoicePosition, [
            'action' => $this->generateUrl('invoices.new.miscellaneous.position', ['invoiceId' => $invoiceId])
        ]);
        $form->handleRequest($request);
        $em = $this->getDoctrine()->getManager();

        if ($form->isSubmitted() && $form->isValid()) {
            
            // float values must be converted to string for later calculation steps in calculateSums()
            $invoicePosition->setPrice(number_format($invoicePosition->getPrice(), 2));
            $invoicePosition->setVat(number_format($invoicePosition->getVat(), 2));
            
            // during invoice create process
            if($invoiceId === 'new') {
                $is->saveNewMiscPosition($invoicePosition, $session);

                return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction');
            } else { // during edit process
                $em = $this->getDoctrine()->getManager();
                $invoice = $em->getRepository(Invoice::class)->find($invoiceId);
                $invoicePosition->setInvoice($invoice);
                
                $em->persist($invoicePosition);
                $em->flush();
                
                $this->addFlash('success', 'invoice.flash.edit.success');
                
                return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', array(
                    'id'  => $invoiceId,
                ));
            }
        }
        
        $prices = $em->getRepository(Price::class)->getActiveMiscellaneousPrices();

        // during invoice create process
        if($invoiceId === 'new') {
            $newInvoiceReservationsArray = $session->get("invoiceInCreation");

            foreach ($newInvoiceReservationsArray as $reservationid) {
                $reservations[] = $em->getRepository(Reservation::class)->findById(
                    $reservationid
                )[0];
            }
        } else { // during invoice edit process
            $invoice = $em->getRepository(Invoice::class)->find($invoiceId);
            $reservations = $invoice->getReservations();
        }
        
        return $this->render(
            'Invoices/invoice_form_show_create_miscellaneous_position.html.twig',
            [
                'reservations' => $reservations,
                'prices' => $prices,
                'invoiceId' => $invoiceId,
                'form' => $form->createView()
            ]
        );
    }
    
    /**
     * @Route("/{invoiceId}/edit/miscellaneous/{id}/edit", name="invoices.edit.miscellaneous.position", methods={"GET","POST"})
     */
    public function editMiscellaneousPosition($invoiceId, $id, Request $request, SessionInterface $session): Response
    {
        // during invoice create process
        if($invoiceId === 'new') {
            $newInvoicePositionsMiscellaneousArray = $session->get("invoicePositionsMiscellaneous");
            $positionMiscellaneous = $newInvoicePositionsMiscellaneousArray[$id];
        } else { // during edit process     
            $em = $this->getDoctrine()->getManager();
            $positionMiscellaneous = $em->getRepository(InvoicePosition::class)->find($id);
        }
        
        $form = $this->createForm(InvoiceMiscPositionType::class, $positionMiscellaneous, [
            'action' => $this->generateUrl('invoices.edit.miscellaneous.position', ['invoiceId' => $invoiceId, 'id' => $id])
        ]);
        $form->handleRequest($request);
        $em = $this->getDoctrine()->getManager();

        if ($form->isSubmitted() && $form->isValid()) {
            // float values must be converted to string for later calculation steps in calculateSums()
            $positionMiscellaneous->setPrice(number_format($positionMiscellaneous->getPrice(), 2));
            $positionMiscellaneous->setVat(number_format($positionMiscellaneous->getVat(), 2));
            
            // during invoice create process
            if($invoiceId === 'new') {
                $newInvoicePositionsMiscellaneousArray[$id] = $positionMiscellaneous;
                $session->set("invoicePositionsMiscellaneous", $newInvoicePositionsMiscellaneousArray);

                return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction');
            } else { // during edit process                
                $em->persist($positionMiscellaneous);
                $em->flush();
                
                $this->addFlash('success', 'invoice.flash.edit.success');
                
                return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', array(
                    'id'  => $positionMiscellaneous->getInvoice()->getId(),
                ));
            }
        }
        
        $prices = $em->getRepository(Price::class)->getActiveMiscellaneousPrices();
        
        // during invoice create process
        if($invoiceId === 'new') {
            $newInvoiceReservationsArray = $session->get("invoiceInCreation");

            foreach ($newInvoiceReservationsArray as $reservationid) {
                $reservations[] = $em->getRepository(Reservation::class)->find($reservationid);
            }
        } else { // during edit process
            $reservations = $positionMiscellaneous->getInvoice()->getReservations();
        }

        return $this->render('Invoices/invoice_form_show_edit_miscellaneous_position.html.twig', [
            'reservations' => $reservations,
            'prices' => $prices,
            'invoiceId' => $invoiceId,
            'form' => $form->createView(),
        ]);
    }

    public function deleteMiscellaneousInvoicePositionAction(SessionInterface $session, Request $request)
    {
        $index = $request->get("miscellaneousInvoicePositionIndex", -1);       // during create process
        $positionId = $request->get("miscellaneousInvoicePositionEditId", -1); // during edit process
        
        if($index != -1) {
            $newInvoicePositionsMiscellaneousArray = $session->get("invoicePositionsMiscellaneous");
            unset($newInvoicePositionsMiscellaneousArray[$index]);
            $session->set("invoicePositionsMiscellaneous", $newInvoicePositionsMiscellaneousArray);

            return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction');
        } else {
            $em = $this->getDoctrine()->getManager();
            $invoiceMisc = $em->getRepository(InvoicePosition::class)->find($positionId);
            $invoiceId = $invoiceMisc->getInvoice()->getId();
            
            $em->remove($invoiceMisc);
            $em->flush();
            
            $this->addFlash('success', 'invoice.flash.edit.success');
            
            return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', array(
                    'id'  => $invoiceId,
            ));
        }    
    }

    public function showNewInvoicePreviewAction(CSRFProtectionService $csrf, SessionInterface $session, InvoiceService $is)
    {
        $em = $this->getDoctrine()->getManager();

        $newInvoiceReservationsArray = $session->get("invoiceInCreation");

        $invoiceCustomerArray = $session->get("invoiceCustomer");
        $customer = $is->makeInvoiceCustomerFromArray($invoiceCustomerArray);

        $newInvoicePositionsMiscellaneousArray = $session->get("invoicePositionsMiscellaneous");
        $newInvoicePositionsAppartmentsArray = $session->get("invoicePositionsAppartments");

        $invoice = $is->getNewInvoiceForCustomer(
            $customer,
            $session->get("new-invoice-id")
        );

        $invoiceDate =  $session->get("invoiceDate");
        $invoice->setDate($invoiceDate);

        $vatSums = Array();
        $brutto = 0;
        $netto = 0;
        $apartmentTotal = 0;
        $miscTotal = 0;
        $is->calculateSums(
            $invoice,
            $newInvoicePositionsAppartmentsArray,
            $newInvoicePositionsMiscellaneousArray,
            $vatSums,
            $brutto,
            $netto,
            $apartmentTotal,
            $miscTotal
        );

        return $this->render(
            'Invoices/invoice_show_preview.html.twig',
            array(
                'invoice' => $invoice,
                'vats' => $vatSums,
                'brutto' => $brutto,
                'netto' => $netto,
                'appartments' => $newInvoicePositionsAppartmentsArray,
                'positions' => $newInvoicePositionsMiscellaneousArray,
                'apartmentTotal' => $apartmentTotal,
                'miscTotal' => $miscTotal,
                'token' => $csrf->getCSRFTokenForForm()
            )
        );
    }

    public function createNewInvoiceAction(CSRFProtectionService $csrf, SessionInterface $session, InvoiceService $is, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $error = false;

        if (($csrf->validateCSRFToken($request))) {
            $newInvoiceReservationsArray = $session->get("invoiceInCreation");

            $invoiceCustomerArray = $session->get("invoiceCustomer");
            $customer = $is->makeInvoiceCustomerFromArray($invoiceCustomerArray);

            $newInvoicePositionsMiscellaneousArray = $session->get("invoicePositionsMiscellaneous");
            $newInvoicePositionsAppartmentsArray = $session->get("invoicePositionsAppartments");

            $invoice = $is->getNewInvoiceForCustomer(
                $customer,
                $session->get("new-invoice-id")
            );

            $invoiceDate =  $session->get("invoiceDate");
            $invoice->setDate($invoiceDate);

            $invoice->setRemark($request->get("remark"));
            $em->persist($invoice);

            foreach ($newInvoicePositionsAppartmentsArray as $appartmentPosition) {
                $appartmentPosition->setInvoice($invoice);
                $em->persist($appartmentPosition);
            }

            foreach ($newInvoicePositionsMiscellaneousArray as $miscellaneousPosition) {
                $miscellaneousPosition->setInvoice($invoice);
                $em->persist($miscellaneousPosition);
            }

            foreach ($newInvoiceReservationsArray as $reservationid) {
                $reservation = $em->getReference(Reservation::class, $reservationid);
                $reservation->addInvoice($invoice);
                $em->persist($reservation);
            }

            $em->flush();

            $this->addFlash('success', 'invoice.flash.create.success');
        }

        return $this->render(
            'feedback.html.twig',
            array(
                "error" => $error
            )
        );
    }

    public function updateStatusAction(CSRFProtectionService $csrf, Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        if (($csrf->validateCSRFToken($request))) {
            $invoice = $em->getRepository(Invoice::class)->find($id);
            $invoice->setStatus($request->get('invoice-status'));
            $em->persist($invoice);
            $em->flush();
        }

        return new Response("");
    }
    
    /**
     * 
     * @param integer $id
     * @return type
     */
    public function showChangeCustomerInvoiceEditAction(CSRFProtectionService $csrf, InvoiceService $is, $id) {
        $em = $this->getDoctrine()->getManager();
        $invoice = $em->getRepository(Invoice::class)->find($id);
        $reservations = $invoice->getReservations();
        $customersArray = Array();
        
        // collect all unique bookers and customers for recommendation list
        foreach ($reservations as $reservation) {
            $booker = $reservation->getBooker();
            $customers = $reservation->getCustomers();
            foreach($customers as $customer) {
                if(!array_key_exists($customer->getId(), $customersArray)) {
                    $customersArray[$customer->getId()] = $customer;
                }
            }
            if(!array_key_exists($booker->getId(), $customersArray)) {
                $customersArray[$booker->getId()] = $booker;
            }
        }
        
        $customer = $is->makeInvoiceCustomerFromInvoice($invoice);
        
        return $this->render(
            'Invoices/invoice_form_show_change_customer.html.twig',
            array(
                'reservations' => $reservations,
                'customers' => $customersArray,
                'invoiceCustomer' => $customer,
                'invoiceId' => $invoice->getId(),
                'token' => $csrf->getCSRFTokenForForm()
            )
        );
    }
    
    public function showChangeNumberInvoiceEditAction(CSRFProtectionService $csrf, $id) {
        $em = $this->getDoctrine()->getManager();
        $invoice = $em->getRepository(Invoice::class)->find($id);
        $reservations = $invoice->getReservations();
        
        return $this->render(
            'Invoices/invoice_form_show_change_number.html.twig',
            array(
                'reservations' => $reservations,
                'invoice' => $invoice,
                'invoiceId' => $invoice->getId(),
                'token' => $csrf->getCSRFTokenForForm()
            )
        );
    }
    
    public function saveChangeNumberInvoiceEditAction(CSRFProtectionService $csrf, Request $request) {
        $em = $this->getDoctrine()->getManager();
        $id = $request->get('invoice-id');
        if ($csrf->validateCSRFToken($request, true)) {
            if(strlen($request->get("date")) > 0 && strlen($request->get("number")) > 0) {
                $invoice = $em->getRepository(Invoice::class)->find($id);
                $invoice->setDate(new \DateTime($request->get("date")));
                $invoice->setNumber($request->get('number'));
                $em->persist($invoice);
                $em->flush();
                
                $this->addFlash('success', 'invoice.flash.edit.success');
            } else {
                return $this->showChangeNumberInvoiceEditAction($id);
            }
        } 
        
        return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', array(
            'id'  => $id,
        ));
    }
    
    public function showChangeRemarkInvoiceEditAction(CSRFProtectionService $csrf, $id) {
        $em = $this->getDoctrine()->getManager();
        $invoice = $em->getRepository(Invoice::class)->find($id);
        $reservations = $invoice->getReservations();
  
        return $this->render(
            'Invoices/invoice_form_show_change_remark.html.twig',
            array(
                'reservations' => $reservations,
                'invoice' => $invoice,
                'invoiceId' => $invoice->getId(),
                'token' => $csrf->getCSRFTokenForForm()
            )
        );
    }
    
    public function saveChangeRemarkInvoiceEditAction(CSRFProtectionService $csrf, Request $request) {
        $em = $this->getDoctrine()->getManager();
        $id = $request->get('invoice-id');
        if ($csrf->validateCSRFToken($request, true)) {
            $invoice = $em->getRepository(Invoice::class)->find($id);
            $invoice->setRemark($request->get("remark"));
            $em->persist($invoice);
            $em->flush();

            $this->addFlash('success', 'invoice.flash.edit.success');
        } 
        
        return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', array(
            'id'  => $id,
        ));
    }
    
    public function exportToPdfAction(SessionInterface $session, TemplatesService $ts, InvoiceService $is, $id, $templateId)
    {
        $em = $this->getDoctrine()->getManager();
        // save id, after page reload template will be preselected in dropdown
        $session->set("invoice-template-id", $templateId);
        
        $templateOutput = $ts->renderTemplate($templateId, $id, $is);
        $template = $em->getRepository(Template::class)->find($templateId);
        $invoice = $em->getRepository(Invoice::class)->find($id);

        $pdfOutput = $ts->getPDFOutput($templateOutput, "Rechnung-".$invoice->getNumber(), $template);
        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }
    
    /**
     * Will be called when clicking on the delete button in the show/edit modal
     * @param Request $request
     * @return string
     */
    public function deleteInvoiceAction(CSRFProtectionService $csrf, AuthorizationCheckerInterface $authChecker, InvoiceService $is, Request $request)
    {
        if ($authChecker->isGranted('ROLE_ADMIN')) {
            if (($csrf->validateCSRFToken($request, true))) {
                $delete = $is->deleteInvoice($request->get('id'));

                if ($delete) {
                    $this->addFlash('success', 'invoice.flash.delete.success');
                } else {
                    $this->addFlash('warning', 'invoice.flash.delete.not.possible');
                }
            }
        }
        return new Response("ok");
    }
}
