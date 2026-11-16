<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reference page of the design system: every building block next to each other in the light and
 * the dark colour scheme.
 *
 * A tool for developing the interface and themes, not a part of the application: it only exists
 * while debugging is on, and its texts are not translated.
 */
#[IsGranted('IS_AUTHENTICATED')]
final class StyleguideController extends AbstractController
{
    #[Route('/_styleguide', name: 'styleguide', methods: [Request::METHOD_GET])]
    public function index(#[Autowire('%kernel.debug%')] bool $debug): Response
    {
        if (!$debug) {
            throw $this->createNotFoundException();
        }

        return $this->render('Styleguide/index.html.twig');
    }
}
