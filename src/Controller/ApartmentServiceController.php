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
use App\Entity\CalendarSync;
use App\Entity\CalendarSyncImport;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Form\ApartmentType;
use App\Form\CalendarSyncExportType;
use App\Form\CalendarSyncImportType;
use App\Repository\AppartmentRepository;
use App\Service\AppartmentService;
use App\Service\CalendarImportService;
use App\Service\CalendarSyncService;
use App\Service\CSRFProtectionService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/settings/apartments')]
class ApartmentServiceController extends AbstractController
{
    #[Route('/', name: 'apartments.overview', methods: ['GET'])]
    public function index(AppartmentRepository $apartmentRepository): Response
    {
        return $this->render('Apartments/index.html.twig', [
            'apartments' => $apartmentRepository->findAll(),
        ]);
    }

    #[Route('/{id}/get', name: 'appartments.get.appartment', defaults: ['id' => '0'], methods: ['GET'])]
    public function getAppartmentAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, $id)
    {
        $em = $doctrine->getManager();

        $appartment = $em->getRepository(Appartment::class)->find($id);
        $objects = $em->getRepository(Subsidiary::class)->findAll();
        $categories = $em->getRepository(RoomCategory::class)->findAll();

        return $this->render('Apartments/appartment_form_edit.html.twig', [
            'objects' => $objects,
            'categories' => $categories,
            'appartment' => $appartment,
            'token' => $csrf->getCSRFTokenForForm(),
        ]);
    }

    #[Route('/new', name: 'apartments.new.apartment', methods: ['GET', 'POST'])]
    public function new(ManagerRegistry $doctrine, Request $request, CalendarSyncService $css): Response
    {
        $apartment = new Appartment();
        $form = $this->createForm(ApartmentType::class, $apartment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $doctrine->getManager();
            $entityManager->persist($apartment);
            $entityManager->flush();
            $css->initSync($apartment);

            // add succes message
            $this->addFlash('success', 'appartment.flash.create.success');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('Apartments/new.html.twig', [
            'apartment' => $apartment,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'apartments.edit.apartment', defaults: ['id' => '0'], methods: ['GET', 'POST'])]
    public function edit(ManagerRegistry $doctrine, Request $request, Appartment $apartment): Response
    {
        $form = $this->createForm(ApartmentType::class, $apartment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();

            // add success message
            $this->addFlash('success', 'appartment.flash.edit.success');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('Apartments/edit.html.twig', [
            'apartment' => $apartment,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/delete', name: 'appartments.delete.appartment', methods: ['GET', 'DELETE'])]
    public function delete(ManagerRegistry $doctrine, Request $request, AppartmentService $as, Appartment $apartment): Response
    {
        if ('GET' === $request->getMethod()) {
            // initial get load (ask for deleting)
            return $this->render('common/form_delete_ask.html.twig', [
                'id' => $apartment->getId(),
            ]);
        } elseif ($this->isCsrfTokenValid('delete'.$apartment->getId(), $request->request->get('_token'))) {
            $status = $as->deleteAppartment($apartment);

            if ($status) {
                $this->addFlash('success', 'appartment.flash.delete.success');
            } else {
                $this->addFlash('warning', 'appartment.flash.delete.error.still.in.use');
            }
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/sync/{id}/edit', name: 'apartments.sync.edit', methods: ['GET', 'POST'])]
    public function editSync(
        ManagerRegistry $doctrine,
        Request $request,
        CalendarSync $sync,
        FormFactoryInterface $formFactory
    ): Response
    {
        $exportForm = $this->createForm(CalendarSyncExportType::class, $sync);
        $exportForm->handleRequest($request);

        if ($exportForm->isSubmitted() && $exportForm->isValid()) {
            $doctrine->getManager()->flush();

            // add success message
            $this->addFlash('success', 'appartment.flash.edit.success');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->renderSyncModal($sync, $formFactory, $exportForm);
    }

    #[Route('/sync/{id}/import/new', name: 'apartments.sync.import.new', methods: ['POST'])]
    /** Persist a new iCal import configuration for the apartment. */
    public function createImport(
        ManagerRegistry $doctrine,
        Request $request,
        CalendarSync $sync,
        CalendarImportService $calendarImportService,
        FormFactoryInterface $formFactory
    ): Response
    {
        $import = $this->createImportModel($sync);
        $importCreateForm = $formFactory->createNamed('import_new', CalendarSyncImportType::class, $import);
        $importCreateForm->handleRequest($request);

        if ($importCreateForm->isSubmitted() && $importCreateForm->isValid()) {
            $doctrine->getManager()->persist($import);
            $doctrine->getManager()->flush();
            $calendarImportService->syncImport($import);

            $this->addFlash('success', 'calendar.sync.import.flash.create.success');

            return $this->renderSyncModal($sync, $formFactory);
        }

        return $this->renderSyncModal($sync, $formFactory, null, $importCreateForm);
    }

    #[Route('/sync/import/{id}/edit', name: 'apartments.sync.import.edit', methods: ['POST'])]
    /** Update an existing iCal import configuration. */
    public function editImport(
        ManagerRegistry $doctrine,
        Request $request,
        CalendarSyncImport $import,
        FormFactoryInterface $formFactory
    ): Response
    {
        $importEditForm = $formFactory->createNamed('import_'.$import->getId(), CalendarSyncImportType::class, $import);
        $importEditForm->handleRequest($request);

        if ($importEditForm->isSubmitted() && $importEditForm->isValid()) {
            $doctrine->getManager()->flush();

            $this->addFlash('success', 'calendar.sync.import.flash.edit.success');

            $sync = $import->getApartment()->getCalendarSync();

            return $this->renderSyncModal($sync, $formFactory);
        }

        $sync = $import->getApartment()->getCalendarSync();
        $importEditForms = $this->buildImportEditForms($sync, $formFactory);
        $importEditForms[$import->getId()] = $importEditForm;

        return $this->renderSyncModal($sync, $formFactory, null, null, $importEditForms);
    }

    #[Route('/sync/import/{id}/delete', name: 'apartments.sync.import.delete', methods: ['DELETE'])]
    /** Remove an iCal import configuration. */
    public function deleteImport(
        ManagerRegistry $doctrine,
        CalendarSyncImport $import,
        FormFactoryInterface $formFactory
    ): Response
    {
        $doctrine->getManager()->remove($import);
        $doctrine->getManager()->flush();

        $this->addFlash('success', 'calendar.sync.import.flash.delete.success');

        $sync = $import->getApartment()->getCalendarSync();

        return $this->renderSyncModal($sync, $formFactory);
    }

    /** Create a default import instance for the given sync. */
    private function createImportModel(CalendarSync $sync): CalendarSyncImport
    {
        $import = new CalendarSyncImport();
        $import->setApartment($sync->getApartment());

        return $import;
    }

    /** Build edit forms for all imports of a sync. */
    private function buildImportEditForms(CalendarSync $sync, FormFactoryInterface $formFactory): array
    {
        $forms = [];
        foreach ($sync->getApartment()->getCalendarSyncImports() as $import) {
            $forms[$import->getId()] = $formFactory->createNamed('import_'.$import->getId(), CalendarSyncImportType::class, $import);
        }

        return $forms;
    }

    /** Render the calendar sync modal with export and import forms. */
    private function renderSyncModal(
        CalendarSync $sync,
        FormFactoryInterface $formFactory,
        ?FormInterface $exportForm = null,
        ?FormInterface $importCreateForm = null,
        array $importEditForms = []
    ): Response {
        $exportFormView = ($exportForm ?? $this->createForm(CalendarSyncExportType::class, $sync))->createView();
        $importCreateFormView = ($importCreateForm ?? $formFactory->createNamed('import_new', CalendarSyncImportType::class, $this->createImportModel($sync)))->createView();
        $importEditForms = $importEditForms ?: $this->buildImportEditForms($sync, $formFactory);

        $importEditFormViews = [];
        foreach ($importEditForms as $id => $form) {
            $importEditFormViews[$id] = $form->createView();
        }

        return $this->render('Apartments/sync_edit.html.twig', [
            'sync' => $sync,
            'exportForm' => $exportFormView,
            'importCreateForm' => $importCreateFormView,
            'importEditForms' => $importEditFormViews,
        ]);
    }
}
