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
use App\Entity\Enum\IDCardType;
use App\Entity\RegistrationBookEntry;
use App\Entity\Reservation;
use App\Service\CSRFProtectionService;
use App\Service\CustomerService;
use App\Service\RegistrationBookService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[Route('/registrationbook')]
class RegistrationBookServiceController extends AbstractController
{
    private $perPage = 20;

    #[Route('/', name: 'registrationbook.overview', methods: ['GET'])]
    public function indexAction(ManagerRegistry $doctrine, RequestStack $requestStack, Request $request)
    {
        $em = $doctrine->getManager();
        $search = $request->query->get('search', '');
        $page = $request->query->get('page', 1);

        $entries = $em->getRepository(RegistrationBookEntry::class)->findByFilter($search, $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($entries->count() / $this->perPage);

        // unset session variables that are usesd in modals on this page
        $requestStack->getSession()->remove('registrationbook.start');
        $requestStack->getSession()->remove('registrationbook.end');

        return $this->render('RegistrationBook/index.html.twig', [
            'bookEntries' => $entries,
            'search' => $search,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    #[Route('/search', name: 'registrationbook.search', methods: ['POST'])]
    public function searchAction(ManagerRegistry $doctrine, Request $request)
    {
        $em = $doctrine->getManager();
        $search = $request->request->get('search', '');
        $page = $request->request->get('page', 1);
        $entries = $em->getRepository(RegistrationBookEntry::class)->findByFilter($search, $page, $this->perPage);

        // calculate the number of pages for pagination
        $pages = ceil($entries->count() / $this->perPage);

        return $this->render('RegistrationBook/registrationbook_table.html.twig', [
            'bookEntries' => $entries,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
        ]);
    }

    /**
     * Loads the first modal page to add registration book entries and lists all reservations that are not in the book already.
     *
     * @return type
     */
    #[Route('/showadd/reservations', name: 'registrationbook.showadd.reservations', methods: ['GET'])]
    public function showAddReservationsAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, RequestStack $requestStack, Request $request)
    {
        $em = $doctrine->getManager();

        $start = $request->query->get('start', '');
        if ('' !== $start) {
            try {
                $startDate = new \DateTime($start);
            } catch (Exception $ex) {
                $startDate = new \DateTime();
                $startDate->sub(new \DateInterval('P30D'));
            }
        } elseif ($requestStack->getSession()->has('registrationbook.start')) {
            $startDate = unserialize($requestStack->getSession()->get('registrationbook.start'));
        } else {
            $startDate = new \DateTime();
            $startDate->sub(new \DateInterval('P30D'));
        }

        $end = $request->query->get('end', '');
        if ('' !== $end) {
            try {
                $endDate = new \DateTime($end);
            } catch (\Exception $ex) {
                $endDate = new \DateTime();
            }
        } elseif ($requestStack->getSession()->has('registrationbook.end')) {
            $endDate = unserialize($requestStack->getSession()->get('registrationbook.end'));
        } else {
            $endDate = new \DateTime();
        }

        // if start is grater end -> change start and end
        if ($startDate > $endDate) {
            $tmpDate = $startDate;
            $startDate = $endDate;
            $endDate = $tmpDate;
        }
        $requestStack->getSession()->set('registrationbook.start', serialize($startDate));
        $requestStack->getSession()->set('registrationbook.end', serialize($endDate));

        $reservations = $em->getRepository(RegistrationBookEntry::class)->getReservationsNotInBook($startDate, $endDate);

        return $this->render('RegistrationBook/registrationbook_form_showadd.html.twig', [
            'reservations' => $reservations,
            'token' => $csrf->getCSRFTokenForForm(),
            'error' => true,
            'start' => $startDate,
            'end' => $endDate,
        ]);
    }

    #[Route('/add/registration', name: 'registrationbook.add.registration', methods: ['POST'])]
    public function addRegistrationAction(CSRFProtectionService $csrf, RegistrationBookService $rbs, Request $request)
    {
        if ($csrf->validateCSRFToken($request)) {
            $id = $request->request->get('id');
            $result = $rbs->addBookEntriesFromReservation($id);

            $this->addFlash('success', 'reservationbook.flash.add.success');
        }

        return $this->forward('App\Controller\RegistrationBookServiceController::showAddReservationsAction');
    }

    #[Route('/add/delete/customer', name: 'registrationbook.add.delete.customer', methods: ['POST'])]
    public function deleteRegistrationBookCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request)
    {
        $customerId = $request->request->get('customer-id');
        $reservationId = $request->request->get('reservation-id');

        if ($csrf->validateCSRFToken($request)) {
            $em = $doctrine->getManager();
            $customer = $em->getRepository(Customer::class)->findById($customerId)[0];

            /* @var $reservation \Pensionsverwaltung\Database\Entity\Reservation */
            $reservation = $em->getRepository(Reservation::class)->findById($reservationId)[0];
            $reservation->removeCustomer($customer);
            $em->persist($reservation);
            $em->flush();
            $this->addFlash('success', 'reservation.flash.delete.customer.success');
        }

        return $this->forward('App\Controller\RegistrationBookServiceController::showAddReservationsAction');
    }

    #[Route('/add/add/customer', name: 'registrationbook.add.add.customer', methods: ['POST'])]
    public function showAddReservationCustomerAction(ManagerRegistry $doctrine, Request $request)
    {
        $em = $doctrine->getManager();
        $id = $request->request->get('id');
        $reservation = $em->getRepository(Reservation::class)->find($id);

        return $this->render('RegistrationBook/registrationbook_form_add_change_customer.html.twig', [
            'reservation' => $reservation,
        ]);
    }

    #[Route('/add/edit/customer', name: 'registrationbook.add.edit.customer', methods: ['POST'])]
    public function getEditCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, Request $request)
    {
        $em = $doctrine->getManager();
        $id = $request->request->get('id');
        $customer = $em->getRepository(Customer::class)->find($id);

        // Get the country names for a locale
        $countries = Countries::getNames($request->getLocale());

        return $this->render('RegistrationBook/registrationbook_form_edit_customer.html.twig', [
            'customer' => $customer,
            'token' => $csrf->getCSRFTokenForForm(),
            'countries' => $countries,
            'addresstypes' => CustomerServiceController::$addessTypes,
            'cardTypes' => IDCardType::cases(),
        ]);
    }

    #[Route('/add/edit/customer/save', name: 'registrationbook.add.edit.customer.save', methods: ['POST'])]
    public function saveEditCustomerAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, CustomerService $cs, Request $request)
    {
        $id = $request->request->get('customer-id');
        $error = false;
        if ($csrf->validateCSRFToken($request)) {
            /* @var $customer \Pensionsverwaltung\Database\Entity\Customer */
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

        return $this->forward('App\Controller\RegistrationBookServiceController::showAddReservationsAction');
    }

    /**
     * delete entity.
     *
     * @return string
     */
    #[Route('/{id}/delete', name: 'registrationbook.delete.origin', methods: ['GET', 'POST'])]
    public function deleteAction(CSRFProtectionService $csrf, AuthorizationCheckerInterface $authChecker, RegistrationBookService $rbs, Request $request, $id)
    {
        if ($authChecker->isGranted('ROLE_ADMIN')) {
            if ('POST' == $request->getMethod()) {
                if ($csrf->validateCSRFToken($request, true)) {
                    $origin = $rbs->deleteEntry($id);
                    if ($origin) {
                        $this->addFlash('success', 'registrationbook.flash.delete.success');
                    }
                }

                return new Response('ok');
            } else {
                // initial get load (ask for deleting)
                return $this->render('common/form_delete_entry.html.twig', [
                    'id' => $id,
                    'token' => $csrf->getCSRFTokenForForm(),
                ]);
            }
        }
    }
}
