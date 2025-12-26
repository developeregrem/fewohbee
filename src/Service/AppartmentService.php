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

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;

class AppartmentService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function deleteAppartment(Appartment $apartment): bool
    {
        $reservations = $this->em->getRepository(Reservation::class)->findByAppartment($apartment);

        if (0 == count($reservations)) {
            $this->em->remove($apartment);
            $this->em->flush();

            return true;
        } else {
            return false;
        }
    }
}
