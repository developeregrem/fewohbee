<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Subsidiary;
use App\Entity\Template;
use App\Service\FrontdeskViewService;
use App\Service\HousekeepingViewService;
use App\Service\OperationsFilterService;
use App\Service\ReservationService;
use App\Service\TemplatesService;
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
#[IsGranted('ROLE_HOUSEKEEPING')]
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

        return $this->render('Operations/Frontdesk/index.html.twig', [
            'subsidiaries' => $subsidiaries,
            'selectedSubsidiaryId' => $subsidiaryId,
            'selectedDate' => $selectedDate,
            'selectedCategories' => $selectedCategories,
            'includeCanceled' => $includeCanceled,
            'frontdeskItems' => $items,
            'reservationStatuses' => $reservationStatuses,
        ]);
    }

    /**
     * Download the default registration template for a reservation.
     */
    #[Route('/registration/download', name: 'operations.frontdesk.registration.download', methods: ['GET'])]
    public function downloadRegistrationTemplateAction(
        ManagerRegistry $doctrine,
        TemplatesService $templatesService,
        Request $request
    ): Response {
        $em = $doctrine->getManager();
        $reservationId = $request->query->getInt('reservationId', 0);
        $reservation = $reservationId > 0 ? $em->getRepository(Reservation::class)->find($reservationId) : null;

        if (!$reservation instanceof Reservation) {
            $this->addFlash('warning', 'templates.notfound');

            return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('operations.frontdesk'));
        }

        $templates = $em->getRepository(Template::class)->loadByTypeName(['TEMPLATE_REGISTRATION_PDF']);
        $template = $templatesService->getDefaultTemplate($templates);

        if (!$template instanceof Template) {
            $this->addFlash('warning', 'operations.frontdesk.registration.missing');

            return $this->redirect($this->generateUrl('operations.frontdesk'));
        }

        $templateOutput = $templatesService->renderTemplate(
            $template->getId(),
            [$reservation]
        );
        $pdfOutput = $templatesService->getPDFOutput(
            $templateOutput,
            'Registration-'.$reservation->getId(),
            $template,
            false,
            'I'
        );

        $response = new Response($pdfOutput);
        $response->headers->set('Content-Type', 'application/pdf');
        //$response->headers->set('Content-Disposition', 'attachment; filename="Registration-'.$reservation->getId().'.pdf"');

        return $response;
    }

    /**
     * Update reservation status from the frontdesk view.
     */
    #[Route('/reservation/{id}/status', name: 'operations.frontdesk.reservation.status', methods: ['POST'])]
    public function updateReservationStatusAction(
        ManagerRegistry $doctrine,
        CsrfTokenManagerInterface $csrfTokenManager,
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
            $reservation->setReservationStatus($status);
            $doctrine->getManager()->flush();
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
