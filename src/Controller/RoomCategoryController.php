<?php

namespace App\Controller;

use App\Entity\RoomCategory;
use App\Form\RoomCategoryType;
use App\Repository\RoomCategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @Route("/settings/category")
 */
class RoomCategoryController extends AbstractController
{
    
    /**
     * @Route("/", name="room_category_index", methods={"GET"})
     */
    public function index(RoomCategoryRepository $roomCategoryRepository): Response
    {
        return $this->render('RoomCategory/index.html.twig', [
            'room_categories' => $roomCategoryRepository->findAll(),
        ]);
    }

    /**
     * @Route("/new", name="room_category_new", methods={"GET","POST"})
     */
    public function new(ManagerRegistry $doctrine, Request $request): Response
    {
        $roomCategory = new RoomCategory();
        $form = $this->createForm(RoomCategoryType::class, $roomCategory);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $doctrine->getManager();
            $entityManager->persist($roomCategory);
            $entityManager->flush();

            // add succes message
            $this->addFlash('success', 'category.flash.create.success');
            
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('RoomCategory/new.html.twig', [
            'category' => $roomCategory,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{id}", name="room_category_show", methods={"GET"})
     */
//    public function show(RoomCategory $roomCategory): Response
//    {
//        return $this->render('RoomCategory/show.html.twig', [
//            'room_category' => $roomCategory,
//        ]);
//    }

    /**
     * @Route("/{id}/edit", name="room_category_edit", methods={"GET","POST"})
     */
    public function edit(ManagerRegistry $doctrine, Request $request, RoomCategory $roomCategory): Response
    {
        $form = $this->createForm(RoomCategoryType::class, $roomCategory);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();

            // add succes message
            $this->addFlash('success', 'category.flash.edit.success');
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('RoomCategory/edit.html.twig', [
            'category' => $roomCategory,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{id}/delete", name="room_category_delete", methods={"DELETE", "GET"})
     */
    public function delete(ManagerRegistry $doctrine, Request $request, RoomCategory $roomCategory): Response
    {
        if ($request->getMethod() === 'GET') {
            // initial get load (ask for deleting)           
            return $this->render('common/form_delete_ask.html.twig', [
                'id' => $roomCategory->getId(),
            ]);
        } else if ($this->isCsrfTokenValid('delete'.$roomCategory->getId(), $request->request->get('_token'))) {
            if($roomCategory->getPrices()->count() > 0 || $roomCategory->getApartments()->count() > 0) {
                $this->addFlash('warning', 'category.flash.delete.error.still.in.use');
            } else {
                $entityManager = $doctrine->getManager();
                $entityManager->remove($roomCategory);
                $entityManager->flush();
                $this->addFlash('success', 'category.flash.delete.success');
            }            
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
