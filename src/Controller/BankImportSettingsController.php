<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\BankImportSettingsType;
use App\Service\BookingJournal\AccountingSettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/journal/bank-import/settings')]
#[IsGranted('ROLE_CASHJOURNAL')]
class BankImportSettingsController extends AbstractController
{
    #[Route('', name: 'bank_import.settings', methods: ['GET', 'POST'])]
    public function index(Request $request, AccountingSettingsService $settingsService): Response
    {
        $settings = $settingsService->getSettings();
        $form = $this->createForm(BankImportSettingsType::class, $settings, [
            'action' => $this->generateUrl('bank_import.settings'),
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $settingsService->saveSettings($settings);
            $this->addFlash('success', 'accounting.bank_import.settings.flash.saved');

            return $this->redirectToRoute('bank_import.settings');
        }

        return $this->render('BookingJournal/BankImport/settings.html.twig', [
            'form' => $form,
        ]);
    }
}
