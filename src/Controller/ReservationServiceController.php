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
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Intl\Countries;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use App\Controller\CustomerServiceController;
use App\Service\CSRFProtectionService;
use App\Service\ReservationObject;
use App\Service\ReservationService;
use App\Service\CustomerService;
use App\Service\TemplatesService;
use App\Entity\ReservationOrigin;
use App\Entity\Reservation;
use App\Entity\User;
use App\Entity\Customer;
use App\Entity\Subsidiary;
use App\Entity\Appartment;
use App\Entity\Template;
use App\Entity\Correspondence;


class ReservationServiceController extends AbstractController
{
    private $perPage = 15;

    public function __construct()
    {
    }

    /**
     * Index Action start page
     *
     * @return mixed
     */
    public function indexAction(SessionInterface $session)
    {
        $em = $this->getDoctrine()->getManager();
        $objects = $em->getRepository(Subsidiary::class)->findAll();

        $today = strtotime(date("Y").'-'.date("m").'-'.(date("d")-2). ' UTC');
        $start = $session->get("reservation-overview-start", $today);
        $interval = $session->get("reservation-overview-interval", 15);
        $objectId = $session->get("reservation-overview-objectid", "all");

        return $this->render('Reservations/index.html.twig', array(
            'objects' => $objects,
            'today' => $start,
            'interval' => $interval,
            'objectId' => $objectId
        ));
    }

    /**
     * Gets the reservation overview as a table
     *
     * @param Request $request
     * @return mixed
     */
    public function getTableAction(SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $date = $request->get("start");
        $intervall = $request->get("intervall");
        $objectId = $request->get("object");

        if ($date == null) {
            $date = strtotime(date("Y").'-'.date("m").'-'.(date("d")-2). ' UTC');
        } else {
            $date = strtotime($date .' UTC');   // set timezone to UTC to ignore daylight saving changes
        }

        if ($intervall == null) {
            $intervall = 15;
        }

        if ($objectId == null || $objectId == "all") {
            $appartments = $em->getRepository(Appartment::class)->findAll();
        } else {
            $object = $em->getRepository(Subsidiary::class)->findById($objectId);
            $appartments = $em->getRepository(Appartment::class)->findByObject($object);
        }

        $session->set("reservation-overview-start", $date);
        $session->set("reservation-overview-interval", $intervall);
        $session->set("reservation-overview-objectid", $objectId);

        return $this->render('Reservations/reservation_table.html.twig', array(
            "appartments" => $appartments,
            "today" => $date,
            "intervall" => $intervall
        ));
    }

    /**
     * Shows the first form in the create reservation process, where you select a period and an appartment
     *
     * @param Request $request
     * @return mixed
     */
    public function showSelectAppartmentsFormAction(SessionInterface $session, ReservationService $rs, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $objects = $em->getRepository(Subsidiary::class)->findAll();

        if ($request->get('createNewReservation') == "true") {
            $newReservationsInformationArray = array();
            $session->set("reservationInCreation", $newReservationsInformationArray);
            $session->set("customersInReservation", Array());
            $session->remove("customersInReservation");    // unset
        } else {
            $newReservationsInformationArray = $session->get("reservationInCreation");
        }

        if (count($newReservationsInformationArray) != 0) {
            $objectHasAppartments = true;
        } else {
            $objectHasAppartments = false;
        }

        $reservations = $rs->createReservationsFromReservationInformationArray($newReservationsInformationArray);

        return $this->render('Reservations/reservation_form_select_period_and_appartment.html.twig', array(
            'objects' => $objects,
            'objectSelected' => $request->get("object"),
            'objectHasAppartments' => $objectHasAppartments,
            'reservations' => $reservations,
        ));
    }

    /**
     * Gets the available appartments in the reservation process for the given period
     *
     * @param Request $request
     * @return mixed
     */
    public function getAvailableAppartmentsAction(SessionInterface $session, ReservationService $rs, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $start = $request->get("from");
        $end = $request->get("end");
        $appartmentsDb = $em->getRepository(Appartment::class)->loadAvailableAppartmentsForPeriod($start, $end, $request->get("object"));

        $newReservationsInformationArray = $session->get("reservationInCreation", array());


        if (count($newReservationsInformationArray) != 0) {
            foreach ($appartmentsDb as $appartment) {
                if (!($rs->isAppartmentAlreadyBookedInCreationProcess($newReservationsInformationArray, $appartment, $start, $end))) {
                    $availableAppartments[] = $appartment;
                }
            }
        } else {
            $availableAppartments = $appartmentsDb;
        }

        return $this->render('Reservations/reservation_form_show_available_appartments.html.twig', array(
            'appartments' => $availableAppartments,
        ));
    }

    /**
     * Gets the available appartments in the edit process of a reservation for the given period
     *
     * @param Request $request
     * @return mixed
     */
    public function getEditAvailableAppartmentsAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $start = $request->get("from");
        $end = $request->get("end");
        $appartmentsDb = $em->getRepository(Appartment::class)->loadAvailableAppartmentsForPeriod($start, $end, $request->get("object"));

        return $this->render('Reservations/reservation_form_edit_show_available_appartments.html.twig', array(
            'appartments' => $appartmentsDb,
        ));
    }

    /**
     * Adds an Appartment to the selected ones in the reservation process
     *
     * @param Request $request
     * @return mixed
     */
    public function addAppartmentToReservationAction(HttpKernelInterface $kernel, SessionInterface $session, Request $request)
    {
        $newReservationsInformationArray = $session->get("reservationInCreation");

        if ($request->get("appartmentid") != null) {
            $newReservationsInformationArray[] = new ReservationObject($request->get("appartmentid"), $request->get("from"), $request->get("end"), 
				$request->get("status"), $request->get("persons"));
            $session->set("reservationInCreation", $newReservationsInformationArray);
        }

        $request2 = $request->duplicate([], []);
        $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::showSelectAppartmentsFormAction');

        return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * Adds an Appartment to create a reservation if user selects period in reservation table (mouse)
     *
     * @param Request $request
     * @return mixed
     */
    public function addAppartmentToReservationSelectableAction(HttpKernelInterface $kernel, SessionInterface $session, Request $request)
    {
        if ($request->get('createNewReservation') == "true") {
            $newReservationsInformationArray = array();
            $session->set("reservationInCreation", $newReservationsInformationArray);
            $session->remove("customersInReservation");
        }

        if ($request->get("appartmentid") != null) {
            $from = $request->get("from");
            $fromDate = new \DateTime($from);
            $end = $request->get("end");
            $endDate = new \DateTime($end);

            // if start is grater end -> change start and end
            if($fromDate > $endDate) {
                $end = $from;
                $from = $request->get("end");
            }
            $newReservationsInformationArray[] = new ReservationObject($request->get("appartmentid"), $from,
                                                    $end, $request->get("status", 1), $request->get("persons", 1));
            $session->set("reservationInCreation", $newReservationsInformationArray);
        }
        
        $request2 = $request->duplicate([], []);
        $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::showSelectAppartmentsFormAction');
        
        return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * Deletes an Appartment from the selection
     *
     * @param Request $request
     * @return mixed
     */
    public function removeAppartmentFromReservationAction(HttpKernelInterface $kernel, SessionInterface $session, Request $request)
    {
        $newReservationsInformationArray = $session->get("reservationInCreation");
        unset($newReservationsInformationArray[$request->get("appartmentid")]);
        $session->set("reservationInCreation", $newReservationsInformationArray);

        $request2 = $request->duplicate([], []);
        $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::showSelectAppartmentsFormAction');

        return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * Modifys the Appartment options of a selected a Appartment
     *
     * @param Request $request
     * @return mixed
     */
    public function modifyAppartmentOptionsAction(HttpKernelInterface $kernel, SessionInterface $session, Request $request)
    {
        $newReservationsInformationArray = $session->get("reservationInCreation");

        $newReservationInformation = $newReservationsInformationArray[$request->get("appartmentid")];
        $newReservationInformation->setPersons($request->get("persons"));
        $newReservationInformation->setStatus($request->get("status"));

        $session->set("reservationInCreation", $newReservationsInformationArray);

        $request2 = $request->duplicate([], []);
        $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::showSelectAppartmentsFormAction');

        return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * Shows the form for selecting a booker for the reservation
     *
     * @param Request $request
     * @return mixed
     */
    public function selectCustomerAction(SessionInterface $session, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $newReservationsInformationArray = $session->get("reservationInCreation");
        $customerId = array_values($newReservationsInformationArray)[0]->getCustomerId();

        if ($customerId != null) {
            $customer = $em->getRepository(Customer::class)->findById($customerId)[0];
        } else {
            $customer = null;
        }

        return $this->render('Reservations/reservation_form_select_customer.html.twig', array(
            'customer' => $customer
        ));
    }

    /**
     * Gets all Customers which fit the given criteria
     *
     * @param Request $request
     * @return mixed
     */
    public function getCustomersAction(Request $request)
    {
        $search = $request->get('lastname', '');
        $page = $request->get('page', 1);

        $em = $this->getDoctrine()->getManager();
        $customers = $em->getRepository(Customer::class)->getCustomersLike("%" . $search . "%", $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($customers->count() / $this->perPage);

        return $this->render('Reservations/reservation_form_show_customers.html.twig', array(
            'customers' => $customers,
            'page' => $page,
            'pages' => $pages,
            'search' => $search
        ));
    }

    /**
     * Shows the form for creating a new customer
     *
     * @param Request $request
     * @return mixed
     */
    public function getNewCustomerFormAction(CSRFProtectionService $csrf, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        // Get the country names for a locale
        $countries = Countries::getNames($request->getLocale());

        $customer = new Customer();
        $customer->setId('new');

        $customersForTemplate = $em->getRepository(Customer::class)->getLastCustomers(5);

        return $this->render('Customers/customer_form_create_input_fields.html.twig', array(
            'token' => $csrf->getCSRFTokenForForm(),
            'countries' => $countries,
            'customer' => $customer,
            'customersForTemplate' => $customersForTemplate,
            'addresstypes' => CustomerServiceController::$addessTypes
        ));
    }

    /**
     * Creates a new customer and continues to the new reservation preview
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function createNewCustomerAction(HttpKernelInterface $kernel, CSRFProtectionService $csrf, CustomerService $cs, Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $customer = $cs->getCustomerFromForm($request);

        $em->persist($customer);
        $em->flush();

        //$request->request->set("customerid", $customer->getId());
        //$subRequest = Request::create('/reservations/reservation/new/preview', 'POST', $request->request->all());
        $request2 = $request->duplicate([], []);
        $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::previewNewReservationAction');
        $request2->request->add(Array('customerid' => $customer->getId()));

        return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);

    }

    /**
     * Creates a preview of the new reservation, where the user can add additional guests to the reservation
     *
     * @param Request $request
     * @return mixed
     */
    public function previewNewReservationAction(CSRFProtectionService $csrf, SessionInterface $session, ReservationService $rs, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $tab = $request->get('tab', 'booker');

        if ($request->get('customerid') != null) {
            $bookerId = $request->get('customerid');
            $session->set("booker", $bookerId);
        } else {
            $bookerId = $session->get("booker");
        }
        $booker = $em->getRepository(Customer::class)->find($bookerId);

        $newReservationsInformationArray = $session->get("reservationInCreation");
        $rs->setCustomerInReservationInformationArray($newReservationsInformationArray, $booker);

        $reservations = $rs->createReservationsFromReservationInformationArray($newReservationsInformationArray);

        $customers = Array();
        $customersInSession = $session->get("customersInReservation");

        if (is_array($customersInSession)) {
            foreach ($customersInSession as $customer) {
                $customers[] = Array('c' => $em->getRepository(Customer::class)->find($customer['id']), 
                                     'appartmentId' => $customer['appartmentId']);
            }
        } else {
            // initial set booker as customer (guest in room)
            $customersInReservation = Array();
            foreach($reservations as $reservation) {
                $customersInReservation[] = Array('id' => $booker->getId(), 'appartmentId' => $reservation->getAppartment()->getId());
                $customers[] = Array('c' => $booker, 'appartmentId' => $reservation->getAppartment()->getId());
            }
            $session->set("customersInReservation", $customersInReservation);   
        }

        $origins = $em->getRepository(ReservationOrigin::class)->findAll();

        return $this->render('Reservations/reservation_form_show_preview.html.twig', array(
            'booker' => $booker,
            'customers' => $customers,
            "reservations" => $reservations,
            'token' => $csrf->getCSRFTokenForForm(),
            'tab' => $tab,
            'error' => true,
            'origins' => $origins,
            'correspondences' => Array()
        ));
    }

    /**
     * Creates a new reservation with the information which have been entered in the process before
     *
     * @param Request $request
     * @return mixed
     */
    public function createNewReservationAction(CSRFProtectionService $csrf, SessionInterface $session, ReservationService $rs, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $error = false;

        if (($csrf->validateCSRFToken($request))) {
            $newReservationsInformationArray = $session->get("reservationInCreation");

            $booker = $em->getRepository(Customer::class)->find($session->get("booker"));

            $reservations = $rs->createReservationsFromReservationInformationArray($newReservationsInformationArray, $booker);

            $customersInReservation = $session->get("customersInReservation");

            $origin = $em->getRepository(ReservationOrigin::class)->find($request->get('reservation-origin'));

            foreach ($reservations as $reservation) {
                $reservation->setRemark($request->get('remark'));
                $reservation->setReservationOrigin($origin);

                foreach($customersInReservation as $guest) {
                    // add guest only if he is in the appartment
                    if($guest['appartmentId'] == $reservation->getAppartment()->getId()) {
                        $guest = $em->getRepository(Customer::class)->find($guest['id']);
                        $reservation->addCustomer($guest);
                    }
                    
                }

                $em->persist($reservation);
            }
            $em->flush();

            $this->addFlash('success', 'reservation.flash.create.success');
        }

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }

    /**
     * Gets an already existing reservation and shows it
     *
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function getReservationAction(CSRFProtectionService $csrf, Request $request, $id)
    {
        $tab = $request->get('tab', 'booker');
        $em = $this->getDoctrine()->getManager();

        /* @var $reservation Reservation */
        $reservation = $em->getRepository(Reservation::class)->findById($id)[0];

        $correspondences = $reservation->getCorrespondences();

        $origins = $em->getRepository(ReservationOrigin::class)->findAll();

        return $this->render('Reservations/reservation_form_show.html.twig', array(
            'booker' => $reservation->getBooker(),
            'customers' => $reservation->getCustomers(),
            'reservations' => Array($reservation),
            'token' => $csrf->getCSRFTokenForForm(),
            'error' => true,
            'tab' => $tab,
            'origins' => $origins,
            'correspondences' => $correspondences
        ));
    }

    /**
     * Edits an existing reservation
     *
     * @param Request $request
     * @param $id
     * @param bool $error
     * @return mixed
     */
    public function editReservationAction(SessionInterface $session, Request $request, $id, $error = false)
    {
        $em = $this->getDoctrine()->getManager();
        $objects = $em->getRepository(Subsidiary::class)->findAll();
        $reservation = $em->getRepository(Reservation::class)->findById($id)[0];

        // clear session variable
        $newReservationsInformationArray = array();
        $session->set("reservationInCreation", $newReservationsInformationArray);

        $origins = $em->getRepository(ReservationOrigin::class)->findAll();

        return $this->render('Reservations/reservation_form_edit.html.twig', array(
            'objects' => $objects,
            'objectSelected' => $request->get("object"),
            'reservation' => $reservation,
            'error' => $error,
            'origins' => $origins
        ));
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function editChangeAppartmentAction(ReservationService $rs, Request $request)
    {
        $id = $request->get('id');
        $reservations = $rs->updateReservation($request);
        if (count($reservations) > 0) {
            $this->addFlash('warning', 'reservation.flash.update.conflict');
        } else {
            $this->addFlash('success', 'reservation.flash.update.success');
        }
        
        return $this->forward('App\Controller\ReservationServiceController::editReservationAction', array(
            'id' => $id,
            'error' => true
        ));
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function editChangeReservationAction(ReservationService $rs, Request $request)
    {
        $id = $request->get('id');
        $reservations = $rs->updateReservation($request);
        if (count($reservations) > 0) {
            $this->addFlash('warning', 'reservation.flash.update.conflict');
        } else {
            $this->addFlash('success', 'reservation.flash.update.success');
        }
        
        return $this->forward('App\Controller\ReservationServiceController::editReservationAction', array(
            'id' => $id,
            'error' => true
        ));
    }

    /**
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function editReservationCustomerAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();
        if ($id != "new") {
            $reservation = $em->getRepository(Reservation::class)->findById($id)[0];
        } else {
            $reservation = new Reservation();
            $reservation->setId("new");
        }

        return $this->render('Reservations/reservation_form_edit_change_customer.html.twig', array(
            'reservation' => $reservation,
            'tab' => $request->get('tab', 'booker'), // from which tab this method was called
            'appartmentId' => $request->get('appartmentId', 0) // for which appartment we want to change customer (0 = booker of the reservation)
        ));
    }

    /**
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function editReservationCustomerCreateAction(CSRFProtectionService $csrf, SessionInterface $session, ReservationService $rs, CustomerService $cs, Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $error = false;
        if (($csrf->validateCSRFToken($request))) {

            $customer = $cs->getCustomerFromForm($request);

            if (strlen($customer->getSalutation()) == 0 || strlen($customer->getLastname()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em->persist($customer);
                $em->flush();
                // add succes message
                $this->addFlash('success', 'customer.flash.create.success');

                $tab = $request->get('tab', 'booker');
                if($id !== 'new') {
                    $reservation = $em->getRepository(Reservation::class)->find($id);
                    $rs->updateReservationCustomers($reservation, $customer, $tab);
                } else {
                    $customersInReservation = $session->get("customersInReservation");
                    $customerIsAlreadyInReservation = false;
                    if (!$customerIsAlreadyInReservation) {
                        $customersInReservation[] = Array('id' => $customer->getId(), 'appartmentId' => $request->get('appartmentId'));
                        $session->set("customersInReservation", $customersInReservation);
                    }
                }
            }
        }

        return $this->render('feedback.html.twig', array(
            'error' => $error,
        ));
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function editReservationCustomersGetAction(Request $request)
    {
        $search = $request->get('lastname', '');
        $page = $request->get('page', 1);

        $em = $this->getDoctrine()->getManager();
        $customers = $em->getRepository(Customer::class)->getCustomersLike("%" . $request->get("lastname") . "%", $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($customers->count() / $this->perPage);

        return $this->render('Reservations/reservation_form_edit_show_customers.html.twig', array(
            'customers' => $customers,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
            'tab' => $request->get('tab', 'booker'), // from wihich tab this method was called
            'appartmentId' => $request->get('appartmentId', 0) // for which appartment we want to change customer (0 = booker of the reservation)
        ));
    }

    /**
     * @param Request $request
     * @param $id
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function editReservationCustomerChangeAction(HttpKernelInterface $kernel, SessionInterface $session, ReservationService $rs, Request $request, $id)
    {
        $tab = $request->get('tab', 'booker');
        $appartmentId = $request->get('appartmentId', 0);
        $customerId = $request->get('customerId');     
        
        if ($id != "new") {
            $em = $this->getDoctrine()->getManager();
            $customer = $em->getRepository(Customer::class)->find($customerId);

            /* @var $reservation Reservation */
            $reservation = $em->getRepository(Reservation::class)->find($id);

            $rs->updateReservationCustomers($reservation, $customer, $tab);

            $forwardController = 'App\Controller\ReservationServiceController::getReservationAction';
            $params = Array(
                'id' => $id,
                'tab' => $tab
            );
            return $this->forward($forwardController, $params);
        } else {
            $customersInReservation = $session->get("customersInReservation");                   
            $customerIsAlreadyInReservation = false;
            
            if ($customersInReservation == null) {
                $customersInReservation = Array();
            } else {                
                foreach($customersInReservation as $customer) {
                    if($customer['id'] == $customerId && $customer['appartmentId'] == $appartmentId) {
                        $customerIsAlreadyInReservation = true;
                        break;
                    }
                }
            }

            if (!$customerIsAlreadyInReservation) {
                $customersInReservation[] = Array('id' => $customerId, 'appartmentId' => $appartmentId);
                $session->set("customersInReservation", $customersInReservation);
            }

            $request2 = $request->duplicate([], []);
            $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::previewNewReservationAction');
            $request2->request->add(Array(
                'tab' => $tab
            ));
            return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);
        }
        
        
    }

    /**
     * @param Request $request
     * @return string
     */
    public function deleteReservationAction(CSRFProtectionService $csrf, ReservationService $rs, Request $request)
    {
        if (($csrf->validateCSRFToken($request, true))) {
            $delete = $rs->deleteReservation($request->get('id'));

            if ($delete) {
                $this->addFlash('success', 'reservation.flash.delete.success');
            } else {
                $this->addFlash('warning', 'reservation.flash.delete.not.possible');
            }
        }
        return new Response("ok");
    }

    /**
     * Deletes Customer that is in the appartment if user clicks the delete icon in tab "Gäste in diesem Zimmer"
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function deleteReservationCustomerAction(HttpKernelInterface $kernel, CSRFProtectionService $csrf, SessionInterface $session, Request $request)
    {
        $customerId = $request->get('customer-id');
        $reservationId = $request->get('reservation-id');
        $tab = $request->get('tab', 'booker');

        if ($reservationId != "new") {
            if (($csrf->validateCSRFToken($request))) {
                $em = $this->getDoctrine()->getManager();
                $customer = $em->getRepository(Customer::class)->findById($customerId)[0];

                /* @var $reservation Reservation */
                $reservation = $em->getRepository(Reservation::class)->findById($reservationId)[0];
                $reservation->removeCustomer($customer);
                $em->persist($reservation);
                $em->flush();
                $this->addFlash('success', 'reservation.flash.delete.customer.success');
            }
            
            $forwardController = 'App\Controller\ReservationServiceController::getReservationAction';
            $params = Array(
                'id' => $reservationId,
                'tab' => $tab
            );
            
            return $this->forward($forwardController, $params);
        } else {
            $guestsInReservation = $session->get("customersInReservation");
            $appartmentId = $request->get('appartmentId', 0);

            foreach($guestsInReservation as $key=>$guest) {
                if($guest['id'] == $customerId && $guest['appartmentId'] == $appartmentId ) {
                    unset($guestsInReservation[$key]);
                    $session->set("customersInReservation", $guestsInReservation);
                    break;
                }
            }

            $request2 = $request->duplicate([], []);
            $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::previewNewReservationAction');
            $request2->request->add(Array(
                'tab' => $tab
            ));
            
            return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);
        }        
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getEditCustomerAction(CSRFProtectionService $csrf, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $customerId = $request->get('customer-id');
        $reservationId = $request->get('reservation-id');
        $tab = $request->get('tab', 'booker');
        $customer = $em->getRepository(Customer::class)->find($customerId);

        if ($reservationId != "new") {
            $reservation = $em->getRepository(Reservation::class)->findById($reservationId)[0];
        } else {
            $reservation = new Reservation();
            $reservation->setId("new");
        }

        // Get the country names for a locale
        $countries = Countries::getNames($request->getLocale());

        return $this->render('Reservations/reservation_form_edit_customer_edit.html.twig', array(
            'customer' => $customer,
            'reservation' => $reservation,
            'token' => $csrf->getCSRFTokenForForm(),
            'countries' => $countries,
            'addresstypes' => CustomerServiceController::$addessTypes,
            'tab' => $tab
        ));
    }

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function saveEditCustomerAction(HttpKernelInterface $kernel, CSRFProtectionService $csrf, CustomerService $cs, Request $request)
    {
        $id = $request->get('customer-id');
        $reservationId = $request->get('reservation-id');
        $tab = $request->get('tab');
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            /* @var $customer Customer */
            $customer = $cs->getCustomerFromForm($request, $id);

            // check for mandatory fields
            if (strlen($customer->getSalutation()) == 0 || strlen($customer->getLastname()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em = $this->getDoctrine()->getManager();
                $em->persist($customer);
                $em->flush();

                // add succes message
                $this->addFlash('success', 'customer.flash.edit.success');
            }
        }

        if ($reservationId != "new") {
            $forwardController = 'App\Controller\ReservationServiceController::getReservationAction';
            $params = Array(
                'id' => $reservationId,
                'tab' => $tab,
            );
        } else {
            $forwardController = 'App\Controller\ReservationServiceController::previewNewReservationAction';
            $params = Array(
                'tab' => $tab
            );
        }
        return $this->forward($forwardController, $params);
    }
    
    
    public function selectTemplateAction(SessionInterface $session, TemplatesService $ts, Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $progress = $request->get("inProcess", 'false');
        // if email is inProcess, you can attach other files but no other emails
        if($request->get("inProcess") == 'true') {
            $search = Array('TEMPLATE_FILE%', 'TEMPLATE_RESERVATION_PDF');
            $session->set("selectedTemplateId", $request->get("templateId"));
            $correspondences = $ts->getCorrespondencesForAttachment();
        } else {
            $search = Array('TEMPLATE_RESERVATION_%', 'TEMPLATE_FILE%');
            // reset do defaults at start of progress
            $session->set("selectedTemplateId", null);
            $session->set("templateAttachmentIds", Array());
            $correspondences = array();
        }
        
        $templates = $em->getRepository(Template::class)->loadByTypeName($search);

        return $this->render('Reservations/reservation_form_select_template.html.twig', array(
            'templates' => $templates,
            'selectedTemplateId' => $request->get("templateId"),
            'inProcess' => $progress,
            'correspondences' => $correspondences
        ));
    }
    
    public function previewTemplateAction(CSRFProtectionService $csrf, SessionInterface $session, TemplatesService $ts, Request $request, ReservationService $rs, $id)
    {
        $em = $this->getDoctrine()->getManager();       
        $inProcess = $request->get("inProcess");
        
        $selectedReservationIds = $session->get("selectedReservationIds");
        $reservations = Array();
        foreach ($selectedReservationIds as $reservationId) {
            $reservations[] = $em->getRepository(Reservation::class)->find($reservationId);
        }
        $selectedTemplateId = $session->get("selectedTemplateId");
        // now we came back from attachment and view previously mail (with new attachment)

        if($inProcess == 'false' && $selectedTemplateId != null) {
            $id = $selectedTemplateId;
        }

        /* @var $template Template */
        $template = $em->getRepository(Template::class)->find($id);
        $templateOutput = $ts->renderTemplate($id, $reservations, $rs);  
        
        // add attachments
        $attachments = Array();
        $attachmentIds = $session->get("templateAttachmentIds", Array());

        foreach($attachmentIds as $attId) {
            $aId = array_values($attId)[0]; // we only need the first id, it doesent matter how many reseervations are selected, in this view only one file is needed
            $attachments[] = $em->getRepository(Correspondence::class)->find($aId);
        }

        return $this->render('Reservations/reservation_form_preview_template.html.twig', array(
            'templateOutput' => $templateOutput,
            'template' => $template,
            'reservations' => $reservations,
            'token' => $csrf->getCSRFTokenForForm(),
            'inProcess' => $inProcess,
            'attachmentIds' => $session->get("templateAttachmentIds"),
            'attachments' => $attachments
        ));
    }
}
