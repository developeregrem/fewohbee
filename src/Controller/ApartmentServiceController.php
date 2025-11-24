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
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Form\ApartmentType;
use App\Form\CalendarSyncExportType;
use App\Repository\AppartmentRepository;
use App\Service\AppartmentService;
use App\Service\CalendarService;
use App\Service\CalendarSyncService;
use App\Service\CSRFProtectionService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

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
    public function editSync(ManagerRegistry $doctrine, Request $request, CalendarSync $sync): Response
    {
        $form = $this->createForm(CalendarSyncExportType::class, $sync);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();

            // add success message
            $this->addFlash('success', 'category.flash.edit.success');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('Apartments/sync_edit.html.twig', [
            'sync' => $sync,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/calendar/{uuid}/calendar.ics', name: 'apartments.get.calendar', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function getCalendarAction(ManagerRegistry $doctrine, CalendarService $cs, string $uuid, CalendarSyncService $css): Response
    {
        $em = $doctrine->getManager();
        $sync = $em->getRepository(CalendarSync::class)->findOneBy(['uuid' => Uuid::fromString($uuid)]);
        /* @var $sync CalendarSync */
        if (!$sync instanceof CalendarSync || !$sync->getIsPublic()) {
            throw new NotFoundHttpException();
        }
        $css->updateExportDate($sync);

        $response = new Response(
            $cs->getIcalContent($sync),
            Response::HTTP_OK,
            ['content-type' => 'text/calendar; charset=utf-8']
        );
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'calendar.ics'
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }
}
