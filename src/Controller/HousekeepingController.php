<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Appartment;
use App\Entity\RoomDayStatus;
use App\Entity\Subsidiary;
use App\Entity\User;
use App\Form\HousekeepingRowType;
use App\Service\HousekeepingExportService;
use App\Service\HousekeepingViewService;
use App\Service\OperationsFilterService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Handles the housekeeping operations view and updates.
 */
#[IsGranted('ROLE_OPERATIONS')]
#[Route('/operations/housekeeping')]
class HousekeepingController extends AbstractController
{
    /**
     * Display the housekeeping day or week overview.
     */
    #[Route('', name: 'operations.housekeeping', methods: ['GET'])]
    public function indexAction(
        ManagerRegistry $doctrine,
        Request $request,
        HousekeepingViewService $viewService,
        OperationsFilterService $filterService
    ): Response {
        $em = $doctrine->getManager();
        $subsidiaries = $em->getRepository(Subsidiary::class)->findAll();
        $subsidiaryId = (string) $request->query->get('subsidiary', 'all');
        $selectedSubsidiary = $filterService->resolveSubsidiary($em, $subsidiaryId);
        $selectedDate = $filterService->resolveDate($request->query->get('date'));
        $view = $request->query->get('view', 'day');
        $queryParams = $request->query->all();
        $selectedOccupancyTypes = $viewService->normalizeOccupancyTypes($queryParams['occupancyTypes'] ?? null);

        $dayView = null;
        $weekView = null;
        if ('week' === $view) {
            $weekStart = $filterService->resolveWeekStart($selectedDate);
            $weekEnd = $weekStart->modify('+6 days');
            $weekView = $viewService->buildWeekView($weekStart, $weekEnd, $selectedSubsidiary);
            $weekView = $viewService->filterWeekViewByOccupancy($weekView, $selectedOccupancyTypes);
        } else {
            $view = 'day';
            $dayView = $viewService->buildDayView($selectedDate, $selectedSubsidiary);
            $dayView = $viewService->filterDayViewByOccupancy($dayView, $selectedOccupancyTypes);
        }

        $rowForms = [];
        $rowFormsMobile = [];
        if ('day' === $view && $dayView) {
            foreach ($dayView['rows'] as $row) {
                $status = $row['status'] ?? new RoomDayStatus();
                $form = $this->createForm(HousekeepingRowType::class, $status, [
                    'date' => $selectedDate->format('Y-m-d'),
                ]);
                $mobileForm = $this->createForm(HousekeepingRowType::class, $status, [
                    'date' => $selectedDate->format('Y-m-d'),
                ]);
                $rowForms[$row['apartment']->getId()] = $form->createView();
                $rowFormsMobile[$row['apartment']->getId()] = $mobileForm->createView();
            }
        }

        $viewData = [
            'subsidiaries' => $subsidiaries,
            'selectedSubsidiaryId' => $subsidiaryId,
            'selectedDate' => $selectedDate,
            'view' => $view,
            'dayView' => $dayView,
            'weekView' => $weekView,
            'rowForms' => $rowForms,
            'rowFormsMobile' => $rowFormsMobile,
            'statusLabels' => $viewService->getStatusLabels(),
            'occupancyLabels' => $viewService->getOccupancyLabels(),
            'occupancyClasses' => $this->getOccupancyClasses(),
            'occupancyTypes' => $viewService->getAllowedOccupancyTypes(),
            'selectedOccupancyTypes' => $selectedOccupancyTypes,
        ];

        // AJAX request: return only content partial
        if ($request->isXmlHttpRequest()) {
            return $this->render('Operations/Housekeeping/_content.html.twig', $viewData);
        }

        return $this->render('Operations/Housekeeping/index.html.twig', $viewData);
    }

    /**
     * Persist changes to the housekeeping status for a room and date.
     */
    #[Route('/update/{id}', name: 'operations.housekeeping.update', methods: ['POST'])]
    public function updateAction(
        ManagerRegistry $doctrine,
        Request $request,
        Appartment $apartment,
        OperationsFilterService $filterService
    ): Response {
        $em = $doctrine->getManager();
        $formPayload = $request->request->all('housekeeping_row');
        $dateValue = is_array($formPayload) ? (string) ($formPayload['date'] ?? '') : '';
        $date = $filterService->resolveDate($dateValue);

        $status = $em->getRepository(RoomDayStatus::class)->findOneBy([
            'appartment' => $apartment,
            'date' => $date,
        ]) ?? new RoomDayStatus();

        $status->setAppartment($apartment);
        $status->setDate($date);

        $form = $this->createForm(HousekeepingRowType::class, $status, [
            'date' => $date->format('Y-m-d'),
        ]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($form->has('_token') && $form->get('_token')->getErrors()->count() > 0) {
                $this->addFlash('warning', 'flash.invalidtoken');
            }

            return new JsonResponse([
                'ok' => false,
                'message' => 'flash.invalidtoken',
            ], Response::HTTP_BAD_REQUEST);
        }

        $status->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $status->setUpdatedBy($this->getUser() instanceof User ? $this->getUser() : null);

        $em->persist($status);
        $em->flush();

        return new JsonResponse([
            'ok' => true,
            'hkStatus' => $status->getHkStatus()->value,
        ]);
    }

    /**
     * Stream a CSV export for the selected day or week.
     */
    #[Route('/export', name: 'operations.housekeeping.export', methods: ['GET'])]
    public function exportAction(
        ManagerRegistry $doctrine,
        Request $request,
        HousekeepingViewService $viewService,
        HousekeepingExportService $exportService,
        OperationsFilterService $filterService
    ): Response {
        $em = $doctrine->getManager();
        $subsidiaryId = (string) $request->query->get('subsidiary', 'all');
        $subsidiary = $filterService->resolveSubsidiary($em, $subsidiaryId);
        $selectedDate = $filterService->resolveDate($request->query->get('date'));
        $range = (string) $request->query->get('range', 'day');
        $queryParams = $request->query->all();
        $selectedOccupancyTypes = $viewService->normalizeOccupancyTypes($queryParams['occupancyTypes'] ?? null);
        $locale = $request->getLocale();

        if ('week' === $range) {
            $weekStart = $filterService->resolveWeekStart($selectedDate);
            $weekEnd = $weekStart->modify('+6 days');
            $weekView = $viewService->buildWeekView($weekStart, $weekEnd, $subsidiary);
            $weekView = $viewService->filterWeekViewByOccupancy($weekView, $selectedOccupancyTypes);

            return $exportService->buildWeekCsvResponse($weekView, $subsidiaryId, $locale);
        }

        $dayView = $viewService->buildDayView($selectedDate, $subsidiary);
        $dayView = $viewService->filterDayViewByOccupancy($dayView, $selectedOccupancyTypes);

        return $exportService->buildDayCsvResponse($dayView, $subsidiaryId, $locale);
    }

    /**
     * Define CSS classes for occupancy badges.
     */
    private function getOccupancyClasses(): array
    {
        return [
            'FREE' => 'bg-secondary',
            'STAYOVER' => 'bg-info',
            'ARRIVAL' => 'bg-success',
            'DEPARTURE' => 'bg-warning text-dark',
            'TURNOVER' => 'bg-danger',
        ];
    }
}
