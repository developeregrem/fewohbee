<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\Subsidiary;
use App\Entity\Template;
use App\Enum\InvoiceStatus;
use App\Service\HousekeepingViewService;
use App\Service\ReservationService;
use App\Service\TemplatesService;
use Doctrine\ORM\EntityManagerInterface;
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
        HousekeepingViewService $viewService
    ): Response {
        $session = $request->getSession();
        $em = $doctrine->getManager();
        $subsidiaries = $em->getRepository(Subsidiary::class)->findAll();
        $reservationStatuses = $em->getRepository(ReservationStatus::class)->findAll();
        $subsidiaryId = $this->resolveFilterValue(
            $request,
            $session,
            'frontdesk.subsidiary',
            'subsidiary',
            'all'
        );
        $selectedSubsidiary = $this->resolveSubsidiary($em, $subsidiaryId);
        $selectedDate = $this->resolveDate(
            $this->resolveFilterValue($request, $session, 'frontdesk.date', 'date')
        );
        $selectedCategories = $this->normalizeCategories(
            $this->resolveFilterArray($request, $session, 'frontdesk.categories', 'categories')
        );
        $includeCanceled = $this->resolveFilterBool(
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

        $items = $this->buildFrontdeskItems($dayView['rows'] ?? [], $selectedDate, $selectedCategories);

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
        ReservationService $reservationService,
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
            [$reservation],
            $reservationService
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

    /**
     * Resolve the selected date from query input, defaulting to today (UTC).
     */
    private function resolveDate(?string $dateParam): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('UTC');
        if ($dateParam) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $dateParam, $timezone);
            if ($parsed instanceof \DateTimeImmutable) {
                return $parsed->setTime(0, 0, 0);
            }
        }

        return (new \DateTimeImmutable('today', $timezone))->setTime(0, 0, 0);
    }

    /**
     * Resolve the requested subsidiary entity, if any.
     */
    private function resolveSubsidiary(EntityManagerInterface $em, string $subsidiaryId): ?Subsidiary
    {
        if ('all' === $subsidiaryId || '' === $subsidiaryId) {
            return null;
        }

        return $em->getRepository(Subsidiary::class)->find($subsidiaryId);
    }

    /**
     * Normalize selected categories (arrival, departure, inhouse).
     *
     * @return string[]
     */
    private function normalizeCategories(array $selected): array
    {
        $allowed = ['arrival', 'departure', 'inhouse'];
        $values = array_values(array_intersect($allowed, array_map('strval', $selected)));

        return [] === $values ? $allowed : $values;
    }

    private function resolveFilterValue(Request $request, $session, string $sessionKey, string $queryKey, string $default = ''): string
    {
        if ($request->query->has($queryKey)) {
            $value = (string) $request->query->get($queryKey, $default);
            $session->set($sessionKey, $value);

            return $value;
        }

        return (string) $session->get($sessionKey, $default);
    }

    private function resolveFilterArray(Request $request, $session, string $sessionKey, string $queryKey): array
    {
        if ($request->query->has($queryKey)) {
            $value = $request->query->all($queryKey);
            $session->set($sessionKey, $value);

            return is_array($value) ? $value : [];
        }

        $stored = $session->get($sessionKey, []);

        return is_array($stored) ? $stored : [];
    }

    private function resolveFilterBool(Request $request, $session, string $sessionKey, string $queryKey, bool $default): bool
    {
        if ($request->query->has($queryKey)) {
            $value = $request->query->getBoolean($queryKey, $default);
            $session->set($sessionKey, $value);

            return $value;
        }

        return (bool) $session->get($sessionKey, $default);
    }

    /**
     * Build list items for the selected day and filters.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildFrontdeskItems(array $rows, \DateTimeImmutable $date, array $selectedCategories): array
    {
        $dateKey = $date->format('Y-m-d');
        $items = [];
        $seen = [];

        foreach ($rows as $row) {
            $reservations = $row['apartmentReservations'] ?? [];
            foreach ($reservations as $reservation) {
                $reservationId = $reservation->getId();
                if (isset($seen[$reservationId])) {
                    continue;
                }

                $startKey = $reservation->getStartDate()->format('Y-m-d');
                $endKey = $reservation->getEndDate()->format('Y-m-d');
                $categories = [];
                if ($startKey === $dateKey) {
                    $categories[] = 'arrival';
                }
                if ($endKey === $dateKey) {
                    $categories[] = 'departure';
                }
                if ($startKey < $dateKey && $endKey > $dateKey) {
                    $categories[] = 'inhouse';
                }

                if (empty($categories)) {
                    continue;
                }

                if (count(array_intersect($categories, $selectedCategories)) === 0) {
                    continue;
                }

                $invoiceStatusLabel = null;
                $firstInvoice = $reservation->getInvoices()->first();
                if ($firstInvoice) {
                    $statusEnum = InvoiceStatus::fromStatus($firstInvoice->getStatus());
                    $invoiceStatusLabel = $statusEnum?->labelKey();
                }

                $items[] = [
                    'reservation' => $reservation,
                    'apartment' => $row['apartment'],
                    'categories' => $categories,
                    'invoiceStatusLabel' => $invoiceStatusLabel,
                ];
                $seen[$reservationId] = true;
            }
        }

        return $items;
    }
}
