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
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\Template;
use App\Form\InvoiceApartmentPositionType;
use App\Form\InvoiceCustomerType;
use App\Form\InvoiceMiscPositionType;
use App\Service\CSRFProtectionService;
use App\Entity\InvoiceSettingsData;
use App\Form\InvoiceSettingsType;
use App\Service\InvoiceService;
use App\Service\TemplatesService;
use App\Service\XRechnungService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[Route('/invoices')]
class InvoiceServiceController extends AbstractController
{
    private $perPage = 20;

    #[Route('/', name: 'invoices.overview', methods: ['GET'])]
    public function indexAction(ManagerRegistry $doctrine, RequestStack $requestStack, TemplatesService $ts, Request $request)
    {
        $em = $doctrine->getManager();

        $templates = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_INVOICE_PDF']);
        $defaultTemplate = $ts->getDefaultTemplate($templates);

        $templateId = 1;
        if (null != $defaultTemplate) {
            $templateId = $defaultTemplate->getId();
        }

        $templateId = $requestStack->getSession()->get('invoice-template-id', $templateId); // get previously selected id

        $search = $request->query->get('search', '');
        $page = $request->query->get('page', 1);

        $invoices = $em->getRepository(Invoice::class)->findByFilter($search, $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($invoices->count() / $this->perPage);

        return $this->render(
            'Invoices/index.html.twig',
            [
                'invoices' => $invoices,
                'templates' => $templates,
                'templateId' => $templateId,
                'page' => $page,
                'pages' => $pages,
                'search' => $search,
            ]
        );
    }

    #[Route('/search', name: 'invoices.search', methods: ['POST'])]
    public function searchInvoicesAction(ManagerRegistry $doctrine, RequestStack $requestStack, TemplatesService $ts, Request $request)
    {
        $em = $doctrine->getManager();
        $search = $request->request->get('search', '');
        $page = $request->request->get('page', 1);
        $invoices = $em->getRepository(Invoice::class)->findByFilter($search, $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($invoices->count() / $this->perPage);

        $templates = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_INVOICE_PDF']);
        $defaultTemplate = $ts->getDefaultTemplate($templates);

        $templateId = 1;
        if (null != $defaultTemplate) {
            $templateId = $defaultTemplate->getId();
        }

        $templateId = $requestStack->getSession()->get('invoice-template-id', $templateId); // get previously selected id

        return $this->render('Invoices/invoice_table.html.twig', [
            'invoices' => $invoices,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
            'templateId' => $templateId,
        ]);
    }

    #[Route('/get/{id}/', name: 'invoices.get.invoice', methods: ['GET'], defaults: ['id' => '0'])]
    public function getInvoiceAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, RequestStack $requestStack, TemplatesService $ts, InvoiceService $is, $id)
    {
        $em = $doctrine->getManager();
        $invoice = $em->getRepository(Invoice::class)->find($id);
        $vatSums = [];
        $brutto = 0;
        $netto = 0;
        $apartmentTotal = 0;
        $miscTotal = 0;
        $is->calculateSums(
            $invoice->getAppartments(),
            $invoice->getPositions(),
            $vatSums,
            $brutto,
            $netto,
            $apartmentTotal,
            $miscTotal
        );

        $templates = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_INVOICE_PDF']);
        $defaultTemplate = $ts->getDefaultTemplate($templates);

        $templateId = 1;
        if (null != $defaultTemplate) {
            $templateId = $defaultTemplate->getId();
        }

        $templateId = $requestStack->getSession()->get('invoice-template-id', $templateId); // get previously selected id

        return $this->render(
            'Invoices/invoice_form_show.html.twig',
            [
                'invoice' => $invoice,
                'vats' => $vatSums,
                'brutto' => $brutto,
                'netto' => $netto,
                'token' => $csrf->getCSRFTokenForForm(),
                'templateId' => $templateId,
                'apartmentTotal' => $apartmentTotal,
                'miscTotal' => $miscTotal,
                'error' => true,
            ]
        );
    }

    #[Route('/new', name: 'invoices.new.invoice', methods: ['GET'])]
    public function newInvoiceAction(ManagerRegistry $doctrine, InvoiceService $is, CSRFProtectionService $csrf, RequestStack $requestStack, Request $request)
    {
        $em = $doctrine->getManager();

        if ('true' == $request->query->get('createNewInvoice')) {
            $newInvoiceInformationArray = [];
            // reset session variables
            $is->unsetInvoiceInCreation($requestStack);
        } else {
            $newInvoiceInformationArray = $requestStack->getSession()->get('invoiceInCreation');
        }

        if (0 == count($newInvoiceInformationArray)) {
            $objectContainsReservations = 'false';
        } else {
            $objectContainsReservations = 'true';
        }

        return $this->render(
            'Invoices/invoice_form_select_reservation.html.twig',
            [
                'objectContainsReservations' => $objectContainsReservations,
            ]
        );
    }

    #[Route('/get/reservations/in/period', name: 'invoices.get.reservations.in.period', methods: ['POST'])]
    public function getReservationsInPeriodAction(ManagerRegistry $doctrine, RequestStack $requestStack, Request $request)
    {
        $em = $doctrine->getManager();
        $reservations = [];
        $newInvoiceInformationArray = $requestStack->getSession()->get('invoiceInCreation', []);

        $potentialReservations = $em->getRepository(
            Reservation::class
        )->loadReservationsForPeriod($request->request->get('from'), $request->request->get('end'));

        foreach ($potentialReservations as $reservation) {
            // make sure that already selected reservation can not be choosen twice
            if (!in_array($reservation->getId(), $newInvoiceInformationArray)) {
                $reservations[] = $reservation;
            }
        }

        return $this->render(
            'Reservations/reservation_matching_reservations.html.twig',
            [
                'reservations' => $reservations,
            ]
        );
    }

    #[Route('/get/reservations/for/customer', name: 'invoices.get.reservations.for.customer', methods: ['POST'])]
    public function getReservationsForCustomerAction(ManagerRegistry $doctrine, RequestStack $requestStack, Request $request)
    {
        $em = $doctrine->getManager();
        $reservations = [];

        $customer = $em->getRepository(Customer::class)->findOneByLastname(
            $request->request->get('lastname')
        );

        if ($customer instanceof Customer) {
            $potentialReservations = $em->getRepository(
                Reservation::class
            )->loadReservationsWithoutInvoiceForCustomer($customer);

            $newInvoiceInformationArray = $requestStack->getSession()->get('invoiceInCreation', []);

            foreach ($potentialReservations as $reservation) {
                if (!in_array($reservation->getId(), $newInvoiceInformationArray)) {
                    $reservations[] = $reservation;
                }
            }
        }

        return $this->render(
            'Reservations/reservation_matching_reservations.html.twig',
            [
                'reservations' => $reservations,
            ]
        );
    }

    #[Route('/select/reservation', name: 'invoices.select.reservation', methods: ['POST'])]
    public function selectReservationAction(ManagerRegistry $doctrine, InvoiceService $is, RequestStack $requestStack, Request $request)
    {
        $em = $doctrine->getManager();

        if ('true' == $request->request->get('createNewInvoice')) {
            $is->unsetInvoiceInCreation($requestStack);
        }

        if (null != $request->request->get('reservationid')) {
            $newInvoiceInformationArray = $requestStack->getSession()->get('invoiceInCreation', []);
            $newInvoiceInformationArray[] = $request->request->get('reservationid');
            $requestStack->getSession()->set('invoiceInCreation', $newInvoiceInformationArray);
        }

        $reservations = $is->getInvoiceReservationsInCreation($requestStack);

        if (count($reservations) > 0) {
            $arrayContainsReservations = true;
        }

        return $this->render(
            'Invoices/invoice_form_show_selected_reservations.html.twig',
            [
                'reservations' => $reservations,
                'arrayContainsReservations' => $arrayContainsReservations ?? false,
            ]
        );
    }

    #[Route('/remove/reservation/from/selection', name: 'invoices.remove.reservation.from.selection', methods: ['POST'])]
    public function removeReservationFromSelectionAction(ManagerRegistry $doctrine, RequestStack $requestStack, Request $request)
    {
        $em = $doctrine->getManager();

        $newInvoiceInformationArray = $requestStack->getSession()->get('invoiceInCreation');

        if (null != $request->request->get('reservationkey')) {
            unset($newInvoiceInformationArray[$request->request->get('reservationkey')]);
            $requestStack->getSession()->set('invoiceInCreation', $newInvoiceInformationArray);
        }
        $reservations = [];
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
            [
                'reservations' => $reservations,
                'arrayContainsReservations' => $arrayContainsReservations,
            ]
        );
    }

    #[Route('/create/positions/create', name: 'invoices.create.invoice.positions', methods: ['GET', 'POST'])]
    public function showCreateInvoicePositionsFormAction(ManagerRegistry $doctrine, RequestStack $requestStack, InvoiceService $is, Request $request)
    {
        $em = $doctrine->getManager();
        $newInvoiceReservationsArray = $requestStack->getSession()->get('invoiceInCreation');

        if (!$requestStack->getSession()->has('invoicePositionsMiscellaneous')) {
            $requestStack->getSession()->set('invoicePositionsMiscellaneous', []);
            // prefill positions for all selected reservations
            $is->prefillMiscPositionsWithReservationIds($newInvoiceReservationsArray, $requestStack, true);
        }
        $newInvoicePositionsMiscellaneousArray = $requestStack->getSession()->get('invoicePositionsMiscellaneous');

        if (!$requestStack->getSession()->has('invoicePositionsAppartments')) {
            $requestStack->getSession()->set('invoicePositionsAppartments', []);
            // prefill positions for all selected reservations
            foreach ($newInvoiceReservationsArray as $resId) {
                $reservation = $em->getRepository(Reservation::class)->find($resId);
                $is->prefillAppartmentPositions($reservation, $requestStack);
            }
        }
        $newInvoicePositionsAppartmentsArray = $requestStack->getSession()->get('invoicePositionsAppartments');

        if (!$requestStack->getSession()->has('newInvoice')) {
            $customer = $em->getRepository(Reservation::class)->find(
                $newInvoiceReservationsArray[0]
            )->getBooker();
            $is->setDefaultCustomer($customer, $requestStack);
        }
        $invoice = $is->getInvoiceInCreation($requestStack);

        if (!$requestStack->getSession()->has('invoiceDate')) {
            $invoiceDate = new \DateTime();
            $requestStack->getSession()->set('invoiceDate', $invoiceDate);
        } else {
            $invoiceDate = $requestStack->getSession()->get('invoiceDate');
        }

        if (!$requestStack->getSession()->has('new-invoice-id')) {
            $invoiceid = null;
            $lastInvoiceId = $em->getRepository(Invoice::class)->getLastInvoiceId();
        } else {
            $invoiceid = $requestStack->getSession()->get('new-invoice-id');
            $lastInvoiceId = '';
        }

        if (null != $request->request->get('invoiceid')) {
            $invoiceid = $request->request->get('invoiceid');
            $requestStack->getSession()->set('new-invoice-id', $invoiceid);
        }
        if (null != $request->request->get('invoiceDate') && strlen($request->request->get('invoiceDate')) > 0) {
            $invoiceDate = new \DateTime($request->request->get('invoiceDate'));
            $requestStack->getSession()->set('invoiceDate', $invoiceDate);
        }

        if (0 != count($newInvoicePositionsAppartmentsArray) && null != $invoiceid) {
            $appartmentPositionExists = true;
        } else {
            $appartmentPositionExists = false;
        }

        return $this->render(
            'Invoices/invoice_form_show_create_invoice_positions.html.twig',
            [
                'positionsMiscellaneous' => $newInvoicePositionsMiscellaneousArray,
                'positionsAppartment' => $newInvoicePositionsAppartmentsArray,
                'appartmentPositionExists' => $appartmentPositionExists,
                'lastinvoiceid' => $lastInvoiceId,
                'invoiceid' => $invoiceid,
                'invoice' => $invoice,
                'invoiceDate' => $invoiceDate,
            ]
        );
    }

    #[Route('/create/invoice/customer/change', name: 'invoices.show.change.customer', methods: ['GET', 'POST'])]
    public function showChangeCustomerInvoiceFormAction(ManagerRegistry $doctrine, InvoiceService $is, Request $request, RequestStack $requestStack)
    {
        $invoice = $is->getInvoiceInCreation($requestStack);

        $form = $this->createForm(InvoiceCustomerType::class, $invoice, [
            'action' => $this->generateUrl('invoices.show.change.customer'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // no need to explicitly write back to session since reference to invoice is still there
            return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction');
        }

        $reservations = $is->getInvoiceReservationsInCreation($requestStack);
        $customersArray = $is->getCustomersForRecommendation($reservations);

        return $this->render(
            'Invoices/invoice_form_show_change_customer.html.twig',
            [
                'reservations' => $reservations,
                'customers' => $customersArray,
                'invoice' => $invoice,
                'form' => $form->createView(),
            ]
        );
    }

    #[Route('/{invoiceId}/position/apartment/new', name: 'invoices.new.apartment.position', methods: ['GET', 'POST'])]
    public function newApartmentPosition(ManagerRegistry $doctrine, $invoiceId, RequestStack $requestStack, InvoiceService $is, Request $request): Response
    {
        $invoicePosition = new InvoiceAppartment();
        $em = $doctrine->getManager();

        // during invoice create process
        if ('new' === $invoiceId) {
            $newInvoiceReservationsArray = $requestStack->getSession()->get('invoiceInCreation');

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
            'reservations' => $reservations,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // float values must be converted to string for later calculation steps in calculateSums()
            $invoicePosition->setPrice(number_format($invoicePosition->getPrice(), 2, '.', ''));
            $invoicePosition->setVat(number_format($invoicePosition->getVat(), 2, '.', ''));

            // during invoice create process
            if ('new' === $invoiceId) {
                $is->saveNewAppartmentPosition($invoicePosition, $requestStack);

                return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction');
            } else { // during edit process
                $em = $doctrine->getManager();
                $invoice = $em->getRepository(Invoice::class)->find($invoiceId);
                $invoicePosition->setInvoice($invoice);

                $em->persist($invoicePosition);
                $em->flush();

                $this->addFlash('success', 'invoice.flash.edit.success');

                return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', [
                    'id' => $invoiceId,
                ]);
            }
        }

        $prices = $em->getRepository(Price::class)->getActiveAppartmentPrices();

        return $this->render(
            'Invoices/invoice_form_show_create_appartment_position.html.twig',
            [
                'reservations' => $reservations,
                'prices' => $prices,
                'invoiceId' => $invoiceId,
                'form' => $form->createView(),
            ]
        );
    }

    #[Route('/{invoiceId}/edit/position/apartment/{id}/edit', name: 'invoices.edit.apartment.position', methods: ['GET', 'POST'])]
    public function editApartmentPosition(ManagerRegistry $doctrine, $invoiceId, $id, Request $request, RequestStack $requestStack, InvoiceService $is): Response
    {
        $em = $doctrine->getManager();

        // during invoice create process
        if ('new' === $invoiceId) {
            $newInvoicePositionsAppartmentArray = $requestStack->getSession()->get('invoicePositionsAppartments');
            $invoicePosition = $newInvoicePositionsAppartmentArray[$id];

            $reservations = $is->getInvoiceReservationsInCreation($requestStack);
        } else { // during edit process
            $invoicePosition = $em->getRepository(InvoiceAppartment::class)->find($id);

            $reservations = $invoicePosition->getInvoice()->getReservations();
        }

        $form = $this->createForm(InvoiceApartmentPositionType::class, $invoicePosition, [
            'action' => $this->generateUrl('invoices.edit.apartment.position', ['invoiceId' => $invoiceId, 'id' => $id]),
            'reservations' => $reservations,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // float values must be converted to string for later calculation steps in calculateSums()
            $invoicePosition->setPrice(number_format($invoicePosition->getPrice(), 2, '.', ''));
            $invoicePosition->setVat(number_format($invoicePosition->getVat(), 2, '.', ''));

            // during invoice create process
            if ('new' === $invoiceId) {
                $newInvoicePositionsAppartmentArray[$id] = $invoicePosition;
                $requestStack->getSession()->set('invoicePositionsAppartments', $newInvoicePositionsAppartmentArray);

                return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction');
            } else { // during edit process
                $em->persist($invoicePosition);
                $em->flush();

                $this->addFlash('success', 'invoice.flash.edit.success');

                return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', [
                    'id' => $invoicePosition->getInvoice()->getId(),
                ]);
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

    #[Route('/delete/invoice/appartment/position', name: 'invoices.delete.appartment.invoice.position', methods: ['POST'])]
    public function deleteAppartmentInvoicePositionAction(ManagerRegistry $doctrine, RequestStack $requestStack, Request $request)
    {
        $index = $request->request->get('appartmentInvoicePositionIndex', -1);       // during create process
        $positionId = $request->request->get('appartmentInvoicePositionEditId', -1); // during edit process

        if (-1 != $index) {
            $newInvoicePositionsAppartmentsArray = $requestStack->getSession()->get('invoicePositionsAppartments');
            unset($newInvoicePositionsAppartmentsArray[$index]);
            $requestStack->getSession()->set('invoicePositionsAppartments', $newInvoicePositionsAppartmentsArray);

            return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction');
        } else {
            $em = $doctrine->getManager();
            $invoiceAppartment = $em->getRepository(InvoiceAppartment::class)->find($positionId);
            $invoiceId = $invoiceAppartment->getInvoice()->getId();
            $em->remove($invoiceAppartment);
            $em->flush();

            $this->addFlash('success', 'invoice.flash.edit.success');

            return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', [
                    'id' => $invoiceId,
            ]);
        }
    }

    #[Route('/{invoiceId}/new/miscellaneous', name: 'invoices.new.miscellaneous.position', methods: ['GET', 'POST'])]
    public function newMiscellaneousPosition($invoiceId, ManagerRegistry $doctrine, RequestStack $requestStack, InvoiceService $is, Request $request): Response
    {
        $invoicePosition = new InvoicePosition();
        $form = $this->createForm(InvoiceMiscPositionType::class, $invoicePosition, [
            'action' => $this->generateUrl('invoices.new.miscellaneous.position', ['invoiceId' => $invoiceId]),
        ]);
        $form->handleRequest($request);
        $em = $doctrine->getManager();

        if ($form->isSubmitted() && $form->isValid()) {
            // float values must be converted to string for later calculation steps in calculateSums()
            $invoicePosition->setPrice(number_format($invoicePosition->getPrice(), 2));
            $invoicePosition->setVat(number_format($invoicePosition->getVat(), 2));

            // during invoice create process
            if ('new' === $invoiceId) {
                $is->saveNewMiscPosition($invoicePosition, $requestStack);

                return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction');
            } else { // during edit process
                $em = $doctrine->getManager();
                $invoice = $em->getRepository(Invoice::class)->find($invoiceId);
                $invoicePosition->setInvoice($invoice);

                $em->persist($invoicePosition);
                $em->flush();

                $this->addFlash('success', 'invoice.flash.edit.success');

                return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', [
                    'id' => $invoiceId,
                ]);
            }
        }

        $prices = $em->getRepository(Price::class)->getActiveMiscellaneousPrices();

        // during invoice create process
        if ('new' === $invoiceId) {
            $reservations = $is->getInvoiceReservationsInCreation($requestStack);
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
                'form' => $form->createView(),
            ]
        );
    }

    #[Route('/{invoiceId}/edit/miscellaneous/{id}/edit', name: 'invoices.edit.miscellaneous.position', methods: ['GET', 'POST'])]
    public function editMiscellaneousPosition($invoiceId, $id, ManagerRegistry $doctrine, Request $request, RequestStack $requestStack, InvoiceService $is): Response
    {
        // during invoice create process
        if ('new' === $invoiceId) {
            $newInvoicePositionsMiscellaneousArray = $requestStack->getSession()->get('invoicePositionsMiscellaneous');
            $positionMiscellaneous = $newInvoicePositionsMiscellaneousArray[$id];
        } else { // during edit process
            $em = $doctrine->getManager();
            $positionMiscellaneous = $em->getRepository(InvoicePosition::class)->find($id);
        }

        $form = $this->createForm(InvoiceMiscPositionType::class, $positionMiscellaneous, [
            'action' => $this->generateUrl('invoices.edit.miscellaneous.position', ['invoiceId' => $invoiceId, 'id' => $id]),
        ]);
        $form->handleRequest($request);
        $em = $doctrine->getManager();

        if ($form->isSubmitted() && $form->isValid()) {
            // float values must be converted to string for later calculation steps in calculateSums()
            $positionMiscellaneous->setPrice(number_format($positionMiscellaneous->getPrice(), 2));
            $positionMiscellaneous->setVat(number_format($positionMiscellaneous->getVat(), 2));

            // during invoice create process
            if ('new' === $invoiceId) {
                $newInvoicePositionsMiscellaneousArray[$id] = $positionMiscellaneous;
                $requestStack->getSession()->set('invoicePositionsMiscellaneous', $newInvoicePositionsMiscellaneousArray);

                return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction');
            } else { // during edit process
                $em->persist($positionMiscellaneous);
                $em->flush();

                $this->addFlash('success', 'invoice.flash.edit.success');

                return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', [
                    'id' => $positionMiscellaneous->getInvoice()->getId(),
                ]);
            }
        }

        $prices = $em->getRepository(Price::class)->getActiveMiscellaneousPrices();

        // during invoice create process
        if ('new' === $invoiceId) {
            $reservations = $is->getInvoiceReservationsInCreation($requestStack);
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

    #[Route('/delete/invoice/miscellaneous/position', name: 'invoices.delete.miscellaneous.invoice.position', methods: ['POST'])]
    public function deleteMiscellaneousInvoicePositionAction(ManagerRegistry $doctrine, RequestStack $requestStack, Request $request)
    {
        $index = $request->request->get('miscellaneousInvoicePositionIndex', -1);       // during create process
        $positionId = $request->request->get('miscellaneousInvoicePositionEditId', -1); // during edit process

        if (-1 != $index) {
            $newInvoicePositionsMiscellaneousArray = $requestStack->getSession()->get('invoicePositionsMiscellaneous');
            unset($newInvoicePositionsMiscellaneousArray[$index]);
            $requestStack->getSession()->set('invoicePositionsMiscellaneous', $newInvoicePositionsMiscellaneousArray);

            return $this->forward('App\Controller\InvoiceServiceController::showCreateInvoicePositionsFormAction');
        } else {
            $em = $doctrine->getManager();
            $invoiceMisc = $em->getRepository(InvoicePosition::class)->find($positionId);
            $invoiceId = $invoiceMisc->getInvoice()->getId();

            $em->remove($invoiceMisc);
            $em->flush();

            $this->addFlash('success', 'invoice.flash.edit.success');

            return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', [
                    'id' => $invoiceId,
            ]);
        }
    }

    #[Route('/new/invoice/preview', name: 'invoices.show.new.invoice.preview', methods: ['POST'])]
    public function showNewInvoicePreviewAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, RequestStack $requestStack, InvoiceService $is)
    {
        $em = $doctrine->getManager();

        $invoice = $is->getInvoiceInCreation($requestStack);

        $newInvoicePositionsMiscellaneousArray = $requestStack->getSession()->get('invoicePositionsMiscellaneous');
        $newInvoicePositionsAppartmentsArray = $requestStack->getSession()->get('invoicePositionsAppartments');

        $invoice->setNumber($requestStack->getSession()->get('new-invoice-id'));

        $invoiceDate = $requestStack->getSession()->get('invoiceDate');
        $invoice->setDate($invoiceDate);

        $vatSums = [];
        $brutto = 0;
        $netto = 0;
        $apartmentTotal = 0;
        $miscTotal = 0;
        $is->calculateSums(
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
            [
                'invoice' => $invoice,
                'vats' => $vatSums,
                'brutto' => $brutto,
                'netto' => $netto,
                'positionsApartment' => $newInvoicePositionsAppartmentsArray,
                'positionsMiscellaneous' => $newInvoicePositionsMiscellaneousArray,
                'apartmentTotal' => $apartmentTotal,
                'miscTotal' => $miscTotal,
                'token' => $csrf->getCSRFTokenForForm(),
            ]
        );
    }

    #[Route('/create/new/invoice', name: 'invoices.create.invoice', methods: ['POST'])]
    public function createNewInvoiceAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, RequestStack $requestStack, InvoiceService $is, Request $request)
    {
        $em = $doctrine->getManager();
        $error = false;

        if ($csrf->validateCSRFToken($request)) {
            $newInvoiceReservationsArray = $requestStack->getSession()->get('invoiceInCreation');

            $invoice = $is->getInvoiceInCreation($requestStack);

            $newInvoicePositionsMiscellaneousArray = $requestStack->getSession()->get('invoicePositionsMiscellaneous');
            $newInvoicePositionsAppartmentsArray = $requestStack->getSession()->get('invoicePositionsAppartments');

            $invoice->setRemark($request->request->get('remark'));
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
            [
                'error' => $error,
            ]
        );
    }

    #[Route('/update/{id}/status', name: 'invoices.update.status', methods: ['POST'])]
    public function updateStatusAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request, $id)
    {
        $em = $doctrine->getManager();

        if ($csrf->validateCSRFToken($request)) {
            $invoice = $em->getRepository(Invoice::class)->find($id);
            $invoice->setStatus($request->request->get('invoice-status'));
            $em->persist($invoice);
            $em->flush();
        }

        return new Response('');
    }

    #[Route('/{id}/edit/customer/change', name: 'invoices.get.invoice.customer.change', methods: ['GET', 'POST'])]
    public function showChangeCustomerInvoiceEditAction(ManagerRegistry $doctrine, InvoiceService $is, Request $request, RequestStack $requestStack, Invoice $invoice): Response
    {
        $em = $doctrine->getManager();

        $form = $this->createForm(InvoiceCustomerType::class, $invoice, [
            'action' => $this->generateUrl('invoices.get.invoice.customer.change', ['id' => $invoice->getId()]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($invoice);
            $em->flush();

            $this->addFlash('success', 'invoice.flash.edit.success');

            return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', [
                'id' => $invoice->getId(),
            ]);
        }
        $reservations = $invoice->getReservations();
        $customersArray = $is->getCustomersForRecommendation($reservations);

        return $this->render(
            'Invoices/invoice_form_show_change_customer.html.twig',
            [
                'reservations' => $reservations,
                'customers' => $customersArray,
                'invoice' => $invoice,
                'form' => $form->createView(),
            ]
        );
    }

    #[Route('/{id}/edit/number/show', name: 'invoices.edit.invoice.number.show', methods: ['GET'], defaults: ['id' => '0'])]
    public function showChangeNumberInvoiceEditAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, $id)
    {
        $em = $doctrine->getManager();
        $invoice = $em->getRepository(Invoice::class)->find($id);
        $reservations = $invoice->getReservations();

        return $this->render(
            'Invoices/invoice_form_show_change_number.html.twig',
            [
                'reservations' => $reservations,
                'invoice' => $invoice,
                'invoiceId' => $invoice->getId(),
                'token' => $csrf->getCSRFTokenForForm(),
            ]
        );
    }

    #[Route('/edit/number/save', name: 'invoices.edit.invoice.number.save', methods: ['POST'])]
    public function saveChangeNumberInvoiceEditAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request)
    {
        $em = $doctrine->getManager();
        $id = $request->request->get('invoice-id');
        if ($csrf->validateCSRFToken($request, true)) {
            if (strlen($request->request->get('date')) > 0 && strlen($request->request->get('number')) > 0) {
                $invoice = $em->getRepository(Invoice::class)->find($id);
                $invoice->setDate(new \DateTime($request->request->get('date')));
                $invoice->setNumber($request->request->get('number'));
                $em->persist($invoice);
                $em->flush();

                $this->addFlash('success', 'invoice.flash.edit.success');
            } else {
                return $this->showChangeNumberInvoiceEditAction($id);
            }
        }

        return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', [
            'id' => $id,
        ]);
    }

    #[Route('/{id}/edit/remark/show', name: 'invoices.edit.invoice.remark.show', methods: ['GET'], defaults: ['id' => '0'])]
    public function showChangeRemarkInvoiceEditAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, $id)
    {
        $em = $doctrine->getManager();
        $invoice = $em->getRepository(Invoice::class)->find($id);
        $reservations = $invoice->getReservations();

        return $this->render(
            'Invoices/invoice_form_show_change_remark.html.twig',
            [
                'reservations' => $reservations,
                'invoice' => $invoice,
                'invoiceId' => $invoice->getId(),
                'token' => $csrf->getCSRFTokenForForm(),
            ]
        );
    }

    #[Route('/edit/remark/save', name: 'invoices.edit.invoice.remark.save', methods: ['POST'])]
    public function saveChangeRemarkInvoiceEditAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request)
    {
        $em = $doctrine->getManager();
        $id = $request->request->get('invoice-id');
        if ($csrf->validateCSRFToken($request, true)) {
            $invoice = $em->getRepository(Invoice::class)->find($id);
            $invoice->setRemark($request->request->get('remark'));
            $em->persist($invoice);
            $em->flush();

            $this->addFlash('success', 'invoice.flash.edit.success');
        }

        return $this->forward('App\Controller\InvoiceServiceController::getInvoiceAction', [
            'id' => $id,
        ]);
    }

    #[Route('/export/pdf/{id}/{templateId}', name: 'invoices.export.pdf', methods: ['GET'])]
    public function exportToPdfAction(ManagerRegistry $doctrine, RequestStack $requestStack, TemplatesService $ts, InvoiceService $is, int $id, int $templateId): Response
    {
        $em = $doctrine->getManager();
        // save id, after page reload template will be preselected in dropdown
        $requestStack->getSession()->set('invoice-template-id', $templateId);

        $templateOutput = $ts->renderTemplate($templateId, $id, $is);
        $template = $em->getRepository(Template::class)->find($templateId);
        $invoice = $em->getRepository(Invoice::class)->find($id);

        $pdfOutput = $ts->getPDFOutput($templateOutput, 'Rechnung-'.$invoice->getNumber(), $template);
        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    #[Route('/{id}/export/einvoice', name: 'invoices.export.xrechnung', methods: ['GET'])]
    public function exportToXRechnung(ManagerRegistry $doctrine, RequestStack $requestStack, XRechnungService $xrechnung, Invoice $invoice): Response
    {
        $em = $doctrine->getManager();
        $invoiceSettings = $em->getRepository(InvoiceSettingsData::class)->findBy(['isActive' => true]);
        if(!($invoiceSettings instanceof InvoiceSettingsData)) {
            // todo: error handling
        }
        $xml = $xrechnung->createInvoice($invoice, $invoiceSettings);

        $response = new Response($xml);
        $response->headers->set('Content-Type', 'text/xml');

        return $response;
    }

    #[Route('/settings', name: 'invoices.settings.get', methods: ['GET'])]
    public function getSettings(ManagerRegistry $doctrine, Request $request, RequestStack $requestStack, TemplatesService $ts): Response
    {
        $settings = $doctrine->getRepository(InvoiceSettingsData::class)->findBy([], ['isActive' => 'DESC']);
        $forms = [];
        foreach ($settings as $setting) {
            $form = $this->createForm(InvoiceSettingsType::class, $setting, [
                'action' => $this->generateUrl('invoices.settings.edit', ['id' => $setting->getId()]),
            ]);
            $forms[] = $form->createView();
        }
        $em = $doctrine->getManager();
        $templates = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_INVOICE_PDF']);
        $defaultTemplate = $ts->getDefaultTemplate($templates);

        $templateId = 1;
        if (null != $defaultTemplate) {
            $templateId = $defaultTemplate->getId();
        }

        $templateId = $requestStack->getSession()->get('invoice-template-id', $templateId); // get previously selected id


        return $this->render('Invoices/invoice_form_settings.html.twig', [
            'settings' => $settings,
            'forms' => $forms,
            'templates' => $templates,
            'templateId' => $templateId,
        ]);
    }

    #[Route('/settings/new', name: 'invoices.settings.new', methods: ['GET', 'POST'])]
    public function newSettings(ManagerRegistry $doctrine, Request $request): Response
    {
        $setting = new InvoiceSettingsData();
        $form = $this->createForm(InvoiceSettingsType::class, $setting, [
            'action' => $this->generateUrl('invoices.settings.new'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if($setting->isActive()) {
                $doctrine->getRepository(InvoiceSettingsData::class)->setAllInactive();
            }
            $doctrine->getManager()->persist($setting);
            $doctrine->getManager()->flush();

            // add success message
            $this->addFlash('success', 'invoice.settings.flash.create.success');

            return $this->forward('App\Controller\InvoiceServiceController::getSettings');
        }

        return $this->render('Invoices/invoice_form_settings_new.html.twig', [
            'setting' => $setting,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/settings/{id}/edit', name: 'invoices.settings.edit', methods: ['POST'])]
    public function editSettings(ManagerRegistry $doctrine, Request $request, InvoiceSettingsData $setting): Response
    {
        $form = $this->createForm(InvoiceSettingsType::class, $setting, [
            'action' => $this->generateUrl('invoices.settings.edit', ['id' => $setting->getId()]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if($setting->isActive()) {
                $doctrine->getRepository(InvoiceSettingsData::class)->setAllInactive($setting->getId());

            }
            $doctrine->getManager()->flush();

            // add success message
            $this->addFlash('success', 'invoice.settings.flash.edit.success');            
        }
        return $this->forward('App\Controller\InvoiceServiceController::getSettings');
    }

    #[Route('/settings/{id}/delete', name: 'invoices.settings.delete', methods: ['DELETE'])]
    public function delete(ManagerRegistry $doctrine, Request $request, InvoiceSettingsData $setting): Response
    {
        if ($this->isCsrfTokenValid('delete'.$setting->getId(), $request->request->get('_token'))) {
            $entityManager = $doctrine->getManager();
            $entityManager->remove($setting);
            $entityManager->flush();
            $this->addFlash('success', 'invoice.settings.flash.delete.success');
        }

        return $this->forward('App\Controller\InvoiceServiceController::getSettings');
    }

    /**
     * Will be called when clicking on the delete button in the show/edit modal.
     *
     * @return string
     */
    #[Route('/invoice/delete', name: 'invoices.dodelete.invoice', methods: ['POST'])]
    public function deleteInvoiceAction(CSRFProtectionService $csrf, AuthorizationCheckerInterface $authChecker, InvoiceService $is, Request $request)
    {
        if ($authChecker->isGranted('ROLE_ADMIN')) {
            if ($csrf->validateCSRFToken($request, true)) {
                $delete = $is->deleteInvoice($request->request->get('id'));

                if ($delete) {
                    $this->addFlash('success', 'invoice.flash.delete.success');
                } else {
                    $this->addFlash('warning', 'invoice.flash.delete.not.possible');
                }
            }
        }

        return new Response('ok');
    }
}
