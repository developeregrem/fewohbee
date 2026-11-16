<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ReservationStatus;
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

        return $this->render('Styleguide/index.html.twig', [
            'statuses' => $this->sampleStatuses(),
        ]);
    }

    /**
     * Statuses as an operator might colour them, including colours close to either background.
     *
     * @return list<ReservationStatus>
     */
    private function sampleStatuses(): array
    {
        $samples = [
            ['Confirmed', '#2d9434', '#ffffff'],
            ['Option', '#f6e95c', '#000000'],
            ['Request', '#ffffff', '#000000'],
            ['Blocked', '#161b22', '#ffffff'],
            ['Channel', '#7b1fa2', '#ffffff'],
        ];

        return array_map(
            static fn (array $sample): ReservationStatus => (new ReservationStatus())
                ->setName($sample[0])
                ->setColor($sample[1])
                ->setContrastColor($sample[2]),
            $samples,
        );
    }
}
