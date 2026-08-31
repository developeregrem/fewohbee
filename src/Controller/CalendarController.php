<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Calendar;
use App\Form\CalendarType;
use App\Repository\CalendarEntryRepository;
use App\Repository\CalendarRepository;
use App\Service\Calendar\Sync\CalendarEntrySyncService;
use App\Exception\CalendarSyncException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Manages the configured calendars (waste collection, vacations, events...)
 * that CalendarEntry rows are imported into or manually added to.
 * Generalizes what used to be the "Abfallkalender" card in AppSettings.
 */
#[Route('/settings/calendars')]
#[IsGranted('ROLE_ADMIN')]
class CalendarController extends AbstractController
{
    #[Route('', name: 'settings.calendars.index', methods: ['GET'])]
    public function index(CalendarRepository $calendarRepo, CalendarEntryRepository $entryRepo): Response
    {
        return $this->render('Calendar/index.html.twig', [
            'calendars' => $calendarRepo->findAllOrdered(),
            'entryCounts' => $entryRepo->countGroupedByCalendar(),
        ]);
    }

    #[Route('/new', name: 'settings.calendars.new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        CalendarEntrySyncService $syncService,
        CalendarEntryRepository $entryRepo,
        TranslatorInterface $translator,
    ): Response {
        $calendar = new Calendar();

        return $this->handleForm($request, $em, $syncService, $entryRepo, $translator, $calendar, true);
    }

    #[Route('/{id}/edit', name: 'settings.calendars.edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        EntityManagerInterface $em,
        CalendarEntrySyncService $syncService,
        CalendarEntryRepository $entryRepo,
        TranslatorInterface $translator,
        Calendar $calendar,
    ): Response {
        return $this->handleForm($request, $em, $syncService, $entryRepo, $translator, $calendar, false);
    }

    private function handleForm(
        Request $request,
        EntityManagerInterface $em,
        CalendarEntrySyncService $syncService,
        CalendarEntryRepository $entryRepo,
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
                $result = null !== $icsFile
                    ? $syncService->importIcsString($calendar, (string) file_get_contents($icsFile->getPathname()))
                    : $syncService->sync($calendar);

                if (null !== $result) {
                    if ($result->total() > 0) {
                        $this->addFlash('success', $translator->trans('calendar.flash.synced', [
                            '%new%' => $result->new,
                            '%updated%' => $result->updated,
                            '%unchanged%' => $result->unchanged,
                        ]));
                    } elseif (0 === $result->skippedInvalid) {
                        // Only claim the source was empty when it really was:
                        // a feed made entirely of unreadable events imports
                        // nothing either, and the message below names that
                        // reason instead of blaming the source.
                        $this->addFlash('warning', 'calendar.flash.synced_empty');
                    }

                    if ($result->skippedInvalid > 0) {
                        $this->addFlash('warning', $translator->trans('calendar.flash.synced_invalid_skipped', [
                            '%count%' => $result->skippedInvalid,
                        ]));
                    }
                }
            } catch (CalendarSyncException $e) {
                $syncFailed = true;
                $this->addFlash('danger', $translator->trans('calendar.flash.sync_failed', [
                    '%message%' => $translator->trans($e->translationKey, $e->translationParameters),
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
            'unconfirmedPastCount' => $isNew ? 0 : $entryRepo->countUnconfirmedPast($calendar),
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
     * Deletes this calendar's past, never-confirmed entries - offered as a
     * button in the edit dialog so the database doesn't keep accumulating
     * rows now that sync() never prunes anything on its own (see
     * CalendarEntrySyncService). Confirmed entries and anything from today
     * onwards are never touched.
     */
    #[Route('/{id}/cleanup-unconfirmed', name: 'settings.calendars.cleanup_unconfirmed', requirements: ['id' => '\\d+'], methods: ['DELETE'])]
    public function cleanupUnconfirmed(Request $request, Calendar $calendar, CalendarEntryRepository $entryRepo, TranslatorInterface $translator): Response
    {
        // 'delete' ~ id, where the template passes id as "cleanup<id>" - the
        // shared delete_popover component builds the token that way, and the
        // prefix keeps it distinct from the calendar's own delete token.
        if (!$this->isCsrfTokenValid('deletecleanup'.$calendar->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $deleted = $entryRepo->deleteUnconfirmedPast($calendar);
        $this->addFlash('success', $translator->trans('calendar.cleanup.flash.deleted', [
            '%count%' => $deleted,
            '%calendar%' => $calendar->getName(),
        ]));

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
