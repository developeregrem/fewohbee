<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The settings area: an overview of all settings pages in groups. The pages themselves are
 * listed in its template; the navigation links here only.
 */
#[IsGranted('ROLE_ADMIN')]
final class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'settings.overview', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('Settings/index.html.twig');
    }
}
