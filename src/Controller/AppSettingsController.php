<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\AppSettingsType;
use App\Service\AppSettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings/general')]
#[IsGranted('ROLE_ADMIN')]
class AppSettingsController extends AbstractController
{
    #[Route('', name: 'settings.general.index', methods: ['GET', 'POST'])]
    public function index(Request $request, AppSettingsService $settingsService): Response
    {
        $settings = $settingsService->getSettings();

        $form = $this->createForm(AppSettingsType::class, $settings);

        // populate the unmapped salutations field
        $form->get('customerSalutations')->setData(implode(', ', $settings->getCustomerSalutations()));

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // parse comma-separated salutations back into array
            $rawSalutations = (string) $form->get('customerSalutations')->getData();
            $settings->setCustomerSalutations(
                array_map('trim', explode(',', $rawSalutations))
            );

            $settingsService->saveSettings($settings);
            $this->addFlash('success', 'app_settings.flash.saved');

            return $this->redirectToRoute('settings.general.index');
        }

        return $this->render('Settings/AppSettings/index.html.twig', [
            'form' => $form->createView(),
            'fallbackEmail' => $settingsService->getNotificationEmail($settings),
        ]);
    }
}
