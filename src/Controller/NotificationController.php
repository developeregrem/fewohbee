<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\NotificationCenterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
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

        $groups = $this->notificationCenter->getGroupedItems($user);

        // Only stored entries can be dismissed, so the "mark all read" control
        // is pointless when the panel holds nothing but derived state.
        $hasStored = false;
        foreach ($groups as $items) {
            foreach ($items as $item) {
                if (null !== $item->id) {
                    $hasStored = true;
                    break 2;
                }
            }
        }

        return $this->render('Notifications/_panel.html.twig', [
            'groups' => $groups,
            'hasStored' => $hasStored,
            'summary' => $this->notificationCenter->getSummary($user),
        ]);
    }

    /** Marks a single stored notification as read. */
    #[Route('/{id}/read', name: 'notifications.read', methods: [Request::METHOD_POST], requirements: ['id' => '\\d+'])]
    public function read(int $id, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        if (!$this->isCsrfTokenValid('notification-read-' . $id, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException();
        }

        $this->notificationCenter->markRead($user, $id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Clears everything the user can currently see. */
    #[Route('/read-all', name: 'notifications.read_all', methods: [Request::METHOD_POST])]
    public function readAll(Request $request, RoleHierarchyInterface $roleHierarchy, Security $security): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        if (!$this->isCsrfTokenValid('notification-read-all', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException();
        }

        $token = $security->getToken();
        $roles = null !== $token ? $roleHierarchy->getReachableRoleNames($token->getRoleNames()) : [];
        $this->notificationCenter->markAllRead($user, $roles);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
