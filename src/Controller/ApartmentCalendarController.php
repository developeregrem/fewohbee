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

namespace App\Controller;

use App\Entity\CalendarSync;
use App\Service\Calendar\Sync\ApartmentCalendarExportService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Publishes privacy-aware apartment availability calendars by unguessable UUID. */
class ApartmentCalendarController extends AbstractController
{
    #[Route('/apartments/calendar/{uuid}/calendar.ics', name: 'apartments.get.calendar', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function getCalendarAction(
        ManagerRegistry $doctrine,
        ApartmentCalendarExportService $exportService,
        string $uuid,
    ): Response {
        $sync = $doctrine->getManager()->getRepository(CalendarSync::class)
            ->findOneBy(['uuid' => Uuid::fromString($uuid)]);
        if (!$sync instanceof CalendarSync || !$sync->getIsPublic() || !$sync->getApartment()?->isActive()) {
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
