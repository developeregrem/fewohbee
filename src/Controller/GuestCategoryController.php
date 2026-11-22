<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GuestCategory;
use App\Entity\GuestCategoryModifier;
use App\Form\GuestCategoryModifierType;
use App\Form\GuestCategoryType;
use App\Repository\GuestCategoryModifierRepository;
use App\Repository\GuestCategoryRepository;
use App\Service\Pricing\PricePromiseService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/settings/guest-category')]
class GuestCategoryController extends AbstractController
{
    #[Route('/', name: 'guest_category_index', methods: ['GET'])]
    public function index(GuestCategoryRepository $repository, GuestCategoryModifierRepository $modifierRepository): Response
    {
        return $this->render('GuestCategory/index.html.twig', [
            'guest_categories' => $repository->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']),
            'modifiers' => $modifierRepository->findAllOrdered(),
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
    public function edit(ManagerRegistry $doctrine, PricePromiseService $pricePromises, Request $request, GuestCategory $category): Response
    {
        $this->promiseOpenBookings($request, $pricePromises);
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
    public function delete(ManagerRegistry $doctrine, PricePromiseService $pricePromises, Request $request, GuestCategory $category): Response
    {
        if ($this->isCsrfTokenValid('delete'.$category->getId(), $request->request->get('_token'))) {
            $this->promiseOpenBookings($request, $pricePromises);
            // prevent deletion of default adult category
            if ($category->isSystem() && 'default_adult' === $category->getSystemCode()) {
                $this->addFlash('warning', 'status.flash.delete.error.system');

                return new Response('', Response::HTTP_NO_CONTENT);
            }
            $em = $doctrine->getManager();
            $em->remove($category);
            $em->flush();
            $this->addFlash('success', 'guest_category.flash.delete.success');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/modifiers/new', name: 'guest_category_modifier_new', methods: ['GET', 'POST'])]
    public function newModifier(ManagerRegistry $doctrine, PricePromiseService $pricePromises, Request $request): Response
    {
        $this->promiseOpenBookings($request, $pricePromises);
        $modifier = new GuestCategoryModifier();
        $form = $this->createForm(GuestCategoryModifierType::class, $modifier);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $doctrine->getManager();
            $em->persist($modifier);
            $em->flush();

            $this->addFlash('success', 'guest_category_modifier.flash.create.success');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('GuestCategory/modifier_new.html.twig', [
            'modifier' => $modifier,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/modifiers/{id}/edit', name: 'guest_category_modifier_edit', methods: ['GET', 'POST'])]
    public function editModifier(ManagerRegistry $doctrine, PricePromiseService $pricePromises, Request $request, GuestCategoryModifier $modifier): Response
    {
        $this->promiseOpenBookings($request, $pricePromises);
        $form = $this->createForm(GuestCategoryModifierType::class, $modifier);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();
            $this->addFlash('success', 'guest_category_modifier.flash.edit.success');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('GuestCategory/modifier_edit.html.twig', [
            'modifier' => $modifier,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Switches a guest category on or off from the list (ActiveToggle), with the price promise
     * of the edit form. Answers the list's fetch with 204, a plain form post with the list.
     */
    #[Route('/{id}/toggle', name: 'guest_category_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(ManagerRegistry $doctrine, PricePromiseService $pricePromises, Request $request, GuestCategory $category): Response
    {
        if (!$this->isCsrfTokenValid('guest-category-toggle-'.$category->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->promiseOpenBookings($request, $pricePromises);
        $category->setActive(!$category->isActive());
        $doctrine->getManager()->flush();

        return $this->toggleResponse($request);
    }

    /** Switches a modifier on or off from the list, like toggle() */
    #[Route('/modifiers/{id}/toggle', name: 'guest_category_modifier_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleModifier(ManagerRegistry $doctrine, PricePromiseService $pricePromises, Request $request, GuestCategoryModifier $modifier): Response
    {
        if (!$this->isCsrfTokenValid('guest-category-modifier-toggle-'.$modifier->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->promiseOpenBookings($request, $pricePromises);
        $modifier->setActive(!$modifier->isActive());
        $doctrine->getManager()->flush();

        return $this->toggleResponse($request);
    }

    #[Route('/modifiers/{id}/delete', name: 'guest_category_modifier_delete', methods: ['DELETE'])]
    public function deleteModifier(ManagerRegistry $doctrine, PricePromiseService $pricePromises, Request $request, GuestCategoryModifier $modifier): Response
    {
        if ($this->isCsrfTokenValid('delete'.$modifier->getId(), $request->request->get('_token'))) {
            $this->promiseOpenBookings($request, $pricePromises);
            $em = $doctrine->getManager();
            $em->remove($modifier);
            $em->flush();
            $this->addFlash('success', 'guest_category_modifier.flash.delete.success');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Guest categories and their modifiers shape room prices. Open bookings without a promise
     * are promised today's price before a change can reach them; the form changes the entity in
     * memory right away, so this runs first.
     */
    private function promiseOpenBookings(Request $request, PricePromiseService $pricePromises): void
    {
        if (!$request->isMethod('GET')) {
            $pricePromises->promiseOpenReservations();
        }
    }

    private function toggleResponse(Request $request): Response
    {
        return $request->isXmlHttpRequest()
            ? new Response(status: Response::HTTP_NO_CONTENT)
            : $this->redirectToRoute('guest_category_index');
    }
}
