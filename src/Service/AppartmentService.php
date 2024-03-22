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
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class AppartmentService
{
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function getAppartmentFromForm(Request $request, $id = 'new')
    {
        $appartment = null;

        if ('new' === $id) {
            $appartment = new Appartment();
        } else {
            $appartment = $this->em->getRepository(Appartment::class)->find($id);
        }

        $appartment->setNumber($request->request->get('number-'.$id));
        $appartment->setBedsMax($request->request->get('bedsmax-'.$id));
        $appartment->setDescription($request->request->get('description-'.$id));

        $object = $this->em->getRepository(Subsidiary::class)->find($request->request->get('object-'.$id));
        $appartment->setObject($object);
        $category = $this->em->getRepository(RoomCategory::class)->find($request->request->get('category-'.$id));
        $appartment->setRoomCategory($category);

        return $appartment;
    }

    public function deleteAppartment($id)
    {
        $appartment = $this->em->getRepository(Appartment::class)->find($id);

        $reservations = $this->em->getRepository(Reservation::class)->findByAppartment($appartment);

        if (0 == count($reservations)) {
            $this->em->remove($appartment);
            $this->em->flush();

            return true;
        } else {
            return false;
        }
    }
}
