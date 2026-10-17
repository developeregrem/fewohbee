<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\GuestCheckInStatus;
use App\Form\GuestCheckInConfigType;
use App\Form\GuestCheckInType;
use App\Service\GuestCheckIn\GuestCheckInConfigService;
use App\Service\GuestCheckIn\GuestCheckInExtrasService;
use App\Service\GuestCheckIn\GuestCheckInFormDataFactory;
use App\Service\GuestCheckIn\GuestCheckInInvitationService;
use App\Service\GuestCheckIn\GuestCheckInPolicy;
use App\Service\GuestCheckIn\GuestCheckInPreviewService;
use App\Service\GuestCheckIn\Section\GuestCheckInSections;
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
        GuestCheckInInvitationService $invitationService,
    ): Response {
        $config = $configService->getConfig();
        $wasEnabled = $config->isEnabled();
        $form = $this->createForm(GuestCheckInConfigType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $configService->saveConfig($config);

            if (!$wasEnabled && $config->isEnabled()) {
                // The invitation example only appears once the check-in is in use.
                $workflowSeeder->seedGuestCheckInWorkflows();
            }

            $this->addFlash('success', 'guest_checkin.flash.settings_saved');

            return $this->redirectToRoute('settings.guest_checkin.index');
        }

        return $this->render('Settings/GuestCheckIn/index.html.twig', [
            'form' => $form->createView(),
            'fieldGroups' => GuestCheckInConfigType::FIELD_GROUPS,
            'publicBaseUrl' => $publicUrlService->getBaseUrl(),
            'invitationWorkflows' => $invitationService->findInvitationWorkflows(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * The guest page with sample data and the current settings, as form or as it looks after
     * sending. Rendered with the public template, but nothing on it can be sent.
     */
    #[Route('/preview', name: 'settings.guest_checkin.preview', methods: ['GET'])]
    public function preview(
        Request $request,
        GuestCheckInConfigService $configService,
        GuestCheckInPreviewService $previewService,
        GuestCheckInPolicy $policy,
        GuestCheckInFormDataFactory $formDataFactory,
        GuestCheckInExtrasService $extrasService,
        GuestCheckInSections $sections,
    ): Response {
        $view = GuestCheckInPreviewService::VIEW_STAY === $request->query->getString('view')
            ? GuestCheckInPreviewService::VIEW_STAY
            : GuestCheckInPreviewService::VIEW_FORM;
        $checkIn = $previewService->sample($view);
        if (null === $checkIn) {
            $this->addFlash('warning', 'guest_checkin.settings.preview_no_room');

            return $this->redirectToRoute('settings.guest_checkin.index');
        }

        $config = $configService->getConfig();
        $reservation = $checkIn->getReservation();
        $extras = $config->isExtrasEnabled() ? $extrasService->available($reservation) : [];
        $form = null;
        if (GuestCheckInPreviewService::VIEW_FORM === $view) {
            $salutations = $configService->offeredSalutations();
            $companionCount = $policy->companionCount($reservation);
            $form = $this->createForm(GuestCheckInType::class, $formDataFactory->create($checkIn, $companionCount, $salutations, $config), [
                'config' => $config,
                'salutations' => $salutations,
                'companion_count' => $companionCount,
                'extras' => $extras,
                'action' => '#',
            ]);
        }

        $response = $this->render('GuestCheckIn/public/show.html.twig', [
            'preview' => true,
            'previewView' => $view,
            'reservation' => $reservation,
            'checkIn' => $checkIn,
            'config' => $config,
            'sections' => $sections->visibleFor($checkIn),
            'extras' => $extras,
            'bookedExtras' => [],
            'existingRequestedExtras' => [],
            'extrasChanged' => false,
            'canStartOtherGuest' => false,
            'otherGuestForm' => false,
            'otherGuestUrl' => '#',
            'restoreGuestUrl' => '#',
            'form' => $form?->createView(),
            'editable' => true,
            'submitted' => GuestCheckInStatus::OPEN !== $checkIn->getStatus(),
            'primaryColor' => $configService->pageAccentColor(),
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
