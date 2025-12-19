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

use App\Entity\Appartment;
use App\Entity\Correspondence;
use App\Entity\Customer;
use App\Entity\Enum\IDCardType;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\Subsidiary;
use App\Entity\Template;
use App\Form\ReservationMetaType;
use App\Service\CalendarService;
use App\Service\CSRFProtectionService;
use App\Service\CustomerService;
use App\Service\InvoiceService;
use App\Service\PriceService;
use App\Service\ReservationObject;
use App\Service\ReservationService;
use App\Service\TemplatesService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_RESERVATIONS_RO')] // ROLE_RESERVATIONS is included
#[Route('/reservation')]
class ReservationServiceController extends AbstractController
{
    private $perPage = 15;

    /**
     * Index Action start page.
     */
    #[Route('/', name: 'start', methods: ['GET'])]
    public function indexAction(ManagerRegistry $doctrine, RequestStack $requestStack, CalendarService $cs)
    {
        $em = $doctrine->getManager();
        $objects = $em->getRepository(Subsidiary::class)->findAll();

        $today = strtotime(date('Y').'-'.date('m').'-'.(date('d') - 2).' UTC');
        $start = $requestStack->getSession()->get('reservation-overview-start', $today);
        $interval = $requestStack->getSession()->get('reservation-overview-interval', 30);

        $year = $requestStack->getSession()->get('reservation-overview-year', date('Y'));

        $objectId = $requestStack->getSession()->get('reservation-overview-objectid', 'all');

        $apartments = $em->getRepository(Appartment::class)->findAllByProperty($objectId);
        $firstApartmentId = isset($apartments[0]) ? $apartments[0]->getId() : 0;
        $selectedApartmentId = $requestStack->getSession()->get('reservation-overview-apartment', $firstApartmentId);

        $show = $requestStack->getSession()->get('reservation-overview', 'table');

        return $this->render('Reservations/index.html.twig', [
            'objects' => $objects,
            'today' => $start,
            'interval' => $interval,
            'year' => $year,
            'selectedApartmentId' => $selectedApartmentId,
            'apartments' => $apartments,
            'objectId' => $objectId,
            'holidayCountries' => $cs->getHolidayCountries($requestStack->getCurrentRequest()->getLocale()),
            'selectedCountry' => 'DE',
            'selectedSubdivision' => 'all',
            'show' => $show,
            'showFirstSteps' => (0 == $firstApartmentId),
        ]);
    }

    /**
     * Triggered by the buttons to switch reservation view table/yearly.
     */
    #[Route('/view/{show}', name: 'start.toggle.view', methods: ['GET'])]
    public function indexActionToggle(RequestStack $requestStack, string $show): Response
    {
        if ('yearly' === $show) {
            $requestStack->getSession()->set('reservation-overview', 'yearly');
        } else {
            $requestStack->getSession()->set('reservation-overview', 'table');
        }

        return $this->forward('App\Controller\ReservationServiceController::indexAction');
    }

    /**
     * Gets the reservation overview.
     */
    #[Route('/table', name: 'reservations.get.table', methods: ['GET'])]
    public function getTableAction(ManagerRegistry $doctrine, RequestStack $requestStack, Request $request): Response
    {
        $year = $request->query->get('year', null);
        if (null === $year) {
            return $this->_handleTableRequest($doctrine, $requestStack, $request);
        } else {
            return $this->_handleTableYearlyRequest($doctrine, $requestStack, $request);
        }
    }

    /**
     * Displays the regular table overview based on a start date and a period.
     */
    private function _handleTableRequest(ManagerRegistry $doctrine, RequestStack $requestStack, Request $request): Response
    {
        $em = $doctrine->getManager();
        $date = $request->query->get('start');
        $interval = $request->query->get('interval');
        $objectId = $request->query->get('object');
        $holidayCountry = $request->query->get('holidayCountry', 'DE');
        $selectedSubdivision = $request->query->get('holidaySubdivision', 'all');

        if (null == $date) {
            $date = strtotime(date('Y').'-'.date('m').'-'.(date('d') - 2).' UTC');
        } else {
            $date = strtotime($date.' UTC');   // set timezone to UTC to ignore daylight saving changes
        }

        if (null == $interval) {
            $interval = 30;
        } else if($interval < 8) {
            $interval = 8;
        } else if($interval > 180) {
            $interval = 180;
        } else {
            $interval = (int)$interval;
        }

        if (null == $objectId || 'all' == $objectId) {
            $appartments = $em->getRepository(Appartment::class)->findAll();
        } else {
            $appartments = $em->getRepository(Appartment::class)->findBy(['object' => $objectId], ['number' => 'ASC']);
        }

        $requestStack->getSession()->set('reservation-overview-start', $date);
        $requestStack->getSession()->set('reservation-overview-interval', $interval);
        $requestStack->getSession()->set('reservation-overview-objectid', $objectId);
        $requestStack->getSession()->set('reservation-overview', 'table');

        return $this->render('Reservations/reservation_table.html.twig', [
            'appartments' => $appartments,
            'today' => $date,
            'interval' => $interval,
            'holidayCountry' => $holidayCountry,
            'selectedSubdivision' => $selectedSubdivision,
            'objectId' => $objectId
        ]);
    }

    /**
     * Loads the actual table based on a given year and apartment.
     *
     * @throws NotFoundHttpException
     */
    private function _handleTableYearlyRequest(ManagerRegistry $doctrine, RequestStack $requestStack, Request $request): Response
    {
        $em = $doctrine->getManager();
        $objectId = $request->query->get('object');
        $year = $request->query->get('year', date('Y'));
        $apartmentId = $request->query->get('apartment');
        if(null == $apartmentId) {
            $apartments = $em->getRepository(Appartment::class)->findAllByProperty($objectId);
            $apartmentId = isset($apartments[0]) ? $apartments[0]->getId() : 0;
        }
        $apartment = $em->getRepository(Appartment::class)->find($apartmentId);


        if (!$apartment instanceof Appartment) {
            throw new NotFoundHttpException();
        }

        if (!preg_match('/[0-9]{4}/', $year)) {
            throw new NotFoundHttpException();
        }

        $requestStack->getSession()->set('reservation-overview-objectid', $objectId);
        $requestStack->getSession()->set('reservation-overview-year', $year);
        $requestStack->getSession()->set('reservation-overview-apartment', $apartment->getId());
        $requestStack->getSession()->set('reservation-overview', 'yearly');

        return $this->render('Reservations/reservation_table_year.html.twig', [
            'year' => $year,
            'apartment' => $apartment,
            // "holidayCountry" => $holidayCountry,
            // 'selectedSubdivision' => $selectedSubdivision
        ]);
    }

    #[Route('/table/settings', name: 'reservations.table.settings', methods: ['POST'])]
    public function tableSettingsAction(ManagerRegistry $doctrine, RequestStack $requestStack, CalendarService $cs)
    {
        $em = $doctrine->getManager();
        $request = $requestStack->getCurrentRequest();
        $objects = $em->getRepository(Subsidiary::class)->findAll();
        $selectedCountry = $request->request->get('holidayCountry', 'DE');
        $selectedSubdivision = $request->request->get('holidaySubdivision', 'all');
        $requestStack->getSession()->set('reservation-overview', 'table');

        $objectId = $requestStack->getSession()->get('reservation-overview-objectid', 'all');

        return $this->render('Reservations/reservation_table_settings_input_fields.html.twig', [
            'objects' => $objects,
            'objectId' => $objectId,
            'holidayCountries' => $cs->getHolidayCountries($requestStack->getCurrentRequest()->getLocale()),
            'selectedCountry' => $selectedCountry,
            'selectedSubdivision' => $selectedSubdivision,
        ]);
    }

    /**
     * Shows the first form in the create reservation process, where you select a period and an appartment.
     */
    #[Route('/select/appartment', name: 'reservations.select.appartment', methods: ['GET'])]
    public function showSelectAppartmentsFormAction(ManagerRegistry $doctrine, RequestStack $requestStack, ReservationService $rs, Request $request)
    {
        $em = $doctrine->getManager();
        $objects = $em->getRepository(Subsidiary::class)->findAll();
        $reservationStatus = $em->getRepository(ReservationStatus::class)->findAll();

        if ('true' == $request->query->get('createNewReservation')) {
            $newReservationsInformationArray = [];
            $requestStack->getSession()->set('reservationInCreation', $newReservationsInformationArray);
            $requestStack->getSession()->set('customersInReservation', []);
            $requestStack->getSession()->remove('customersInReservation');    // unset
            $requestStack->getSession()->remove('reservatioInCreationPrices');
        } else {
            $newReservationsInformationArray = $requestStack->getSession()->get('reservationInCreation');
        }

        if (0 != count($newReservationsInformationArray)) {
            $objectHasAppartments = true;
        } else {
            $objectHasAppartments = false;
        }

        $reservations = $rs->createReservationsFromReservationInformationArray($newReservationsInformationArray);

        return $this->render('Reservations/reservation_form_select_period_and_appartment.html.twig', [
            'objects' => $objects,
            'objectSelected' => $request->query->get('object'),
            'objectHasAppartments' => $objectHasAppartments,
            'reservations' => $reservations,
            'reservationStatus' => $reservationStatus,
        ]);
    }

    /**
     * Gets the available appartments in the reservation process for the given period.
     */
    #[Route('/appartments/available/get', name: 'reservations.get.available.appartments', methods: ['POST'])]
    public function getAvailableAppartmentsAction(ManagerRegistry $doctrine, ReservationService $rs, Request $request)
    {
        $em = $doctrine->getManager();
        $start = $request->request->get('from');
        $startDate = new \DateTime($start);
        $end = $request->request->get('end');
        $endDate = new \DateTime($end);
        $apartments = $rs->getAvailableApartments($startDate, $endDate, null, $request->request->get('object'));
        $reservationStatus = $em->getRepository(ReservationStatus::class)->findAll();

        return $this->render('Reservations/reservation_form_show_available_appartments.html.twig', [
            'appartments' => $apartments,
            'reservationStatus' => $reservationStatus,
        ]);
    }

    /**
     * Gets the available appartments in the edit process of a reservation for the given period.
     */
    #[Route('/edit/available/get', name: 'reservations.get.edit.available.appartments', methods: ['POST'])]
    public function getEditAvailableAppartmentsAction(ManagerRegistry $doctrine, Request $request, ReservationService $rs)
    {
        $em = $doctrine->getManager();
        $start = $request->request->get('from');
        $startDate = new \DateTime($start);
        $end = $request->request->get('end');
        $endDate = new \DateTime($end);

        $apartments = $rs->getAvailableApartments($startDate, $endDate, null, $request->request->get('object'));
        $reservationStatus = $em->getRepository(ReservationStatus::class)->findAll();

        return $this->render('Reservations/reservation_form_edit_show_available_appartments.html.twig', [
            'appartments' => $apartments,
            'reservationStatus' => $reservationStatus,
        ]);
    }

    /**
     * Adds an Appartment to the selected ones in the reservation process.
     */
    #[Route('/appartments/add/to/reservation', name: 'reservations.add.appartment.to.reservation', methods: ['POST'])]
    public function addAppartmentToReservationAction(HttpKernelInterface $kernel, RequestStack $requestStack, Request $request)
    {
        $newReservationsInformationArray = $requestStack->getSession()->get('reservationInCreation');

        if (null != $request->request->get('appartmentid')) {
            $newReservationsInformationArray[] = new ReservationObject(
                $request->request->get('appartmentid'),
                $request->request->get('from'),
                $request->request->get('end'),
                $request->request->get('status'),
                $request->request->get('persons')
            );
            $requestStack->getSession()->set('reservationInCreation', $newReservationsInformationArray);
        }

        $request2 = $request->duplicate([], []);
        $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::showSelectAppartmentsFormAction');

        return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * Adds an Appartment to create a reservation if user selects period in reservation table (mouse).
     */
    #[Route('/appartments/selectable/add/to/reservation', name: 'reservations.add.appartment.to.reservation.selectable', methods: ['POST'])]
    public function addAppartmentToReservationSelectableAction(ManagerRegistry $doctrine, HttpKernelInterface $kernel, RequestStack $requestStack, Request $request, ReservationService $rs)
    {
        if ('true' == $request->request->get('createNewReservation')) {
            $newReservationsInformationArray = [];
            $requestStack->getSession()->set('reservationInCreation', $newReservationsInformationArray);
            $requestStack->getSession()->remove('customersInReservation');
            $requestStack->getSession()->remove('reservatioInCreationPrices');
        }

        if (null != $request->request->get('appartmentid')) {
            $from = $request->request->get('from');
            $fromDate = new \DateTime($from);
            $end = $request->request->get('end');
            $endDate = new \DateTime($end);

            // if start is grater end -> change start and end
            if ($fromDate > $endDate) {
                $end = $from;
                $from = $request->request->get('end');
                $fromDate = $endDate;
                $endDate = new \DateTime($end);
            }
            $em = $doctrine->getManager();
            $room = $em->getRepository(Appartment::class)->find($request->request->get('appartmentid'));

            $isselactable = $rs->isApartmentAvailable($fromDate, $endDate, $room, 0);
            if ($isselactable) {
                $newReservationsInformationArray[] = new ReservationObject(
                    $request->request->get('appartmentid'),
                    $from,
                    $end,
                    $request->request->get('status', 1),
                    $request->request->get('persons', $room->getBedsMax())
                );
                $requestStack->getSession()->set('reservationInCreation', $newReservationsInformationArray);
            } else {
                $this->addFlash('warning', 'reservation.flash.update.conflict');
            }
        }

        $request2 = $request->duplicate([], []);
        $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::showSelectAppartmentsFormAction');

        return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * Deletes an Appartment from the selection.
     */
    #[Route('/appartments/remove/from/reservation', name: 'reservations.remove.appartment.from.reservation', methods: ['POST'])]
    public function removeAppartmentFromReservationAction(HttpKernelInterface $kernel, RequestStack $requestStack, Request $request)
    {
        $newReservationsInformationArray = $requestStack->getSession()->get('reservationInCreation');
        unset($newReservationsInformationArray[$request->request->get('appartmentid')]);
        $requestStack->getSession()->set('reservationInCreation', $newReservationsInformationArray);

        $request2 = $request->duplicate([], []);
        $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::showSelectAppartmentsFormAction');

        return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * Modifys the Appartment options of a selected a Appartment.
     */
    #[Route('/appartments/modify/options', name: 'reservations.modify.appartment.options', methods: ['POST'])]
    public function modifyAppartmentOptionsAction(HttpKernelInterface $kernel, RequestStack $requestStack, Request $request)
    {
        $newReservationsInformationArray = $requestStack->getSession()->get('reservationInCreation');

        $newReservationInformation = $newReservationsInformationArray[$request->request->get('appartmentid')];
        $newReservationInformation->setPersons($request->request->get('persons'));
        $newReservationInformation->setReservationStatus($request->request->get('status'));

        $requestStack->getSession()->set('reservationInCreation', $newReservationsInformationArray);

        $request2 = $request->duplicate([], []);
        $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::showSelectAppartmentsFormAction');

        return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * Shows the form for selecting a booker for the reservation.
     */
    #[Route('/select/customer', name: 'reservations.select.customer', methods: ['POST'])]
    public function selectCustomerAction(ManagerRegistry $doctrine, RequestStack $requestStack, Request $request)
    {
        $em = $doctrine->getManager();
        $newReservationsInformationArray = $requestStack->getSession()->get('reservationInCreation');
        $customerId = array_values($newReservationsInformationArray)[0]->getCustomerId();

        if (null != $customerId) {
            $customer = $em->getRepository(Customer::class)->findById($customerId)[0];
        } else {
            $customer = null;
        }

        return $this->render('Reservations/reservation_form_select_customer.html.twig', [
            'customer' => $customer,
        ]);
    }

    /**
     * Gets all Customers which fit the given criteria.
     */
    #[Route('/customers/get', name: 'reservations.get.customers', methods: ['POST'])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function getCustomersAction(ManagerRegistry $doctrine, Request $request)
    {
        $search = $request->request->get('lastname', '');
        $page = $request->request->get('page', 1);

        $em = $doctrine->getManager();
        $customers = $em->getRepository(Customer::class)->getCustomersLike('%'.$search.'%', $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($customers->count() / $this->perPage);

        return $this->render('Reservations/reservation_form_show_customers.html.twig', [
            'customers' => $customers,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
        ]);
    }

    /**
     * Shows the form for creating a new customer.
     */
    #[Route('/customers/new', name: 'reservations.get.customer.new.form', methods: ['POST'])]
    public function getNewCustomerFormAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request)
    {
        $em = $doctrine->getManager();
        // Get the country names for a locale
        $countries = Countries::getNames($request->getLocale());

        $customer = new Customer();
        $customer->setId('new');

        $customersForTemplate = $em->getRepository(Customer::class)->getLastCustomers(5);

        return $this->render('Customers/customer_form_create_input_fields.html.twig', [
            'token' => $csrf->getCSRFTokenForForm(),
            'countries' => $countries,
            'customer' => $customer,
            'customersForTemplate' => $customersForTemplate,
            'addresstypes' => CustomerServiceController::$addessTypes,
            'cardTypes' => IDCardType::cases(),
            'withController' => true,
        ]);
    }

    /**
     * Creates a new customer and continues to the new reservation preview.
     *
     * @return Response
     */
    #[Route('/customers/create', name: 'reservations.get.customer.create', methods: ['POST'])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function createNewCustomerAction(ManagerRegistry $doctrine, HttpKernelInterface $kernel, CSRFProtectionService $csrf, CustomerService $cs, Request $request)
    {
        $em = $doctrine->getManager();

        $customer = $cs->getCustomerFromForm($request);

        $em->persist($customer);
        $em->flush();

        // $request->request->set("customerid", $customer->getId());
        // $subRequest = Request::create('/reservations/reservation/new/preview', 'POST', $request->request->all());
        $request2 = $request->duplicate([], []);
        $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::previewNewReservationAction');
        $request2->request->add(['customerid' => $customer->getId()]);

        return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * Creates a preview of the new reservation, where the user can add additional guests to the reservation.
     */
    #[Route('/reservation/new/preview', name: 'reservations.create.preview', methods: ['POST'])]
    public function previewNewReservationAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, RequestStack $requestStack, InvoiceService $is, ReservationService $rs, PriceService $ps, Request $request)
    {
        $em = $doctrine->getManager();
        $tab = $request->request->get('tab', 'booker');

        if (null != $request->request->get('customerid')) {
            $bookerId = $request->request->get('customerid');
            $requestStack->getSession()->set('booker', $bookerId);
        } else {
            $bookerId = $requestStack->getSession()->get('booker');
        }
        $booker = $em->getRepository(Customer::class)->find($bookerId);

        $newReservationsInformationArray = $requestStack->getSession()->get('reservationInCreation');
        $rs->setCustomerInReservationInformationArray($newReservationsInformationArray, $booker);

        $reservations = $rs->createReservationsFromReservationInformationArray($newReservationsInformationArray);

        $customers = [];
        $customersInSession = $requestStack->getSession()->get('customersInReservation');

        if (is_array($customersInSession)) {
            foreach ($customersInSession as $customer) {
                $customers[] = ['c' => $em->getRepository(Customer::class)->find($customer['id']),
                                     'appartmentId' => $customer['appartmentId'], ];
            }
        } else {
            // initial set booker as customer (guest in room)
            $customersInReservation = [];
            foreach ($reservations as $reservation) {
                $customersInReservation[] = ['id' => $booker->getId(), 'appartmentId' => $reservation->getAppartment()->getId()];
                $customers[] = ['c' => $booker, 'appartmentId' => $reservation->getAppartment()->getId()];
            }
            $requestStack->getSession()->set('customersInReservation', $customersInReservation);
        }

        $origins = $em->getRepository(ReservationOrigin::class)->findAll();
        if (count($origins) > 0) {
            foreach ($reservations as $reservation) {
                $reservation->setReservationOrigin($origins[0]);
            }
        }

        $miscPricePositions = $rs->getMiscPricesInCreation($is, $reservations, $ps, $requestStack);
        $pricesInCreation = $requestStack->getSession()->get('reservatioInCreationPrices', []);

        $requestStack->getSession()->set('invoicePositionsAppartments', []);
        foreach ($reservations as $reservation) {
            $is->prefillAppartmentPositions($reservation, $requestStack);

            // add selected misc prices to reservation
            foreach ($pricesInCreation as $priceInCreation) {
                $reservation->addPrice($priceInCreation);
            }
        }
        $apartmentPricePositions = $requestStack->getSession()->get('invoicePositionsAppartments');

        $vatSums = [];
        $brutto = 0;
        $netto = 0;
        $apartmentTotal = 0;
        $miscTotal = 0;
        $is->calculateSums(
            new ArrayCollection($apartmentPricePositions),
            new ArrayCollection($miscPricePositions),
            $vatSums,
            $brutto,
            $netto,
            $apartmentTotal,
            $miscTotal
        );

        return $this->render('Reservations/reservation_form_show_preview.html.twig', [
            'booker' => $booker,
            'customers' => $customers,
            'reservations' => $reservations,
            'token' => $csrf->getCSRFTokenForForm(),
            'tab' => $tab,
            'error' => true,
            'origins' => $origins,
            'correspondences' => [],
            'miscPrices' => $ps->getActiveMiscellaneousPrices(),
            'positionsMiscellaneous' => $miscPricePositions,
            'positionsApartment' => $apartmentPricePositions,
            'vats' => $vatSums,
            'brutto' => $brutto,
            'netto' => $netto,
            'apartmentTotal' => $apartmentTotal,
            'miscTotal' => $miscTotal,
        ]);
    }

    /**
     * Creates a new reservation with the information which have been entered in the process before.
     */
    #[Route('/reservation/create', name: 'reservations.create.reservations', methods: ['POST'])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function createNewReservationAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, RequestStack $requestStack, ReservationService $rs, Request $request)
    {
        $em = $doctrine->getManager();
        $error = false;
        $arrivalTimeInput = $request->request->get('arrivalTime');
        $departureTimeInput = $request->request->get('departureTime');
        $arrivalTime = $this->createTimeFromRequestValue($arrivalTimeInput);
        $departureTime = $this->createTimeFromRequestValue($departureTimeInput);

        if ($csrf->validateCSRFToken($request)) {
            $newReservationsInformationArray = $requestStack->getSession()->get('reservationInCreation');

            $booker = $em->getRepository(Customer::class)->find($requestStack->getSession()->get('booker'));

            $reservations = $rs->createReservationsFromReservationInformationArray($newReservationsInformationArray, $booker);

            $customersInReservation = $requestStack->getSession()->get('customersInReservation');

            $origin = $em->getRepository(ReservationOrigin::class)->find($request->request->get('reservation-origin'));

            $pricesInCreation = $requestStack->getSession()->get('reservatioInCreationPrices', []);

            foreach ($reservations as $reservation) {
                $reservation->setRemark($request->request->get('remark'));
                $reservation->setArrivalTime($arrivalTime ? clone $arrivalTime : null);
                $reservation->setDepartureTime($departureTime ? clone $departureTime : null);
                $reservation->setReservationOrigin($origin);
                $reservation->setUuid(Uuid::v4());

                foreach ($customersInReservation as $guest) {
                    // add guest only if he is in the appartment
                    if ($guest['appartmentId'] == $reservation->getAppartment()->getId()) {
                        $guest = $em->getRepository(Customer::class)->find($guest['id']);
                        $reservation->addCustomer($guest);
                    }
                }

                // add selected misc prices to reservation
                foreach ($pricesInCreation as $priceInCreation) {
                    // we need to fetch the entity again because it is not managed anymore by the entitymanager when loading from the session
                    $price = $em->getRepository(Price::class)->find($priceInCreation->getId());
                    $reservation->addPrice($price);
                }
                if(!$rs->isApartmentAvailable($reservation->getStartDate(), $reservation->getEndDate(), $reservation->getAppartment(), $reservation->getPersons())) {
                    $this->addFlash('warning', 'reservation.flash.update.conflict.persons');
                }
                $em->persist($reservation);
            }
            $em->flush();

            $this->addFlash('success', 'reservation.flash.create.success');
        }

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    /**
     * Gets an already existing reservation and shows it.
     */
    #[Route('/get/{id}', name: 'reservations.get.reservation', methods: ['GET'])]
    public function getReservationAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, RequestStack $requestStack, InvoiceService $is, PriceService $ps, Request $request, $id)
    {
        $tab = $request->query->get('tab', 'booker');
        $em = $doctrine->getManager();

        /* @var $reservation Reservation */
        $reservation = $em->getRepository(Reservation::class)->findById($id)[0];

        $correspondences = $reservation->getCorrespondences();

        $origins = $em->getRepository(ReservationOrigin::class)->findAll();

        $requestStack->getSession()->set('invoicePositionsMiscellaneous', new ArrayCollection());
        $is->prefillMiscPositionsWithReservations([$reservation], $requestStack, true);
        $miscPricePositions = $requestStack->getSession()->get('invoicePositionsMiscellaneous');

        $requestStack->getSession()->set('invoicePositionsAppartments', new ArrayCollection());
        $is->prefillAppartmentPositions($reservation, $requestStack);
        $apartmentPricePositions = $requestStack->getSession()->get('invoicePositionsAppartments');

        $vatSums = [];
        $brutto = 0;
        $netto = 0;
        $apartmentTotal = 0;
        $miscTotal = 0;
        $is->calculateSums(
            $apartmentPricePositions,
            $miscPricePositions,
            $vatSums,
            $brutto,
            $netto,
            $apartmentTotal,
            $miscTotal
        );

        return $this->render('Reservations/reservation_form_show.html.twig', [
            'booker' => $reservation->getBooker(),
            'customers' => $reservation->getCustomers(),
            'reservations' => [$reservation],
            'token' => $csrf->getCSRFTokenForForm(),
            'error' => true,
            'tab' => $tab,
            'origins' => $origins,
            'correspondences' => $correspondences,
            'miscPrices' => $ps->getActiveMiscellaneousPrices(),
            'positionsMiscellaneous' => $miscPricePositions,
            'positionsApartment' => $apartmentPricePositions,
            'vats' => $vatSums,
            'brutto' => $brutto,
            'netto' => $netto,
            'apartmentTotal' => $apartmentTotal,
            'miscTotal' => $miscTotal,
        ]);
    }

    /**
     * Edits an existing reservation.
     *
     * @param bool $error
     */
    #[Route('/edit/{id}', name: 'reservations.edit.reservation', methods: ['GET'])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function editReservationAction(ManagerRegistry $doctrine, RequestStack $requestStack, Request $request, $id, $error = false)
    {
        $em = $doctrine->getManager();
        $objects = $em->getRepository(Subsidiary::class)->findAll();
        $reservation = $em->getRepository(Reservation::class)->findById($id)[0];
        $reservationStatus = $em->getRepository(ReservationStatus::class)->findAll();

        // clear session variable
        $requestStack->getSession()->set('reservationInCreation', []);

        $origins = $em->getRepository(ReservationOrigin::class)->findAll();

        return $this->render('Reservations/reservation_form_edit.html.twig', [
            'objects' => $objects,
            'objectSelected' => $request->query->get('object'),
            'reservation' => $reservation,
            'error' => $error,
            'origins' => $origins,
            'reservationStatus' => $reservationStatus,
        ]);
    }

    #[Route(path: '/{id}/edit/remark', name: 'reservations.edit.remark', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function editReservationRemark(ManagerRegistry $doctrine, Request $request, Reservation $reservation): Response
    {
        $form = $this->createForm(ReservationMetaType::class, $reservation);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();

            // add succes message
            $this->addFlash('success', 'reservation.flash.update.success');

            // on success edit
            return $this->forward('App\Controller\ReservationServiceController::getReservationAction', [
                'id' => $reservation->getId(),
                'error' => true,
            ]);
        } else {
            return $this->render('Reservations/reservation_form_edit_remark.html.twig', [
                'reservation' => $reservation,
                'form' => $form->createView(),
            ]);
        }
    }

    /**
     * @return mixed
     */
    #[Route('/edit/{id}', name: 'reservations.edit.reservation.change', methods: ['POST'])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function editChangeReservationAction(ReservationService $rs, Request $request, Reservation $reservation) : Response
    {
        $success = $rs->updateReservation($request, $reservation);
        if (!$success) {
            $this->addFlash('warning', 'reservation.flash.update.conflict');
        } else {
            $this->addFlash('success', 'reservation.flash.update.success');
        }

        return $this->forward('App\Controller\ReservationServiceController::editReservationAction', [
            'id' => $reservation->getId(),
            'error' => true,
        ]);
    }

    #[Route('/edit/{id}/customer', name: 'reservations.edit.reservation.customer', methods: ['GET'])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function editReservationCustomerAction(ManagerRegistry $doctrine, Request $request, $id)
    {
        $em = $doctrine->getManager();
        if ('new' != $id) {
            $reservation = $em->getRepository(Reservation::class)->findById($id)[0];
        } else {
            $reservation = new Reservation();
            $reservation->setId('new');
        }

        return $this->render('Reservations/reservation_form_edit_change_customer.html.twig', [
            'reservation' => $reservation,
            'tab' => $request->query->get('tab', 'booker'), // from which tab this method was called
            'appartmentId' => $request->query->get('appartmentId', 0), // for which appartment we want to change customer (0 = booker of the reservation)
        ]);
    }

    #[Route('/edit/{id}/customer/create', name: 'reservations.edit.reservation.customer.create', methods: ['POST'])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function editReservationCustomerCreateAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, RequestStack $requestStack, ReservationService $rs, CustomerService $cs, Request $request, $id)
    {
        $em = $doctrine->getManager();

        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            $customer = $cs->getCustomerFromForm($request);

            if (0 == strlen($customer->getLastname())) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em->persist($customer);
                $em->flush();
                // add succes message
                $this->addFlash('success', 'customer.flash.create.success');

                $tab = $request->request->get('tab', 'booker');
                if ('new' !== $id) {
                    $reservation = $em->getRepository(Reservation::class)->find($id);
                    $rs->updateReservationCustomers($reservation, $customer, $tab);
                } else {
                    $customersInReservation = $requestStack->getSession()->get('customersInReservation');
                    $customerIsAlreadyInReservation = false;
                    if (!$customerIsAlreadyInReservation) {
                        $customersInReservation[] = ['id' => $customer->getId(), 'appartmentId' => $request->request->get('appartmentId')];
                        $requestStack->getSession()->set('customersInReservation', $customersInReservation);
                    }
                }
            }
        }

        return $this->render('feedback.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/edit/customers/get', name: 'reservations.edit.customers.get', methods: ['POST'])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function editReservationCustomersGetAction(ManagerRegistry $doctrine, Request $request)
    {
        $search = $request->request->get('lastname', '');
        $page = $request->request->get('page', 1);
        $selectAction = $request->request->get('selectAction', 'reservations#editCustomerChangeAction');
        $changeUrl = $request->request->get('changeUrl', null);

        $em = $doctrine->getManager();
        $customers = $em->getRepository(Customer::class)->getCustomersLike('%'.$request->request->get('lastname').'%', $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($customers->count() / $this->perPage);

        return $this->render('Reservations/reservation_form_edit_show_customers.html.twig', [
            'customers' => $customers,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
            'tab' => $request->request->get('tab', 'booker'), // from wihich tab this method was called
            'appartmentId' => $request->request->get('appartmentId', 0), // for which appartment we want to change customer (0 = booker of the reservation)
            'selectAction' => $selectAction,
            'changeUrl' => $changeUrl,
        ]);
    }

    /**
     * @return Response
     */
    #[Route('/edit/{id}/customer/change', name: 'reservations.edit.customer.change', methods: ['POST'])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function editReservationCustomerChangeAction(ManagerRegistry $doctrine, HttpKernelInterface $kernel, RequestStack $requestStack, ReservationService $rs, Request $request, $id)
    {
        $tab = $request->request->get('tab', 'booker');
        $appartmentId = $request->request->get('appartmentId', 0);
        $customerId = $request->request->get('customerId');

        if ('new' != $id) {
            $em = $doctrine->getManager();
            $customer = $em->getRepository(Customer::class)->find($customerId);

            /* @var $reservation Reservation */
            $reservation = $em->getRepository(Reservation::class)->find($id);

            $rs->updateReservationCustomers($reservation, $customer, $tab);

            $forwardController = 'App\Controller\ReservationServiceController::getReservationAction';
            $params = ['id' => $id];
            $query = ['tab' => $tab];

            return $this->forward($forwardController, $params, $query);
        } else {
            $customersInReservation = $requestStack->getSession()->get('customersInReservation');
            $customerIsAlreadyInReservation = false;

            if (null == $customersInReservation) {
                $customersInReservation = [];
            } else {
                foreach ($customersInReservation as $customer) {
                    if ($customer['id'] == $customerId && $customer['appartmentId'] == $appartmentId) {
                        $customerIsAlreadyInReservation = true;
                        break;
                    }
                }
            }

            if (!$customerIsAlreadyInReservation) {
                $customersInReservation[] = ['id' => $customerId, 'appartmentId' => $appartmentId];
                $requestStack->getSession()->set('customersInReservation', $customersInReservation);
            }

            $request2 = $request->duplicate([], []);
            $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::previewNewReservationAction');
            $request2->request->add([
                'tab' => $tab,
            ]);

            return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);
        }
    }

    /**
     * @return string
     */
    #[Route('/reservation/delete', name: 'reservations.dodelete.reservation', methods: ['POST'])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function deleteReservationAction(CSRFProtectionService $csrf, ReservationService $rs, Request $request)
    {
        if ($csrf->validateCSRFToken($request, true)) {
            $delete = $rs->deleteReservation($request->request->get('id'));

            if ($delete) {
                $this->addFlash('success', 'reservation.flash.delete.success');
            } else {
                $this->addFlash('warning', 'reservation.flash.delete.not.possible');
            }
        }

        return new Response('ok');
    }

    /**
     * Deletes Customer that is in the appartment if user clicks the delete icon in tab "Gäste in diesem Zimmer".
     *
     * @return Response
     */
    #[Route('/edit/delete/customer', name: 'reservations.edit.delete.customer', methods: ['POST'])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function deleteReservationCustomerAction(ManagerRegistry $doctrine, HttpKernelInterface $kernel, CSRFProtectionService $csrf, RequestStack $requestStack, Request $request)
    {
        $customerId = $request->request->get('customer-id');
        $reservationId = $request->request->get('reservation-id');
        $tab = $request->request->get('tab', 'booker');

        if ('new' != $reservationId) {
            if ($csrf->validateCSRFToken($request)) {
                $em = $doctrine->getManager();
                $customer = $em->getRepository(Customer::class)->findById($customerId)[0];

                /* @var $reservation Reservation */
                $reservation = $em->getRepository(Reservation::class)->findById($reservationId)[0];
                $reservation->removeCustomer($customer);
                $em->persist($reservation);
                $em->flush();
                $this->addFlash('success', 'reservation.flash.delete.customer.success');
            }

            $forwardController = 'App\Controller\ReservationServiceController::getReservationAction';
            $params = ['id' => $reservationId];
            $query = ['tab' => $tab];

            return $this->forward($forwardController, $params, $query);
        } else {
            $guestsInReservation = $requestStack->getSession()->get('customersInReservation');
            $appartmentId = $request->request->get('appartmentId', 0);

            foreach ($guestsInReservation as $key => $guest) {
                if ($guest['id'] == $customerId && $guest['appartmentId'] == $appartmentId) {
                    unset($guestsInReservation[$key]);
                    $requestStack->getSession()->set('customersInReservation', $guestsInReservation);
                    break;
                }
            }

            $request2 = $request->duplicate([], []);
            $request2->attributes->set('_controller', 'App\Controller\ReservationServiceController::previewNewReservationAction');
            $request2->request->add([
                'tab' => $tab,
            ]);

            return $kernel->handle($request2, HttpKernelInterface::SUB_REQUEST);
        }
    }

    #[Route('/edit/customer/edit', name: 'reservations.edit.customer.edit', methods: ['POST'])]
    public function getEditCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request)
    {
        $em = $doctrine->getManager();
        $customerId = $request->request->get('customer-id');
        $reservationId = $request->request->get('reservation-id');
        $tab = $request->request->get('tab', 'booker');
        $customer = $em->getRepository(Customer::class)->find($customerId);

        if ('new' != $reservationId) {
            $reservation = $em->getRepository(Reservation::class)->findById($reservationId)[0];
        } else {
            $reservation = new Reservation();
            $reservation->setId('new');
        }

        // Get the country names for a locale
        $countries = Countries::getNames($request->getLocale());

        return $this->render('Reservations/reservation_form_edit_customer_edit.html.twig', [
            'customer' => $customer,
            'reservation' => $reservation,
            'token' => $csrf->getCSRFTokenForForm(),
            'countries' => $countries,
            'addresstypes' => CustomerServiceController::$addessTypes,
            'tab' => $tab,
            'cardTypes' => IDCardType::cases(),
        ]);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[Route('/edit/customer/edit/save', name: 'reservations.edit.customer.edit.save', methods: ['POST'])]
    #[IsGranted('ROLE_RESERVATIONS')]
    public function saveEditCustomerAction(ManagerRegistry $doctrine, HttpKernelInterface $kernel, CSRFProtectionService $csrf, CustomerService $cs, Request $request)
    {
        $id = $request->request->get('customer-id');
        $reservationId = $request->request->get('reservation-id');
        $tab = $request->request->get('tab');
        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            /* @var $customer Customer */
            $customer = $cs->getCustomerFromForm($request, $id);

            // check for mandatory fields
            if (0 == strlen($customer->getLastname())) {
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

        if ('new' != $reservationId) {
            $forwardController = 'App\Controller\ReservationServiceController::getReservationAction';
            $params = ['id' => $reservationId];
            $query = ['tab' => $tab];
        } else {
            $forwardController = 'App\Controller\ReservationServiceController::previewNewReservationAction';
            $params = ['tab' => $tab];
            $query = [];
        }

        return $this->forward($forwardController, $params, $query);
    }

    /**
     * Shown in conversations when clicking on next after reservation selection.
     */
    #[Route('/select/template', name: 'reservations.select.template', methods: ['POST'])]
    public function selectTemplateAction(ManagerRegistry $doctrine, RequestStack $requestStack, TemplatesService $ts, ReservationService $rs, Request $request)
    {
        $em = $doctrine->getManager();
        $progress = $request->request->get('inProcess', 'false');
        // if email is inProcess, you can attach other files but no other emails
        if ('true' == $request->request->get('inProcess')) {
            $search = ['TEMPLATE_FILE%', 'TEMPLATE_RESERVATION_PDF'];
            $requestStack->getSession()->set('selectedTemplateId', $request->request->get('templateId'));
            $correspondences = $ts->getCorrespondencesForAttachment();
            $invoices = $rs->getInvoicesForReservationsInProgress();
        } else {
            $search = ['TEMPLATE_RESERVATION_%', 'TEMPLATE_FILE%'];
            // reset do defaults at start of progress
            $requestStack->getSession()->set('selectedTemplateId', null);
            $requestStack->getSession()->set('templateAttachmentIds', []);
            $correspondences = [];
            $invoices = [];
        }

        $templates = $em->getRepository(Template::class)->loadByTypeName($search);

        return $this->render('Reservations/reservation_form_select_template.html.twig', [
            'templates' => $templates,
            'selectedTemplateId' => $request->request->get('templateId'),
            'inProcess' => $progress,
            'correspondences' => $correspondences,
            'invoices' => $invoices,
        ]);
    }

    #[Route('/select/template/{id}', name: 'reservations.select.template.preview', methods: ['POST'])]
    public function previewTemplateAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, RequestStack $requestStack, TemplatesService $ts, Request $request, ReservationService $rs, int $id)
    {
        $em = $doctrine->getManager();
        $inProcess = $request->request->get('inProcess');

        $reservations = $rs->getSelectedReservations();
        $selectedTemplateId = $requestStack->getSession()->get('selectedTemplateId');
        // now we came back from attachment and view previously mail (with new attachment)

        if ('false' == $inProcess && null != $selectedTemplateId) {
            $id = $selectedTemplateId;
        }

        /* @var $template Template */
        $template = $em->getRepository(Template::class)->find($id);
        $templateOutput = $ts->renderTemplate($template->getId(), $reservations, $rs);

        // add attachments
        $attachments = [];
        $attachmentIds = $requestStack->getSession()->get('templateAttachmentIds', []);

        foreach ($attachmentIds as $attId) {
            $aId = array_values($attId)[0]; // we only need the first id, it doesent matter how many reseervations are selected, in this view only one file is needed
            $attachments[] = $em->getRepository(Correspondence::class)->find($aId);
        }

        return $this->render('Reservations/reservation_form_preview_template.html.twig', [
            'templateOutput' => $templateOutput,
            'template' => $template,
            'reservations' => $reservations,
            'token' => $csrf->getCSRFTokenForForm(),
            'inProcess' => $inProcess,
            'attachmentIds' => $requestStack->getSession()->get('templateAttachmentIds'),
            'attachments' => $attachments,
        ]);
    }

    #[Route(path: '/{reservationId}/edit/prices/{id}/update', name: 'reservations.update.misc.price', methods: ['POST'])]
    public function updateMiscPriceForReservation($reservationId, Price $price, ManagerRegistry $doctrine, ReservationService $rs, RequestStack $requestStack, Request $request): Response
    {
        if ($this->isCsrfTokenValid('reservation-update-misc-price', $request->request->get('_token'))) {
            // during reservation create process
            if ('new' === $reservationId) {
                $rs->toggleInCreationPrice($price, $requestStack);
            } else { // during reservation edit process
                $em = $doctrine->getManager();
                /* @var $reservation Reservation */
                $reservation = $em->getRepository(Reservation::class)->find($reservationId);
                $prices = $reservation->getPrices();

                if ($prices->contains($price)) {
                    $reservation->removePrice($price);
                } else {
                    $reservation->addPrice($price);
                }

                $em->persist($reservation);
                $em->flush();
            }
        }

        return new Response('', Response::HTTP_OK);
    }

    #[Route('/get/reservations/in/period', name: 'reservations.get.reservations.in.period', methods: ['POST'])]
    public function getReservationsInPeriodAction(ManagerRegistry $doctrine, ReservationService $reservationService, Request $request)
    {
        $em = $doctrine->getManager();
        $reservations = [];

        $potentialReservations = $em->getRepository(
            Reservation::class
        )->loadReservationsForPeriod($request->request->get('from'), $request->request->get('end'));

        foreach ($potentialReservations as $reservation) {
            // make sure that already selected reservation can not be choosen twice
            if (!$reservationService->isReservationAlreadySelected($reservation->getId())) {
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

    #[Route('/get/reservations/for/customer', name: 'reservations.get.reservations.for.customer', methods: ['POST'])]
    public function getReservationsForCustomerAction(ManagerRegistry $doctrine, ReservationService $reservationService, Request $request)
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

            foreach ($potentialReservations as $reservation) {
                if (!$reservationService->isReservationAlreadySelected($reservation->getId())) {
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

    private function createTimeFromRequestValue(?string $value): ?\DateTime
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        $time = \DateTime::createFromFormat('H:i', $value);

        return false === $time ? null : $time;
    }
}
