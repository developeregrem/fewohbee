<?php

namespace App\Controller;

use App\Entity\ReservationStatus;
use App\Form\ReservationStatusType;
use App\Repository\ReservationStatusRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Persistence\ManagerRegistry;

#[Route('/settings/status')]
class ReservationStatusController extends AbstractController
{

    #[Route('/', name: 'reservation_status_index', methods: ['GET'])]
    public function index(ReservationStatusRepository $reservationStatusRepository): Response
    {
        return $this->render('ReservationStatus/index.html.twig', [
            'reservation_status' => $reservationStatusRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'reservation_status_new', methods: ['GET', 'POST'])]
    public function new(ManagerRegistry $doctrine, Request $request): Response
    {
        $reservationStatus = new ReservationStatus();
        $form = $this->createForm(ReservationStatusType::class, $reservationStatus);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $reservationStatus->setContrastColor($this->calculateColor($reservationStatus->getColor()));
            $entityManager = $doctrine->getManager();
            $entityManager->persist($reservationStatus);
            $entityManager->flush();

            // add succes message
            $this->addFlash('success', 'status.flash.create.success');
            
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('ReservationStatus/new.html.twig', [
            'status' => $reservationStatus,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{id}", name="reservation_status_show", methods={"GET"})
     */
//    public function show(ReservationStatus $reservationStatus): Response
//    {
//        return $this->render('ReservationStatus/show.html.twig', [
//            'reservation_status' => $reservationStatus,
//        ]);
//    }

    #[Route('/{id}/edit', name: 'reservation_status_edit', methods: ['GET', 'POST'])]
    public function edit(ManagerRegistry $doctrine, Request $request, ReservationStatus $reservationStatus): Response
    {
        $form = $this->createForm(ReservationStatusType::class, $reservationStatus);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $reservationStatus->setContrastColor($this->calculateColor($reservationStatus->getColor()));
            $doctrine->getManager()->flush();

            // add succes message
            $this->addFlash('success', 'status.flash.edit.success');
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('ReservationStatus/edit.html.twig', [
            'status' => $reservationStatus,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/delete', name: 'reservation_status_delete', methods: ['GET', 'DELETE'])]
    public function delete(ManagerRegistry $doctrine, Request $request, ReservationStatus $reservationStatus): Response
    {
        if ($request->getMethod() === 'GET') {
            // initial get load (ask for deleting)           
            return $this->render('common/form_delete_ask.html.twig', [
                'id' => $reservationStatus->getId(),
            ]);
        } else if ($this->isCsrfTokenValid('delete'.$reservationStatus->getId(), $request->request->get('_token'))) {
            if($reservationStatus->getReservations()->count() > 0) {
                $this->addFlash('warning', 'status.flash.delete.error.still.in.use');
            } else {
                $entityManager = $doctrine->getManager();
                $entityManager->remove($reservationStatus);
                $entityManager->flush();
                $this->addFlash('success', 'status.flash.delete.success');
            }            
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
    
    private function calculateColor($bgColor) {
        list($r, $g, $b) = sscanf($bgColor, "#%02x%02x%02x");
        $color = 1 - ( 0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        if ($color < 0.5)
            $color = '#000000'; // bright colors - black font
        else
            $color = '#ffffff'; // dark colors - white font

        return $color;
    }

}
