<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\OnlineBookingConfigType;
use App\Service\OnlineBookingConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings/online-booking')]
#[IsGranted('ROLE_ADMIN')]
class OnlineBookingSettingsController extends AbstractController
{
    /** Render and persist the system-wide online booking settings. */
    #[Route('', name: 'settings.online_booking.index', methods: ['GET', 'POST'])]
    public function index(Request $request, OnlineBookingConfigService $configService): Response
    {
        $config = $configService->getConfig();
        $form = $this->createForm(OnlineBookingConfigType::class, $config, [
            'attr' => [
                'data-controller' => 'online-booking-settings',
            ],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $configService->saveConfig($config);
            $this->addFlash('success', 'online_booking.flash.settings_saved');

            return $this->redirectToRoute('settings.online_booking.index');
        }

        return $this->render('Settings/OnlineBooking/index.html.twig', [
            'form' => $form->createView(),
            'reservationOriginConfigured' => null !== $configService->getReservationOrigin($config),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
