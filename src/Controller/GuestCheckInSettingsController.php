<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\GuestCheckInConfigType;
use App\Service\GuestCheckIn\GuestCheckInConfigService;
use App\Service\PublicUrlService;
use App\Workflow\WorkflowSeeder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings/guest-checkin')]
#[IsGranted('ROLE_ADMIN')]
final class GuestCheckInSettingsController extends AbstractController
{
    #[Route('', name: 'settings.guest_checkin.index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        GuestCheckInConfigService $configService,
        PublicUrlService $publicUrlService,
        WorkflowSeeder $workflowSeeder,
    ): Response {
        $config = $configService->getConfig();
        $wasEnabled = $config->isEnabled();
        $form = $this->createForm(GuestCheckInConfigType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $configService->saveConfig($config);

            if (!$wasEnabled && $config->isEnabled()) {
                // Notification and invitation example only appear once the check-in is in use.
                $workflowSeeder->seedGuestCheckInWorkflows();
            }

            $this->addFlash('success', 'guest_checkin.flash.settings_saved');

            return $this->redirectToRoute('settings.guest_checkin.index');
        }

        $publicBaseUrl = $publicUrlService->getBaseUrl();

        return $this->render('Settings/GuestCheckIn/index.html.twig', [
            'form' => $form->createView(),
            'fieldGroups' => GuestCheckInConfigType::FIELD_GROUPS,
            'publicBaseUrl' => $publicBaseUrl,
            'publicBaseUrlWarning' => null !== $publicBaseUrl ? PublicUrlService::reachabilityWarning($publicBaseUrl) : null,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
