<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Api;

use App\Entity\Appartment;
use App\Entity\CalendarSync;
use App\Service\Calendar\Sync\ApartmentCalendarExportService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Token-authenticated iCal export that does not require the calendar's public flag.
 */
class ApartmentCalendarApiController extends AbstractController
{
    #[Route('/api/v1/apartments/{id}/calendar.ics', name: 'api.apartments.calendar', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('API_SCOPE_CALENDAR_READ')]
    public function ics(
        int $id,
        ManagerRegistry $doctrine,
        ApartmentCalendarExportService $exportService,
    ): Response {
        $entityManager = $doctrine->getManager();
        $apartment = $entityManager->getRepository(Appartment::class)->find($id);
        if (!$apartment instanceof Appartment || !$apartment->isActive()) {
            throw new NotFoundHttpException();
        }

        $sync = $entityManager->getRepository(CalendarSync::class)->findOneBy(['apartment' => $apartment]);
        if (!$sync instanceof CalendarSync) {
            throw new NotFoundHttpException();
        }

        $response = new Response(
            $exportService->export($sync),
            Response::HTTP_OK,
            ['content-type' => 'text/calendar; charset=utf-8'],
        );
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'calendar.ics'),
        );

        return $response;
    }
}
