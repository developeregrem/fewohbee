<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <alex.pensionsverwaltung@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;

use App\Entity\Appartment;
use App\Entity\Subsidiary;
use App\Entity\Reservation;
use App\Entity\RoomCategory;

class AppartmentService
{
    private $em = null;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function getAppartmentFromForm(Request $request, $id = 'new')
    {

        $appartment = null;

        if ($id === 'new') {
            $appartment = new Appartment();
        } else {
            $appartment = $this->em->getRepository(Appartment::class)->find($id);
        }

        $appartment->setNumber($request->get("number-" . $id));
        $appartment->setBedsMax($request->get("bedsmax-" . $id));
        $appartment->setDescription($request->get("description-" . $id));

        $object = $this->em->getRepository(Subsidiary::class)->find($request->get("object-" . $id));
        $appartment->setObject($object);
        $category = $this->em->getRepository(RoomCategory::class)->find($request->get("category-" . $id));
        $appartment->setRoomCategory($category);

        return $appartment;
    }

    public function deleteAppartment($id)
    {
        $appartment = $this->em->getRepository(Appartment::class)->find($id);

        $reservations = $this->em->getRepository(Reservation::class)->findByAppartment($appartment);

        if (count($reservations) == 0) {
            $this->em->remove($appartment);
            $this->em->flush();

            return true;
        } else {
            return false;
        }
    }
}
