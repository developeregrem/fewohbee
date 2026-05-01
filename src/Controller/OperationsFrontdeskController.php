<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Subsidiary;
use App\Service\FrontdeskViewService;
use App\Service\HousekeepingViewService;
use App\Service\OperationsFilterService;
use App\Service\ReservationService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Frontdesk checklist view for arrivals, departures and inhouse guests.
 */
#[IsGranted('ROLE_OPERATIONS')]
#[Route('/operations/frontdesk')]
class OperationsFrontdeskController extends AbstractController
{
    /**
     * Show the frontdesk overview.
     */
    #[Route('', name: 'operations.frontdesk', methods: ['GET'])]
    public function indexAction(
        ManagerRegistry $doctrine,
        Request $request,
        HousekeepingViewService $viewService,
        OperationsFilterService $filterService,
        FrontdeskViewService $frontdeskViewService
    ): Response {
        $session = $request->getSession();
        $em = $doctrine->getManager();
        $subsidiaries = $em->getRepository(Subsidiary::class)->findAll();
        $reservationStatuses = $em->getRepository(ReservationStatus::class)->findAll();
        $subsidiaryId = $filterService->resolveFilterValue(
            $request,
            $session,
            'frontdesk.subsidiary',
            'subsidiary',
            'all'
        );
        $selectedSubsidiary = $filterService->resolveSubsidiary($em, $subsidiaryId);
        $selectedDate = $filterService->resolveDate(
            $filterService->resolveFilterValue($request, $session, 'frontdesk.date', 'date')
        );
        $selectedCategories = $filterService->normalizeCategories(
            $filterService->resolveFilterArray($request, $session, 'frontdesk.categories', 'categories')
        );
        $includeCanceled = $filterService->resolveFilterBool(
            $request,
            $session,
            'frontdesk.includeCanceled',
            'includeCanceled',
            true
        );
        $statusMode = $includeCanceled ? 'all' : 'blocking';

        $rangeView = $viewService->buildRangeView(
            $selectedDate,
            $selectedDate,
            $selectedSubsidiary,
            $viewService->getAllowedOccupancyTypes(),
            $statusMode
        );
        $dayKey = $selectedDate->format('Y-m-d');
        $dayView = $rangeView['dayViews'][$dayKey] ?? [
            'date' => $selectedDate,
            'rows' => [],
            'apartments' => $rangeView['apartments'] ?? [],
        ];

        $items = $frontdeskViewService->buildItems($dayView['rows'] ?? [], $selectedDate, $selectedCategories);

        $viewData = [
            'subsidiaries' => $subsidiaries,
            'selectedSubsidiaryId' => $subsidiaryId,
            'selectedDate' => $selectedDate,
            'selectedCategories' => $selectedCategories,
            'includeCanceled' => $includeCanceled,
            'frontdeskItems' => $items,
            'reservationStatuses' => $reservationStatuses,
        ];

        // AJAX request: return only content partial
        if ($request->isXmlHttpRequest()) {
            return $this->render('Operations/Frontdesk/_content.html.twig', $viewData);
        }

        return $this->render('Operations/Frontdesk/index.html.twig', $viewData);
    }

    /**
     * Update reservation status from the frontdesk view.
     */
    #[Route('/reservation/{id}/status', name: 'operations.frontdesk.reservation.status', methods: ['POST'])]
    public function updateReservationStatusAction(
        ManagerRegistry $doctrine,
        CsrfTokenManagerInterface $csrfTokenManager,
        ReservationService $reservationService,
        Request $request,
        Reservation $reservation
    ): Response {
        $token = new CsrfToken('reservation-status-update', (string) $request->request->get('_token'));
        if (!$csrfTokenManager->isTokenValid($token)) {
            return new Response('invalid token', 400);
        }

        $statusId = (int) $request->request->get('status');
        $status = $doctrine->getManager()->getRepository(ReservationStatus::class)->find($statusId);
        if ($status instanceof ReservationStatus) {
            $reservationService->changeStatus($reservation, $status);
        }

        return new Response('ok');
    }

    /**
     * Quick-select a reservation and open the template selection directly.
     */
    #[Route('/select/template/quick', name: 'operations.frontdesk.template.quick', methods: ['POST'])]
    public function selectTemplateQuickAction(ReservationService $reservationService, Request $request): Response
    {
        $reservationService->resetSelectedReservations();
        $reservationId = $request->query->getInt('reservationId', 0);
        if ($reservationId > 0) {
            $reservationService->addReservationToSelection($reservationId);
        }

        if (!$request->request->has('inProcess')) {
            $request->request->set('inProcess', 'false');
        }

        return $this->forward('App\\Controller\\ReservationServiceController::selectTemplateAction', [], $request->request->all());
    }

}
