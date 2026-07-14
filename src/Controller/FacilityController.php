<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CalendarEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Facility overview: confirmed reminders (e.g. "bin put out") across all
 * calendars that require confirmation, together with who confirmed them.
 */
#[IsGranted('ROLE_OPERATIONS')]
#[Route('/operations/facility')]
class FacilityController extends AbstractController
{
    #[Route('', name: 'operations.facility', methods: ['GET'])]
    public function index(CalendarEntryRepository $calendarEntryRepository, Request $request): Response
    {
        $availableYears = $calendarEntryRepository->findDistinctConfirmedYears();
        $currentYear = (int) date('Y');

        // always offer the current year even if nothing's been confirmed yet,
        // so the dropdown isn't empty on a freshly configured calendar
        if (!\in_array($currentYear, $availableYears, true)) {
            array_unshift($availableYears, $currentYear);
        }

        $year = (int) $request->query->get('year', (string) $currentYear);

        return $this->render('Operations/Facility/index.html.twig', [
            'year' => $year,
            'availableYears' => $availableYears,
            'entries' => $calendarEntryRepository->findConfirmedForYear($year),
        ]);
    }
}
