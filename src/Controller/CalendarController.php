<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Calendar;
use App\Form\CalendarType;
use App\Repository\CalendarEntryRepository;
use App\Repository\CalendarRepository;
use App\Service\CalendarEntrySyncService;
use App\Service\Exception\CalendarSyncException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Manages the configured calendars (waste collection, vacations, events...)
 * that CalendarEntry rows are imported into or manually added to.
 * Generalizes what used to be the "Abfallkalender" card in AppSettings.
 */
#[Route('/settings/calendars')]
class CalendarController extends AbstractController
{
    #[Route('', name: 'settings.calendars.index', methods: ['GET'])]
    public function index(CalendarRepository $calendarRepo, CalendarEntryRepository $entryRepo, Request $request): Response
    {
        $calendars = $calendarRepo->findAllOrdered();
        $entryCounts = [];
        foreach ($calendars as $calendar) {
            $entryCounts[$calendar->getId()] = $entryRepo->count(['calendar' => $calendar]);
        }

        $currentYear = (int) date('Y');
        $availableCleanupYears = $entryRepo->findDistinctYears();
        // the cleanup is only ever offered for years that are over, so
        // don't bother suggesting the current one even if there's data in it
        $availableCleanupYears = array_values(array_filter(
            $availableCleanupYears,
            static fn (int $y) => $y < $currentYear,
        ));
        $cleanupYear = (int) $request->query->get('cleanupYear', (string) ($availableCleanupYears[0] ?? $currentYear - 1));

        return $this->render('Calendar/index.html.twig', [
            'calendars' => $calendars,
            'entryCounts' => $entryCounts,
            'availableCleanupYears' => $availableCleanupYears,
            'cleanupYear' => $cleanupYear,
            'unconfirmedCleanupCount' => [] !== $availableCleanupYears ? $entryRepo->countUnconfirmedForYear($cleanupYear) : 0,
        ]);
    }

    #[Route('/new', name: 'settings.calendars.new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        CalendarEntrySyncService $syncService,
        TranslatorInterface $translator,
    ): Response {
        $calendar = new Calendar();

        return $this->handleForm($request, $em, $syncService, $translator, $calendar, true);
    }

    #[Route('/{id}/edit', name: 'settings.calendars.edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        EntityManagerInterface $em,
        CalendarEntrySyncService $syncService,
        TranslatorInterface $translator,
        Calendar $calendar,
    ): Response {
        return $this->handleForm($request, $em, $syncService, $translator, $calendar, false);
    }

    private function handleForm(
        Request $request,
        EntityManagerInterface $em,
        CalendarEntrySyncService $syncService,
        TranslatorInterface $translator,
        Calendar $calendar,
        bool $isNew,
    ): Response {
        $form = $this->createForm(CalendarType::class, $calendar);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($calendar);
            $em->flush();

            /** @var ?UploadedFile $icsFile */
            $icsFile = $form->get('icsFile')->getData();

            $syncFailed = false;
            try {
                // an uploaded file is a one-off bulk import, independent of
                // whatever URL is (or isn't) configured for ongoing sync -
                // the file itself is never stored, only the entries it produces
                $count = null !== $icsFile
                    ? $syncService->importIcsString($calendar, (string) file_get_contents($icsFile->getPathname()))
                    : $syncService->sync($calendar);

                if (null !== $count) {
                    $calendar->setLastSyncedAt(new \DateTime());
                    $calendar->setLastSyncCount($count);
                    $em->flush();

                    if (0 === $count) {
                        $this->addFlash('warning', 'calendar.flash.synced_empty');
                    } else {
                        $this->addFlash('success', 'calendar.flash.synced');
                    }
                }
            } catch (CalendarSyncException $e) {
                $syncFailed = true;
                $this->addFlash('danger', $translator->trans('calendar.flash.sync_failed', [
                    '%message%' => $e->getMessage(),
                ]));
            }

            if (!$syncFailed) {
                $this->addFlash('success', 'calendar.flash.saved');
            }

            if ($request->isXmlHttpRequest()) {
                return new Response('', Response::HTTP_NO_CONTENT);
            }

            return $this->redirectToRoute('settings.calendars.index');
        }

        return $this->render('Calendar/form.html.twig', [
            'form' => $form->createView(),
            'calendar' => $calendar,
            'isNew' => $isNew,
        ]);
    }

    #[Route('/{id}/delete', name: 'settings.calendars.delete', requirements: ['id' => '\\d+'], methods: ['DELETE'])]
    public function delete(Request $request, EntityManagerInterface $em, Calendar $calendar): Response
    {
        if (!$this->isCsrfTokenValid('delete'.$calendar->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // orphanRemoval on Calendar::$entries takes care of the entries themselves
        $em->remove($calendar);
        $em->flush();

        $this->addFlash('success', 'calendar.flash.deleted');

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Deletes entries with no confirmedAt for a past year, across every
     * calendar - a manual cleanup so the database doesn't keep accumulating
     * rows now that sync() never prunes anything on its own (see
     * CalendarEntrySyncService). Deliberately restricted to years before
     * the current one - this year's still-open reminders must stay
     * reachable.
     */
    #[Route('/cleanup-unconfirmed/{year}', name: 'settings.calendars.cleanup_unconfirmed', requirements: ['year' => '\\d{4}'], methods: ['POST'])]
    public function cleanupUnconfirmed(int $year, Request $request, CalendarEntryRepository $entryRepo, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('calendar-cleanup-'.$year, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($year >= (int) date('Y')) {
            throw $this->createAccessDeniedException('Cannot clean up the current or a future year.');
        }

        $deleted = $entryRepo->deleteUnconfirmedForYear($year);
        $this->addFlash('success', $translator->trans('calendar.cleanup.flash.deleted', [
            '%count%' => $deleted,
            '%year%' => $year,
        ]));

        return $this->redirectToRoute('settings.calendars.index', ['cleanupYear' => $year]);
    }
}
