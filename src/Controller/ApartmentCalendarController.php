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
use App\Service\CalendarService;
use App\Service\CalendarSyncService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class ApartmentCalendarController extends AbstractController
{
    #[Route('/apartments/calendar/{uuid}/calendar.ics', name: 'apartments.get.calendar', requirements: ['uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function getCalendarAction(ManagerRegistry $doctrine, CalendarService $cs, string $uuid, CalendarSyncService $css): Response
    {
        $em = $doctrine->getManager();
        $sync = $em->getRepository(CalendarSync::class)->findOneBy(['uuid' => Uuid::fromString($uuid)]);
        /* @var $sync CalendarSync */
        if (!$sync instanceof CalendarSync || !$sync->getIsPublic()) {
            throw new NotFoundHttpException();
        }
        $css->updateExportDate($sync);

        $response = new Response(
            $cs->getIcalContent($sync),
            Response::HTTP_OK,
            ['content-type' => 'text/calendar; charset=utf-8']
        );
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'calendar.ics'
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }
}
