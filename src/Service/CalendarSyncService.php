<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\CalendarSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
class CalendarSyncService
{

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function initSync(Appartment $room): void
    {
        $sync = new CalendarSync();
        $sync->setApartment($room)
             ->setUuid(Uuid::v4());
        $this->em->persist($sync);
        $this->em->flush();
    }

    public function updateExportDate(CalendarSync $sync): void
    {
        $sync->setLastExport(new \DateTime());
        $this->em->persist($sync);
        $this->em->flush();
    }
}
