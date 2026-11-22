<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TouristTax;
use App\Form\TouristTaxType;
use App\Repository\TouristTaxRepository;
use App\Service\BookingJournal\AccountingSettingsService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/settings/tourist-tax')]
class TouristTaxController extends AbstractController
{
    #[Route('/', name: 'tourist_tax_index', methods: ['GET'])]
    public function index(TouristTaxRepository $repo): Response
    {
        return $this->render('TouristTax/index.html.twig', [
            'tourist_taxes' => $repo->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'tourist_tax_new', methods: ['GET', 'POST'])]
    public function new(ManagerRegistry $doctrine, Request $request, AccountingSettingsService $settings): Response
    {
        $tax = new TouristTax();
        $form = $this->createForm(TouristTaxType::class, $tax, [
            'active_preset' => $settings->getActivePreset(),
            'reference_date' => $tax->getValidFrom() ?? new \DateTime(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $doctrine->getManager();
            $em->persist($tax);
            $em->flush();

            $this->addFlash('success', 'tourist_tax.flash.create.success');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('TouristTax/new.html.twig', [
            'tax' => $tax,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'tourist_tax_edit', methods: ['GET', 'POST'])]
    public function edit(ManagerRegistry $doctrine, Request $request, TouristTax $tax, AccountingSettingsService $settings): Response
    {
        $form = $this->createForm(TouristTaxType::class, $tax, [
            'active_preset' => $settings->getActivePreset(),
            'reference_date' => $tax->getValidFrom() ?? new \DateTime(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();
            $this->addFlash('success', 'tourist_tax.flash.edit.success');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->render('TouristTax/edit.html.twig', [
            'tax' => $tax,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Switches a tourist tax on or off from the list (ActiveToggle). Answers the list's fetch
     * with 204, a plain form post with the list.
     */
    #[Route('/{id}/toggle', name: 'tourist_tax_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(ManagerRegistry $doctrine, Request $request, TouristTax $tax): Response
    {
        if (!$this->isCsrfTokenValid('tourist-tax-toggle-'.$tax->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $tax->setActive(!$tax->isActive());
        $doctrine->getManager()->flush();

        return $request->isXmlHttpRequest()
            ? new Response(status: Response::HTTP_NO_CONTENT)
            : $this->redirectToRoute('tourist_tax_index');
    }

    #[Route('/{id}/delete', name: 'tourist_tax_delete', methods: ['DELETE'])]
    public function delete(ManagerRegistry $doctrine, Request $request, TouristTax $tax): Response
    {
        if ($this->isCsrfTokenValid('delete'.$tax->getId(), $request->request->get('_token'))) {
            $em = $doctrine->getManager();
            $em->remove($tax);
            $em->flush();
            $this->addFlash('success', 'tourist_tax.flash.delete.success');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
