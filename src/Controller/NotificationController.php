<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\NotificationCenterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The notification centre behind the navbar bell.
 *
 * Open to every authenticated user; what each one actually sees is decided per
 * provider, so the roles that already govern conflicts and calendar reminders
 * keep governing them here.
 */
#[Route('/notifications')]
#[IsGranted('IS_AUTHENTICATED')]
final class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationCenterService $notificationCenter,
    ) {
    }

    /** Panel body, loaded when the dropdown is opened for the first time. */
    #[Route('/panel', name: 'notifications.panel', methods: [Request::METHOD_GET])]
    public function panel(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        return $this->render('Notifications/_panel.html.twig', [
            'groups' => $this->notificationCenter->getGroupedItems($user),
        ]);
    }
}
