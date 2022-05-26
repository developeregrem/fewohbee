<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use App\Service\CSRFProtectionService;
use App\Service\AppartmentService;
use App\Entity\Appartment;
use App\Entity\Subsidiary;
use App\Entity\RoomCategory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;
use App\Service\CalendarService;
use App\Form\CalendarSyncExportType;
use App\Entity\CalendarSync;
use App\Service\CalendarSyncService;

#[Route('/apartments')]
class AppartmentServiceController extends AbstractController
{
    
    #[Route('/', name: 'appartments.overview', methods: ['GET'])]
    public function indexAction(ManagerRegistry $doctrine)
    {
        $em = $doctrine->getManager();
        $appartments = $em->getRepository(Appartment::class)->findAll();

        return $this->render('Appartments/index.html.twig', array(
            "appartments" => $appartments
        ));
    }

    #[Route('/{id}/get', name: 'appartments.get.appartment', methods: ['GET'], defaults: ['id' => '0'])]
    public function getAppartmentAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, $id)
    {
        $em = $doctrine->getManager();

        $appartment = $em->getRepository(Appartment::class)->find($id);
        $objects = $em->getRepository(Subsidiary::class)->findAll();
        $categories = $em->getRepository(RoomCategory::class)->findAll();

        return $this->render('Appartments/appartment_form_edit.html.twig', array(
            'objects' => $objects,
            'categories' => $categories,
            'appartment' => $appartment,
            'token' => $csrf->getCSRFTokenForForm()
        ));
    }

    #[Route('/new', name: 'appartments.new.appartment', methods: ['GET'])]
    public function newAppartmentAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf)
    {
        $em = $doctrine->getManager();

        $objects = $em->getRepository(Subsidiary::class)->findAll();
        $categories = $em->getRepository(RoomCategory::class)->findAll();
        $appartment = new Appartment();
        $appartment->setId('new');

        return $this->render('Appartments/appartment_form_create.html.twig', array(
            'objects' => $objects,
            'categories' => $categories,
            "appartment" => $appartment,
            'token' => $csrf->getCSRFTokenForForm()
        ));
    }

    #[Route('/create', name: 'appartments.create.appartment', methods: ['POST'])]
    public function createAppartmentAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, AppartmentService $as, Request $request, CalendarSyncService $css)
    {
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            /* @var $appartment Appartment */
            $appartment = $as->getAppartmentFromForm($request, "new");

            // check for mandatory fields
            if (strlen($appartment->getNumber()) == 0 || strlen($appartment->getBedsMax()) == 0
                || strlen($appartment->getDescription()) == 0
            ) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
            } else {
                $em = $doctrine->getManager();
                $em->persist($appartment);
                $em->flush();
                $css->initSync($appartment);                

                // add succes message
                $this->addFlash('success', 'appartment.flash.create.success');
            }
        }

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }


    #[Route('/{id}/edit', name: 'appartments.edit.appartment', methods: ['POST'], defaults: ['id' => '0'])]
    public function editAppartmentAction(ManagerRegistry $doctrine, CSRFProtectionService $csrf, AppartmentService $as, Request $request, $id)
    {
        $error = false;
        if (($csrf->validateCSRFToken($request))) {
            /* @var $appartment Appartment */
            $appartment = $as->getAppartmentFromForm($request, $id);
            $em = $doctrine->getManager();

            // check for mandatory fields
            if (strlen($appartment->getNumber()) == 0 || strlen($appartment->getBedsMax()) == 0
                || strlen($appartment->getDescription()) == 0) {
                $error = true;
                $this->addFlash('warning', 'flash.mandatory');
                // stop auto commit of doctrine with invalid field values
                $em->clear(Appartment::class);
            } else {
                $em->persist($appartment);
                $em->flush();

                // add success message           
                $this->addFlash('success', 'appartment.flash.edit.success');
            }
        }

        return $this->render('feedback.html.twig', array(
            "error" => $error
        ));
    }

    #[Route('/{id}/delete', name: 'appartments.delete.appartment', methods: ['GET', 'POST'])]
    public function deleteAppartmentAction(CSRFProtectionService $csrf, AppartmentService $as, Request $request, $id)
    {
        if ($request->getMethod() == 'POST') {
            if (($csrf->validateCSRFToken($request, true))) {
                $appartment = $as->deleteAppartment($id);

                if ($appartment) {
                    $this->addFlash('success', 'appartment.flash.delete.success');
                } else {
                    $this->addFlash('warning', 'appartment.flash.delete.error.still.in.use');
                }
            }
            return new Response('', Response::HTTP_NO_CONTENT);
        } else {
            // initial get load (ask for deleting)           
            return $this->render('common/form_delete_entry.html.twig', array(
                "id" => $id,
                'token' => $csrf->getCSRFTokenForForm()
            ));
        }
    }
    
    #[Route('/sync/{id}/edit', name: 'apartments.sync.edit', methods: ['GET', 'POST'])]
    public function editSync(ManagerRegistry $doctrine, Request $request, CalendarSync $sync): Response
    {
        $form = $this->createForm(CalendarSyncExportType::class, $sync);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();

            // add succes message
            $this->addFlash('success', 'category.flash.edit.success');
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('Appartments/sync_edit.html.twig', [
            'sync' => $sync,
            'form' => $form->createView(),
        ]);
    }
    
    #[Route('/calendar/{uuid}/calendar.ics', name: 'apartments.get.calendar', methods: ['GET'], requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function getCalendarAction(ManagerRegistry $doctrine, CalendarService $cs, string $uuid, CalendarSyncService $css)
    {
        $em = $doctrine->getManager();
        $sync = $em->getRepository(CalendarSync::class)->findOneBy(['uuid' => Uuid::fromString($uuid)]);
        /* @var $sync CalendarSync */
        if( !$sync instanceof CalendarSync || !$sync->getIsPublic() ) {
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
