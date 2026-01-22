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
use Doctrine\ORM\EntityManagerInterface;
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
#[IsGranted('ROLE_HOUSEKEEPING')]
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
        HousekeepingViewService $viewService
    ): Response {
        $em = $doctrine->getManager();
        $subsidiaries = $em->getRepository(Subsidiary::class)->findAll();
        $subsidiaryId = (string) $request->query->get('subsidiary', 'all');
        $selectedSubsidiary = $this->resolveSubsidiary($em, $subsidiaryId);
        $selectedDate = $this->resolveDate($request->query->get('date'));
        $view = $request->query->get('view', 'day');

        $dayView = null;
        $weekView = null;
        if ('week' === $view) {
            $weekStart = $this->resolveWeekStart($selectedDate);
            $weekEnd = $weekStart->modify('+6 days');
            $weekView = $viewService->buildWeekView($weekStart, $weekEnd, $selectedSubsidiary);
        } else {
            $view = 'day';
            $dayView = $viewService->buildDayView($selectedDate, $selectedSubsidiary);
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

        return $this->render('Operations/Housekeeping/index.html.twig', [
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
        ]);
    }

    /**
     * Persist changes to the housekeeping status for a room and date.
     */
    #[Route('/update/{id}', name: 'operations.housekeeping.update', methods: ['POST'])]
    public function updateAction(
        ManagerRegistry $doctrine,
        Request $request,
        Appartment $apartment
    ): Response {
        $em = $doctrine->getManager();
        $formPayload = $request->request->all('housekeeping_row');
        $dateValue = is_array($formPayload) ? (string) ($formPayload['date'] ?? '') : '';
        $date = $this->resolveDate($dateValue);

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
        HousekeepingExportService $exportService
    ): Response {
        $em = $doctrine->getManager();
        $subsidiaryId = (string) $request->query->get('subsidiary', 'all');
        $subsidiary = $this->resolveSubsidiary($em, $subsidiaryId);
        $selectedDate = $this->resolveDate($request->query->get('date'));
        $range = (string) $request->query->get('range', 'day');
        $locale = $request->getLocale();

        if ('week' === $range) {
            $weekStart = $this->resolveWeekStart($selectedDate);
            $weekEnd = $weekStart->modify('+6 days');
            $weekView = $viewService->buildWeekView($weekStart, $weekEnd, $subsidiary);

            return $exportService->buildWeekCsvResponse($weekView, $subsidiaryId, $locale);
        }

        $dayView = $viewService->buildDayView($selectedDate, $subsidiary);

        return $exportService->buildDayCsvResponse($dayView, $subsidiaryId, $locale);
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

        $subsidiary = $em->getRepository(Subsidiary::class)->find($subsidiaryId);

        return $subsidiary instanceof Subsidiary ? $subsidiary : null;
    }

    /**
     * Normalize a date into its Monday week start.
     */
    private function resolveWeekStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify('monday this week')->setTime(0, 0, 0);
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
