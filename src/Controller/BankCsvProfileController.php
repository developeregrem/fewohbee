<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BankCsvProfile;
use App\Form\BankCsvProfileType;
use App\Repository\BankCsvProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/journal/bank-import/profiles')]
#[IsGranted('ROLE_CASHJOURNAL')]
class BankCsvProfileController extends AbstractController
{
    #[Route('', name: 'bank_import.profiles.index', methods: ['GET'])]
    public function index(BankCsvProfileRepository $repo): Response
    {
        return $this->render('BookingJournal/BankImport/profiles_index.html.twig', [
            'profiles' => $repo->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'bank_import.profiles.new', methods: ['GET'])]
    public function new(): Response
    {
        $profile = new BankCsvProfile();
        $form = $this->createForm(BankCsvProfileType::class, $profile, [
            'action' => $this->generateUrl('bank_import.profiles.create'),
        ]);

        return $this->renderProfileForm($form, $profile, isNew: true);
    }

    #[Route('/create', name: 'bank_import.profiles.create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $profile = new BankCsvProfile();
        $form = $this->createForm(BankCsvProfileType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($profile);
            $em->flush();
            $this->addFlash('success', 'accounting.bank_import.profile.flash.created');

            return $this->redirectToRoute('bank_import.profiles.index');
        }

        return $this->renderProfileForm($form, $profile, isNew: true);
    }

    #[Route('/{id}/edit', name: 'bank_import.profiles.edit', methods: ['GET'])]
    public function edit(BankCsvProfile $profile): Response
    {
        $form = $this->createForm(BankCsvProfileType::class, $profile, [
            'action' => $this->generateUrl('bank_import.profiles.update', ['id' => $profile->getId()]),
        ]);

        return $this->renderProfileForm($form, $profile, isNew: false);
    }

    #[Route('/{id}/update', name: 'bank_import.profiles.update', methods: ['POST'])]
    public function update(BankCsvProfile $profile, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(BankCsvProfileType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'accounting.bank_import.profile.flash.updated');

            return $this->redirectToRoute('bank_import.profiles.index');
        }

        return $this->renderProfileForm($form, $profile, isNew: false);
    }

    #[Route('/{id}/delete', name: 'bank_import.profiles.delete', methods: ['DELETE'])]
    public function delete(BankCsvProfile $profile, EntityManagerInterface $em, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete'.$profile->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'flash.invalidtoken');

            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $em->remove($profile);
        $em->flush();
        $this->addFlash('success', 'accounting.bank_import.profile.flash.deleted');

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function renderProfileForm($form, BankCsvProfile $profile, bool $isNew): Response
    {
        return $this->render('BookingJournal/BankImport/profile_form.html.twig', [
            'form' => $form,
            'profile' => $profile,
            'isNew' => $isNew,
        ]);
    }
}
