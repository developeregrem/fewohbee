<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GuestCategory;
use App\Form\GuestCategoryType;
use App\Repository\GuestCategoryRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/settings/guest-category')]
class GuestCategoryController extends AbstractController
{
    #[Route('/', name: 'guest_category_index', methods: ['GET'])]
    public function index(GuestCategoryRepository $repository): Response
    {
        return $this->render('GuestCategory/index.html.twig', [
            'guest_categories' => $repository->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'guest_category_new', methods: ['GET', 'POST'])]
    public function new(ManagerRegistry $doctrine, Request $request): Response
    {
        $category = new GuestCategory();
        $form = $this->createForm(GuestCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $doctrine->getManager();
            $em->persist($category);
            $em->flush();

            $this->addFlash('success', 'guest_category.flash.create.success');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('GuestCategory/new.html.twig', [
            'category' => $category,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'guest_category_edit', methods: ['GET', 'POST'])]
    public function edit(ManagerRegistry $doctrine, Request $request, GuestCategory $category): Response
    {
        $form = $this->createForm(GuestCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();
            $this->addFlash('success', 'guest_category.flash.edit.success');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('GuestCategory/edit.html.twig', [
            'category' => $category,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/delete', name: 'guest_category_delete', methods: ['DELETE'])]
    public function delete(ManagerRegistry $doctrine, Request $request, GuestCategory $category): Response
    {
        if ($this->isCsrfTokenValid('delete'.$category->getId(), $request->request->get('_token'))) {
            $em = $doctrine->getManager();
            $em->remove($category);
            $em->flush();
            $this->addFlash('success', 'guest_category.flash.delete.success');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
