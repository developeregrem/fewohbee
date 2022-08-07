<?php

declare(strict_types=1);

/*
 * Click nbfs://nbhost/SystemFileSystem/Templates/Licenses/license-default.txt to change this license
 * Click nbfs://nbhost/SystemFileSystem/Templates/Scripting/PHPClass.php to edit this template
 */

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
    private $em = null;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function initSync(Appartment $room): void
    {
        if (null === $room->getCalendarSync()) {
            $sync = new CalendarSync();
            $sync->setApartment($room)
                 ->setUuid(Uuid::v4());
            $this->em->persist($sync);
            $this->em->flush();
        }
    }

    public function updateExportDate(CalendarSync $sync): void
    {
        $sync->setLastExport(new \DateTime());
        $this->em->persist($sync);
        $this->em->flush();
    }
}
